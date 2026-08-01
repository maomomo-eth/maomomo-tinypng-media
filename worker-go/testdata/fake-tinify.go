//go:build ignore

package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"sync"
)

func main() {
	listen := os.Getenv("FAKE_TINIFY_LISTEN")
	if listen == "" {
		listen = "127.0.0.1:19000"
	}
	store := &fakeStore{items: make(map[string][]byte)}
	mux := http.NewServeMux()
	mux.HandleFunc("POST /shrink", store.shrink)
	mux.HandleFunc("GET /output/{id}", store.output)
	mux.HandleFunc("POST /output/{id}", store.convert)
	log.Printf("Fake Tinify 监听 %s", listen)
	log.Fatal(http.ListenAndServe(listen, mux))
}

type fakeStore struct {
	mu    sync.Mutex
	items map[string][]byte
	count int
}

func (s *fakeStore) shrink(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(io.LimitReader(r.Body, 32*1024*1024))
	if err != nil || len(body) == 0 {
		http.Error(w, `{"error":"BadRequest","message":"empty"}`, http.StatusBadRequest)
		return
	}
	hash := sha256.Sum256(body)
	id := hex.EncodeToString(hash[:8])
	compressed := body[:max(1, len(body)/2)]
	s.mu.Lock()
	s.items[id] = append([]byte(nil), compressed...)
	s.count++
	count := s.count
	s.mu.Unlock()
	w.Header().Set("Location", "http://127.0.0.1:19000/output/"+id)
	w.Header().Set("Compression-Count", fmt.Sprintf("%d", count))
	w.WriteHeader(http.StatusCreated)
}

func (s *fakeStore) output(w http.ResponseWriter, r *http.Request) {
	s.mu.Lock()
	data := append([]byte(nil), s.items[r.PathValue("id")]...)
	s.mu.Unlock()
	if len(data) == 0 {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "application/octet-stream")
	_, _ = w.Write(data)
}

func (s *fakeStore) convert(w http.ResponseWriter, r *http.Request) {
	var payload map[string]any
	_ = json.NewDecoder(r.Body).Decode(&payload)
	s.mu.Lock()
	data := append([]byte("RIFF-fake-webp-"), s.items[r.PathValue("id")]...)
	s.mu.Unlock()
	if len(data) == len("RIFF-fake-webp-") {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "image/webp")
	_, _ = w.Write(data)
}
