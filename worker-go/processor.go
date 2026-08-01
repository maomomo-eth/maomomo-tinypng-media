package main

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

const tinyPNGEndpoint = "https://api.tinify.com/shrink"

type processor struct {
	allowedRoots []string
	endpoint     string
	usageMu      sync.Mutex
	usage        map[string]int
	clientsMu    sync.Mutex
	clients      map[string]*http.Client
}

type processError struct {
	message   string
	retryable bool
	status    int
}

func (e *processError) Error() string { return e.message }

func newProcessor(allowedRoots []string) (*processor, error) {
	roots := make([]string, 0, len(allowedRoots))
	for _, root := range allowedRoots {
		root = filepath.Clean(strings.TrimSpace(root))
		if root == "." || !filepath.IsAbs(root) {
			continue
		}
		resolved, err := filepath.EvalSymlinks(root)
		if err != nil {
			return nil, fmt.Errorf("解析 uploads 根目录失败 %s: %w", root, err)
		}
		roots = append(roots, filepath.Clean(resolved))
	}
	if len(roots) == 0 {
		return nil, errors.New("至少需要一个有效的 MAOMOMO_WORKER_UPLOADS_ROOTS")
	}
	return &processor{
		allowedRoots: roots,
		endpoint:     tinyPNGEndpoint,
		usage:        make(map[string]int),
		clients:      make(map[string]*http.Client),
	}, nil
}

func (p *processor) process(value job) jobResult {
	result := jobResult{
		JobID:        value.ID,
		SiteID:       value.SiteID,
		AttachmentID: value.AttachmentID,
		Mode:         value.Mode,
		Summary:      emptySummary(),
		Usage:        make(map[string]int),
		CallbackURL:  value.CallbackURL,
		CompletedAt:  completedNow(),
	}

	if err := p.validateJob(value); err != nil {
		result.Summary.Failed++
		result.Summary.Messages = append(result.Summary.Messages, err.Error())
		return result
	}
	p.mergeInitialUsage(value.Tokens)

	if value.Mode == "compress" || value.Mode == "both" {
		p.compressFiles(value, &result.Summary)
	}
	if value.Mode == "webp" || value.Mode == "both" {
		p.convertWebP(value, &result)
	}
	result.Usage = p.usageSnapshot(value.Tokens)
	result.CompletedAt = completedNow()
	return result
}

func (p *processor) validateJob(value job) error {
	if !safeIDPattern.MatchString(value.ID) || !safeIDPattern.MatchString(value.SiteID) {
		return errors.New("任务 ID 格式无效")
	}
	if value.AttachmentID <= 0 {
		return errors.New("附件 ID 无效")
	}
	if value.Mode != "compress" && value.Mode != "webp" && value.Mode != "both" {
		return errors.New("处理模式无效")
	}
	if len(value.Tokens) == 0 {
		return errors.New("请先在设置页配置 TinyPNG API Token。")
	}
	root, err := filepath.EvalSymlinks(filepath.Clean(value.UploadsRoot))
	if err != nil || !p.isAllowedRoot(root) {
		return errors.New("任务 uploads 根目录不在服务允许范围内")
	}
	for _, path := range append(append([]string{}, value.CompressPaths...), value.SourcePath) {
		if path == "" {
			continue
		}
		if err := ensureExistingPathInRoot(root, path); err != nil {
			return err
		}
	}
	if value.WebPPath != "" {
		if err := ensureOutputPathInRoot(root, value.WebPPath); err != nil {
			return err
		}
	}
	return nil
}

func (p *processor) isAllowedRoot(root string) bool {
	root = filepath.Clean(root)
	for _, allowed := range p.allowedRoots {
		if root == allowed {
			return true
		}
	}
	return false
}

func ensureExistingPathInRoot(root, path string) error {
	resolved, err := filepath.EvalSymlinks(filepath.Clean(path))
	if err != nil {
		return fmt.Errorf("文件不存在或不可读取：%s", filepath.Base(path))
	}
	if !pathWithin(root, resolved) {
		return fmt.Errorf("文件不在 uploads 目录：%s", filepath.Base(path))
	}
	info, err := os.Stat(resolved)
	if err != nil || !info.Mode().IsRegular() {
		return fmt.Errorf("文件不存在或不可读取：%s", filepath.Base(path))
	}
	return nil
}

