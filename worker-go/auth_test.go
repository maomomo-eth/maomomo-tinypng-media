package main

import (
	"net/http/httptest"
	"strconv"
	"testing"
	"time"
)

func TestAuthenticatorAcceptsValidSignatureAndRejectsReplay(t *testing.T) {
	secret := "0123456789abcdef0123456789abcdef"
	auth, err := newAuthenticator(secret)
	if err != nil {
		t.Fatal(err)
	}
	body := []byte(`{"job_id":"job-12345678"}`)
	req := httptest.NewRequest("POST", "http://127.0.0.1/v1/jobs", nil)
	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	nonce := "1234567890abcdef"
	req.Header.Set(authTimestampHeader, timestamp)
	req.Header.Set(authNonceHeader, nonce)
	req.Header.Set(authSignatureHeader, signRequest([]byte(secret), req.Method, req.URL.RequestURI(), timestamp, nonce, body))

	if err := auth.verify(req, body); err != nil {
		t.Fatalf("有效签名被拒绝: %v", err)
	}
	if err := auth.verify(req, body); err == nil {
		t.Fatal("重复 nonce 未被拒绝")
	}
}

func TestAuthenticatorRejectsExpiredTimestamp(t *testing.T) {
	secret := "0123456789abcdef0123456789abcdef"
	auth, _ := newAuthenticator(secret)
	body := []byte{}
	req := httptest.NewRequest("GET", "http://127.0.0.1/v1/results?site_id=site-12345678", nil)
	timestamp := strconv.FormatInt(time.Now().Add(-5*time.Minute).Unix(), 10)
	nonce := "abcdef1234567890"
	req.Header.Set(authTimestampHeader, timestamp)
	req.Header.Set(authNonceHeader, nonce)
	req.Header.Set(authSignatureHeader, signRequest([]byte(secret), req.Method, req.URL.RequestURI(), timestamp, nonce, body))

	if err := auth.verify(req, body); err == nil {
		t.Fatal("过期时间戳未被拒绝")
	}
}

func TestSignatureMatchesPHPHashHMAC(t *testing.T) {
	secret := []byte("0123456789abcdef0123456789abcdef")
	actual := signRequest(secret, "POST", "/v1/jobs", "1700000000", "nonce-1234567890", []byte(`{"a":1}`))
	want := "c8918b267e67e382c6f6539f1ce1b3e989ba1194c94136ff06c4c26e92564b5b"
	if actual != want {
		t.Fatalf("Go 与 PHP HMAC 结果不一致: got=%s want=%s", actual, want)
	}
}
