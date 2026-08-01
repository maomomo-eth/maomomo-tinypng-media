package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

var safeIDPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{8,128}$`)

type jobStore struct {
	root    string
	pending string
	running string
	done    string
}

func newJobStore(root string) (*jobStore, error) {
	root = filepath.Clean(strings.TrimSpace(root))
	if !filepath.IsAbs(root) {
		return nil, errors.New("spool 目录必须是绝对路径")
	}

	store := &jobStore{
		root:    root,
		pending: filepath.Join(root, "pending"),
		running: filepath.Join(root, "running"),
		done:    filepath.Join(root, "done"),
	}
	for _, dir := range []string{store.root, store.pending, store.running, store.done} {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return nil, fmt.Errorf("创建 spool 目录失败: %w", err)
		}
		if err := os.Chmod(dir, 0o700); err != nil {
			return nil, fmt.Errorf("设置 spool 目录权限失败: %w", err)
		}
	}
	if err := store.recoverRunning(); err != nil {
		return nil, err
	}
	return store, nil
}

func (s *jobStore) enqueue(value job) (bool, error) {
	if !safeIDPattern.MatchString(value.ID) || !safeIDPattern.MatchString(value.SiteID) {
		return false, errors.New("job_id 或 site_id 格式无效")
	}
	for _, dir := range []string{s.pending, s.running, s.done} {
		if _, err := os.Stat(filepath.Join(dir, value.ID+".json")); err == nil {
			return false, nil
		} else if !errors.Is(err, os.ErrNotExist) {
			return false, err
		}
	}
	if err := writeJSONAtomic(s.pending, value.ID+".json", value); err != nil {
		return false, err
	}
	return true, nil
}

func (s *jobStore) claimNext() (string, bool, error) {
	entries, err := os.ReadDir(s.pending)
	if err != nil {
		return "", false, err
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })
	for _, entry := range entries {
		name := entry.Name()
		if entry.IsDir() || filepath.Ext(name) != ".json" {
			continue
		}
		from := filepath.Join(s.pending, name)
		to := filepath.Join(s.running, name)
		if err := os.Rename(from, to); err != nil {
			if errors.Is(err, os.ErrNotExist) {
				continue
			}
			return "", false, err
		}
		return to, true, nil
	}
	return "", false, nil
}

func (s *jobStore) readJob(path string) (job, error) {
	var value job
	data, err := os.ReadFile(path)
	if err != nil {
		return value, err
	}
	if err := json.Unmarshal(data, &value); err != nil {
		return value, err
	}
	return value, nil
}

func (s *jobStore) complete(runningPath string, result jobResult) error {
	if err := writeJSONAtomic(s.done, result.JobID+".json", result); err != nil {
		return err
	}
	return os.Remove(runningPath)
}

func (s *jobStore) active(siteID string) ([]activeJob, error) {
	return s.listActiveJobs([]string{s.pending, s.running}, siteID)
}

func (s *jobStore) results(siteID string, limit int) ([]jobResult, error) {
	return s.readResults(siteID, limit)
}

func (s *jobStore) allResults(limit int) ([]jobResult, error) {
	return s.readResults("", limit)
}

func (s *jobStore) readResults(siteID string, limit int) ([]jobResult, error) {
	entries, err := os.ReadDir(s.done)
	if err != nil {
		return nil, err
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })
	results := make([]jobResult, 0)
	for _, entry := range entries {
		if entry.IsDir() || filepath.Ext(entry.Name()) != ".json" {
			continue
		}
		data, err := os.ReadFile(filepath.Join(s.done, entry.Name()))
		if err != nil {
			return nil, err
		}
		var result jobResult
		if err := json.Unmarshal(data, &result); err != nil {
			return nil, err
		}
		if siteID != "" && result.SiteID != siteID {
			continue
		}
		results = append(results, result)
		if len(results) >= limit {
			break
		}
	}
	return results, nil
}

func (s *jobStore) ack(siteID string, jobIDs []string) error {
	for _, jobID := range jobIDs {
		if !safeIDPattern.MatchString(jobID) {
			continue
		}
		path := filepath.Join(s.done, jobID+".json")
		data, err := os.ReadFile(path)
		if errors.Is(err, os.ErrNotExist) {
			continue
		}
		if err != nil {
			return err
		}
		var result jobResult
		if err := json.Unmarshal(data, &result); err != nil {
			return err
		}
		if result.SiteID != siteID {
			continue
		}
		if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	return nil
}

func (s *jobStore) listActiveJobs(dirs []string, siteID string) ([]activeJob, error) {
	jobs := make([]activeJob, 0)
	for _, dir := range dirs {
		entries, err := os.ReadDir(dir)
		if err != nil {
			return nil, err
		}
		for _, entry := range entries {
			if entry.IsDir() || filepath.Ext(entry.Name()) != ".json" {
				continue
			}
			value, err := s.readJob(filepath.Join(dir, entry.Name()))
			if err != nil {
				return nil, err
			}
			if value.SiteID == siteID {
				jobs = append(jobs, activeJob{JobID: value.ID, AttachmentID: value.AttachmentID})
			}
		}
	}
	sort.Slice(jobs, func(i, j int) bool { return jobs[i].JobID < jobs[j].JobID })
	return jobs, nil
}

func (s *jobStore) recoverRunning() error {
	entries, err := os.ReadDir(s.running)
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if entry.IsDir() || filepath.Ext(entry.Name()) != ".json" {
			continue
		}
		from := filepath.Join(s.running, entry.Name())
		if _, err := os.Stat(filepath.Join(s.done, entry.Name())); err == nil {
			if err := os.Remove(from); err != nil {
				return fmt.Errorf("清理已完成的 running 任务失败: %w", err)
			}
			continue
		} else if !errors.Is(err, os.ErrNotExist) {
			return err
		}
		to := filepath.Join(s.pending, entry.Name())
		if err := os.Rename(from, to); err != nil {
			return fmt.Errorf("恢复运行中任务失败: %w", err)
		}
	}
	return nil
}

func writeJSONAtomic(dir, name string, value any) error {
	data, err := json.Marshal(value)
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, ".maomomo-*.tmp")
	if err != nil {
		return err
	}
	tmpName := tmp.Name()
	defer os.Remove(tmpName)
	if err := tmp.Chmod(0o600); err != nil {
		_ = tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmpName, filepath.Join(dir, name))
}
