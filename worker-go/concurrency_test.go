package main

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"sync/atomic"
	"testing"
	"time"
)

func TestThreeWorkersProcessAttachmentsConcurrently(t *testing.T) {
	var active int32
	var maximum int32
	var fake *httptest.Server
	fake = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/shrink" {
			current := atomic.AddInt32(&active, 1)
			for {
				old := atomic.LoadInt32(&maximum)
				if current <= old || atomic.CompareAndSwapInt32(&maximum, old, current) {
					break
				}
			}
			time.Sleep(150 * time.Millisecond)
			atomic.AddInt32(&active, -1)
			w.Header().Set("Location", fake.URL+"/output")
			w.WriteHeader(http.StatusCreated)
			return
		}
		_, _ = w.Write(bytes.Repeat([]byte("z"), 1000))
	}))
	defer fake.Close()

	root := t.TempDir()
	store, err := newJobStore(filepath.Join(root, "spool"))
	if err != nil {
		t.Fatal(err)
	}
	processor, err := newProcessor([]string{root})
	if err != nil {
		t.Fatal(err)
	}
	processor.endpoint = fake.URL + "/shrink"
	service := &server{
		store:     store,
		processor: processor,
		workers:   3,
		wake:      make(chan struct{}, 3),
		stop:      make(chan struct{}),
	}
	service.startWorkers()
	defer func() {
		close(service.stop)
		service.wg.Wait()
	}()

	for i := 1; i <= 3; i++ {
		source := filepath.Join(root, "photo-"+strconv.Itoa(i)+".jpg")
		if err := os.WriteFile(source, bytes.Repeat([]byte("a"), 10_000), 0o644); err != nil {
			t.Fatal(err)
		}
		value := testJob(root, source, "compress")
		value.ID = "job-1234567" + strconv.Itoa(i)
		value.AttachmentID = int64(i)
		if _, err := store.enqueue(value); err != nil {
			t.Fatal(err)
		}
	}
	service.wakeWorkers()

	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		results, err := store.allResults(10)
		if err != nil {
			t.Fatal(err)
		}
		if len(results) == 3 {
			if atomic.LoadInt32(&maximum) < 3 {
				t.Fatalf("没有达到附件级 3 Worker 并发，最大并发=%d", maximum)
			}
			return
		}
		time.Sleep(25 * time.Millisecond)
	}
	t.Fatal("等待三个并发任务完成超时")
}
