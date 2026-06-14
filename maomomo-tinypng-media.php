<?php
/**
 * Plugin Name: MaoMoMo TinyPNG Media
 * Plugin URI: https://www.maomomo.com
 * Description: 在媒体库中使用多个 TinyPNG API Token 轮换压缩图片，并支持转换 WebP。
 * Version: 1.0.0
 * Author: MAOMOMO
 * Author URI: https://www.maomomo.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: maomomo-tinypng-media
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MaoMoMo_TinyPNG_Media {
    const OPTION_SETTINGS = 'maomomo_tinypng_media_settings';
    const OPTION_USAGE    = 'maomomo_tinypng_media_usage';
    const NOTICE_PREFIX   = 'maomomo_tinypng_media_notice_';
    const API_ENDPOINT    = 'https://api.tinify.com/shrink';
    const DEFAULT_LIMIT   = 500;

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_post_maomomo_tinypng_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_maomomo_tinypng_reset_usage', array( $this, 'reset_usage' ) );
        add_action( 'admin_post_maomomo_tinypng_fix_webp_paths', array( $this, 'fix_webp_paths_action' ) );
        add_action( 'admin_post_maomomo_tinypng_media', array( $this, 'handle_single_action' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
        add_action( 'http_api_curl', array( $this, 'apply_tinypng_proxy' ), 10, 3 );

        add_filter( 'media_row_actions', array( $this, 'add_media_row_actions' ), 10, 3 );
        add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
        add_action( 'attachment_submitbox_misc_actions', array( $this, 'render_attachment_buttons' ) );
        add_filter( 'upload_mimes', array( $this, 'allow_webp_upload' ) );
    }

    public function register_settings_page() {
        add_options_page(
            'TinyPNG 媒体压缩',
            'TinyPNG 媒体压缩',
            'manage_options',
            'maomomo-tinypng-media',
            array( $this, 'render_settings_page' )
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $tokens   = $this->get_tokens();
        $usage    = $this->get_usage();
        ?>
        <div class="wrap">
            <h1>TinyPNG 媒体压缩</h1>
            <p>支持配置任意数量的 TinyPNG API Token。处理图片时会按配置顺序使用 Token，遇到本月额度用完或临时失败时自动切到下一个。</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'maomomo_tinypng_save_settings' ); ?>
                <input type="hidden" name="action" value="maomomo_tinypng_save_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="maomomo-tinypng-tokens">API Token</label></th>
                        <td>
                            <textarea
                                id="maomomo-tinypng-tokens"
                                name="tokens_text"
                                rows="12"
                                class="large-text code"
                                spellcheck="false"
                            ><?php echo esc_textarea( $settings['tokens_text'] ); ?></textarea>
                            <p class="description">
                                支持一行一个 Token，也支持 <code>名称|TOKEN|月额度</code>。月额度不填时使用下方默认值。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maomomo-tinypng-default-limit">默认月额度</label></th>
                        <td>
                            <input
                                id="maomomo-tinypng-default-limit"
                                type="number"
                                min="1"
                                name="default_limit"
                                value="<?php echo esc_attr( (string) $settings['default_limit'] ); ?>"
                            >
                            <p class="description">TinyPNG 免费账户通常是每个 Token 每月 500 张。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">压缩范围</th>
                        <td>
                            <label>
                                <input type="checkbox" name="include_sizes" value="1" <?php checked( ! empty( $settings['include_sizes'] ) ); ?>>
                                压缩原图和 WordPress 已生成的缩略图尺寸
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maomomo-tinypng-timeout">请求超时</label></th>
                        <td>
                            <input
                                id="maomomo-tinypng-timeout"
                                type="number"
                                min="10"
                                max="300"
                                name="timeout"
                                value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>"
                            > 秒
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="maomomo-tinypng-proxy">代理地址</label></th>
                        <td>
                            <input
                                id="maomomo-tinypng-proxy"
                                type="text"
                                class="regular-text"
                                name="proxy"
                                value="<?php echo esc_attr( (string) $settings['proxy'] ); ?>"
                                placeholder="http://127.0.0.1:7890"
                            >
                            <p class="description">
                                仅 TinyPNG API 请求使用此代理。支持 <code>http://</code>、<code>https://</code>、<code>socks5://</code>、<code>socks5h://</code>，也支持在 URL 中带账号密码。
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( '保存设置' ); ?>
            </form>

            <hr>

            <h2>Token 用量</h2>
            <?php if ( empty( $tokens ) ) : ?>
                <p>还没有可用 Token。</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>Token ID</th>
                            <th>本月用量</th>
                            <th>月额度</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $tokens as $token ) : ?>
                            <tr>
                                <td><?php echo esc_html( $token['name'] ); ?></td>
                                <td><code><?php echo esc_html( $token['id'] ); ?></code></td>
                                <td><?php echo esc_html( (string) $this->get_count( $usage, $token ) ); ?></td>
                                <td><?php echo esc_html( (string) $token['monthly_limit'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px;">
                <?php wp_nonce_field( 'maomomo_tinypng_reset_usage' ); ?>
                <input type="hidden" name="action" value="maomomo_tinypng_reset_usage">
                <?php submit_button( '重置本地用量记录', 'secondary', 'submit', false ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px;">
                <?php wp_nonce_field( 'maomomo_tinypng_fix_webp_paths' ); ?>
                <input type="hidden" name="action" value="maomomo_tinypng_fix_webp_paths">
                <?php submit_button( '修复已生成 WebP 附件路径', 'secondary', 'submit', false ); ?>
                <p class="description">如果在 Windows 环境中出现 <code>2026/05filename.webp</code> 这类少一个斜杠的 URL，可点击此按钮修复。</p>
            </form>
        </div>
        <?php
    }

    public function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        check_admin_referer( 'maomomo_tinypng_save_settings' );

        $tokens_text   = isset( $_POST['tokens_text'] ) ? wp_unslash( $_POST['tokens_text'] ) : '';
        $default_limit = isset( $_POST['default_limit'] ) ? absint( $_POST['default_limit'] ) : self::DEFAULT_LIMIT;
        $timeout       = isset( $_POST['timeout'] ) ? absint( $_POST['timeout'] ) : 90;
        $proxy         = isset( $_POST['proxy'] ) ? sanitize_text_field( wp_unslash( $_POST['proxy'] ) ) : '';

        $settings = array(
            'tokens_text'   => $this->normalize_tokens_text( $tokens_text ),
            'default_limit' => max( 1, $default_limit ),
            'include_sizes' => ! empty( $_POST['include_sizes'] ),
            'timeout'       => min( 300, max( 10, $timeout ) ),
            'proxy'         => trim( $proxy ),
        );

        update_option( self::OPTION_SETTINGS, $settings, false );
        $this->store_notice( 'success', 'TinyPNG 设置已保存。' );

        wp_safe_redirect( admin_url( 'options-general.php?page=maomomo-tinypng-media' ) );
        exit;
    }

    public function reset_usage() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        check_admin_referer( 'maomomo_tinypng_reset_usage' );

        update_option(
            self::OPTION_USAGE,
            array(
                'month' => $this->current_month(),
                'usage' => array(),
            ),
            false
        );

        $this->store_notice( 'success', '本地用量记录已重置。' );

        wp_safe_redirect( admin_url( 'options-general.php?page=maomomo-tinypng-media' ) );
        exit;
    }

    public function fix_webp_paths_action() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '权限不足。' );
        }

        check_admin_referer( 'maomomo_tinypng_fix_webp_paths' );

        $fixed = $this->fix_generated_webp_paths();
        $this->store_notice( 'success', '已修复 WebP 附件路径：' . $fixed . ' 个。' );

        wp_safe_redirect( admin_url( 'options-general.php?page=maomomo-tinypng-media' ) );
        exit;
    }

    public function add_media_row_actions( $actions, $post, $detached ) {
        if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
            return $actions;
        }

        if ( ! $this->is_supported_attachment( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }

        $actions['maomomo_tinypng_compress'] = $this->media_action_link( $post->ID, 'compress', 'TinyPNG 压缩' );
        $actions['maomomo_tinypng_webp']     = $this->media_action_link( $post->ID, 'webp', '转 WebP' );
        $actions['maomomo_tinypng_both']     = $this->media_action_link( $post->ID, 'both', '压缩+WebP' );

        return $actions;
    }

    public function register_bulk_actions( $actions ) {
        $actions['maomomo_tinypng_compress'] = 'TinyPNG：压缩';
        $actions['maomomo_tinypng_webp']     = 'TinyPNG：转 WebP';
        $actions['maomomo_tinypng_both']     = 'TinyPNG：压缩并转 WebP';

        return $actions;
    }

    public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
        $map = array(
            'maomomo_tinypng_compress' => 'compress',
            'maomomo_tinypng_webp'     => 'webp',
            'maomomo_tinypng_both'     => 'both',
        );

        if ( ! isset( $map[ $action ] ) ) {
            return $redirect_to;
        }

        if ( ! current_user_can( 'upload_files' ) ) {
            $this->store_notice( 'error', '权限不足，无法处理媒体文件。' );
            return $redirect_to;
        }

        @set_time_limit( 0 );

        $summary = $this->empty_summary();
        $mode    = $map[ $action ];

        foreach ( (array) $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
                $summary['failed']++;
                $summary['messages'][] = '跳过无权限附件：' . $post_id;
                continue;
            }

            $result = $this->process_attachment( $post_id, $mode );
            $this->merge_summary( $summary, $result );
        }

        $type = $summary['failed'] ? 'warning' : 'success';
        $this->store_notice( $type, $this->format_summary_message( $summary ) );

        return $redirect_to;
    }

    public function add_media_column( $columns ) {
        $columns['maomomo_tinypng'] = 'TinyPNG';
        return $columns;
    }

    public function render_media_column( $column_name, $post_id ) {
        if ( 'maomomo_tinypng' !== $column_name ) {
            return;
        }

        $last    = get_post_meta( $post_id, '_maomomo_tinypng_last_result', true );
        $webp_id = (int) get_post_meta( $post_id, '_maomomo_tinypng_webp_id', true );

        if ( is_array( $last ) && ! empty( $last['time'] ) ) {
            echo '<div>最近：' . esc_html( $last['label'] ) . '</div>';
            if ( isset( $last['saved'] ) && $last['saved'] > 0 ) {
                echo '<div>节省：' . esc_html( size_format( (int) $last['saved'], 1 ) ) . '</div>';
            }
        } else {
            echo '<span style="color:#777;">未处理</span>';
        }

        if ( $webp_id && 'attachment' === get_post_type( $webp_id ) ) {
            $edit_link = get_edit_post_link( $webp_id );
            if ( $edit_link ) {
                echo '<div><a href="' . esc_url( $edit_link ) . '">WebP 附件 #' . esc_html( (string) $webp_id ) . '</a></div>';
            }
        }
    }

    public function render_attachment_buttons() {
        global $post;

        if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
            return;
        }

        if ( ! $this->is_supported_attachment( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }
        ?>
        <div class="misc-pub-section">
            <strong>TinyPNG</strong><br>
            <?php echo $this->media_action_link( $post->ID, 'compress', '压缩' ); ?>
            &nbsp;|&nbsp;
            <?php echo $this->media_action_link( $post->ID, 'webp', '转 WebP' ); ?>
            &nbsp;|&nbsp;
            <?php echo $this->media_action_link( $post->ID, 'both', '压缩+WebP' ); ?>
        </div>
        <?php
    }

    public function allow_webp_upload( $mimes ) {
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }

    public function apply_tinypng_proxy( $handle, $parsed_args, $url ) {
        if ( empty( $parsed_args['maomomo_tinypng_proxy'] ) ) {
            return;
        }

        $proxy = trim( (string) $parsed_args['maomomo_tinypng_proxy'] );
        if ( '' === $proxy ) {
            return;
        }

        $parts = wp_parse_url( $proxy );
        if ( empty( $parts['host'] ) ) {
            return;
        }

        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
        $port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
        $host   = $parts['host'] . ( $port ? ':' . $port : '' );

        curl_setopt( $handle, CURLOPT_PROXY, $host );

        if ( ! empty( $parts['user'] ) ) {
            $password = isset( $parts['pass'] ) ? rawurldecode( $parts['pass'] ) : '';
            curl_setopt( $handle, CURLOPT_PROXYUSERPWD, rawurldecode( $parts['user'] ) . ':' . $password );
        }

        if ( 'socks5h' === $scheme && defined( 'CURLPROXY_SOCKS5_HOSTNAME' ) ) {
            curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME );
        } elseif ( 'socks5' === $scheme && defined( 'CURLPROXY_SOCKS5' ) ) {
            curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5 );
        } elseif ( 'https' === $scheme && defined( 'CURLPROXY_HTTPS' ) ) {
            curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS );
        } elseif ( defined( 'CURLPROXY_HTTP' ) ) {
            curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
        }
    }

    public function handle_single_action() {
        $attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
        $mode          = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'compress';

        if ( ! $attachment_id || ! in_array( $mode, array( 'compress', 'webp', 'both' ), true ) ) {
            wp_die( '请求参数不完整。' );
        }

        if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
            wp_die( '权限不足。' );
        }

        check_admin_referer( 'maomomo_tinypng_media_' . $attachment_id );

        @set_time_limit( 0 );

        $result = $this->process_attachment( $attachment_id, $mode );
        $type   = $result['failed'] ? 'error' : 'success';
        $this->store_notice( $type, $this->format_summary_message( $result ) );

        $fallback = admin_url( 'upload.php' );
        $referer  = wp_get_referer();

        wp_safe_redirect( $referer ? $referer : $fallback );
        exit;
    }

    public function render_admin_notice() {
        $notice = get_transient( self::NOTICE_PREFIX . get_current_user_id() );
        if ( ! is_array( $notice ) ) {
            return;
        }

        delete_transient( self::NOTICE_PREFIX . get_current_user_id() );

        $type    = isset( $notice['type'] ) ? sanitize_html_class( $notice['type'] ) : 'info';
        $message = isset( $notice['message'] ) ? $notice['message'] : '';

        if ( '' === $message ) {
            return;
        }

        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
    }

    private function media_action_link( $attachment_id, $mode, $label ) {
        $url = add_query_arg(
            array(
                'action'        => 'maomomo_tinypng_media',
                'attachment_id' => absint( $attachment_id ),
                'mode'          => $mode,
            ),
            admin_url( 'admin-post.php' )
        );

        $url = wp_nonce_url( $url, 'maomomo_tinypng_media_' . absint( $attachment_id ) );

        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    private function process_attachment( $attachment_id, $mode ) {
        $summary = $this->empty_summary();

        if ( ! $this->is_supported_attachment( $attachment_id ) ) {
            $summary['failed']++;
            $summary['messages'][] = '附件 #' . $attachment_id . ' 不是支持的图片格式。';
            return $summary;
        }

        $label = $this->mode_label( $mode );

        if ( in_array( $mode, array( 'compress', 'both' ), true ) ) {
            $result = $this->compress_attachment_files( $attachment_id );
            $this->merge_summary( $summary, $result );
        }

        if ( in_array( $mode, array( 'webp', 'both' ), true ) ) {
            $result = $this->convert_attachment_to_webp( $attachment_id );
            $this->merge_summary( $summary, $result );
        }

        if ( 0 === $summary['failed'] ) {
            update_post_meta(
                $attachment_id,
                '_maomomo_tinypng_last_result',
                array(
                    'label' => $label,
                    'time'  => current_time( 'mysql' ),
                    'saved' => $summary['bytes_before'] > $summary['bytes_after']
                        ? $summary['bytes_before'] - $summary['bytes_after']
                        : 0,
                )
            );
        }

        return $summary;
    }

    private function compress_attachment_files( $attachment_id ) {
        $summary = $this->empty_summary();
        $paths   = $this->get_attachment_image_paths( $attachment_id );

        if ( empty( $paths ) ) {
            $summary['failed']++;
            $summary['messages'][] = '附件 #' . $attachment_id . ' 没有可压缩的本地文件。';
            return $summary;
        }

        foreach ( $paths as $path ) {
            if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
                $summary['failed']++;
                $summary['messages'][] = '文件不可读写：' . esc_html( wp_basename( $path ) );
                continue;
            }

            $before = filesize( $path );
            $result = $this->tinypng_process_file( $path, null );

            if ( is_wp_error( $result ) ) {
                $summary['failed']++;
                $summary['messages'][] = wp_basename( $path ) . '：' . $result->get_error_message();
                continue;
            }

            $body = $result['body'];
            if ( '' === $body ) {
                $summary['failed']++;
                $summary['messages'][] = 'TinyPNG 返回空文件：' . wp_basename( $path );
                continue;
            }

            if ( ! $this->should_replace_compressed_file( (int) $before, strlen( $body ) ) ) {
                $summary['skipped']++;
                $summary['bytes_before'] += (int) $before;
                $summary['bytes_after']  += (int) $before;
                $summary['messages'][] = wp_basename( $path ) . ' 压缩收益不足，保留原图。';
                continue;
            }

            $write = $this->write_file_atomic( $path, $body );
            if ( is_wp_error( $write ) ) {
                $summary['failed']++;
                $summary['messages'][] = wp_basename( $path ) . '：' . $write->get_error_message();
                continue;
            }

            clearstatcache( true, $path );
            $after = filesize( $path );

            $summary['ok']++;
            $summary['bytes_before'] += (int) $before;
            $summary['bytes_after']  += (int) $after;
        }

        $this->refresh_attachment_filesizes( $attachment_id );

        return $summary;
    }

    private function convert_attachment_to_webp( $attachment_id ) {
        $summary = $this->empty_summary();
        $source  = get_attached_file( $attachment_id );

        if ( ! $source || ! file_exists( $source ) ) {
            $summary['failed']++;
            $summary['messages'][] = '附件 #' . $attachment_id . ' 找不到原图文件。';
            return $summary;
        }

        if ( 'webp' === strtolower( pathinfo( $source, PATHINFO_EXTENSION ) ) ) {
            $summary['skipped']++;
            $summary['messages'][] = '附件 #' . $attachment_id . ' 已经是 WebP。';
            return $summary;
        }

        $before = filesize( $source );
        $result = $this->tinypng_process_file( $source, 'image/webp' );

        if ( is_wp_error( $result ) ) {
            $summary['failed']++;
            $summary['messages'][] = '附件 #' . $attachment_id . ' 转 WebP 失败：' . $result->get_error_message();
            return $summary;
        }

        $webp_path = $this->get_or_create_webp_path( $attachment_id, $source );
        if ( is_wp_error( $webp_path ) ) {
            $summary['failed']++;
            $summary['messages'][] = $webp_path->get_error_message();
            return $summary;
        }

        $write = $this->write_file_atomic( $webp_path, $result['body'] );
        if ( is_wp_error( $write ) ) {
            $summary['failed']++;
            $summary['messages'][] = $write->get_error_message();
            return $summary;
        }

        clearstatcache( true, $webp_path );
        $after   = filesize( $webp_path );
        $webp_id = $this->upsert_webp_attachment( $attachment_id, $webp_path );

        if ( is_wp_error( $webp_id ) ) {
            $summary['failed']++;
            $summary['messages'][] = $webp_id->get_error_message();
            return $summary;
        }

        $summary['ok']++;
        $summary['webp']++;
        $summary['bytes_before'] += (int) $before;
        $summary['bytes_after']  += (int) $after;

        return $summary;
    }

    private function tinypng_process_file( $path, $target_type ) {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return new WP_Error( 'maomomo_tinypng_missing_file', '文件不存在或不可读取。' );
        }

        $content = file_get_contents( $path );
        if ( false === $content ) {
            return new WP_Error( 'maomomo_tinypng_read_failed', '读取文件失败。' );
        }

        return $this->run_with_token(
            function ( $candidate, &$usage ) use ( $content, $target_type ) {
                $shrink = $this->tinypng_request(
                    $candidate,
                    'POST',
                    self::API_ENDPOINT,
                    array(
                        'headers' => array(
                            'Content-Type' => 'application/octet-stream',
                        ),
                        'body'    => $content,
                    ),
                    $usage
                );

                if ( is_wp_error( $shrink ) ) {
                    return $shrink;
                }

                $location = isset( $shrink['headers']['location'] ) ? $shrink['headers']['location'] : '';
                if ( '' === $location ) {
                    return new WP_Error( 'maomomo_tinypng_no_location', 'TinyPNG 没有返回输出地址。' );
                }

                if ( $target_type ) {
                    return $this->tinypng_request(
                        $candidate,
                        'POST',
                        $location,
                        array(
                            'headers' => array(
                                'Content-Type' => 'application/json',
                            ),
                            'body'    => wp_json_encode(
                                array(
                                    'convert' => array(
                                        'type' => array( $target_type ),
                                    ),
                                )
                            ),
                        ),
                        $usage
                    );
                }

                return $this->tinypng_request(
                    $candidate,
                    'GET',
                    $location,
                    array(),
                    $usage
                );
            }
        );
    }

    private function tinypng_request( $candidate, $method, $url, $args, &$usage ) {
        $settings = $this->get_settings();
        $headers  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

        $headers['Authorization'] = 'Basic ' . base64_encode( 'api:' . $candidate['key'] );

        $request_args = array(
            'method'      => $method,
            'timeout'     => (int) $settings['timeout'],
            'redirection' => 3,
            'headers'     => $headers,
        );

        if ( ! empty( $settings['proxy'] ) ) {
            $request_args['maomomo_tinypng_proxy'] = $settings['proxy'];
        }

        if ( array_key_exists( 'body', $args ) ) {
            $request_args['body'] = $args['body'];
        }

        $response = wp_remote_request( $url, $request_args );
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'maomomo_tinypng_connection',
                $response->get_error_message(),
                array( 'retryable' => true )
            );
        }

        $count = wp_remote_retrieve_header( $response, 'compression-count' );
        if ( '' !== $count && null !== $count ) {
            $this->set_count( $usage, $candidate, (int) $count );
            $this->save_usage( $usage );
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $body    = wp_remote_retrieve_body( $response );
        $headers = wp_remote_retrieve_headers( $response );

        if ( $code < 200 || $code >= 300 ) {
            $message = $this->parse_tinypng_error_message( $body );
            return new WP_Error(
                'maomomo_tinypng_http_' . $code,
                $message ? $message : 'TinyPNG 请求失败，状态码：' . $code,
                array(
                    'status'    => $code,
                    'retryable' => in_array( $code, array( 429, 500, 502, 503, 504 ), true ),
                    'count'     => $count,
                )
            );
        }

        return array(
            'body'    => $body,
            'headers' => $this->normalize_headers( $headers ),
            'code'    => $code,
            'key'     => $candidate,
        );
    }

    private function run_with_token( $callback ) {
        $tokens = $this->get_tokens();
        if ( empty( $tokens ) ) {
            return new WP_Error( 'maomomo_tinypng_no_tokens', '请先在设置页配置 TinyPNG API Token。' );
        }

        $usage      = $this->get_usage();
        $blocked    = array();
        $last_error = null;

        while ( true ) {
            $candidate = $this->choose_key( $tokens, $usage, $blocked );
            if ( ! $candidate ) {
                $message = '所有 API Token 都已达到本月上限或暂不可用。';
                if ( is_wp_error( $last_error ) ) {
                    $message .= ' 最后错误：' . $last_error->get_error_message();
                }

                return new WP_Error( 'maomomo_tinypng_all_tokens_blocked', $message );
            }

            $result = call_user_func_array( $callback, array( $candidate, &$usage ) );

            if ( ! is_wp_error( $result ) ) {
                $this->save_usage( $usage );
                return $result;
            }

            $last_error = $result;
            $data       = $result->get_error_data();
            $status     = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
            $retryable  = is_array( $data ) && ! empty( $data['retryable'] );

            if ( 429 === $status ) {
                $this->set_count( $usage, $candidate, $candidate['monthly_limit'] );
                $this->save_usage( $usage );
                $blocked[ $candidate['id'] ] = true;
                continue;
            }

            if ( in_array( $status, array( 401, 403 ), true ) || $retryable ) {
                $blocked[ $candidate['id'] ] = true;
                continue;
            }

            return $result;
        }
    }

    private function get_attachment_image_paths( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) || ! $this->is_supported_path( $file ) ) {
            return array();
        }

        $settings = $this->get_settings();
        $paths    = array( wp_normalize_path( $file ) => $file );

        if ( ! empty( $settings['include_sizes'] ) ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
            $base_dir = dirname( $file );

            if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size ) {
                    if ( empty( $size['file'] ) ) {
                        continue;
                    }

                    $path = $base_dir . DIRECTORY_SEPARATOR . $size['file'];
                    if ( file_exists( $path ) && $this->is_supported_path( $path ) ) {
                        $paths[ wp_normalize_path( $path ) ] = $path;
                    }
                }
            }
        }

        return array_values( $paths );
    }

    private function refresh_attachment_filesizes( $attachment_id ) {
        $file     = get_attached_file( $attachment_id );
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! $file || ! is_array( $metadata ) ) {
            return;
        }

        if ( file_exists( $file ) ) {
            $metadata['filesize'] = filesize( $file );
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $base_dir = dirname( $file );
            foreach ( $metadata['sizes'] as $name => $size ) {
                if ( empty( $size['file'] ) ) {
                    continue;
                }

                $path = $base_dir . DIRECTORY_SEPARATOR . $size['file'];
                if ( file_exists( $path ) ) {
                    $metadata['sizes'][ $name ]['filesize'] = filesize( $path );
                }
            }
        }

        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    private function get_or_create_webp_path( $attachment_id, $source ) {
        $existing_id = (int) get_post_meta( $attachment_id, '_maomomo_tinypng_webp_id', true );
        if ( $existing_id && 'attachment' === get_post_type( $existing_id ) ) {
            $existing_file = get_attached_file( $existing_id );
            if ( $existing_file && file_exists( $existing_file ) ) {
                return $existing_file;
            }

            $repaired_file = $this->guess_existing_webp_file( $existing_id, $source );
            if ( $repaired_file ) {
                update_post_meta( $existing_id, '_wp_attached_file', $this->attachment_relative_path( $repaired_file ) );
                return $repaired_file;
            }
        }

        $dir       = dirname( $source );
        $base_name = pathinfo( $source, PATHINFO_FILENAME ) . '.webp';
        $filename  = wp_unique_filename( $dir, $base_name );

        return $dir . DIRECTORY_SEPARATOR . $filename;
    }

    private function upsert_webp_attachment( $source_id, $webp_path ) {
        if ( ! file_exists( $webp_path ) ) {
            return new WP_Error( 'maomomo_tinypng_webp_missing', 'WebP 文件没有成功写入。' );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing_id = (int) get_post_meta( $source_id, '_maomomo_tinypng_webp_id', true );
        $source_post = get_post( $source_id );
        $title       = $source_post ? get_the_title( $source_post ) . ' WebP' : wp_basename( $webp_path );

        if ( $existing_id && 'attachment' === get_post_type( $existing_id ) ) {
            $webp_id = $existing_id;
            wp_update_post(
                array(
                    'ID'             => $webp_id,
                    'post_mime_type' => 'image/webp',
                    'post_title'     => $title,
                )
            );
            update_post_meta( $webp_id, '_wp_attached_file', $this->attachment_relative_path( $webp_path ) );
        } else {
            $webp_id = wp_insert_attachment(
                array(
                    'post_mime_type' => 'image/webp',
                    'post_title'     => $title,
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                    'post_parent'    => $source_post ? (int) $source_post->post_parent : 0,
                ),
                $webp_path,
                $source_post ? (int) $source_post->post_parent : 0
            );

            if ( is_wp_error( $webp_id ) ) {
                return $webp_id;
            }

            update_post_meta( $webp_id, '_wp_attached_file', $this->attachment_relative_path( $webp_path ) );
        }

        $metadata = wp_generate_attachment_metadata( $webp_id, $webp_path );
        if ( ! is_array( $metadata ) || empty( $metadata ) ) {
            $metadata = $this->build_basic_image_metadata( $webp_path );
        }

        wp_update_attachment_metadata( $webp_id, $metadata );
        update_post_meta( $source_id, '_maomomo_tinypng_webp_id', $webp_id );
        update_post_meta( $webp_id, '_maomomo_tinypng_source_id', $source_id );

        $alt = get_post_meta( $source_id, '_wp_attachment_image_alt', true );
        if ( '' !== $alt ) {
            update_post_meta( $webp_id, '_wp_attachment_image_alt', $alt );
        }

        return $webp_id;
    }

    private function build_basic_image_metadata( $path ) {
        $size = wp_getimagesize( $path );

        return array(
            'width'    => is_array( $size ) ? (int) $size[0] : 0,
            'height'   => is_array( $size ) ? (int) $size[1] : 0,
            'file'     => $this->attachment_relative_path( $path ),
            'filesize' => file_exists( $path ) ? filesize( $path ) : 0,
            'sizes'    => array(),
        );
    }

    private function attachment_relative_path( $path ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
        $path       = wp_normalize_path( $path );

        if ( 0 === strpos( $path, $base_dir ) ) {
            return ltrim( substr( $path, strlen( $base_dir ) ), '/' );
        }

        return wp_basename( $path );
    }

    private function fix_generated_webp_paths() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image/webp',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_maomomo_tinypng_source_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        $fixed = 0;

        foreach ( $query->posts as $webp_id ) {
            $file = get_attached_file( $webp_id, true );
            if ( ! $file || ! file_exists( $file ) ) {
                $source_id = (int) get_post_meta( $webp_id, '_maomomo_tinypng_source_id', true );
                $source    = $source_id ? get_attached_file( $source_id, true ) : '';

                if ( $source ) {
                    $file = $this->guess_existing_webp_file( $webp_id, $source );
                }
            }

            if ( ! $file || ! file_exists( $file ) ) {
                continue;
            }

            $relative = $this->attachment_relative_path( $file );
            if ( $relative && $relative !== get_post_meta( $webp_id, '_wp_attached_file', true ) ) {
                update_post_meta( $webp_id, '_wp_attached_file', $relative );
                $fixed++;
            }
        }

        return $fixed;
    }

    private function guess_existing_webp_file( $webp_id, $source ) {
        if ( ! $source ) {
            return '';
        }

        $dir        = dirname( $source );
        $sourcebase = pathinfo( $source, PATHINFO_FILENAME );
        $candidates = array(
            $dir . DIRECTORY_SEPARATOR . $sourcebase . '.webp',
        );

        $current = get_post_meta( $webp_id, '_wp_attached_file', true );
        if ( $current ) {
            $basename = wp_basename( $current );
            $month    = wp_basename( dirname( wp_normalize_path( $source ) ) );

            $candidates[] = $dir . DIRECTORY_SEPARATOR . $basename;
            if ( $month && 0 === strpos( $basename, $month ) ) {
                $candidates[] = $dir . DIRECTORY_SEPARATOR . substr( $basename, strlen( $month ) );
            }
        }

        foreach ( array_unique( $candidates ) as $candidate ) {
            if ( $candidate && file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        return '';
    }

    private function write_file_atomic( $path, $body ) {
        $tmp = $path . '.maomomo-tinypng-tmp';

        if ( false === file_put_contents( $tmp, $body, LOCK_EX ) ) {
            return new WP_Error( 'maomomo_tinypng_write_failed', '写入临时文件失败。' );
        }

        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return new WP_Error( 'maomomo_tinypng_replace_failed', '替换目标文件失败。' );
        }

        return true;
    }

    private function is_supported_attachment( $attachment_id ) {
        $mime = get_post_mime_type( $attachment_id );
        $file = get_attached_file( $attachment_id );

        if ( ! $mime || 0 !== strpos( $mime, 'image/' ) || ! $file ) {
            return false;
        }

        return $this->is_supported_path( $file );
    }

    private function is_supported_path( $path ) {
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $ext, array( 'png', 'jpg', 'jpeg', 'webp', 'avif' ), true );
    }

    private function get_settings() {
        $defaults = array(
            'tokens_text'   => '',
            'default_limit' => self::DEFAULT_LIMIT,
            'include_sizes' => true,
            'timeout'       => 90,
            'proxy'         => '',
        );

        $settings = get_option( self::OPTION_SETTINGS, array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return array_merge( $defaults, $settings );
    }

    private function should_replace_compressed_file( $before, $after ) {
        if ( $before <= 0 || $after <= 0 ) {
            return false;
        }

        $threshold = $before < MB_IN_BYTES ? 0.8 : 0.9;

        return ( $after / $before ) <= $threshold;
    }

    private function normalize_tokens_text( $text ) {
        $text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
        $lines = array();

        foreach ( explode( "\n", $text ) as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            if ( false !== strpos( $line, '|' ) ) {
                $lines[] = $line;
                continue;
            }

            $parts = preg_split( '/[\s,;]+/u', $line, -1, PREG_SPLIT_NO_EMPTY );
            foreach ( $parts as $part ) {
                $lines[] = trim( $part );
            }
        }

        return implode( "\n", array_unique( $lines ) );
    }

    private function get_tokens() {
        $settings      = $this->get_settings();
        $default_limit = max( 1, (int) $settings['default_limit'] );
        $tokens        = array();
        $index         = 1;

        foreach ( explode( "\n", (string) $settings['tokens_text'] ) as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $name  = 'Token-' . $index;
            $key   = $line;
            $limit = $default_limit;

            if ( false !== strpos( $line, '|' ) ) {
                $parts = array_map( 'trim', explode( '|', $line ) );
                if ( isset( $parts[0] ) && '' !== $parts[0] ) {
                    $name = $parts[0];
                }
                if ( isset( $parts[1] ) && '' !== $parts[1] ) {
                    $key = $parts[1];
                }
                if ( isset( $parts[2] ) && absint( $parts[2] ) > 0 ) {
                    $limit = absint( $parts[2] );
                }
            }

            if ( '' === $key || 0 === strpos( $key, 'YOUR_TINIFY_API_KEY' ) ) {
                continue;
            }

            $tokens[] = array(
                'id'            => $this->key_id( $key ),
                'name'          => $name,
                'key'           => $key,
                'monthly_limit' => max( 1, (int) $limit ),
            );
            $index++;
        }

        return $tokens;
    }

    private function get_usage() {
        $usage = get_option( self::OPTION_USAGE, array() );
        if ( ! is_array( $usage ) || ! isset( $usage['month'] ) || $usage['month'] !== $this->current_month() ) {
            $usage = array(
                'month' => $this->current_month(),
                'usage' => array(),
            );
        }

        if ( ! isset( $usage['usage'] ) || ! is_array( $usage['usage'] ) ) {
            $usage['usage'] = array();
        }

        return $usage;
    }

    private function save_usage( $usage ) {
        update_option( self::OPTION_USAGE, $usage, false );
    }

    private function choose_key( $tokens, $usage, $blocked ) {
        foreach ( $tokens as $token ) {
            if ( isset( $blocked[ $token['id'] ] ) ) {
                continue;
            }

            if ( $this->get_count( $usage, $token ) >= $token['monthly_limit'] ) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function get_count( $usage, $candidate ) {
        return isset( $usage['usage'][ $candidate['id'] ] ) ? (int) $usage['usage'][ $candidate['id'] ] : 0;
    }

    private function set_count( &$usage, $candidate, $count ) {
        if ( ! isset( $usage['usage'] ) || ! is_array( $usage['usage'] ) ) {
            $usage['usage'] = array();
        }

        $usage['usage'][ $candidate['id'] ] = max( 0, (int) $count );
    }

    private function key_id( $key ) {
        return substr( hash( 'sha256', $key ), 0, 16 );
    }

    private function current_month() {
        return current_time( 'Y-m' );
    }

    private function parse_tinypng_error_message( $body ) {
        $data = json_decode( (string) $body, true );
        if ( is_array( $data ) ) {
            if ( ! empty( $data['message'] ) ) {
                return (string) $data['message'];
            }
            if ( ! empty( $data['error'] ) ) {
                return (string) $data['error'];
            }
        }

        return '';
    }

    private function normalize_headers( $headers ) {
        $normalized = array();

        foreach ( $headers as $key => $value ) {
            $normalized[ strtolower( (string) $key ) ] = is_array( $value ) ? reset( $value ) : $value;
        }

        return $normalized;
    }

    private function empty_summary() {
        return array(
            'ok'           => 0,
            'failed'       => 0,
            'skipped'      => 0,
            'webp'         => 0,
            'bytes_before' => 0,
            'bytes_after'  => 0,
            'messages'     => array(),
        );
    }

    private function merge_summary( &$target, $source ) {
        foreach ( array( 'ok', 'failed', 'skipped', 'webp', 'bytes_before', 'bytes_after' ) as $key ) {
            $target[ $key ] += isset( $source[ $key ] ) ? (int) $source[ $key ] : 0;
        }

        if ( ! empty( $source['messages'] ) && is_array( $source['messages'] ) ) {
            $target['messages'] = array_merge( $target['messages'], $source['messages'] );
        }
    }

    private function format_summary_message( $summary ) {
        $saved = max( 0, (int) $summary['bytes_before'] - (int) $summary['bytes_after'] );

        $message = sprintf(
            'TinyPNG 处理完成：成功 %d，失败 %d，跳过 %d，WebP %d，节省 %s。',
            (int) $summary['ok'],
            (int) $summary['failed'],
            (int) $summary['skipped'],
            (int) $summary['webp'],
            esc_html( size_format( $saved, 1 ) )
        );

        if ( ! empty( $summary['messages'] ) ) {
            $items = array_slice( array_unique( $summary['messages'] ), 0, 5 );
            $message .= '<br>' . esc_html( implode( '；', $items ) );
        }

        return $message;
    }

    private function mode_label( $mode ) {
        $labels = array(
            'compress' => '压缩',
            'webp'     => '转 WebP',
            'both'     => '压缩+WebP',
        );

        return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
    }

    private function store_notice( $type, $message ) {
        set_transient(
            self::NOTICE_PREFIX . get_current_user_id(),
            array(
                'type'    => $type,
                'message' => $message,
            ),
            MINUTE_IN_SECONDS
        );
    }
}

MaoMoMo_TinyPNG_Media::instance();
