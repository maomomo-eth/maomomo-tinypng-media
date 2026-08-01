package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestJobStorePersistsCompletesAndAcknowledges(t *testing.T) {
	store, err := newJobStore(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	value := job{ID: "job-12345678", SiteID: "site-12345678", AttachmentID: 42, Mode: "compress"}
	created, err := store.enqueue(value)
	if err != nil || !created {
		t.Fatalf("任务入队失败: created=%v err=%v", created, err)
	}
	created, err = store.enqueue(value)
	if err != nil || created {
		t.Fatalf("重复任务没有保持幂等: created=%v err=%v", created, err)
	}

	runningPath, ok, err := store.claimNext()
	if err != nil || !ok {
		t.Fatalf("领取任务失败: ok=%v err=%v", ok, err)
	}
	result := jobResult{JobID: value.ID, SiteID: value.SiteID, AttachmentID: value.AttachmentID, Mode: value.Mode, Summary: emptySummary()}
	if err := store.complete(runningPath, result); err != nil {
		t.Fatal(err)
	}
	results, err := store.results(value.SiteID, 10)
	if err != nil || len(results) != 1 {
		t.Fatalf("读取结果失败: len=%d err=%v", len(results), err)
	}
	if err := store.ack(value.SiteID, []string{value.ID}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(store.done, value.ID+".json")); !os.IsNotExist(err) {
		t.Fatal("确认后的结果文件仍然存在")
	}
}

func TestJobStoreRecoversRunningJobs(t *testing.T) {
	root := t.TempDir()
	store, err := newJobStore(root)
	if err != nil {
		t.Fatal(err)
	}
	value := job{ID: "job-abcdefgh", SiteID: "site-abcdefgh", AttachmentID: 7, Mode: "webp"}
	if _, err := store.enqueue(value); err != nil {
		t.Fatal(err)
	}
	if _, ok, err := store.claimNext(); err != nil || !ok {
		t.Fatalf("领取任务失败: ok=%v err=%v", ok, err)
	}
	store, err = newJobStore(root)
	if err != nil {
		t.Fatal(err)
	}
	if _, ok, err := store.claimNext(); err != nil || !ok {
		t.Fatalf("服务重启后没有恢复 running 任务: ok=%v err=%v", ok, err)
	}
}
