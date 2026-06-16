=== MaoMoMo TinyPNG Media ===
Contributors: maomomo
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.2.0

在 WordPress 媒体库中使用多个 TinyPNG API Token 轮换压缩图片，并支持转换 WebP。

== 功能 ==

* 设置页支持配置任意数量 TinyPNG API Token。
* 支持一行一个 Token，或 `名称|TOKEN|月额度`。
* 媒体库列表支持单张图片：TinyPNG 压缩、转 WebP、压缩+WebP。
* 媒体库批量操作支持：压缩、转 WebP、压缩并转 WebP。
* 支持 WP-CLI 批量执行压缩/转换，适合大批量后台处理。
* 支持 WP-CLI 将 `-scaled` 附件切回不带 `-scaled` 的原图，并删除 `-scaled` 文件。
* 默认压缩原图和 WordPress 已生成的缩略图尺寸。
* 可选上传后自动处理：不自动处理、自动压缩、自动转 WebP、自动压缩并转 WebP。
* 转 WebP 会创建新的 WebP 附件，并和原附件互相关联。
* 支持 TinyPNG API 专用代理设置。
* 压缩收益不足时自动保留原图：小于 1MB 的图片压缩后大于原图 80% 不覆盖；大于等于 1MB 的图片压缩后大于原图 90% 不覆盖。

== 使用方式 ==

1. 在后台启用插件。
2. 进入「设置 → TinyPNG 媒体压缩」。
3. 填写一个或多个 TinyPNG API Token。
4. 如需代理，在代理地址中填写 HTTP/HTTPS/SOCKS5 代理。
5. 如需新上传图片自动处理，在「上传后自动处理」中选择模式。
6. 到「媒体 → 媒体库」使用行操作或批量操作。

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

`php .\wp-cli.phar maomomo-tinypng-fix-scaled maomomo.com-2026-05-19_18-33-25_773340-scaled.webp maomomo.com-2025-06-01_22-19-03_166492-scaled.jpg maomomo.com-2025-05-21_18-50-34_124507-scaled.png --yes`

建议先预览：

`php .\wp-cli.phar maomomo-tinypng-fix-scaled maomomo.com-2026-05-19_18-33-25_773340-scaled.webp maomomo.com-2025-06-01_22-19-03_166492-scaled.jpg maomomo.com-2025-05-21_18-50-34_124507-scaled.png --dry-run`

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
