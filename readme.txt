=== MaoMoMo TinyPNG Media ===
Contributors: maomomo
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.7.3

在 WordPress 媒体库中使用多个 TinyPNG API Token 轮换压缩图片，并支持转换 WebP。

== 功能 ==

* 设置页支持配置任意数量 TinyPNG API Token。
* 支持一行一个 Token，或 `名称|TOKEN|月额度`。
* 媒体库列表支持单张图片：TinyPNG 压缩、转 WebP、压缩+WebP。
* 媒体库批量操作支持异步加入后台队列：压缩、转 WebP、压缩并转 WebP。
* 支持 WP-CLI 批量执行压缩/转换，适合大批量后台处理。
* 支持 WP-CLI 将 `-scaled` 附件切回不带 `-scaled` 的原图，并删除 `-scaled` 文件。
* 默认压缩原图和 WordPress 已生成的缩略图尺寸。
* 可选上传后自动处理：不自动处理、自动压缩、自动转 WebP、自动压缩并转 WebP。
* 上传后自动处理使用后台队列和系统 Cron 异步执行，上传请求不等待 TinyPNG API 返回。
* 后台队列由 3 个独立 WP-CLI Worker 并发处理；同一附件只会由一个 Worker 领取，附件内原图和缩略图保持串行。
* 支持从 GitHub Release 检查新版，并在 WordPress 插件页面一键升级。
* 转 WebP 会创建新的 WebP 附件，并和原附件互相关联。
* 支持 TinyPNG API 专用代理设置。
* 压缩收益不足时自动保留原图：小于 1MB 的图片压缩后大于原图 80% 不覆盖；大于等于 1MB 的图片压缩后大于原图 90% 不覆盖。

== 使用方式 ==

1. 在后台启用插件。
2. 进入「设置 → TinyPNG 媒体压缩」。
3. 填写一个或多个 TinyPNG API Token。
4. 如需代理，在代理地址中填写 HTTP/HTTPS/SOCKS5 代理。
5. 如需新上传图片自动处理，在「上传后自动处理」中选择模式；启用后图片会先加入后台队列。
6. 到「媒体 → 媒体库」使用行操作或批量操作；批量操作会先加入后台队列。

「自动转 WebP 并删除原文件」会在 WebP 成功生成并写回 WordPress 后，将原附件 ID 切换到 WebP，再删除旧 JPG/PNG、`-scaled` 原图和旧缩略图。转换或元数据写回失败时不会删除原文件。删除不可撤销，已有正文中硬编码的旧图片 URL 不会自动替换。

后台处理支持 Go 常驻服务和 WP-CLI 系统 Cron 两种引擎。推荐小内存服务器使用 Go 常驻服务；WP-CLI Cron 可作为无需额外服务的兼容方案。

== Go 常驻服务 3 Worker ==

Go 服务使用固定 3 个 goroutine 做附件级并发，空闲时不会启动 WordPress。图片通过本机文件路径处理，TinyPNG 响应流式写入临时文件，成功后再原子替换。

1. 在插件设置页选择「Go 常驻服务」。
2. 保存后复制设置页生成的 systemd 部署命令和环境变量。
3. 服务只监听 `127.0.0.1:17863`，以站点 PHP 用户运行。
4. 确认设置页显示 Go Worker 已连接后，停用旧的 3 条图片 Worker Cron。

任务先以权限 `0600` 持久化到 `/var/lib/maomomo-tinypng-worker`，服务重启会自动恢复。插件与服务之间使用 HMAC-SHA256、时间戳和一次性 nonce；Go 只允许读写配置指定的 WordPress uploads 目录，不直接修改 WordPress 数据库。

正式 Release 的插件 ZIP 内含：

* `bin/maomomo-tinypng-worker-linux-amd64`
* `bin/maomomo-tinypng-worker-linux-arm64`
* `worker/maomomo-tinypng-worker.service`

Go 完成任务后会批量回调 WordPress 写回附件 metadata；回调不可达时，WordPress 仍会通过队列轮询收取结果。

== 一台服务器运行多个 WordPress ==

同一 Linux 用户（例如宝塔默认的 `www`）运行的多个 WordPress，推荐共用一个 Go Worker。systemd 服务只部署一次，3 个 Worker 是整台服务器的全局并发数，不是每个站点各 3 个。

