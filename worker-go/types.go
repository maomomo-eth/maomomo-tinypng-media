package main

import "time"

const workerVersion = "1.7.2"

type tokenConfig struct {
	ID           string `json:"id"`
	Name         string `json:"name"`
	Key          string `json:"key"`
	MonthlyLimit int    `json:"monthly_limit"`
	Count        int    `json:"count"`
}

type job struct {
	ID             string        `json:"job_id"`
	SiteID         string        `json:"site_id"`
	AttachmentID   int64         `json:"attachment_id"`
	Mode           string        `json:"mode"`
	UploadsRoot    string        `json:"uploads_root"`
	CompressPaths  []string      `json:"compress_paths"`
	SourcePath     string        `json:"source_path"`
	WebPPath       string        `json:"webp_path"`
	Tokens         []tokenConfig `json:"tokens"`
	TimeoutSeconds int           `json:"timeout_seconds"`
	Proxy          string        `json:"proxy"`
	CallbackURL    string        `json:"callback_url"`
	CreatedAt      int64         `json:"created_at"`
}

type summary struct {
	OK          int      `json:"ok"`
	Failed      int      `json:"failed"`
	Skipped     int      `json:"skipped"`
	WebP        int      `json:"webp"`
	BytesBefore int64    `json:"bytes_before"`
	BytesAfter  int64    `json:"bytes_after"`
	Messages    []string `json:"messages"`
}

type jobResult struct {
	JobID        string         `json:"job_id"`
	SiteID       string         `json:"site_id"`
	AttachmentID int64          `json:"attachment_id"`
	Mode         string         `json:"mode"`
	Summary      summary        `json:"summary"`
	WebPPath     string         `json:"webp_path,omitempty"`
	Usage        map[string]int `json:"usage"`
	CallbackURL  string         `json:"callback_url,omitempty"`
	CompletedAt  int64          `json:"completed_at"`
}

type resultsResponse struct {
	Active  []activeJob `json:"active"`
	Results []jobResult `json:"results"`
}

type activeJob struct {
	JobID        string `json:"job_id"`
	AttachmentID int64  `json:"attachment_id"`
}

type ackRequest struct {
	SiteID string   `json:"site_id"`
	JobIDs []string `json:"job_ids"`
}

type healthResponse struct {
	OK      bool   `json:"ok"`
	Version string `json:"version"`
	Workers int    `json:"workers"`
	Time    string `json:"time"`
}

func emptySummary() summary {
	return summary{Messages: make([]string, 0)}
}

func completedNow() int64 {
	return time.Now().Unix()
}
