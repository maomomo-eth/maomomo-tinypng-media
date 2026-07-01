<?php
/**
 * Plugin Name: MaoMoMo TinyPNG Media
 * Plugin URI: https://www.maomomo.com
 * Description: 在媒体库中使用多个 TinyPNG API Token 轮换压缩图片，并支持转换 WebP。
 * Version: 1.3.2
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
    const QUEUE_HOOK         = 'maomomo_tinypng_process_queue';
    const QUEUE_LOCK         = 'maomomo_tinypng_queue_lock';
    const QUEUE_BATCH        = 1;
    const QUEUE_MAX_ATTEMPTS = 5;

    const META_QUEUE_STATUS     = '_maomomo_tinypng_queue_status';
    const META_QUEUE_MODE       = '_maomomo_tinypng_queue_mode';
    const META_QUEUE_ATTEMPTS   = '_maomomo_tinypng_queue_attempts';
    const META_QUEUE_NEXT_RUN   = '_maomomo_tinypng_queue_next_run';
    const META_QUEUE_STARTED_AT = '_maomomo_tinypng_queue_started_at';
    const META_QUEUE_UPDATED_AT = '_maomomo_tinypng_queue_updated_at';
    const META_QUEUE_DONE_AT    = '_maomomo_tinypng_queue_done_at';
    const META_QUEUE_LAST_ERROR = '_maomomo_tinypng_queue_last_error';
    const META_QUEUE_SUMMARY    = '_maomomo_tinypng_queue_summary';

    private static $instance = null;

    private $auto_processing = false;

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
        add_action( self::QUEUE_HOOK, array( $this, 'process_queue' ) );

        add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_process_uploaded_attachment' ), 20, 2 );
        add_filter( 'media_row_actions', array( $this, 'add_media_row_actions' ), 10, 3 );
        add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
        add_action( 'attachment_submitbox_misc_actions', array( $this, 'render_attachment_buttons' ) );
        add_filter( 'upload_mimes', array( $this, 'allow_webp_upload' ) );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'maomomo-tinypng', array( $this, 'cli_command' ) );
            WP_CLI::add_command( 'maomomo-tinypng-fix-scaled', array( $this, 'cli_fix_scaled_originals_command' ) );
        }
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
        $queue_counts = $this->get_queue_counts();
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
                        <th scope="row"><label for="maomomo-tinypng-auto-mode">上传后自动处理</label></th>
                        <td>
                            <select id="maomomo-tinypng-auto-mode" name="auto_mode">
                                <option value="none" <?php selected( $settings['auto_mode'], 'none' ); ?>>不自动处理</option>
                                <option value="compress" <?php selected( $settings['auto_mode'], 'compress' ); ?>>自动 TinyPNG 压缩</option>
                                <option value="webp" <?php selected( $settings['auto_mode'], 'webp' ); ?>>自动转 WebP</option>
                                <option value="both" <?php selected( $settings['auto_mode'], 'both' ); ?>>自动压缩并转 WebP</option>
                            </select>
                            <p class="description">启用后，新上传到媒体库的图片会在 WordPress 生成附件元数据后加入后台队列，由 WP-Cron 异步处理。</p>
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

            <h2>后台队列</h2>
            <table class="widefat striped" style="max-width: 620px;">
                <tbody>
                    <tr>
                        <th scope="row">排队中</th>
                        <td><?php echo esc_html( (string) $queue_counts['pending'] ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">处理中</th>
                        <td><?php echo esc_html( (string) $queue_counts['running'] ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">处理完成</th>
                        <td><?php echo esc_html( (string) $queue_counts['done'] ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">处理失败</th>
                        <td><?php echo esc_html( (string) $queue_counts['failed'] ); ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description">如果站点访问量很低，WP-Cron 可能延迟触发；生产环境建议用系统 cron 定时访问 <code>wp-cron.php</code> 或执行 WP-CLI cron。</p>

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
        $auto_mode     = isset( $_POST['auto_mode'] ) ? sanitize_key( wp_unslash( $_POST['auto_mode'] ) ) : 'none';

        if ( ! in_array( $auto_mode, array( 'none', 'compress', 'webp', 'both' ), true ) ) {
            $auto_mode = 'none';
        }

        $settings = array(
            'tokens_text'   => $this->normalize_tokens_text( $tokens_text ),
            'default_limit' => max( 1, $default_limit ),
            'include_sizes' => ! empty( $_POST['include_sizes'] ),
            'auto_mode'     => $auto_mode,
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

        $mode     = $map[ $action ];
        $queued   = 0;
        $failed   = 0;
        $skipped  = 0;
        $messages = array();

        foreach ( (array) $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
                $failed++;
                $messages[] = '跳过无权限附件：' . $post_id;
                continue;
            }

            if ( ! $this->is_supported_attachment( $post_id ) ) {
                $skipped++;
                $messages[] = '跳过不支持的附件：#' . $post_id;
                continue;
            }

            if ( $this->enqueue_attachment( $post_id, $mode, 1 ) ) {
                $queued++;
                continue;
            }

            $failed++;
            $messages[] = '附件 #' . $post_id . ' 加入后台队列失败。';
        }

        if ( $queued > 0 ) {
            $type = $failed ? 'warning' : 'success';
        } else {
            $type = $failed ? 'error' : 'warning';
        }

        $this->store_notice( $type, $this->format_enqueue_message( $mode, $queued, $failed, $skipped, $messages ) );

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

        $last         = get_post_meta( $post_id, '_maomomo_tinypng_last_result', true );
        $webp_id      = (int) get_post_meta( $post_id, '_maomomo_tinypng_webp_id', true );
        $queue_status = (string) get_post_meta( $post_id, self::META_QUEUE_STATUS, true );

        if ( '' !== $queue_status && in_array( $queue_status, array( 'pending', 'running', 'failed' ), true ) ) {
            $attempts = (int) get_post_meta( $post_id, self::META_QUEUE_ATTEMPTS, true );
            $label    = $this->queue_status_label( $queue_status );
            if ( 'pending' === $queue_status && $attempts > 0 ) {
                $label = '队列：等待重试';
            }

            echo '<div>' . esc_html( $label ) . '</div>';

            if ( $attempts > 0 ) {
                echo '<div>尝试：' . esc_html( (string) $attempts ) . '</div>';
            }

            if ( 'pending' === $queue_status && $attempts > 0 ) {
                $next_run = (int) get_post_meta( $post_id, self::META_QUEUE_NEXT_RUN, true );
                if ( $next_run > time() ) {
                    echo '<div>下次重试：' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) ) . '</div>';
                }
            }

            $last_error = (string) get_post_meta( $post_id, self::META_QUEUE_LAST_ERROR, true );
            if ( '' !== $last_error ) {
                echo '<div style="color:#b32d2e;">' . esc_html( wp_trim_words( $last_error, 18 ) ) . '</div>';
            }
        }

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

    public function auto_process_uploaded_attachment( $metadata, $attachment_id ) {
        if ( $this->auto_processing ) {
            return $metadata;
        }

        $settings  = $this->get_settings();
        $auto_mode = isset( $settings['auto_mode'] ) ? $settings['auto_mode'] : 'none';

        if ( ! in_array( $auto_mode, array( 'compress', 'webp', 'both' ), true ) ) {
            return $metadata;
        }

        if ( ! $this->is_supported_attachment( $attachment_id ) ) {
            return $metadata;
        }

        if ( is_array( $metadata ) && ! empty( $metadata ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
        }

        $this->enqueue_attachment( $attachment_id, $auto_mode, 30 );
        $this->store_notice( 'info', '上传后自动处理已加入 TinyPNG 后台队列。' );

        return $metadata;
    }

    public function process_queue() {
        if ( get_transient( self::QUEUE_LOCK ) ) {
            return;
        }

        set_transient( self::QUEUE_LOCK, (string) time(), 10 * MINUTE_IN_SECONDS );

        try {
            @set_time_limit( 0 );
            $this->fail_non_retryable_pending_queue_items();
            $this->recover_stale_queue_items();

            $ids = $this->get_due_queue_attachment_ids( self::QUEUE_BATCH );
            foreach ( $ids as $attachment_id ) {
                $this->process_queue_item( (int) $attachment_id );
            }
        } finally {
            delete_transient( self::QUEUE_LOCK );
        }

        if ( $this->has_due_queue_items() ) {
            $this->schedule_queue_event( 15 );
            return;
        }

        $next_delay = $this->get_next_queue_delay();
        if ( $next_delay > 0 ) {
            $this->schedule_queue_event( $next_delay );
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
        $this->sync_queue_after_direct_processing( $attachment_id, $mode, $result );

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

    /**
     * 批量压缩或转换媒体库图片。
     *
     * ## OPTIONS
     *
     * [<attachment-id>...]
     * : 可选附件 ID，支持空格或逗号分隔。不传时处理全部支持的图片附件。
     *
     * [--mode=<mode>]
     * : 处理模式：compress、webp、both。默认 compress。
     * ---
     * default: compress
     * options:
     *   - compress
     *   - webp
     *   - both
     * ---
     *
     * [--limit=<number>]
     * : 最多处理多少个附件。
     *
     * [--after=<date>]
     * : 只处理此日期之后上传的附件，例如 2026-06-01。
     *
     * [--before=<date>]
     * : 只处理此日期之前上传的附件，例如 2026-06-30。
     *
     * [--dry-run]
     * : 只列出将处理的附件，不调用 TinyPNG，不写入文件。
     *
     * ## EXAMPLES
     *
     *     php .\wp-cli.phar maomomo-tinypng --mode=compress --limit=50
     *     php .\wp-cli.phar maomomo-tinypng --mode=both --after=2026-06-01
     *     php .\wp-cli.phar maomomo-tinypng 123 456,789 --mode=webp
     */
    public function cli_command( $args, $assoc_args ) {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }

        $mode = isset( $assoc_args['mode'] ) ? sanitize_key( (string) $assoc_args['mode'] ) : 'compress';
        if ( ! in_array( $mode, array( 'compress', 'webp', 'both' ), true ) ) {
            WP_CLI::error( 'mode 只支持 compress、webp、both。' );
        }

        $ids = $this->cli_get_attachment_ids( $args, $assoc_args );
        if ( empty( $ids ) ) {
            WP_CLI::warning( '没有找到需要处理的附件。' );
            return;
        }

        $dry_run = ! empty( $assoc_args['dry-run'] );
        $limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
        if ( $limit > 0 ) {
            $ids = array_slice( $ids, 0, $limit );
        }

        $total   = count( $ids );
        $summary = $this->empty_summary();

        WP_CLI::log( sprintf( '准备处理 %d 个附件，模式：%s%s', $total, $mode, $dry_run ? '，dry-run' : '' ) );

        $progress = \WP_CLI\Utils\make_progress_bar( 'TinyPNG processing', $total );

        foreach ( $ids as $attachment_id ) {
            $attachment_id = absint( $attachment_id );

            if ( $dry_run ) {
                $summary['skipped']++;
                WP_CLI::log( sprintf( '#%d %s', $attachment_id, get_attached_file( $attachment_id ) ) );
                $progress->tick();
                continue;
            }

            $result = $this->process_attachment( $attachment_id, $mode );
            $this->sync_queue_after_direct_processing( $attachment_id, $mode, $result );
            $this->merge_summary( $summary, $result );

            if ( ! empty( $result['messages'] ) ) {
                foreach ( array_unique( $result['messages'] ) as $message ) {
                    WP_CLI::warning( sprintf( '#%d %s', $attachment_id, wp_strip_all_tags( $message ) ) );
                }
            }

            $progress->tick();
        }

        $progress->finish();

        $saved = max( 0, (int) $summary['bytes_before'] - (int) $summary['bytes_after'] );
        WP_CLI::success(
            sprintf(
                'TinyPNG 处理完成：成功 %d，失败 %d，跳过 %d，WebP %d，节省 %s。',
                (int) $summary['ok'],
                (int) $summary['failed'],
                (int) $summary['skipped'],
                (int) $summary['webp'],
                size_format( $saved, 1 )
            )
        );
    }

    /**
     * 将当前指向 -scaled 文件的附件切回不带 -scaled 的原图，并删除 -scaled 文件。
     *
     * ## OPTIONS
     *
     * [<attachment-or-file>...]
     * : 可选附件 ID、文件名或路径，支持空格或逗号分隔。不传时扫描全部图片附件。
     *
     * [--dry-run]
     * : 只预览将修复的附件，不更新数据库，不删除文件。
     *
     * [--yes]
     * : 确认真实执行。没有 --dry-run 时必须传入此参数。
     *
     * [--keep-scaled]
     * : 只把附件改回原图，不删除 -scaled 文件。
     *
     * [--no-scan-content]
     * : 修复后不扫描文章和页面正文中的 -scaled 引用。
     *
     * ## EXAMPLES
     *
     *     php .\wp-cli.phar maomomo-tinypng-fix-scaled maomomo.com-2026-05-19_18-33-25_773340-scaled.webp --dry-run
     *     php .\wp-cli.phar maomomo-tinypng-fix-scaled 1742 --yes
     */
    public function cli_fix_scaled_originals_command( $args, $assoc_args ) {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }

        $dry_run     = ! empty( $assoc_args['dry-run'] );
        $confirmed   = ! empty( $assoc_args['yes'] );
        $keep_scaled = ! empty( $assoc_args['keep-scaled'] );
        $scan_content = empty( $assoc_args['no-scan-content'] );
        $scan_targets = $this->cli_get_scaled_reference_targets( $args );

        if ( ! $dry_run && ! $confirmed ) {
            WP_CLI::error( '此操作会更新附件路径并删除 -scaled 文件。请先使用 --dry-run 预览，确认后加 --yes 执行。' );
        }

        $lookup = $this->cli_get_scaled_attachment_ids( $args );
        foreach ( $lookup['missing'] as $target ) {
            WP_CLI::warning( '没有找到匹配的附件：' . $target );
        }

        $ids = $lookup['ids'];
        if ( empty( $ids ) ) {
            if ( $scan_content && ! empty( $scan_targets ) ) {
                $this->cli_report_scaled_references( $scan_targets );
            }

            WP_CLI::warning( '没有找到需要修复的 -scaled 附件。' );
            return;
        }

        $summary = array(
            'ok'      => 0,
            'failed'  => 0,
            'skipped' => 0,
            'deleted' => 0,
        );
        $scaled_paths = array();

        WP_CLI::log(
            sprintf(
                '准备修复 %d 个附件%s%s。',
                count( $ids ),
                $dry_run ? '，dry-run' : '',
                $keep_scaled ? '，保留 -scaled 文件' : ''
            )
        );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Fix scaled originals', count( $ids ) );

        foreach ( $ids as $attachment_id ) {
            $result = $this->restore_scaled_original_attachment( (int) $attachment_id, $dry_run, ! $keep_scaled );

            if ( is_wp_error( $result ) ) {
                $summary['failed']++;
                WP_CLI::warning( sprintf( '#%d %s', $attachment_id, $result->get_error_message() ) );
                $progress->tick();
                continue;
            }

            if ( empty( $result['changed'] ) ) {
                $summary['skipped']++;
            } else {
                $summary['ok']++;
            }

            if ( ! empty( $result['deleted'] ) ) {
                $summary['deleted']++;
            }

            if ( ! empty( $result['scaled'] ) ) {
                $scaled_paths[] = $result['scaled'];
            }

            WP_CLI::log(
                sprintf(
                    '#%d %s -> %s%s',
                    $attachment_id,
                    $result['scaled'],
                    $result['original'],
                    ! empty( $result['message'] ) ? '；' . $result['message'] : ''
                )
            );

            $progress->tick();
        }

        $progress->finish();

        if ( $scan_content ) {
            $this->cli_report_scaled_references( array_merge( $scan_targets, $scaled_paths ) );
        }

        WP_CLI::success(
            sprintf(
                '修复完成：成功 %d，失败 %d，跳过 %d，删除 -scaled 文件 %d。',
                (int) $summary['ok'],
                (int) $summary['failed'],
                (int) $summary['skipped'],
                (int) $summary['deleted']
            )
        );
    }

    private function cli_get_attachment_ids( $args, $assoc_args ) {
        if ( ! empty( $args ) ) {
            $ids = array();
            foreach ( $args as $arg ) {
                foreach ( explode( ',', (string) $arg ) as $id ) {
                    $id = absint( $id );
                    if ( $id ) {
                        $ids[] = $id;
                    }
                }
            }

            return array_values( array_unique( $ids ) );
        }

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );

        if ( ! empty( $assoc_args['after'] ) || ! empty( $assoc_args['before'] ) ) {
            $query_args['date_query'] = array();
            if ( ! empty( $assoc_args['after'] ) ) {
                $query_args['date_query'][] = array( 'after' => (string) $assoc_args['after'], 'inclusive' => true );
            }
            if ( ! empty( $assoc_args['before'] ) ) {
                $query_args['date_query'][] = array( 'before' => (string) $assoc_args['before'], 'inclusive' => true );
            }
        }

        $query = new WP_Query( $query_args );
        $ids   = array();

        foreach ( $query->posts as $attachment_id ) {
            if ( $this->is_supported_attachment( $attachment_id ) ) {
                $ids[] = (int) $attachment_id;
            }
        }

        return $ids;
    }

    private function cli_get_scaled_attachment_ids( $args ) {
        $ids           = array();
        $wanted        = array();
        $numeric_ids   = array();
        $missing       = array();
        $has_targets   = ! empty( $args );
        $normalized_in = array();

        foreach ( (array) $args as $arg ) {
            foreach ( explode( ',', (string) $arg ) as $target ) {
                $target = trim( $target );
                if ( '' === $target ) {
                    continue;
                }

                if ( ctype_digit( $target ) ) {
                    $numeric_ids[] = absint( $target );
                    continue;
                }

                $key                    = $this->normalize_cli_file_target( $target );
                $wanted[ $key ]         = $target;
                $normalized_in[ $key ]  = true;
                $basename               = $this->normalize_cli_file_target( wp_basename( $target ) );
                $normalized_in[ $basename ] = true;
            }
        }

        $ids = $numeric_ids;

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        foreach ( $query->posts as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            $file          = get_attached_file( $attachment_id, true );
            if ( ! $file ) {
                continue;
            }

            if ( $has_targets ) {
                $relative = $this->attachment_relative_path( $file );
                $candidates = array(
                    $this->normalize_cli_file_target( $file ),
                    $this->normalize_cli_file_target( $relative ),
                    $this->normalize_cli_file_target( wp_basename( $file ) ),
                );

                foreach ( $candidates as $candidate ) {
                    if ( isset( $normalized_in[ $candidate ] ) ) {
                        $ids[] = $attachment_id;
                        unset( $wanted[ $candidate ] );
                    }
                }
                continue;
            }

            if ( $this->has_scaled_original_candidate( $attachment_id ) ) {
                $ids[] = $attachment_id;
            }
        }

        foreach ( $wanted as $target ) {
            $missing[] = $target;
        }

        return array(
            'ids'     => array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ),
            'missing' => array_values( array_unique( $missing ) ),
        );
    }

    private function cli_get_scaled_reference_targets( $args ) {
        $targets = array();

        foreach ( (array) $args as $arg ) {
            foreach ( explode( ',', (string) $arg ) as $target ) {
                $target = trim( $target );
                if ( '' === $target || ctype_digit( $target ) ) {
                    continue;
                }

                if ( false === strpos( wp_basename( $target ), '-scaled.' ) ) {
                    continue;
                }

                $targets[] = $target;
            }
        }

        return array_values( array_unique( $targets ) );
    }

    private function cli_report_scaled_references( $scaled_paths ) {
        $references = $this->scan_posts_pages_for_scaled_references( $scaled_paths );
        if ( empty( $references ) ) {
            WP_CLI::log( '未发现 post/page 正文继续引用这些 -scaled 文件。' );
            return;
        }

        WP_CLI::warning( sprintf( '发现 %d 篇 post/page 正文仍引用 -scaled 文件：', count( $references ) ) );
        foreach ( $references as $reference ) {
            WP_CLI::warning(
                sprintf(
                    '#%d [%s] %s：%s',
                    (int) $reference['id'],
                    $reference['type'],
                    $reference['title'],
                    implode( ', ', $reference['files'] )
                )
            );
        }
    }

    private function normalize_cli_file_target( $target ) {
        return strtolower( wp_normalize_path( trim( (string) $target ) ) );
    }

    private function has_scaled_original_candidate( $attachment_id ) {
        $paths = $this->get_scaled_original_paths( $attachment_id );
        return ! is_wp_error( $paths );
    }

    private function restore_scaled_original_attachment( $attachment_id, $dry_run, $delete_scaled ) {
        $paths = $this->get_scaled_original_paths( $attachment_id );
        if ( is_wp_error( $paths ) ) {
            return $paths;
        }

        $scaled   = $paths['scaled'];
        $original = $paths['original'];

        $new_relative = $this->attachment_relative_path( $original );
        $conflict_id  = $this->find_attachment_by_relative_file( $new_relative, $attachment_id );
        if ( $conflict_id ) {
            return new WP_Error(
                'maomomo_tinypng_scaled_conflict',
                sprintf( '原图文件已被附件 #%d 使用：%s', $conflict_id, $new_relative )
            );
        }

        if ( $dry_run ) {
            return array(
                'changed'  => true,
                'deleted'  => false,
                'scaled'   => $scaled,
                'original' => $original,
                'message'  => $delete_scaled ? '将更新附件并删除 -scaled 文件' : '将更新附件并保留 -scaled 文件',
            );
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $metadata ) ) {
            $metadata = $this->build_basic_image_metadata( $original );
        }

        $metadata['file'] = $new_relative;
        unset( $metadata['original_image'] );

        $size = wp_getimagesize( $original );
        if ( is_array( $size ) ) {
            $metadata['width']  = (int) $size[0];
            $metadata['height'] = (int) $size[1];
        }
        $metadata['filesize'] = file_exists( $original ) ? filesize( $original ) : 0;

        update_post_meta( $attachment_id, '_wp_attached_file', $new_relative );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        $filetype = wp_check_filetype( $original );
        if ( ! empty( $filetype['type'] ) ) {
            wp_update_post(
                array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $filetype['type'],
                )
            );
        }

        $deleted = false;
        if ( $delete_scaled ) {
            $delete_result = $this->delete_scaled_file( $scaled, $original );
            if ( is_wp_error( $delete_result ) ) {
                return $delete_result;
            }
            $deleted = (bool) $delete_result;
        }

        return array(
            'changed'  => true,
            'deleted'  => $deleted,
            'scaled'   => $scaled,
            'original' => $original,
            'message'  => $deleted ? '已更新附件并删除 -scaled 文件' : '已更新附件',
        );
    }

    private function get_scaled_original_paths( $attachment_id ) {
        $scaled = get_attached_file( $attachment_id, true );
        if ( ! $scaled ) {
            return new WP_Error( 'maomomo_tinypng_scaled_missing_attachment_file', '附件没有记录文件路径。' );
        }

        if ( ! file_exists( $scaled ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_missing_file', '当前 -scaled 文件不存在：' . $scaled );
        }

        if ( ! $this->is_supported_path( $scaled ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_unsupported', '附件不是支持的图片类型：' . $scaled );
        }

        $suffix_original = $this->remove_scaled_suffix_from_path( $scaled );
        if ( '' === $suffix_original ) {
            return new WP_Error( 'maomomo_tinypng_scaled_not_scaled', '当前附件文件名不含 -scaled：' . $scaled );
        }

        $original = '';
        if ( function_exists( 'wp_get_original_image_path' ) ) {
            $candidate = wp_get_original_image_path( $attachment_id );
            if ( $candidate && wp_normalize_path( $candidate ) !== wp_normalize_path( $scaled ) ) {
                $original = $candidate;
            }
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! $original && is_array( $metadata ) && ! empty( $metadata['original_image'] ) ) {
            $original = dirname( $scaled ) . DIRECTORY_SEPARATOR . wp_basename( $metadata['original_image'] );
        }

        if ( ! $original ) {
            $original = $suffix_original;
        }

        if ( ! $original || wp_normalize_path( $original ) === wp_normalize_path( $scaled ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_not_scaled', '当前附件文件名不含 -scaled：' . $scaled );
        }

        if ( ! file_exists( $original ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_original_missing', '找不到不带 -scaled 的原图：' . $original );
        }

        if ( ! $this->is_supported_path( $original ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_original_unsupported', '原图不是支持的图片类型：' . $original );
        }

        return array(
            'scaled'   => $scaled,
            'original' => $original,
        );
    }

    private function remove_scaled_suffix_from_path( $path ) {
        $dir       = dirname( $path );
        $extension = pathinfo( $path, PATHINFO_EXTENSION );
        $filename  = pathinfo( $path, PATHINFO_FILENAME );

        if ( ! preg_match( '/-scaled$/', $filename ) ) {
            return '';
        }

        $original_name = preg_replace( '/-scaled$/', '', $filename ) . ( $extension ? '.' . $extension : '' );

        return $dir . DIRECTORY_SEPARATOR . $original_name;
    }

    private function find_attachment_by_relative_file( $relative, $exclude_id ) {
        if ( '' === $relative ) {
            return 0;
        }

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_wp_attached_file',
                'meta_value'     => $relative,
                'post__not_in'   => array( (int) $exclude_id ),
            )
        );

        return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
    }

    private function delete_scaled_file( $scaled, $original ) {
        if ( wp_normalize_path( $scaled ) === wp_normalize_path( $original ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_same_file', '安全检查失败：-scaled 文件和原图路径相同。' );
        }

        if ( ! file_exists( $scaled ) ) {
            return false;
        }

        if ( ! $this->is_path_in_uploads( $scaled ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_outside_uploads', '安全检查失败：文件不在 uploads 目录中：' . $scaled );
        }

        if ( '' === $this->remove_scaled_suffix_from_path( $scaled ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_delete_name', '安全检查失败：目标文件名不含 -scaled：' . $scaled );
        }

        if ( ! @unlink( $scaled ) ) {
            return new WP_Error( 'maomomo_tinypng_scaled_delete_failed', '删除 -scaled 文件失败：' . $scaled );
        }

        clearstatcache( true, $scaled );
        return ! file_exists( $scaled );
    }

    private function scan_posts_pages_for_scaled_references( $scaled_paths ) {
        $needles = $this->build_scaled_reference_needles( $scaled_paths );
        if ( empty( $needles ) ) {
            return array();
        }

        $query = new WP_Query(
            array(
                'post_type'      => array( 'post', 'page' ),
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        $references = array();

        foreach ( $query->posts as $post_id ) {
            $post = get_post( (int) $post_id );
            if ( ! $post instanceof WP_Post || '' === (string) $post->post_content ) {
                continue;
            }

            $matched = array();
            foreach ( $needles as $file => $candidates ) {
                foreach ( $candidates as $candidate ) {
                    if ( '' !== $candidate && false !== strpos( $post->post_content, $candidate ) ) {
                        $matched[] = $file;
                        break;
                    }
                }
            }

            if ( empty( $matched ) ) {
                continue;
            }

            $references[] = array(
                'id'    => (int) $post->ID,
                'type'  => (string) $post->post_type,
                'title' => get_the_title( $post ) ? get_the_title( $post ) : '(无标题)',
                'files' => array_values( array_unique( $matched ) ),
            );
        }

        return $references;
    }

    private function build_scaled_reference_needles( $scaled_paths ) {
        $upload_dir = wp_upload_dir();
        $base_url   = isset( $upload_dir['baseurl'] ) ? untrailingslashit( $upload_dir['baseurl'] ) : '';
        $needles    = array();

        foreach ( array_unique( array_filter( (array) $scaled_paths ) ) as $path ) {
            $file     = wp_basename( $path );
            $relative = $this->attachment_relative_path( $path );

            $candidates = array(
                $file,
                $relative,
                str_replace( '/', '\/', $relative ),
            );

            if ( $base_url && $relative ) {
                $url          = $base_url . '/' . ltrim( $relative, '/' );
                $candidates[] = $url;
                $candidates[] = str_replace( '/', '\/', $url );
            }

            $needles[ $file ] = array_values( array_unique( array_filter( $candidates ) ) );
        }

        return $needles;
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

    private function enqueue_attachment( $attachment_id, $mode, $delay = 30 ) {
        $attachment_id = absint( $attachment_id );
        $mode          = sanitize_key( (string) $mode );

        if ( ! $attachment_id || ! in_array( $mode, array( 'compress', 'webp', 'both' ), true ) ) {
            return false;
        }

        $status        = (string) get_post_meta( $attachment_id, self::META_QUEUE_STATUS, true );
        $existing_mode = (string) get_post_meta( $attachment_id, self::META_QUEUE_MODE, true );

        if ( in_array( $status, array( 'pending', 'running' ), true ) && in_array( $existing_mode, array( 'compress', 'webp', 'both' ), true ) ) {
            $mode = $this->merge_modes( $existing_mode, $mode );
        }

        $now = time();
        update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'pending' );
        update_post_meta( $attachment_id, self::META_QUEUE_MODE, $mode );
        update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, $now + max( 1, (int) $delay ) );
        update_post_meta( $attachment_id, self::META_QUEUE_UPDATED_AT, $now );
        delete_post_meta( $attachment_id, self::META_QUEUE_DONE_AT );

        if ( 'running' !== $status ) {
            update_post_meta( $attachment_id, self::META_QUEUE_ATTEMPTS, 0 );
            update_post_meta( $attachment_id, self::META_QUEUE_LAST_ERROR, '' );
            delete_post_meta( $attachment_id, self::META_QUEUE_SUMMARY );
        }

        $this->schedule_queue_event( (int) $delay );

        return true;
    }

    private function process_queue_item( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id ) {
            return;
        }

        $mode = sanitize_key( (string) get_post_meta( $attachment_id, self::META_QUEUE_MODE, true ) );
        if ( ! in_array( $mode, array( 'compress', 'webp', 'both' ), true ) ) {
            $mode = 'compress';
        }

        $attempts = (int) get_post_meta( $attachment_id, self::META_QUEUE_ATTEMPTS, true );
        $attempts++;
        $now = time();

        update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'running' );
        update_post_meta( $attachment_id, self::META_QUEUE_ATTEMPTS, $attempts );
        update_post_meta( $attachment_id, self::META_QUEUE_STARTED_AT, $now );
        update_post_meta( $attachment_id, self::META_QUEUE_UPDATED_AT, $now );

        if ( ! $this->is_supported_attachment( $attachment_id ) ) {
            $summary = $this->empty_summary();
            $summary['failed']++;
            $summary['messages'][] = '附件 #' . $attachment_id . ' 不是支持的图片格式。';
            $this->finish_queue_item( $attachment_id, $attempts, $summary, true );
            return;
        }

        $was_auto_processing   = $this->auto_processing;
        $this->auto_processing = true;

        try {
            $result = $this->process_attachment( $attachment_id, $mode );
        } finally {
            $this->auto_processing = $was_auto_processing;
        }

        $final_failed = ! is_array( $result ) || ! empty( $result['failed'] );
        $this->finish_queue_item( $attachment_id, $attempts, is_array( $result ) ? $result : $this->empty_summary(), $final_failed );
    }

    private function finish_queue_item( $attachment_id, $attempts, $summary, $failed ) {
        $now = time();
        update_post_meta( $attachment_id, self::META_QUEUE_SUMMARY, $summary );
        update_post_meta( $attachment_id, self::META_QUEUE_UPDATED_AT, $now );

        if ( ! $failed ) {
            update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'done' );
            update_post_meta( $attachment_id, self::META_QUEUE_DONE_AT, $now );
            update_post_meta( $attachment_id, self::META_QUEUE_LAST_ERROR, '' );
            update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, 0 );
            return;
        }

        $message = $this->queue_summary_error( $summary );
        update_post_meta( $attachment_id, self::META_QUEUE_LAST_ERROR, $message );

        if ( $attempts >= self::QUEUE_MAX_ATTEMPTS || ! $this->is_retryable_queue_summary( $summary ) ) {
            update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'failed' );
            update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, 0 );
            return;
        }

        update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'pending' );
        update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, $now + $this->queue_retry_delay( $attempts ) );
    }

    private function get_due_queue_attachment_ids( $limit ) {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => max( 1, (int) $limit ),
                'fields'         => 'ids',
                'orderby'        => 'meta_value_num',
                'order'          => 'ASC',
                'meta_key'       => self::META_QUEUE_NEXT_RUN,
                'meta_query'     => array(
                    array(
                        'key'   => self::META_QUEUE_STATUS,
                        'value' => 'pending',
                    ),
                    array(
                        'key'     => self::META_QUEUE_NEXT_RUN,
                        'value'   => time(),
                        'compare' => '<=',
                        'type'    => 'NUMERIC',
                    ),
                ),
                'no_found_rows'  => true,
            )
        );

        return array_map( 'absint', $query->posts );
    }

    private function has_due_queue_items() {
        return ! empty( $this->get_due_queue_attachment_ids( 1 ) );
    }

    private function get_next_queue_delay() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'meta_value_num',
                'order'          => 'ASC',
                'meta_key'       => self::META_QUEUE_NEXT_RUN,
                'meta_query'     => array(
                    array(
                        'key'   => self::META_QUEUE_STATUS,
                        'value' => 'pending',
                    ),
                    array(
                        'key'     => self::META_QUEUE_NEXT_RUN,
                        'value'   => 0,
                        'compare' => '>',
                        'type'    => 'NUMERIC',
                    ),
                ),
                'no_found_rows'  => true,
            )
        );

        if ( empty( $query->posts ) ) {
            return 0;
        }

        $next_run = (int) get_post_meta( (int) $query->posts[0], self::META_QUEUE_NEXT_RUN, true );
        return max( 15, $next_run - time() );
    }

    private function recover_stale_queue_items() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 20,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'   => self::META_QUEUE_STATUS,
                        'value' => 'running',
                    ),
                    array(
                        'key'     => self::META_QUEUE_STARTED_AT,
                        'value'   => time() - ( 30 * MINUTE_IN_SECONDS ),
                        'compare' => '<=',
                        'type'    => 'NUMERIC',
                    ),
                ),
                'no_found_rows'  => true,
            )
        );

        foreach ( $query->posts as $attachment_id ) {
            update_post_meta( (int) $attachment_id, self::META_QUEUE_STATUS, 'pending' );
            update_post_meta( (int) $attachment_id, self::META_QUEUE_NEXT_RUN, time() );
            update_post_meta( (int) $attachment_id, self::META_QUEUE_LAST_ERROR, '上次后台处理超时，已重新排队。' );
            update_post_meta( (int) $attachment_id, self::META_QUEUE_UPDATED_AT, time() );
        }
    }

    private function fail_non_retryable_pending_queue_items() {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 50,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'   => self::META_QUEUE_STATUS,
                        'value' => 'pending',
                    ),
                    array(
                        'key'     => self::META_QUEUE_SUMMARY,
                        'compare' => 'EXISTS',
                    ),
                ),
                'no_found_rows'  => true,
            )
        );

        foreach ( $query->posts as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            $summary       = get_post_meta( $attachment_id, self::META_QUEUE_SUMMARY, true );

            if ( ! is_array( $summary ) || empty( $summary['failed'] ) || $this->is_retryable_queue_summary( $summary ) ) {
                continue;
            }

            update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'failed' );
            update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, 0 );
            update_post_meta( $attachment_id, self::META_QUEUE_LAST_ERROR, $this->queue_summary_error( $summary ) );
            update_post_meta( $attachment_id, self::META_QUEUE_UPDATED_AT, time() );
        }
    }

    private function schedule_queue_event( $delay = 30 ) {
        $run_at   = time() + max( 1, (int) $delay );
        $existing = wp_next_scheduled( self::QUEUE_HOOK );

        if ( $existing && $existing <= $run_at ) {
            return;
        }

        if ( $existing ) {
            wp_unschedule_event( $existing, self::QUEUE_HOOK );
        }

        wp_schedule_single_event( $run_at, self::QUEUE_HOOK );
    }

    private function queue_retry_delay( $attempts ) {
        $delays = array(
            1 => 60,
            2 => 5 * MINUTE_IN_SECONDS,
            3 => 15 * MINUTE_IN_SECONDS,
            4 => HOUR_IN_SECONDS,
        );

        return isset( $delays[ $attempts ] ) ? $delays[ $attempts ] : HOUR_IN_SECONDS;
    }

    private function queue_summary_error( $summary ) {
        if ( ! empty( $summary['messages'] ) && is_array( $summary['messages'] ) ) {
            return wp_strip_all_tags( implode( '；', array_slice( array_unique( $summary['messages'] ), 0, 5 ) ) );
        }

        return 'TinyPNG 后台处理失败。';
    }

    private function is_retryable_queue_summary( $summary ) {
        if ( empty( $summary['messages'] ) || ! is_array( $summary['messages'] ) ) {
            return true;
        }

        $message = wp_strip_all_tags( implode( '；', $summary['messages'] ) );
        $non_retryable_fragments = array(
            '不是支持的图片格式',
            '没有可压缩的本地文件',
            '文件不可读写',
            '找不到原图文件',
            '文件不存在或不可读取',
            '读取文件失败',
            'WebP 文件没有成功写入',
            '写入临时文件失败',
            '替换目标文件失败',
            '请先在设置页配置 TinyPNG API Token',
            'No editor could be selected',
            '没有可用的图片编辑器',
            'Image could not be decoded',
            '图片无法解码',
        );

        foreach ( $non_retryable_fragments as $fragment ) {
            if ( false !== strpos( $message, $fragment ) ) {
                return false;
            }
        }

        return true;
    }

    private function merge_modes( $current, $next ) {
        if ( $current === $next ) {
            return $current;
        }

        if ( 'both' === $current || 'both' === $next ) {
            return 'both';
        }

        return 'both';
    }

    private function sync_queue_after_direct_processing( $attachment_id, $mode, $summary ) {
        $attachment_id = absint( $attachment_id );
        $mode          = sanitize_key( (string) $mode );

        if ( ! $attachment_id || ! in_array( $mode, array( 'compress', 'webp', 'both' ), true ) || ! is_array( $summary ) ) {
            return;
        }

        $status = (string) get_post_meta( $attachment_id, self::META_QUEUE_STATUS, true );
        if ( ! in_array( $status, array( 'pending', 'running', 'failed' ), true ) ) {
            return;
        }

        $queued_mode = sanitize_key( (string) get_post_meta( $attachment_id, self::META_QUEUE_MODE, true ) );
        if ( ! in_array( $queued_mode, array( 'compress', 'webp', 'both' ), true ) ) {
            $queued_mode = $mode;
        }

        $now = time();
        update_post_meta( $attachment_id, self::META_QUEUE_SUMMARY, $summary );
        update_post_meta( $attachment_id, self::META_QUEUE_UPDATED_AT, $now );

        if ( ! empty( $summary['failed'] ) ) {
            update_post_meta( $attachment_id, self::META_QUEUE_LAST_ERROR, $this->queue_summary_error( $summary ) );

            if ( ! $this->is_retryable_queue_summary( $summary ) ) {
                update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'failed' );
                update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, 0 );
            }

            return;
        }

        $remaining_mode = $this->remaining_queue_mode_after_processing( $queued_mode, $mode );
        if ( '' === $remaining_mode ) {
            update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'done' );
            update_post_meta( $attachment_id, self::META_QUEUE_DONE_AT, $now );
            update_post_meta( $attachment_id, self::META_QUEUE_LAST_ERROR, '' );
            update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, 0 );
            return;
        }

        update_post_meta( $attachment_id, self::META_QUEUE_STATUS, 'pending' );
        update_post_meta( $attachment_id, self::META_QUEUE_MODE, $remaining_mode );
        update_post_meta( $attachment_id, self::META_QUEUE_LAST_ERROR, '' );
        update_post_meta( $attachment_id, self::META_QUEUE_NEXT_RUN, $now + 1 );

        $this->schedule_queue_event( 1 );
    }

    private function remaining_queue_mode_after_processing( $queued_mode, $processed_mode ) {
        $needs_compress = in_array( $queued_mode, array( 'compress', 'both' ), true );
        $needs_webp     = in_array( $queued_mode, array( 'webp', 'both' ), true );

        if ( in_array( $processed_mode, array( 'compress', 'both' ), true ) ) {
            $needs_compress = false;
        }

        if ( in_array( $processed_mode, array( 'webp', 'both' ), true ) ) {
            $needs_webp = false;
        }

        if ( $needs_compress && $needs_webp ) {
            return 'both';
        }

        if ( $needs_compress ) {
            return 'compress';
        }

        if ( $needs_webp ) {
            return 'webp';
        }

        return '';
    }

    private function queue_status_label( $status ) {
        $labels = array(
            'pending' => '队列：排队中',
            'running' => '队列：处理中',
            'done'    => '队列：已完成',
            'failed'  => '队列：处理失败',
        );

        return isset( $labels[ $status ] ) ? $labels[ $status ] : '队列：未知状态';
    }

    private function get_queue_counts() {
        $this->fail_non_retryable_pending_queue_items();

        return array(
            'pending' => $this->count_queue_status( 'pending' ),
            'running' => $this->count_queue_status( 'running' ),
            'done'    => $this->count_queue_status( 'done' ),
            'failed'  => $this->count_queue_status( 'failed' ),
        );
    }

    private function count_queue_status( $status ) {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'   => self::META_QUEUE_STATUS,
                        'value' => $status,
                    ),
                ),
            )
        );

        return (int) $query->found_posts;
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

            do_action( 'maomomo_tinypng_attachment_processed', (int) $attachment_id, $mode, $summary );
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

        $was_auto_processing   = $this->auto_processing;
        $this->auto_processing = true;

        try {
            $metadata = wp_generate_attachment_metadata( $webp_id, $webp_path );
        } finally {
            $this->auto_processing = $was_auto_processing;
        }

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

    private function is_path_in_uploads( $path ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
        $path       = wp_normalize_path( $path );

        return 0 === strpos( $path, $base_dir );
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
            'auto_mode'     => 'none',
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

    private function format_enqueue_message( $mode, $queued, $failed, $skipped, $messages ) {
        $message = sprintf(
            'TinyPNG %s 已加入后台队列：排队 %d，失败 %d，跳过 %d。WP-Cron 会异步处理，可在媒体库 TinyPNG 列查看状态。',
            esc_html( $this->mode_label( $mode ) ),
            (int) $queued,
            (int) $failed,
            (int) $skipped
        );

        if ( ! empty( $messages ) ) {
            $items = array_slice( array_unique( $messages ), 0, 5 );
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
