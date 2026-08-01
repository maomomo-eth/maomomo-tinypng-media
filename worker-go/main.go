package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

type server struct {
	store     *jobStore
	processor *processor
	auth      *authenticator
	workers   int
	wake      chan struct{}
	stop      chan struct{}
	wg        sync.WaitGroup
}

func main() {
	listen := flag.String("listen", envOrDefault("MAOMOMO_WORKER_LISTEN", "127.0.0.1:17863"), "监听地址")
	spool := flag.String("spool", envOrDefault("MAOMOMO_WORKER_SPOOL_DIR", "/var/lib/maomomo-tinypng-worker"), "持久化队列目录")
	workers := flag.Int("workers", envInt("MAOMOMO_WORKER_WORKERS", 3), "并发 Worker 数")
	secret := flag.String("secret", os.Getenv("MAOMOMO_WORKER_SECRET"), "HMAC 共享密钥")
	uploadsRoots := flag.String("uploads-roots", os.Getenv("MAOMOMO_WORKER_UPLOADS_ROOTS"), "允许读写的 uploads 根目录，逗号分隔")
	tinyPNGURL := flag.String("tinypng-endpoint", envOrDefault("MAOMOMO_WORKER_TINYPNG_ENDPOINT", tinyPNGEndpoint), "TinyPNG shrink API 地址")
	showVersion := flag.Bool("version", false, "显示版本")
	flag.Parse()
	if *showVersion {
		fmt.Println(workerVersion)
		return
	}
	if *workers < 1 || *workers > 16 {
		log.Fatal("workers 必须在 1 到 16 之间")
	}
	auth, err := newAuthenticator(*secret)
	if err != nil {
		log.Fatal(err)
	}
	store, err := newJobStore(*spool)
	if err != nil {
		log.Fatal(err)
	}
	processor, err := newProcessor(splitNonEmpty(*uploadsRoots))
	if err != nil {
		log.Fatal(err)
	}
	processor.endpoint = *tinyPNGURL

	s := &server{
		store:     store,
		processor: processor,
		auth:      auth,
		workers:   *workers,
		wake:      make(chan struct{}, *workers),
		stop:      make(chan struct{}),
	}
	s.startWorkers()
	s.startNotifier()

	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", s.health)
	mux.HandleFunc("POST /v1/jobs", s.authenticated(s.enqueue))
	mux.HandleFunc("GET /v1/results", s.authenticated(s.results))
	mux.HandleFunc("POST /v1/ack", s.authenticated(s.ack))
	httpServer := &http.Server{
		Addr:              *listen,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
		MaxHeaderBytes:    32 * 1024,
	}

	go func() {
		log.Printf("MaoMoMo TinyPNG Go Worker %s 监听 %s，附件级并发 %d", workerVersion, *listen, *workers)
		if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatalf("HTTP 服务退出: %v", err)
		}
	}()

	stopSignal := make(chan os.Signal, 1)
	signal.Notify(stopSignal, syscall.SIGINT, syscall.SIGTERM)
	<-stopSignal
	close(s.stop)
	_ = httpServer.Close()
	s.wg.Wait()
}

func (s *server) startWorkers() {
	for i := 1; i <= s.workers; i++ {
		s.wg.Add(1)
		go func(slot int) {
			defer s.wg.Done()
			s.workerLoop(slot)
		}(i)
	}
}

func (s *server) wakeWorkers() {
	for i := 0; i < s.workers; i++ {
		select {
		case s.wake <- struct{}{}:
		default:
			return
		}
	}
}

func (s *server) workerLoop(slot int) {
	for {
		select {
		case <-s.stop:
			return
		default:
		}
		runningPath, ok, err := s.store.claimNext()
		if err != nil {
			log.Printf("Worker %d 领取任务失败: %v", slot, err)
			time.Sleep(time.Second)
			continue
		}
		if !ok {
			select {
			case <-s.wake:
			case <-time.After(time.Second):
			case <-s.stop:
				return
			}
			continue
		}
		value, err := s.store.readJob(runningPath)
		if err != nil {
			log.Printf("Worker %d 读取任务失败: %v", slot, err)
			_ = os.Rename(runningPath, runningPath+".invalid")
			continue
		}
		log.Printf("Worker %d 正在处理附件 #%d，job=%s", slot, value.AttachmentID, value.ID)
		result := s.processor.process(value)
		if err := s.store.complete(runningPath, result); err != nil {
			log.Printf("Worker %d 保存任务结果失败: %v", slot, err)
			continue
		}
		log.Printf("Worker %d 完成附件 #%d：成功 %d，失败 %d，跳过 %d", slot, value.AttachmentID, result.Summary.OK, result.Summary.Failed, result.Summary.Skipped)
	}
}

func (s *server) startNotifier() {
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		ticker := time.NewTicker(2 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				s.notifyCompleted()
			case <-s.stop:
				return
			}
		}
	}()
}