func ensureOutputPathInRoot(root, path string) error {
	parent, err := filepath.EvalSymlinks(filepath.Dir(filepath.Clean(path)))
	if err != nil || !pathWithin(root, parent) {
		return fmt.Errorf("WebP 输出目录无效：%s", filepath.Base(path))
	}
	return nil
}

func pathWithin(root, path string) bool {
	rel, err := filepath.Rel(filepath.Clean(root), filepath.Clean(path))
	return err == nil && rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))
}

func (p *processor) compressFiles(value job, out *summary) {
	if len(value.CompressPaths) == 0 {
		out.Failed++
		out.Messages = append(out.Messages, fmt.Sprintf("附件 #%d 没有可压缩的本地文件。", value.AttachmentID))
		return
	}
	for _, path := range value.CompressPaths {
		beforeInfo, err := os.Stat(path)
		if err != nil {
			out.Failed++
			out.Messages = append(out.Messages, filepath.Base(path)+"：文件不存在或不可读取。")
			continue
		}
		tmpPath, after, err := p.requestToTemp(value, path, "")
		if err != nil {
			out.Failed++
			out.Messages = append(out.Messages, filepath.Base(path)+"："+err.Error())
			continue
		}
		before := beforeInfo.Size()
		if !shouldReplace(before, after) {
			_ = os.Remove(tmpPath)
			out.Skipped++
			out.BytesBefore += before
			out.BytesAfter += before
			out.Messages = append(out.Messages, filepath.Base(path)+" 压缩收益不足，保留原图。")
			continue
		}
		if err := replaceFromTemp(tmpPath, path, beforeInfo.Mode()); err != nil {
			out.Failed++
			out.Messages = append(out.Messages, filepath.Base(path)+"：替换目标文件失败。")
			continue
		}
		out.OK++
		out.BytesBefore += before
		out.BytesAfter += after
	}
}

func (p *processor) convertWebP(value job, result *jobResult) {
	if strings.EqualFold(filepath.Ext(value.SourcePath), ".webp") {
		result.Summary.Skipped++
		result.Summary.Messages = append(result.Summary.Messages, fmt.Sprintf("附件 #%d 已经是 WebP。", value.AttachmentID))
		return
	}
	if value.SourcePath == "" || value.WebPPath == "" {
		result.Summary.Failed++
		result.Summary.Messages = append(result.Summary.Messages, fmt.Sprintf("附件 #%d 找不到原图文件。", value.AttachmentID))
		return
	}
	beforeInfo, err := os.Stat(value.SourcePath)
	if err != nil {
		result.Summary.Failed++
		result.Summary.Messages = append(result.Summary.Messages, fmt.Sprintf("附件 #%d 找不到原图文件。", value.AttachmentID))
		return
	}
	tmpPath, after, err := p.requestToTemp(value, value.SourcePath, "image/webp")
	if err != nil {
		result.Summary.Failed++
		result.Summary.Messages = append(result.Summary.Messages, fmt.Sprintf("附件 #%d 转 WebP 失败：%s", value.AttachmentID, err.Error()))
		return
	}
	if err := replaceFromTemp(tmpPath, value.WebPPath, 0o644); err != nil {
		result.Summary.Failed++
		result.Summary.Messages = append(result.Summary.Messages, "替换目标文件失败："+filepath.Base(value.WebPPath))
		return
	}
	result.Summary.OK++
	result.Summary.WebP++
	result.Summary.BytesBefore += beforeInfo.Size()
	result.Summary.BytesAfter += after
	result.WebPPath = value.WebPPath
}

func shouldReplace(before, after int64) bool {
	if before <= 0 || after <= 0 {
		return false
	}
	threshold := 0.9
	if before < 1024*1024 {
		threshold = 0.8
	}
	return float64(after)/float64(before) <= threshold
}

func replaceFromTemp(tmpPath, targetPath string, mode os.FileMode) error {
	defer os.Remove(tmpPath)
	if err := os.Chmod(tmpPath, mode.Perm()); err != nil {
		return err
	}
	return os.Rename(tmpPath, targetPath)
}

