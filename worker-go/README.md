# MaoMoMo TinyPNG Go Worker

Go 常驻服务负责附件级 3 Worker 并发、TinyPNG API 请求、流式文件下载和原子替换。WordPress 插件负责创建任务、Token 配置、附件 metadata 与 WebP 附件记录。

## 环境变量

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `MAOMOMO_WORKER_LISTEN` | `127.0.0.1:17863` | 只建议监听本机回环地址。 |
| `MAOMOMO_WORKER_WORKERS` | `3` | 附件级并发数，允许 1～16。 |
| `MAOMOMO_WORKER_SPOOL_DIR` | `/var/lib/maomomo-tinypng-worker` | 持久化任务目录，权限应为 `0700`。 |
| `MAOMOMO_WORKER_SECRET` | 无 | 与插件相同的 HMAC 共享密钥，至少 32 个字符。 |
| `MAOMOMO_WORKER_UPLOADS_ROOTS` | 无 | 允许读写的 WordPress uploads 绝对路径，多个路径用英文逗号分隔。 |

## 多个 WordPress 共用一个服务

同一服务器、同一 Linux 用户下的多个 WordPress 推荐共用一个 Go Worker，避免为每个站点分别常驻进程。服务只部署一次，`MAOMOMO_WORKER_WORKERS=3` 表示整台服务器共享 3 个附件级并发。

所有站点必须使用相同的 Worker 地址和 HMAC 共享密钥。可在各站插件设置页填写相同内容，也可在各站 `wp-config.php` 中定义：

```php
define( 'MAOMOMO_TINYPNG_GO_WORKER_URL', 'http://127.0.0.1:17863' );
define( 'MAOMOMO_TINYPNG_GO_WORKER_SECRET', '替换为与服务环境文件完全相同的共享密钥' );
```

在 `/etc/maomomo-tinypng-worker.env` 中用英文逗号列出全部 uploads 目录：

```ini
MAOMOMO_WORKER_UPLOADS_ROOTS=/www/wwwroot/site1.com/wp-content/uploads,/www/wwwroot/site2.com/wp-content/uploads,/www/wwwroot/site3.com/wp-content/uploads
```

修改后重启并检查服务：

```bash
systemctl restart maomomo-tinypng-worker
systemctl status maomomo-tinypng-worker --no-pager
```

每个 WordPress 使用自己的 TinyPNG API Token。任务中的站点 ID 和回调地址用于隔离结果；Go 的磁盘结果不会写回其他站点。如果站点属于不同 Linux 用户或需要严格安全隔离，请为每个站点创建独立 systemd 实例，并分别使用不同端口、共享密钥、spool 目录和 uploads 根目录。

## 源码验证

```bash
go test ./...
CGO_ENABLED=0 go build -buildvcs=false -trimpath -ldflags="-s -w" -o maomomo-tinypng-worker .
```

正式 Release 会提供 Linux AMD64 和 ARM64 静态二进制，并把对应二进制放入 WordPress 插件 ZIP 的 `bin/` 目录。

## 安全边界

- 任务提交、查询、确认和 WordPress 回调全部使用 HMAC-SHA256、时间戳和一次性 nonce。
- 服务只接受配置中明确允许的 uploads 根目录。
- 图片响应流式写入同目录临时文件，成功后再原子替换。
- API Token 只存在于权限为 `0600` 的待处理任务文件中，结果文件不保存 Token。
- Go 不直接修改 `wp_posts` 或 `wp_postmeta`。