func (s *server) notifyCompleted() {
	results, err := s.store.allResults(100)
	if err != nil {
		log.Printf("读取待通知结果失败: %v", err)
		return
	}
	type group struct {
		siteID  string
		url     string
		results []jobResult
	}
	groups := make(map[string]*group)
	for _, result := range results {
		if result.CallbackURL == "" {
			continue
		}
		callbackURL, err := url.Parse(result.CallbackURL)
		if err != nil || (callbackURL.Scheme != "http" && callbackURL.Scheme != "https") || callbackURL.Host == "" {
			continue
		}
		key := result.SiteID + "\n" + result.CallbackURL
		if groups[key] == nil {
			groups[key] = &group{siteID: result.SiteID, url: result.CallbackURL}
		}
		if len(groups[key].results) < 10 {
			groups[key].results = append(groups[key].results, result)
		}
	}
	for _, current := range groups {
		if err := s.postResults(current.url, current.results); err != nil {
			log.Printf("回调 WordPress 失败: %v", err)
			continue
		}
		jobIDs := make([]string, 0, len(current.results))
		for _, result := range current.results {
			jobIDs = append(jobIDs, result.JobID)
		}
		if err := s.store.ack(current.siteID, jobIDs); err != nil {
			log.Printf("确认已回调结果失败: %v", err)
		}
	}
}

func (s *server) postResults(callbackURL string, results []jobResult) error {
	body, err := json.Marshal(map[string]any{"results": results})
	if err != nil {
		return err
	}
	parsed, err := url.Parse(callbackURL)
	if err != nil {
		return err
	}
	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	nonce, err := randomNonce()
	if err != nil {
		return err
	}
	requestURI := parsed.RequestURI()
	req, err := http.NewRequest(http.MethodPost, callbackURL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set(authTimestampHeader, timestamp)
	req.Header.Set(authNonceHeader, nonce)
	req.Header.Set(authSignatureHeader, signRequest(s.auth.secret, req.Method, requestURI, timestamp, nonce, body))
	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 64*1024))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("WordPress 回调状态码 %d", resp.StatusCode)
	}
	return nil
}

func (s *server) health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, healthResponse{OK: true, Version: workerVersion, Workers: s.workers, Time: time.Now().UTC().Format(time.RFC3339)})
}

func (s *server) enqueue(w http.ResponseWriter, _ *http.Request, body []byte) {
	var value job
	if err := json.Unmarshal(body, &value); err != nil {
		writeError(w, http.StatusBadRequest, "任务 JSON 无效")
		return
	}
	if value.CreatedAt == 0 {
		value.CreatedAt = time.Now().Unix()
	}
	if err := s.processor.validateJob(value); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	created, err := s.store.enqueue(value)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "任务持久化失败")
		return
	}
	s.wakeWorkers()
	writeJSON(w, http.StatusAccepted, map[string]any{"accepted": true, "created": created, "job_id": value.ID})
}

func (s *server) results(w http.ResponseWriter, r *http.Request, _ []byte) {
	siteID := r.URL.Query().Get("site_id")
	if !safeIDPattern.MatchString(siteID) {
		writeError(w, http.StatusBadRequest, "site_id 格式无效")
		return
	}
	active, err := s.store.active(siteID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "读取活动任务失败")
		return
	}
	results, err := s.store.results(siteID, 100)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "读取任务结果失败")
		return
	}
	writeJSON(w, http.StatusOK, resultsResponse{Active: active, Results: results})
}

func (s *server) ack(w http.ResponseWriter, _ *http.Request, body []byte) {
	var request ackRequest
	if err := json.Unmarshal(body, &request); err != nil || !safeIDPattern.MatchString(request.SiteID) {
		writeError(w, http.StatusBadRequest, "确认请求无效")
		return
	}
	if len(request.JobIDs) > 100 {
		writeError(w, http.StatusBadRequest, "单次最多确认 100 个任务")
		return
	}
	if err := s.store.ack(request.SiteID, request.JobIDs); err != nil {
		writeError(w, http.StatusInternalServerError, "确认任务失败")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"acked": len(request.JobIDs)})
}

func (s *server) authenticated(next func(http.ResponseWriter, *http.Request, []byte)) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		r.Body = http.MaxBytesReader(w, r.Body, 2*1024*1024)
		body, err := io.ReadAll(r.Body)
		if err != nil {
			writeError(w, http.StatusRequestEntityTooLarge, "请求体过大")
			return
		}
		r.Body = io.NopCloser(bytes.NewReader(body))
		if err := s.auth.verify(r, body); err != nil {
			writeError(w, http.StatusUnauthorized, err.Error())
			return
		}
		next(w, r, body)
	}
}

func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}

func writeError(w http.ResponseWriter, status int, message string) {
	writeJSON(w, status, map[string]string{"error": message})
}

func envOrDefault(name, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(name)); value != "" {
		return value
	}
	return fallback
}

func envInt(name string, fallback int) int {
	value, err := strconv.Atoi(strings.TrimSpace(os.Getenv(name)))
	if err != nil {
		return fallback
	}
	return value
}

func splitNonEmpty(value string) []string {
	parts := strings.Split(value, ",")
	result := make([]string, 0, len(parts))
	for _, part := range parts {
		if part = strings.TrimSpace(part); part != "" {
			result = append(result, part)
		}
	}
	return result
}
