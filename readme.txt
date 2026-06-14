=== MaoMoMo TinyPNG Media ===
Contributors: maomomo
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0

在 WordPress 媒体库中使用多个 TinyPNG API Token 轮换压缩图片，并支持转换 WebP。

== 功能 ==

* 设置页支持配置任意数量 TinyPNG API Token。
* 支持一行一个 Token，或 `名称|TOKEN|月额度`。
* 媒体库列表支持单张图片：TinyPNG 压缩、转 WebP、压缩+WebP。
* 媒体库批量操作支持：压缩、转 WebP、压缩并转 WebP。
* 默认压缩原图和 WordPress 已生成的缩略图尺寸。
* 转 WebP 会创建新的 WebP 附件，并和原附件互相关联。

== 使用方式 ==

1. 在后台启用插件。
2. 进入「设置 → TinyPNG 媒体压缩」。
3. 填写一个或多个 TinyPNG API Token。
4. 到「媒体 → 媒体库」使用行操作或批量操作。

== Token 格式 ==

一行一个：

TOKEN_1
TOKEN_2

带名称和额度：

账号-1|TOKEN_1|500
账号-2|TOKEN_2|500

== 说明 ==

插件使用 TinyPNG HTTP API，不依赖 Composer。压缩会覆盖原图及缩略图文件；转换 WebP 会保留原图并新增 WebP 附件。
