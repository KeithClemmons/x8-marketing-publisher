<?php
/**
 * Auto-provisioning: notifies Netlify backend on activation so the API key
 * can be fetched programmatically without client involvement.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Provisioning {

	const MAX_ATTEMPTS = 5;

	/**
	 * Send a one-time notification to Netlify with the bootstrap token.
	 * Netlify then calls back to /x8/v1/provision with that token to retrieve the real API key.
	 */
	public function notify() : void {
		if ( get_option( 'x8_publisher_provisioned' ) ) {
			return; // Already provisioned.
		}

		$attempts = (int) get_option( 'x8_publisher_provision_attempts', 0 );
		$attempts++;
		update_option( 'x8_publisher_provision_attempts', $attempts );

		$bootstrap = get_option( 'x8_publisher_bootstrap_token_plain' );
		$expires   = (int) get_option( 'x8_publisher_bootstrap_expires', 0 );

		if ( empty( $bootstrap ) || time() > $expires ) {
			// Re-mint if expired and we still haven't been provisioned.
			if ( $attempts < self::MAX_ATTEMPTS ) {
				$this->refresh_bootstrap();
				$bootstrap = get_option( 'x8_publisher_bootstrap_token_plain' );
			} else {
				update_option( 'x8_publisher_provision_error', 'Bootstrap token expired and max attempts reached.' );
				return;
			}
		}

		$payload = [
            'username'        => get_option( 'x8_publisher_username', '' ),       // NEW: slugified business name
            'business_name'   => get_option( 'x8_publisher_business_name', '' ),  // NEW: original name for display
            'site_url'        => home_url(),
            'site_name'       => get_bloginfo( 'name' ),
            'admin_email'     => get_bloginfo( 'admin_email' ),
            'plugin_version'  => X8_PUBLISHER_VERSION,
            'wp_version'      => get_bloginfo( 'version' ),
            'rest_url'        => rest_url( X8_PUBLISHER_NAMESPACE ),
            'bootstrap_token' => $bootstrap,
            'expires_at'      => $expires,
        ];

        // Don't send if username isn't set yet (setup wizard not completed).
        if ( empty( $payload['username'] ) ) {
            update_option( 'x8_publisher_provision_error', 'Business Name not configured yet. Complete setup first.' );
            return;
        }

		$response = wp_remote_post(
			X8_PUBLISHER_BOOTSTRAP_URL,
			[
				'timeout'     => 15,
				'headers'     => [
					'Content-Type'    => 'application/json',
					'X-X8-Site-Hash' => $this->site_signature(),
				],
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			]
		);

		if ( is_wp_error( $response ) ) {
			update_option( 'x8_publisher_provision_error', $response->get_error_message() );
			$this->schedule_retry( $attempts );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			update_option( 'x8_publisher_provision_notified_at', current_time( 'mysql' ) );
			delete_option( 'x8_publisher_provision_error' );
			// Don't mark provisioned yet — wait until Netlify calls /provision endpoint.
		} else {
			update_option( 'x8_publisher_provision_error', "Netlify returned HTTP {$code}" );
			$this->schedule_retry( $attempts );
		}
	}

	/**
	 * Re-mint a bootstrap token if expired.
	 */
	private function refresh_bootstrap() : void {
		$token = bin2hex( random_bytes( 24 ) );
		update_option( 'x8_publisher_bootstrap_token', wp_hash_password( $token ) );
		update_option( 'x8_publisher_bootstrap_token_plain', $token );
		update_option( 'x8_publisher_bootstrap_expires', time() + ( 15 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Exponential backoff retry.
	 */
	private function schedule_retry( int $attempts ) : void {
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return;
		}
		$delay = min( 60 * pow( 2, $attempts ), HOUR_IN_SECONDS ); // 60s, 120s, 240s, 480s, 960s
		wp_schedule_single_event( time() + $delay, 'x8_publisher_provision' );
	}

	/**
	 * Validate bootstrap token presented by Netlify in /provision call.
	 *
	 * @return string|null Plaintext API key on success, null on failure.
	 */
	public function exchange_bootstrap( string $bootstrap_token ) : ?string {
		$stored  = get_option( 'x8_publisher_bootstrap_token' );
		$expires = (int) get_option( 'x8_publisher_bootstrap_expires', 0 );

		if ( empty( $stored ) || time() > $expires ) {
			return null;
		}

		if ( ! wp_check_password( $bootstrap_token, $stored ) ) {
			return null;
		}

		// Pull plaintext API key (only available during provisioning window).
		$plain    = get_option( 'x8_publisher_api_key_pending' );
		$pending_exp = (int) get_option( 'x8_publisher_api_key_pending_expires', 0 );

		if ( empty( $plain ) || time() > $pending_exp ) {
			return null;
		}

		// Consume both tokens — one-time use.
		delete_option( 'x8_publisher_bootstrap_token' );
		delete_option( 'x8_publisher_bootstrap_token_plain' );
		delete_option( 'x8_publisher_bootstrap_expires' );
		delete_option( 'x8_publisher_api_key_pending' );
		delete_option( 'x8_publisher_api_key_pending_expires' );

		update_option( 'x8_publisher_provisioned', 1 );
		update_option( 'x8_publisher_provisioned_at', current_time( 'mysql' ) );

		return $plain;
	}

	/**
	 * Site signature so Netlify can verify origin (HMAC of site_url with WP secret salts).
	 */
	private function site_signature() : string {
		$secret = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'x8-fallback-salt';
		return hash_hmac( 'sha256', home_url(), $secret );
	}
}