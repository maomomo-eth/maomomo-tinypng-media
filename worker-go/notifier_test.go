package main

import (
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestNotifierSignsCallbackAndAcknowledgesResult(t *testing.T) {
	secret := "0123456789abcdef0123456789abcdef"
	auth, err := newAuthenticator(secret)
	if err != nil {
		t.Fatal(err)
	}
	called := make(chan struct{}, 1)
	callback := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, readErr := io.ReadAll(r.Body)
		if readErr != nil {
			t.Errorf("读取回调失败: %v", readErr)
			http.Error(w, "read", http.StatusBadRequest)
			return
		}
		if verifyErr := auth.verify(r, body); verifyErr != nil {
			t.Errorf("回调签名无效: %v", verifyErr)
			http.Error(w, "auth", http.StatusUnauthorized)
			return
		}
		called <- struct{}{}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"finalized":1}`))
	}))
	defer callback.Close()

	store, err := newJobStore(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	result := jobResult{
		JobID:        "job-12345678",
		SiteID:       "site-12345678",
		AttachmentID: 123,
		Mode:         "compress",
		Summary:      emptySummary(),
		CallbackURL:  callback.URL + "/wp-json/maomomo-tinypng/v1/results",
	}
	if err := writeJSONAtomic(store.done, result.JobID+".json", result); err != nil {
		t.Fatal(err)
	}
	serviceAuth, _ := newAuthenticator(secret)
	s := &server{store: store, auth: serviceAuth}
	s.notifyCompleted()

	select {
	case <-called:
	default:
		t.Fatal("没有收到签名回调")
	}
	if _, err := os.Stat(filepath.Join(store.done, result.JobID+".json")); !os.IsNotExist(err) {
		t.Fatal("成功回调后结果没有确认删除")
	}
}
