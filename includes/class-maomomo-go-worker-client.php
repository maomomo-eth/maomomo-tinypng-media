<?php
/**
 * Go 常驻 Worker 的本机 HTTP 客户端。
 *
 * @package MaoMoMo_TinyPNG_Media
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MaoMoMo_Go_Worker_Client {
    const HEADER_TIMESTAMP = 'X-Maomomo-Timestamp';
    const HEADER_NONCE     = 'X-Maomomo-Nonce';
    const HEADER_SIGNATURE = 'X-Maomomo-Signature';

    private $base_url;
    private $secret;

    public function __construct( $base_url, $secret ) {
        $this->base_url = untrailingslashit( trim( (string) $base_url ) );
        $this->secret   = trim( (string) $secret );
    }

    public function is_configured() {
        return $this->is_loopback_url( $this->base_url ) && strlen( $this->secret ) >= 32;
    }

    public function health() {
        if ( ! $this->is_loopback_url( $this->base_url ) ) {
            return new WP_Error( 'maomomo_go_worker_url', 'Go Worker 地址必须使用 127.0.0.1、localhost 或 ::1。' );
        }

        $response = wp_remote_get(
            $this->base_url . '/healthz',
            array(
                'timeout'     => 2,
                'redirection' => 0,
            )
        );

        return $this->decode_response( $response );
    }

    public function enqueue( $job ) {
        return $this->request( 'POST', '/v1/jobs', $job, 5 );
    }

    public function results( $site_id ) {
        $path = '/v1/results?site_id=' . rawurlencode( (string) $site_id );
        return $this->request( 'GET', $path, null, 5 );
    }

    public function ack( $site_id, $job_ids ) {
        return $this->request(
            'POST',
            '/v1/ack',
            array(
                'site_id' => (string) $site_id,
                'job_ids' => array_values( array_map( 'strval', (array) $job_ids ) ),
            ),
            5
        );
    }

    private function request( $method, $request_uri, $payload, $timeout ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'maomomo_go_worker_config', 'Go Worker 地址或共享密钥尚未正确配置。' );
        }

        $method    = strtoupper( (string) $method );
        $body      = null === $payload ? '' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $timestamp = (string) time();
        $nonce     = wp_generate_password( 36, false, false );
        $message   = $method . "\n" . $request_uri . "\n" . $timestamp . "\n" . $nonce . "\n" . $body;
        $signature = hash_hmac( 'sha256', $message, $this->secret );
        $args      = array(
            'method'      => $method,
            'timeout'     => max( 1, (int) $timeout ),
            'redirection' => 0,
            'headers'     => array(
                'Content-Type'          => 'application/json',
                self::HEADER_TIMESTAMP  => $timestamp,
                self::HEADER_NONCE      => $nonce,
                self::HEADER_SIGNATURE  => $signature,
            ),
        );

        if ( '' !== $body ) {
            $args['body'] = $body;
        }

        return $this->decode_response( wp_remote_request( $this->base_url . $request_uri, $args ) );
    }

    private function decode_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'maomomo_go_worker_connection',
                '无法连接 Go Worker：' . $response->get_error_message(),
                array( 'retryable' => true )
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        $data   = json_decode( $body, true );
        if ( $status < 200 || $status >= 300 ) {
            $message = is_array( $data ) && ! empty( $data['error'] )
                ? sanitize_text_field( $data['error'] )
                : 'Go Worker 请求失败，状态码：' . $status;
            return new WP_Error(
                'maomomo_go_worker_http_' . $status,
                $message,
                array(
                    'status'    => $status,
                    'retryable' => $status >= 500,
                )
            );
        }

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'maomomo_go_worker_json', 'Go Worker 返回了无效 JSON。' );
        }

        return $data;
    }

    private function is_loopback_url( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = strtolower( trim( $parts['host'], '[]' ) );
        return in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true );
    }
}
