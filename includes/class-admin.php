<?php
/**
 * Admin page + branding + setup wizard.
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const MENU_SLUG = 'x8-marketing-publisher';

	public function register() : void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_setup_notice' ] );

		// Redirect to setup wizard on activation if business_name not set.
		add_action( 'admin_init', [ $this, 'maybe_redirect_to_setup' ] );
	}

	public function add_menu() : void {
		add_menu_page(
			'X8 Marketing Publisher',
			'X8 Publisher',
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			$this->menu_icon_data_uri(),
			30
		);
	}

	private function menu_icon_data_uri() : string {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 20" fill="#a7aaad">'
		. '<text x="12" y="15" font-family="Arial Black,Arial,sans-serif" font-size="15" font-weight="900" text-anchor="middle" textLength="20" lengthAdjust="spacingAndGlyphs">X8</text>'
		. '</svg>';
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }
	/**
	 * On activation, redirect to setup wizard if business name not yet set.
	 */
	public function maybe_redirect_to_setup() : void {
		if ( ! get_transient( 'x8_publisher_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'x8_publisher_activation_redirect' );

		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( get_option( 'x8_publisher_business_name' ) ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&setup=1' ) );
			exit;
		}
	}

	/**
	 * Persistent admin notice if setup is incomplete.
	 */
	public function maybe_show_setup_notice() : void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( ! empty( get_option( 'x8_publisher_business_name' ) ) ) return;

		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, self::MENU_SLUG ) ) return; // Don't double-up on plugin page.

		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&setup=1' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong>X8 Marketing Publisher:</strong>
				Setup needed —
				<a href="<?php echo esc_url( $url ); ?>">connect to your X8 Marketing Dashboard</a>
				to start publishing.
			</p>
		</div>
		<?php
	}

	public function enqueue_assets( $hook ) : void {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) return;
		wp_add_inline_style( 'common', $this->css() );
	}

	private function css() : string {
		return <<<CSS
		.x8-screen-reader-title {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
    .x8-dashboard-cta {
	margin-top: 20px;
	padding: 20px;
	background: linear-gradient(135deg, #000 0%, #1a1a1a 100%);
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 20px;
	flex-wrap: wrap;
        }
        .x8-dashboard-cta-text {
            color: #fff;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .x8-dashboard-cta-text strong {
            font-size: 16px;
            color: #fff;
        }
        .x8-dashboard-cta-text span {
            font-size: 13px;
            color: #aaa;
        }
        .x8-dashboard-cta .button {
            flex-shrink: 0;
        }
        .x8-wrap { max-width: 1100px; margin-top: 20px; }
		.x8-header {
			background: #000; color: #fff; padding: 24px 28px;
			border-radius: 8px 8px 0 0; display: flex;
			align-items: center; gap: 16px;
            justify-content: space-between;  /* ← add this */
        }
        .x8-header > svg { flex-shrink: 0; }
        .x8-header > h1 { flex: 1; }
        .x8-header-actions {
            margin-left: auto;
        }
        .x8-header-link {
            color: #22c55e !important;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 16px;
            border: 1px solid #22c55e;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .x8-header-link:hover {
            background: #22c55e;
            color: #000 !important;
        }
		.x8-header svg { height: 38px; width: auto; }
		.x8-header h1 {
			color: #fff; margin: 0; font-size: 18px; font-weight: 400;
			border-left: 1px solid #444; padding-left: 16px; line-height: 1.2;
		}
		.x8-header h1 span { color: #22c55e; font-weight: 700; display: block; font-size: 14px; }
		.x8-card {
			background: #fff; border: 1px solid #e0e0e0; border-top: none;
			padding: 24px 28px; margin: 0;
		}
		.x8-card + .x8-card { border-top: 1px solid #f0f0f0; }
		.x8-card:last-child { border-radius: 0 0 8px 8px; }
		.x8-card h2 {
			margin: 0 0 16px; font-size: 16px; color: #000;
			display: flex; align-items: center; gap: 10px;
		}
		.x8-card h2::before {
			content: ''; width: 4px; height: 18px;
			background: #22c55e; border-radius: 2px;
		}
		.x8-status-row { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
		.x8-status-dot {
			display: inline-block; width: 14px; height: 14px;
			border-radius: 50%; box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
		}
		.x8-status-green { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.2); }
		.x8-status-red   { background: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,0.2); }
		.x8-status-amber { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.2); }
		.x8-status-text { font-weight: 600; font-size: 15px; }
		.x8-meta { color: #666; font-size: 13px; margin-top: 4px; }
		.x8-btn {
			background: #22c55e !important; border-color: #16a34a !important;
			color: #fff !important; text-shadow: none !important;
			box-shadow: 0 1px 0 #15803d !important; font-weight: 600;
		}
		.x8-btn:hover { background: #16a34a !important; }
		.x8-btn-large {
			font-size: 15px !important; padding: 8px 24px !important;
			height: auto !important; line-height: 1.4 !important;
		}
		.x8-setup-card {
			background: #fff; border: 1px solid #e0e0e0; border-top: none;
			padding: 40px; border-radius: 0 0 8px 8px;
		}
		.x8-setup-card h2 { font-size: 22px; margin: 0 0 8px; }
		.x8-setup-card .x8-lead {
			color: #555; font-size: 14px; margin: 0 0 24px; line-height: 1.6;
		}
		.x8-setup-input {
			width: 100%; max-width: 500px; padding: 12px 14px !important;
			font-size: 15px !important; border: 2px solid #e0e0e0 !important;
			border-radius: 6px !important;
		}
		.x8-setup-input:focus { border-color: #22c55e !important; box-shadow: 0 0 0 3px rgba(34,197,94,0.15) !important; }
		.x8-slug-preview {
			background: #f6f7f7; padding: 10px 14px; border-radius: 4px;
			font-family: monospace; font-size: 13px; color: #555;
			max-width: 500px; margin: 8px 0 20px;
		}
		.x8-slug-preview .accent { color: #22c55e; font-weight: 700; }
		.x8-help {
			background: #f0fdf4; border-left: 3px solid #22c55e;
			padding: 12px 16px; margin: 20px 0; max-width: 500px;
			font-size: 13px; color: #15803d; border-radius: 4px;
		}
		table.x8-logs { width: 100%; border-collapse: collapse; }
		table.x8-logs th {
			background: #fafafa; text-align: left; padding: 10px 12px;
			font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em;
			color: #555; border-bottom: 2px solid #22c55e;
		}
		table.x8-logs td { padding: 10px 12px; font-size: 13px; border-bottom: 1px solid #f0f0f0; }
		.x8-badge { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 700; }
		.x8-badge-ok { background: #dcfce7; color: #15803d; }
		.x8-badge-err { background: #fee2e2; color: #991b1b; }
		.x8-info-grid { display: grid; grid-template-columns: 180px 1fr; gap: 8px 16px; font-size: 13px; }
		.x8-info-grid .label { color: #666; }
		.x8-info-grid code { background: #f6f7f7; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
		.x8-test-result { margin-left: 12px; font-weight: 600; font-size: 13px; }
		.x8-footer { text-align: center; padding: 16px; color: #999; font-size: 12px; }
		.x8-footer .accent { color: #22c55e; font-weight: 700; }
		.x8-business-display {
			background: #f0fdf4; border: 1px solid #22c55e;
			padding: 12px 16px; border-radius: 6px; display: inline-flex;
			align-items: center; gap: 12px; font-size: 14px;
		}
		.x8-business-display strong { color: #15803d; }
CSS;
	}

	/**
 * Render the X8 Marketing logo as an <img> tag.
 *
 * @param int $height Logo height in pixels.
 * @return string
 */
public static function logo_svg( int $height = 38 ) : string {
	$url = X8_PUBLISHER_URL . 'assets/logo.png';
	return sprintf(
		'<img src="%s" alt="X8 Marketing" height="%d" style="height:%dpx;width:auto;display:block;">',
		esc_url( $url ),
		$height,
		$height
	);
}

	/**
	 * Slugify a business name to match Social Connector's `username` format.
	 * "Acme Plumbing & Co." -> "acme_plumbing_co"
	 */
	public static function slugify_business_name( string $name ) : string {
		$slug = strtolower( trim( $name ) );
		// Replace any non-alphanumeric run with single underscore.
		$slug = preg_replace( '/[^a-z0-9]+/', '_', $slug );
		// Trim leading/trailing underscores.
		$slug = trim( $slug, '_' );
		// Collapse multiple underscores.
		$slug = preg_replace( '/_+/', '_', $slug );
		return $slug;
	}

	public function handle_actions() : void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_POST['x8_action'] ) || empty( $_POST['_x8_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_x8_nonce'] ) ), 'x8_publisher_admin' ) ) return;

		$action = sanitize_key( wp_unslash( $_POST['x8_action'] ) );

		switch ( $action ) {
			case 'save_business_name':
				$business_name = isset( $_POST['business_name'] )
					? sanitize_text_field( wp_unslash( $_POST['business_name'] ) )
					: '';

				if ( empty( $business_name ) || strlen( $business_name ) < 2 ) {
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&setup=1&error=name_required' ) );
					exit;
				}
				if ( strlen( $business_name ) > 100 ) {
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&setup=1&error=name_too_long' ) );
					exit;
				}

				$username = self::slugify_business_name( $business_name );
				if ( empty( $username ) ) {
					wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&setup=1&error=invalid_chars' ) );
					exit;
				}

				update_option( 'x8_publisher_business_name', $business_name );
				update_option( 'x8_publisher_username', $username );

				// NOW kick off provisioning since we have everything we need.
				wp_schedule_single_event( time() + 2, 'x8_publisher_provision' );

				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&setup=complete' ) );
				exit;

                case 'open_dashboard':
                    $result = self::fetch_magic_link();

                    if ( is_wp_error( $result ) ) {
                        wp_die(
                            '<h2>Magic link failed</h2>'
                            . '<p><strong>Error:</strong> ' . esc_html( $result->get_error_message() ) . '</p>'
                            . '<p><strong>Endpoint tried:</strong> https://app.x8webdesign.com/.netlify/functions/api/generate-magic-link</p>'
                            . '<p><strong>Site URL sent:</strong> ' . esc_html( home_url() ) . '</p>'
                            . '<p><strong>Username sent:</strong> ' . esc_html( get_option( 'x8_publisher_username' ) ) . '</p>'
                            . '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">← Back to plugin</a></p>',
                            'X8 Magic Link Debug'
                        );
                        exit; // ← ADDED: prevent fall-through
                    }

                    wp_redirect( $result ); // ← CHANGED from wp_safe_redirect
                    exit;
                
			case 'reprovision':
				$plain = bin2hex( random_bytes( 32 ) );
				update_option( 'x8_publisher_api_key', wp_hash_password( $plain ) );
				update_option( 'x8_publisher_api_key_last4', substr( $plain, -4 ) );
				update_option( 'x8_publisher_api_key_pending', $plain );
				update_option( 'x8_publisher_api_key_pending_expires', time() + HOUR_IN_SECONDS );

				$token = bin2hex( random_bytes( 24 ) );
				update_option( 'x8_publisher_bootstrap_token', wp_hash_password( $token ) );
				update_option( 'x8_publisher_bootstrap_token_plain', $token );
				update_option( 'x8_publisher_bootstrap_expires', time() + ( 15 * MINUTE_IN_SECONDS ) );

				update_option( 'x8_publisher_provisioned', 0 );
				update_option( 'x8_publisher_provision_attempts', 0 );

				do_action( 'x8_publisher_provision' ); // fire NOW, synchronously
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&reprovisioned=1' ) );
				exit;

			case 'change_business_name':
				delete_option( 'x8_publisher_business_name' );
				delete_option( 'x8_publisher_username' );
				update_option( 'x8_publisher_provisioned', 0 );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&setup=1' ) );
				exit;

			case 'save_settings':
				update_option( 'x8_publisher_default_status', sanitize_key( wp_unslash( $_POST['default_status'] ?? 'draft' ) ) );
				update_option( 'x8_publisher_default_author', (int) ( $_POST['default_author'] ?? 1 ) );
				update_option( 'x8_publisher_sideload_images', ! empty( $_POST['sideload_images'] ) ? 1 : 0 );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&saved=1' ) );
				exit;
		}
	}

	/**
 * Fetch a one-time magic link URL from the X8 Marketing Dashboard.
 * Returns the URL string on success, or WP_Error on failure.
 */
public static function fetch_magic_link() {
	$site_url = home_url();
	$endpoint = 'https://app.x8webdesign.com/.netlify/functions/api/generate-magic-link';

	$response = wp_remote_post(
		$endpoint,
		[
			'timeout' => 10,
			'headers' => [
				'Content-Type'  => 'application/json',
				'X-X8-Site-URL' => $site_url,
			],
			'body'    => wp_json_encode( [
				'site_url' => $site_url,
				'username' => get_option( 'x8_publisher_username' ),
			] ),
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new \WP_Error(
			'magic_link_failed',
			'Dashboard returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response )
		);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['url'] ) ) {
		return new \WP_Error( 'magic_link_failed', 'No URL in response' );
	}

	return esc_url_raw( $body['url'] );
}
    
    public function render_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$business_name = get_option( 'x8_publisher_business_name' );
		$show_setup    = empty( $business_name ) || ! empty( $_GET['setup'] );

		if ( $show_setup && empty( $_GET['setup_complete'] ) && 'complete' !== ( $_GET['setup'] ?? '' ) ) {
			require X8_PUBLISHER_DIR . 'admin/setup-wizard.php';
		} else {
			require X8_PUBLISHER_DIR . 'admin/settings-page.php';
		}
	}

	public function get_recent_logs( int $limit = 20 ) : array {
		global $wpdb;
		$table = $wpdb->prefix . X8_PUBLISHER_LOGS_TABLE;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ) // phpcs:ignore
		);
	}
}