func (p *processor) requestToTemp(value job, sourcePath, targetType string) (string, int64, error) {
	blocked := make(map[string]bool)
	var lastErr error
	for {
		token, ok := p.chooseToken(value.Tokens, blocked)
		if !ok {
			if lastErr != nil {
				return "", 0, fmt.Errorf("所有 API Token 都已达到本月上限或暂不可用。最后错误：%w", lastErr)
			}
			return "", 0, errors.New("所有 API Token 都已达到本月上限或暂不可用。")
		}
		tmpPath, size, err := p.requestWithToken(value, token, sourcePath, targetType)
		if err == nil {
			return tmpPath, size, nil
		}
		lastErr = err
		var processErr *processError
		if errors.As(err, &processErr) {
			if processErr.status == http.StatusTooManyRequests {
				p.setUsage(token.ID, token.MonthlyLimit)
				blocked[token.ID] = true
				continue
			}
			if processErr.status == http.StatusUnauthorized || processErr.status == http.StatusForbidden || processErr.retryable {
				blocked[token.ID] = true
				continue
			}
		}
		return "", 0, err
	}
}

func (p *processor) requestWithToken(value job, token tokenConfig, sourcePath, targetType string) (string, int64, error) {
	client, err := p.client(value.Proxy, value.TimeoutSeconds)
	if err != nil {
		return "", 0, err
	}
	ctx, cancel := context.WithTimeout(context.Background(), time.Duration(value.TimeoutSeconds)*time.Second)
	defer cancel()

	source, err := os.Open(sourcePath)
	if err != nil {
		return "", 0, &processError{message: "文件不存在或不可读取。"}
	}
	info, err := source.Stat()
	if err != nil {
		_ = source.Close()
		return "", 0, &processError{message: "读取文件失败。"}
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, p.endpoint, source)
	if err != nil {
		_ = source.Close()
		return "", 0, err
	}
	req.ContentLength = info.Size()
	req.Header.Set("Content-Type", "application/octet-stream")
	setBasicAuth(req, token.Key)
	resp, err := client.Do(req)
	_ = source.Close()
	if err != nil {
		return "", 0, &processError{message: err.Error(), retryable: true}
	}
	location, err := p.handleShrinkResponse(resp, token)
	if err != nil {
		return "", 0, err
	}

	var outputReq *http.Request
	if targetType == "" {
		outputReq, err = http.NewRequestWithContext(ctx, http.MethodGet, location, nil)
	} else {
		body, _ := json.Marshal(map[string]any{"convert": map[string]any{"type": []string{targetType}}})
		outputReq, err = http.NewRequestWithContext(ctx, http.MethodPost, location, bytes.NewReader(body))
		if err == nil {
			outputReq.Header.Set("Content-Type", "application/json")
		}
	}
	if err != nil {
		return "", 0, err
	}
	setBasicAuth(outputReq, token.Key)
	outputResp, err := client.Do(outputReq)
	if err != nil {
		return "", 0, &processError{message: err.Error(), retryable: true}
	}
	if err := p.validateResponse(outputResp, token); err != nil {
		return "", 0, err
	}
	defer outputResp.Body.Close()

	tmpDir := filepath.Dir(sourcePath)
	if targetType != "" {
		tmpDir = filepath.Dir(value.WebPPath)
	}
	tmp, err := os.CreateTemp(tmpDir, ".maomomo-tinypng-*.tmp")
	if err != nil {
		return "", 0, &processError{message: "写入临时文件失败。"}
	}
	tmpPath := tmp.Name()
	defer func() {
		_ = tmp.Close()
	}()
	written, copyErr := io.Copy(tmp, outputResp.Body)
	if copyErr == nil {
		copyErr = tmp.Sync()
	}
	if closeErr := tmp.Close(); copyErr == nil {
		copyErr = closeErr
	}
	if copyErr != nil || written <= 0 {
		_ = os.Remove(tmpPath)
		return "", 0, &processError{message: "TinyPNG 返回空文件或写入临时文件失败。", retryable: true}
	}
	return tmpPath, written, nil
}

