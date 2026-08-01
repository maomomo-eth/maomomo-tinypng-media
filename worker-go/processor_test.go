package main

import (
	"bytes"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestProcessorCompressesFileWithoutLoadingWholeOutput(t *testing.T) {
	output := bytes.Repeat([]byte("x"), 1000)
	server := newFakeTinyPNGServer(t, output)
	defer server.Close()

	root := t.TempDir()
	source := filepath.Join(root, "photo.jpg")
	if err := os.WriteFile(source, bytes.Repeat([]byte("a"), 10_000), 0o640); err != nil {
		t.Fatal(err)
	}
	processor, err := newProcessor([]string{root})
	if err != nil {
		t.Fatal(err)
	}
	processor.endpoint = server.URL + "/shrink"
	result := processor.process(testJob(root, source, "compress"))
	if result.Summary.Failed != 0 || result.Summary.OK != 1 {
		t.Fatalf("压缩结果异常: %+v", result.Summary)
	}
	info, err := os.Stat(source)
	if err != nil {
		t.Fatal(err)
	}
	if info.Size() != int64(len(output)) || info.Mode().Perm() != 0o640 {
		t.Fatalf("输出文件大小或权限不正确: size=%d mode=%o", info.Size(), info.Mode().Perm())
	}
}

func TestProcessorConvertsWebP(t *testing.T) {
	output := []byte("fake-webp-output")
	server := newFakeTinyPNGServer(t, output)
	defer server.Close()

	root := t.TempDir()
	source := filepath.Join(root, "photo.png")
	target := filepath.Join(root, "photo.webp")
	if err := os.WriteFile(source, bytes.Repeat([]byte("p"), 2000), 0o644); err != nil {
		t.Fatal(err)
	}
	processor, err := newProcessor([]string{root})
	if err != nil {
		t.Fatal(err)
	}
	processor.endpoint = server.URL + "/shrink"
	value := testJob(root, source, "webp")
	value.WebPPath = target
	result := processor.process(value)
	if result.Summary.Failed != 0 || result.Summary.WebP != 1 || result.WebPPath != target {
		t.Fatalf("WebP 结果异常: %+v", result)
	}
	data, err := os.ReadFile(target)
	if err != nil || !bytes.Equal(data, output) {
		t.Fatalf("WebP 文件内容不正确: err=%v", err)
	}
}

func testJob(root, source, mode string) job {
	value := job{
		ID:             "job-12345678",
		SiteID:         "site-12345678",
		AttachmentID:   123,
		Mode:           mode,
		UploadsRoot:    root,
		SourcePath:     source,
		Tokens:         []tokenConfig{{ID: "token-12345678", Name: "测试", Key: "secret", MonthlyLimit: 500}},
		TimeoutSeconds: 30,
	}
	if mode == "compress" || mode == "both" {
		value.CompressPaths = []string{source}
	}
	return value
}

func newFakeTinyPNGServer(t *testing.T, output []byte) *httptest.Server {
	t.Helper()
	var server *httptest.Server
	server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/shrink":
			w.Header().Set("Location", server.URL+"/output")
			w.Header().Set("Compression-Count", "12")
			w.WriteHeader(http.StatusCreated)
		case "/output":
			w.Header().Set("Content-Type", "application/octet-stream")
			_, _ = w.Write(output)
		default:
			http.Error(w, fmt.Sprintf("unknown path %s", r.URL.Path), http.StatusNotFound)
		}
	}))
	return server
}
