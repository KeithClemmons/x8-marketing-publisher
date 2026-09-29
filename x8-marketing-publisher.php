<?php
/**
 * Plugin Name: X8 Marketing Publisher
 * Plugin URI:  https://x8marketing.com
 * Description: Publishes content from the X8 Marketing dashboard with full SEO meta support. Auto-provisions with Netlify on activation.
 * Version:     1.4.0
 * Requires PHP: 7.4
 * Requires at least: 6.5
 * Author:      X8 Marketing
 * Author URI:  https://x8marketing.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: x8-marketing-publisher
 */

namespace X8Marketing\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'X8_PUBLISHER_VERSION', '1.4.0' );
define( 'X8_PUBLISHER_FILE', __FILE__ );
define( 'X8_PUBLISHER_DIR', plugin_dir_path( __FILE__ ) );
define( 'X8_PUBLISHER_URL', plugin_dir_url( __FILE__ ) );
define( 'X8_PUBLISHER_NAMESPACE', 'x8/v1' );
define( 'X8_PUBLISHER_LOGS_TABLE', 'x8_publisher_logs' );

// Default Netlify provisioning endpoint — override in wp-config.php with:
// define( 'X8_PUBLISHER_BOOTSTRAP_URL', 'https://your-app.netlify.app/.netlify/functions/x8-provision' );
if ( ! defined( 'X8_PUBLISHER_BOOTSTRAP_URL' ) ) {
	define( 'X8_PUBLISHER_BOOTSTRAP_URL', 'https://app.x8webdesign.com/.netlify/functions/x8-provision' );
}

require_once X8_PUBLISHER_DIR . 'includes/class-auth.php';
require_once X8_PUBLISHER_DIR . 'includes/class-image-handler.php';
require_once X8_PUBLISHER_DIR . 'includes/class-seo-handler.php';
require_once X8_PUBLISHER_DIR . 'includes/class-publisher.php';
require_once X8_PUBLISHER_DIR . 'includes/class-provisioning.php';
require_once X8_PUBLISHER_DIR . 'includes/class-divi.php';
require_once X8_PUBLISHER_DIR . 'includes/class-rest-api.php';
require_once X8_PUBLISHER_DIR . 'includes/class-admin.php';

/**
 * Main plugin bootstrap.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 */
	public static function instance() : Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( X8_PUBLISHER_FILE, [ $this, 'on_activate' ] );
		register_deactivation_hook( X8_PUBLISHER_FILE, [ $this, 'on_deactivate' ] );

		add_action( 'plugins_loaded', [ $this, 'init' ] );
		add_action( 'x8_publisher_cleanup_logs', [ $this, 'cleanup_old_logs' ] );
		add_action( 'x8_publisher_provision', [ $this, 'run_provisioning' ] );
	}

	/**
	 * Init plugin components.
	 */
	public function init() : void {
		( new REST_API() )->register();
		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	/**
	 * Activation:
	 *  - Create logs table
	 *  - Generate API key (hashed at rest)
	 *  - Mint one-time bootstrap token
	 *  - Set defaults
	 *  - Set redirect transient so user lands on the setup wizard
	 *
	 * NOTE: Provisioning is NOT scheduled here. It only fires after the
	 * client submits their Business Name in the setup wizard.
	 */
	public function on_activate() : void {
		$this->create_logs_table();

		// Generate API key once (hashed at rest).
		if ( ! get_option( 'x8_publisher_api_key' ) ) {
			$plain = bin2hex( random_bytes( 32 ) );
			update_option( 'x8_publisher_api_key', wp_hash_password( $plain ) );
			update_option( 'x8_publisher_api_key_last4', substr( $plain, -4 ) );

			// Stash plaintext temporarily for ONE-TIME provisioning to Netlify.
			// Cleared automatically after successful provisioning OR after 1 hour.
			update_option( 'x8_publisher_api_key_pending', $plain );
			update_option( 'x8_publisher_api_key_pending_expires', time() + HOUR_IN_SECONDS );
		}

		// Generate one-time bootstrap token (15 min TTL) for Netlify exchange.
		$this->mint_bootstrap_token();

		// Defaults.
		add_option( 'x8_publisher_default_status', 'draft' );
		add_option( 'x8_publisher_default_author', get_current_user_id() ?: 1 );
		add_option( 'x8_publisher_default_category', 0 );
		add_option( 'x8_publisher_sideload_images', 1 );
		add_option( 'x8_publisher_provisioned', 0 );
		add_option( 'x8_publisher_provision_attempts', 0 );

		// Schedule daily log cleanup.
		if ( ! wp_next_scheduled( 'x8_publisher_cleanup_logs' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'x8_publisher_cleanup_logs' );
		}

		// Set redirect transient — Admin::maybe_redirect_to_setup() picks this up
		// on the next admin page load and sends the user to the setup wizard.
		set_transient( 'x8_publisher_activation_redirect', 1, 30 );

		// IMPORTANT: We deliberately do NOT schedule x8_publisher_provision here.
		// Provisioning is gated on the user submitting their Business Name in
		// the setup wizard, which is what links this WP install to the correct
		// Social Connector profile (username). Without that username, Netlify
		// can't route the credentials to the right tenant.
		//
		// The provisioning cron is scheduled inside Admin::handle_actions()
		// when the 'save_business_name' action is processed.
	}

	/**
	 * Deactivation: clear scheduled crons.
	 */
	public function on_deactivate() : void {
		$timestamp = wp_next_scheduled( 'x8_publisher_cleanup_logs' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'x8_publisher_cleanup_logs' );
		}

		$prov = wp_next_scheduled( 'x8_publisher_provision' );
		if ( $prov ) {
			wp_unschedule_event( $prov, 'x8_publisher_provision' );
		}

		// Clear any pending activation redirect so reactivation behaves cleanly.
		delete_transient( 'x8_publisher_activation_redirect' );
	}

	/**
	 * Mint a one-time bootstrap token used by Netlify to exchange for the real API key.
	 */
	private function mint_bootstrap_token() : void {
		$token = bin2hex( random_bytes( 24 ) );
		update_option( 'x8_publisher_bootstrap_token', wp_hash_password( $token ) );
		update_option( 'x8_publisher_bootstrap_token_plain', $token ); // Cleared after first use or expiry.
		update_option( 'x8_publisher_bootstrap_expires', time() + ( 15 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Provisioning attempt (cron-driven). Notifies Netlify that this site is ready.
	 * Triggered by the 'x8_publisher_provision' cron, which is scheduled only
	 * AFTER the client completes the setup wizard.
	 */
	public function run_provisioning() : void {
		$prov = new Provisioning();
		$prov->notify();
	}

	/**
	 * Create logs table.
	 */
	private function create_logs_table() : void {
		global $wpdb;
		$table   = $wpdb->prefix . X8_PUBLISHER_LOGS_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			request_id VARCHAR(64) NOT NULL,
			endpoint VARCHAR(100) NOT NULL,
			status_code INT NOT NULL,
			post_id BIGINT UNSIGNED NULL,
			post_title VARCHAR(300) NULL,
			error_message TEXT NULL,
			ip_address VARCHAR(45) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_created_at (created_at),
			INDEX idx_request_id (request_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Daily log cleanup — keep last 30 days.
	 */
	public function cleanup_old_logs() : void {
		global $wpdb;
		$table = $wpdb->prefix . X8_PUBLISHER_LOGS_TABLE;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
			)
		);
	}
}

Plugin::instance();