func (p *processor) handleShrinkResponse(resp *http.Response, token tokenConfig) (string, error) {
	if err := p.validateResponse(resp, token); err != nil {
		return "", err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	location := resp.Header.Get("Location")
	if location == "" {
		return "", &processError{message: "TinyPNG 没有返回输出地址。", retryable: true}
	}
	return location, nil
}

func (p *processor) validateResponse(resp *http.Response, token tokenConfig) error {
	if countText := resp.Header.Get("Compression-Count"); countText != "" {
		if count, err := strconv.Atoi(countText); err == nil {
			p.setUsage(token.ID, count)
		}
	}
	if resp.StatusCode >= 200 && resp.StatusCode < 300 {
		return nil
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 64*1024))
	message := parseTinyPNGError(body)
	if message == "" {
		message = fmt.Sprintf("TinyPNG 请求失败，状态码：%d", resp.StatusCode)
	}
	return &processError{
		message:   message,
		status:    resp.StatusCode,
		retryable: resp.StatusCode == 429 || resp.StatusCode == 500 || resp.StatusCode == 502 || resp.StatusCode == 503 || resp.StatusCode == 504,
	}
}

func parseTinyPNGError(body []byte) string {
	var payload struct {
		Error   string `json:"error"`
		Message string `json:"message"`
	}
	if json.Unmarshal(body, &payload) == nil {
		if payload.Message != "" {
			return payload.Message
		}
		return payload.Error
	}
	return ""
}

func setBasicAuth(req *http.Request, key string) {
	req.Header.Set("Authorization", "Basic "+base64.StdEncoding.EncodeToString([]byte("api:"+key)))
}

func (p *processor) client(proxyAddress string, timeoutSeconds int) (*http.Client, error) {
	if timeoutSeconds < 10 {
		timeoutSeconds = 90
	}
	if timeoutSeconds > 300 {
		timeoutSeconds = 300
	}
	cacheKey := proxyAddress + "|" + strconv.Itoa(timeoutSeconds)
	p.clientsMu.Lock()
	defer p.clientsMu.Unlock()
	if client, ok := p.clients[cacheKey]; ok {
		return client, nil
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.MaxIdleConns = 16
	transport.MaxIdleConnsPerHost = 8
	transport.IdleConnTimeout = 90 * time.Second
	if strings.TrimSpace(proxyAddress) != "" {
		normalized := strings.TrimSpace(proxyAddress)
		if strings.HasPrefix(strings.ToLower(normalized), "socks5h://") {
			normalized = "socks5://" + normalized[len("socks5h://"):]
		}
		proxyURL, err := url.Parse(normalized)
		if err != nil || proxyURL.Host == "" {
			return nil, errors.New("代理地址无效")
		}
		transport.Proxy = http.ProxyURL(proxyURL)
	}
	client := &http.Client{
		Transport: transport,
		Timeout:   time.Duration(timeoutSeconds) * time.Second,
	}
	p.clients[cacheKey] = client
	return client, nil
}

func (p *processor) mergeInitialUsage(tokens []tokenConfig) {
	p.usageMu.Lock()
	defer p.usageMu.Unlock()
	for _, token := range tokens {
		if token.Count > p.usage[token.ID] {
			p.usage[token.ID] = token.Count
		}
	}
}

func (p *processor) setUsage(tokenID string, count int) {
	p.usageMu.Lock()
	defer p.usageMu.Unlock()
	if count > p.usage[tokenID] {
		p.usage[tokenID] = count
	}
}

func (p *processor) chooseToken(tokens []tokenConfig, blocked map[string]bool) (tokenConfig, bool) {
	p.usageMu.Lock()
	defer p.usageMu.Unlock()
	for _, token := range tokens {
		if token.ID == "" || token.Key == "" || blocked[token.ID] {
			continue
		}
		count := p.usage[token.ID]
		if token.Count > count {
			count = token.Count
		}
		if count < token.MonthlyLimit {
			return token, true
		}
	}
	return tokenConfig{}, false
}

func (p *processor) usageSnapshot(tokens []tokenConfig) map[string]int {
	p.usageMu.Lock()
	defer p.usageMu.Unlock()
	result := make(map[string]int, len(tokens))
	for _, token := range tokens {
		result[token.ID] = p.usage[token.ID]
	}
	return result
}
