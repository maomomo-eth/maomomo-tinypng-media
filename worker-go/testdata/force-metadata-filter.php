<?php
/**
 * 端到端测试夹具：模拟第三方插件拒绝 WebP 附件 metadata 更新。
 */

add_filter(
    'wp_update_attachment_metadata',
    static function ( $metadata, $attachment_id ) {
        if ( 'image/webp' === get_post_mime_type( $attachment_id ) ) {
            return false;
        }

        return $metadata;
    },
    PHP_INT_MAX,
    2
);
