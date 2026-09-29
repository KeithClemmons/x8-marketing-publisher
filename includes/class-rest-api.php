<?php
/**
 * REST API routes.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_API {

	private $auth;
	private $publisher;

	public function __construct() {
		$this->auth      = new Auth();
		$this->publisher = new Publisher();
	}

	public function register() : void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function permission( \WP_REST_Request $request ) {
		return $this->auth->validate( $request );
	}

	public function register_routes() : void {
		register_rest_route( X8_PUBLISHER_NAMESPACE, '/publish', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_publish' ],
			'permission_callback' => [ $this, 'permission' ],
		] );

		register_rest_route( X8_PUBLISHER_NAMESPACE, '/post/(?P<id>\d+)/status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => [ $this, 'permission' ],
			'args'                => [
				'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0 ],
			],
		] );

		register_rest_route( X8_PUBLISHER_NAMESPACE, '/post/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'handle_delete' ],
			'permission_callback' => [ $this, 'permission' ],
			'args'                => [
				'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0 ],
			],
		] );

		register_rest_route( X8_PUBLISHER_NAMESPACE, '/health', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_health' ],
			'permission_callback' => [ $this, 'permission' ],
		] );

		register_rest_route( X8_PUBLISHER_NAMESPACE, '/test-connection', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_test_connection' ],
			'permission_callback' => [ $this, 'permission' ],
		] );

		// NEW: Public ping endpoint (no auth) — lets the admin "Test Connection" button
		// verify the route exists without exposing the key in the browser.
		register_rest_route( X8_PUBLISHER_NAMESPACE, '/ping', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_ping' ],
			'permission_callback' => '__return_true',
		] );

		// Divi support for the control center's agents (see class-divi.php).
		( new Divi( $this->auth ) )->register_routes();

		// NEW: Provisioning exchange endpoint — Netlify calls this with bootstrap token.
		register_rest_route( X8_PUBLISHER_NAMESPACE, '/provision', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_provision' ],
			'permission_callback' => '__return_true', // Auth is via bootstrap token in body.
		] );
	}

	/**
	 * NEW: Lightweight ping (no auth) for client-side admin status check.
	 */
	public function handle_ping() {
		return new \WP_REST_Response( [
			'success'        => true,
			'plugin'         => 'x8-marketing-publisher',
			'plugin_version' => X8_PUBLISHER_VERSION,
			'provisioned'    => (bool) get_option( 'x8_publisher_provisioned' ),
			'features'       => [ 'divi' => null !== Divi::version() ],
			'timestamp'      => current_time( 'mysql' ),
		], 200 );
	}

	/**
	 * NEW: Exchange bootstrap token for real API key.
	 * Body: { "bootstrap_token": "..." }
	 */
	public function handle_provision( \WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$token = isset( $body['bootstrap_token'] ) ? sanitize_text_field( $body['bootstrap_token'] ) : '';

		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_token', 'bootstrap_token required.', [ 'status' => 400 ] );
		}

		$prov   = new Provisioning();
		$apikey = $prov->exchange_bootstrap( $token );

		if ( null === $apikey ) {
			return new \WP_Error(
				'invalid_or_expired_token',
				'Bootstrap token invalid or expired.',
				[ 'status' => 401 ]
			);
		}

		return new \WP_REST_Response( [
			'success'  => true,
			'api_key'  => $apikey,
			'site_url' => home_url(),
			'rest_url' => rest_url( X8_PUBLISHER_NAMESPACE ),
		], 200 );
	}

	// --- existing handle_publish / handle_status / handle_delete / handle_health / handle_test_connection / sanitize_payload / log_request methods unchanged from v1.0.0 ---
	// (Keep all the methods from the previous version exactly as they were.)

	public function handle_publish( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$err = new \WP_Error( 'validation_failed', 'JSON body required.', [ 'status' => 400 ] );
			$this->log_request( $request, '/publish', 400, null, $err->get_error_message() );
			return $err;
		}

		$validated = $this->publisher->validate_payload( $data );
		if ( is_wp_error( $validated ) ) {
			$this->log_request( $request, '/publish', 400, null, $validated->get_error_message() );
			return $validated;
		}

		$sanitized = $this->sanitize_payload( $data );
		$result    = $this->publisher->create_post( $sanitized );

		if ( is_wp_error( $result ) ) {
			$status = $result->get_error_data()['status'] ?? 500;
			$this->log_request( $request, '/publish', $status, null, $result->get_error_message() );
			return $result;
		}

		$response = [
			'success'    => true,
			'request_id' => $sanitized['request_id'] ?? null,
			'post'       => [
				'id'       => $result['id'],
				'url'      => $result['url'],
				'edit_url' => $result['edit_url'],
				'status'   => $result['status'],
			],
			'media'      => $result['media'],
			'seo'        => $result['seo'],
		];

		$this->log_request( $request, '/publish', 201, $result['id'], null, $sanitized['title'] ?? null, $sanitized['request_id'] ?? null );
		return new \WP_REST_Response( $response, 201 );
	}

	public function handle_status( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$body    = $request->get_json_params();
		$status  = isset( $body['status'] ) ? sanitize_key( $body['status'] ) : '';

		$result = $this->publisher->update_status( $post_id, $status );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_data()['status'] ?? 500;
			$this->log_request( $request, '/post/status', $code, $post_id, $result->get_error_message() );
			return $result;
		}

		$this->log_request( $request, '/post/status', 200, $post_id, null );
		return new \WP_REST_Response( [ 'success' => true, 'post' => $result ], 200 );
	}

	public function handle_delete( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$result  = $this->publisher->trash_post( $post_id );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_data()['status'] ?? 500;
			$this->log_request( $request, '/post/delete', $code, $post_id, $result->get_error_message() );
			return $result;
		}
		$this->log_request( $request, '/post/delete', 200, $post_id, null );
		return new \WP_REST_Response( [ 'success' => true, 'post' => $result ], 200 );
	}

	public function handle_health( \WP_REST_Request $request ) {
		$seo = new SEO_Handler();
		$payload = [
			'success'        => true,
			'plugin_version' => X8_PUBLISHER_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'seo_engine'     => $seo->detect_engine(),
			'rankmath'       => $seo->has_rankmath(),
			'yoast'          => $seo->has_yoast(),
			'site_url'       => home_url(),
			'provisioned'    => (bool) get_option( 'x8_publisher_provisioned' ),
			'timestamp'      => current_time( 'mysql' ),
		];
		$this->log_request( $request, '/health', 200, null, null );
		return new \WP_REST_Response( $payload, 200 );
	}

	public function handle_test_connection( \WP_REST_Request $request ) {
		$this->log_request( $request, '/test-connection', 200, null, null );
		return $this->handle_health( $request );
	}

	/**
 * Sanitize publish payload — accepts both legacy (nested seo) and
 * new flat-field formats from the Social Connector API.
 */
