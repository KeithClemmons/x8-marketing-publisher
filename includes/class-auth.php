<?php
/**
 * Authentication & rate limiting.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Auth {

	const RATE_LIMIT_PER_MINUTE = 60;

	/**
	 * Validate API key from request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function validate( \WP_REST_Request $request ) {
		$auth = $request->get_header( 'authorization' );
		if ( empty( $auth ) ) {
			return new \WP_Error(
				'missing_api_key',
				'Authorization header missing.',
				[ 'status' => 401 ]
			);
		}

		if ( ! preg_match( '/Bearer\s+(.+)/i', $auth, $m ) ) {
			return new \WP_Error(
				'missing_api_key',
				'Malformed Authorization header. Expect: Bearer <key>.',
				[ 'status' => 401 ]
			);
		}

		$provided = trim( $m[1] );
		$stored   = get_option( 'x8_publisher_api_key' );

		if ( empty( $stored ) || ! wp_check_password( $provided, $stored ) ) {
			return new \WP_Error(
				'invalid_api_key',
				'Invalid API key.',
				[ 'status' => 401 ]
			);
		}

		// Rate limit.
		$rate = $this->check_rate_limit();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Track last successful request.
		update_option( 'x8_publisher_last_request_at', current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Per-IP rate limiting via transients.
	 *
	 * @return true|\WP_Error
	 */
	public function check_rate_limit() {
		$ip  = $this->get_client_ip();
		$key = 'x8_pub_rl_' . md5( $ip );

		$data = get_transient( $key );
		if ( false === $data || ! is_array( $data ) || ! isset( $data['count'], $data['expires'] ) ) {
			$data = [
				'count'   => 1,
				'expires' => time() + MINUTE_IN_SECONDS,
			];
			set_transient( $key, $data, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $data['count'] >= self::RATE_LIMIT_PER_MINUTE ) {
			return new \WP_Error(
				'rate_limited',
				'Too many requests. Limit: ' . self::RATE_LIMIT_PER_MINUTE . '/min.',
				[ 'status' => 429 ]
			);
		}

		$data['count']++;
		$remaining = $data['expires'] - time();
		if ( $remaining > 0 ) {
			set_transient( $key, $data, $remaining );
		} else {
			$data = [
				'count'   => 1,
				'expires' => time() + MINUTE_IN_SECONDS,
			];
			set_transient( $key, $data, MINUTE_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Resolve client IP.
	 *
	 * @return string
	 */
	public function get_client_ip() : string {
		$candidates = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}