1. 每个站点都选择 Go 模式，并使用同一个 Worker 地址，例如 `http://127.0.0.1:17863`。
2. 每个站点的 Go Worker 共享密钥必须与 `/etc/maomomo-tinypng-worker.env` 中的 `MAOMOMO_WORKER_SECRET` 完全相同。可在各站设置页填写同一密钥，也可在各站 `wp-config.php` 中定义相同的 `MAOMOMO_TINYPNG_GO_WORKER_URL` 和 `MAOMOMO_TINYPNG_GO_WORKER_SECRET`。
3. 修改 `MAOMOMO_WORKER_UPLOADS_ROOTS`，用英文逗号列出全部站点的 uploads 绝对路径，例如：

`MAOMOMO_WORKER_UPLOADS_ROOTS=/www/wwwroot/site1.com/wp-content/uploads,/www/wwwroot/site2.com/wp-content/uploads,/www/wwwroot/site3.com/wp-content/uploads`

4. 修改环境文件后执行 `systemctl restart maomomo-tinypng-worker`，再到每个站点设置页确认 Worker 已连接。

每个站点仍使用自己配置的 TinyPNG API Token。插件提交任务时会携带各站 Token、独立的站点 ID 和回调地址，Go Worker 会按站点写回结果。如果各站由不同 Linux 用户运行，或需要严格安全隔离，应改为每站一个独立 Worker 实例，并分别配置端口、共享密钥、spool 目录和 uploads 根目录。

== 系统 Cron 3 Worker ==

插件设置页会根据 PHP 环境、常见系统路径和 WordPress 根目录生成可直接复制的 3 条 Cron。生成时会自动加入 `--skip-themes`，并通过 `--skip-plugins` 排除本插件之外的其他已启用插件，降低每个 Worker 加载 WordPress 的资源消耗。PHP-FPM 因 `open_basedir` 无法检查站点外路径时，也会生成标准路径；如服务器路径不同，可在 `wp-config.php` 中定义对应路径常量。以下为通用格式：

`* * * * * flock -n /tmp/maomomo-worker-1.lock php /网站目录/wp-cli.phar --path=/网站目录 --skip-plugins=其他插件slug --skip-themes maomomo-tinypng-worker --slot=1 --time-limit=50 --max-jobs=10 >/dev/null 2>&1`

`* * * * * flock -n /tmp/maomomo-worker-2.lock php /网站目录/wp-cli.phar --path=/网站目录 --skip-plugins=其他插件slug --skip-themes maomomo-tinypng-worker --slot=2 --time-limit=50 --max-jobs=10 >/dev/null 2>&1`

`* * * * * flock -n /tmp/maomomo-worker-3.lock php /网站目录/wp-cli.phar --path=/网站目录 --skip-plugins=其他插件slug --skip-themes maomomo-tinypng-worker --slot=3 --time-limit=50 --max-jobs=10 >/dev/null 2>&1`

每个槽位同时只允许运行一个进程；即使上一分钟的任务尚未结束，也不会重复启动同槽位 Worker。插件内部还有 MySQL 槽位锁和附件领取锁，防止同一附件被重复处理。

Go 模式正常运行时不要同时配置这 3 条 Cron；它们仅作为兼容或故障降级方案。

== WP-CLI 用法 ==

以下示例默认使用项目根目录中的 `wp-cli.phar`。全局 `wp` 命令需要额外安装和配置系统 PATH；如果没有配置，请使用 `php .\wp-cli.phar` 执行。

如果已经全局安装 WP-CLI，也可以把命令开头的 `php .\wp-cli.phar` 替换为 `wp`。

注意不要写成 `php .\wp-cli.phar wp maomomo-tinypng ...`，因为 `php .\wp-cli.phar` 已经等同于执行 WP-CLI。

处理全部支持的图片附件：

`php .\wp-cli.phar maomomo-tinypng --mode=compress`

压缩并转换最近上传的一批图片：

`php .\wp-cli.phar maomomo-tinypng --mode=both --after=2026-06-01 --limit=50`

只处理指定附件 ID：

`php .\wp-cli.phar maomomo-tinypng 123 456,789 --mode=webp`

先预览将处理哪些附件，不调用 TinyPNG、不写文件：

`php .\wp-cli.phar maomomo-tinypng --mode=both --dry-run`

修复已经指向 `-scaled` 文件的附件，改为使用不带 `-scaled` 的原图，并删除 `-scaled` 文件：

`php .\wp-cli.phar maomomo-tinypng-fix-scaled --yes`

建议先预览：

`php .\wp-cli.phar maomomo-tinypng-fix-scaled --dry-run`

只修复指定附件 ID 或指定文件名：

`php .\wp-cli.phar maomomo-tinypng-fix-scaled 1742 --dry-run`

`php .\wp-cli.phar maomomo-tinypng-fix-scaled maomomo.com-2026-05-19_18-33-25_773340-scaled.webp --dry-run`

