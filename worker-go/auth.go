package main

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	authTimestampHeader = "X-Maomomo-Timestamp"
	authNonceHeader     = "X-Maomomo-Nonce"
	authSignatureHeader = "X-Maomomo-Signature"
)

type authenticator struct {
	secret []byte
	mu     sync.Mutex
	nonces map[string]time.Time
}

func newAuthenticator(secret string) (*authenticator, error) {
	secret = strings.TrimSpace(secret)
	if len(secret) < 32 {
		return nil, errors.New("MAOMOMO_WORKER_SECRET 至少需要 32 个字符")
	}

	return &authenticator{
		secret: []byte(secret),
		nonces: make(map[string]time.Time),
	}, nil
}

func (a *authenticator) verify(r *http.Request, body []byte) error {
	timestampText := r.Header.Get(authTimestampHeader)
	nonce := r.Header.Get(authNonceHeader)
	signature := strings.ToLower(r.Header.Get(authSignatureHeader))
	if timestampText == "" || nonce == "" || signature == "" {
		return errors.New("缺少签名请求头")
	}
	if len(nonce) < 16 || len(nonce) > 128 {
		return errors.New("nonce 长度无效")
	}

	timestamp, err := strconv.ParseInt(timestampText, 10, 64)
	if err != nil {
		return errors.New("时间戳无效")
	}
	now := time.Now()
	requestTime := time.Unix(timestamp, 0)
	if requestTime.Before(now.Add(-90*time.Second)) || requestTime.After(now.Add(90*time.Second)) {
		return errors.New("请求时间戳已过期")
	}

	expected := signRequest(a.secret, r.Method, r.URL.RequestURI(), timestampText, nonce, body)
	provided, err := hex.DecodeString(signature)
	if err != nil || !hmac.Equal([]byte(expected), []byte(signature)) || len(provided) != sha256.Size {
		return errors.New("请求签名无效")
	}

	a.mu.Lock()
	defer a.mu.Unlock()
	for key, expiresAt := range a.nonces {
		if expiresAt.Before(now) {
			delete(a.nonces, key)
		}
	}
	if _, exists := a.nonces[nonce]; exists {
		return errors.New("请求 nonce 已使用")
	}
	a.nonces[nonce] = now.Add(2 * time.Minute)
	return nil
}

func signRequest(secret []byte, method, requestURI, timestamp, nonce string, body []byte) string {
	message := strings.ToUpper(method) + "\n" + requestURI + "\n" + timestamp + "\n" + nonce + "\n" + string(body)
	mac := hmac.New(sha256.New, secret)
	_, _ = mac.Write([]byte(message))
	return hex.EncodeToString(mac.Sum(nil))
}

func randomNonce() (string, error) {
	buf := make([]byte, 18)
	if _, err := io.ReadFull(rand.Reader, buf); err != nil {
		return "", fmt.Errorf("生成 nonce 失败: %w", err)
	}
	return hex.EncodeToString(buf), nil
}