private function sanitize_payload( array $data ) : array {
	$out = [];

	// Core fields (unchanged from before)
	$out['request_id']        = isset( $data['request_id'] ) ? sanitize_text_field( $data['request_id'] ) : '';
	$out['title']             = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
	$out['content']           = isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '';
	$out['status']            = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';
	$out['categories']        = isset( $data['categories'] ) && is_array( $data['categories'] )
		? array_map( 'sanitize_text_field', $data['categories'] ) : [];
	$out['tags']              = isset( $data['tags'] ) && is_array( $data['tags'] )
		? array_map( 'sanitize_text_field', $data['tags'] ) : [];

	// SLUG — accept top-level "slug", run through sanitize_title()
	$out['slug'] = ! empty( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';

	// FEATURED IMAGE — accept BOTH "featured_image_url" (new) and "feature_image_url" (legacy)
	$featured_image = '';
	if ( ! empty( $data['featured_image_url'] ) ) {
		$featured_image = $data['featured_image_url'];
	} elseif ( ! empty( $data['feature_image_url'] ) ) {
		$featured_image = $data['feature_image_url'];
	}
	$out['feature_image_url'] = $featured_image ? esc_url_raw( $featured_image ) : '';

	// SEO FIELDS — accept both top-level (new) and nested seo.* (legacy)
	$seo = isset( $data['seo'] ) && is_array( $data['seo'] ) ? $data['seo'] : [];

	// Resolve focus keyword (supports singular/plural, top-level/nested, string/array formats)
	$focus_kw = null;
	if ( ! empty( $data['focus_keyword'] ) ) {
		$focus_kw = $data['focus_keyword'];
	} elseif ( ! empty( $data['focus_keywords'] ) ) {
		$focus_kw = $data['focus_keywords'];
	} elseif ( ! empty( $seo['focus_keyword'] ) ) {
		$focus_kw = $seo['focus_keyword'];
	} elseif ( ! empty( $seo['focus_keywords'] ) ) {
		$focus_kw = $seo['focus_keywords'];
	}

	if ( is_array( $focus_kw ) ) {
		$focus_kw = array_map( 'sanitize_text_field', $focus_kw );
		$focus_kw = implode( ', ', array_filter( $focus_kw ) );
	} elseif ( is_string( $focus_kw ) ) {
		$focus_kw = sanitize_text_field( $focus_kw );
	} else {
		$focus_kw = null;
	}

	$out['seo'] = [
		'focus_keyword'    => $focus_kw,

		// Meta title: only nested (not in new spec, but keep for compatibility)
		'meta_title'       => isset( $seo['meta_title'] ) ? sanitize_text_field( $seo['meta_title'] ) : null,

		// Meta description: top-level "meta_description" wins, fall back to seo.meta_description
		'meta_description' => $this->pick_field( $data, $seo, 'meta_description', 'sanitize_textarea_field' ),

		// Other SEO fields stay nested-only (no flat-field equivalents in new spec)
		'canonical_url'    => isset( $seo['canonical_url'] ) ? esc_url_raw( $seo['canonical_url'] ) : null,
		'og_title'         => isset( $seo['og_title'] ) ? sanitize_text_field( $seo['og_title'] ) : null,
		'og_description'   => isset( $seo['og_description'] ) ? sanitize_textarea_field( $seo['og_description'] ) : null,
		'og_image_url'     => isset( $seo['og_image_url'] ) ? esc_url_raw( $seo['og_image_url'] ) : null,
	];

	return $out;
}

/**
 * Helper: pick a field from top-level OR nested seo object, sanitize it.
 * Top-level value takes priority if both are present.
 */
private function pick_field( array $top_level, array $nested, string $field, string $sanitizer ) {
	if ( ! empty( $top_level[ $field ] ) ) {
		return call_user_func( $sanitizer, $top_level[ $field ] );
	}
	if ( ! empty( $nested[ $field ] ) ) {
		return call_user_func( $sanitizer, $nested[ $field ] );
	}
	return null;
}
	private function log_request( \WP_REST_Request $request, string $endpoint, int $status_code, ?int $post_id, ?string $error, ?string $title = null, ?string $request_id = null ) : void {
		global $wpdb;
		$table = $wpdb->prefix . X8_PUBLISHER_LOGS_TABLE;

		if ( null === $request_id ) {
			$body       = $request->get_json_params();
			$request_id = is_array( $body ) && ! empty( $body['request_id'] ) ? sanitize_text_field( $body['request_id'] ) : '';
		}

		$wpdb->insert(
			$table,
			[
				'request_id'    => $request_id,
				'endpoint'      => $endpoint,
				'status_code'   => $status_code,
				'post_id'       => $post_id,
				'post_title'    => $title ? mb_substr( $title, 0, 300 ) : null,
				'error_message' => $error,
				'ip_address'    => $this->auth->get_client_ip(),
				'created_at'    => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
	}
}