参数说明：

* `--mode=compress|webp|both`：压缩、转 WebP、压缩并转 WebP。默认 `compress`。
* `--limit=50`：限制最多处理数量。
* `--after=YYYY-MM-DD` / `--before=YYYY-MM-DD`：按上传日期过滤。
* `--dry-run`：仅预览。
* `maomomo-tinypng-fix-scaled --yes`：确认执行 `-scaled` 修复；没有 `--yes` 时不会真实更新或删除。
* `maomomo-tinypng-fix-scaled --keep-scaled`：只切回原图，不删除 `-scaled` 文件。
* `maomomo-tinypng-fix-scaled --no-scan-content`：修复后不扫描文章和页面正文中的 `-scaled` 引用；默认会扫描并输出命中的 post/page。

== Token 格式 ==

一行一个：

TOKEN_1
TOKEN_2

带名称和额度：

账号-1|TOKEN_1|500
账号-2|TOKEN_2|500

== 说明 ==

插件使用 TinyPNG HTTP API，不依赖 Composer。压缩会覆盖原图及缩略图文件；转换 WebP 会保留原图并新增 WebP 附件。

从 1.5.0 开始，插件支持在 WordPress 后台检查并安装 GitHub 正式 Release。1.4.0 及更早版本尚未包含更新检查器，需要先手动安装一次 1.5.0 或更高版本。

== 更新日志 ==

= 1.7.3 =

* 新增「自动转 WebP 并删除原文件」上传处理模式。
* WebP 成功生成并写回后保留原附件 ID，将附件切换为 WebP，再安全删除旧原图和旧缩略图。
* 转换或附件元数据写回失败时保留原文件，Go 和 WP-CLI 两种处理引擎均支持。

= 1.7.2 =

* 补充一台服务器多个 WordPress 共用一个 Go Worker 的配置方法和安全隔离说明。

= 1.7.1 =

* 设置页根据当前处理引擎显示对应配置和部署命令，Go 模式隐藏 WP-CLI Cron 内容。
* Go systemd 部署命令和 WP-CLI Cron 均新增一键复制按钮。

= 1.7.0 =

* 新增 Go 常驻服务处理引擎，固定 3 个 goroutine 实现附件级并发，取消每分钟空启动多套 WordPress。
* Go Worker 使用磁盘持久化队列，进程重启自动恢复未完成任务。
* TinyPNG 上传和下载改为流式 I/O，临时文件成功落盘后再原子替换原文件。
* Go 与 WordPress 之间使用 HMAC-SHA256、时间戳和一次性 nonce，服务只允许访问配置的 uploads 根目录。
* Go 完成结果支持批量回调 WordPress；失败时保留结果并由 WordPress 后续轮询收取。
* 正式 Release 同时发布 Linux AMD64、ARM64 静态二进制，后台按当前站点生成 systemd 部署命令。
* 保留 WP-CLI Cron 引擎作为兼容和故障降级方案。

= 1.6.3 =

* 后台生成的 Cron 自动加入 `--skip-themes`。
* 自动读取当前站点及多站点网络启用插件，通过 `--skip-plugins` 排除本插件之外的其他插件。
* 设置页显示被排除的插件数量和 slug，便于直接核对 Cron。

= 1.6.2 =

* 修复 `open_basedir` 导致 PHP CLI、WP-CLI 和 `flock` 路径被误判为不存在的问题。
* WordPress 根目录优先使用 `ABSPATH`，并支持通过插件的 `__DIR__` 反推。
* PHP-FPM 无法检查系统路径时按标准位置生成 Cron，仍可通过 `wp-config.php` 路径常量覆盖。

= 1.6.1 =

* 设置页自动显示当前服务器的 PHP、WP-CLI、`flock` 和 WordPress 真实路径。
* 生成包含 `--max-jobs=10` 的 3 条可直接复制 Cron 命令。
* 路径无法识别时显示具体缺失项，并支持在 `wp-config.php` 中定义路径常量。

= 1.6.0 =

* 3 Worker 改为独立 WP-CLI 系统 Cron 进程，不再依赖站点回环访问 `admin-ajax.php`。
* 新增 `maomomo-tinypng-worker` 命令，支持 `--slot`、`--time-limit` 和 `--max-jobs`。
* 自动回收 1.5.0 中已经领取但未成功启动的 Worker 任务。

= 1.5.0 =

* 接入 GitHub Release 更新检查，支持 WordPress 后台一键升级。
* 发布包仅接受名为 `maomomo-tinypng-media.zip` 的正式 Release 附件。
