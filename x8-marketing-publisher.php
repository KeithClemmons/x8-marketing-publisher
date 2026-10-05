<?php
/**
 * Plugin Name: X8 Marketing Publisher
 * Plugin URI:  https://x8marketing.com
 * Description: Publishes content from the X8 Marketing dashboard with full SEO meta support. Auto-provisions with Netlify on activation.
 * Version:     1.9.0
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

// A second copy of the plugin (say, one unzipped from GitHub into a
// "-main" folder) would redefine everything below and take the site down.
// Stand aside instead and say so. Closures only in this file: a named
// function or class here would clash between the two copies.
if ( defined( 'X8_PUBLISHER_FILE' ) ) {
	$x8_publisher_other = X8_PUBLISHER_FILE;
	add_action( 'admin_notices', static function () use ( $x8_publisher_other ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>X8 Marketing Publisher is installed twice.</strong> The copy in <code>%1$s</code> is running and <code>%2$s</code> is doing nothing. Remove the folder that isn\'t running with a file manager or SFTP. Don\'t use Delete on the Plugins screen, which also erases the plugin\'s settings.</p></div>',
			esc_html( dirname( plugin_basename( $x8_publisher_other ) ) ),
			esc_html( dirname( plugin_basename( __FILE__ ) ) )
		);
	} );
	return;
}

define( 'X8_PUBLISHER_VERSION', '1.9.0' );
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

// Loaded first and on its own, so a copy with missing files can still
// update itself back to a whole one.
if ( is_readable( X8_PUBLISHER_DIR . 'includes/class-updater.php' ) ) {
	require_once X8_PUBLISHER_DIR . 'includes/class-updater.php';
	( new Updater( X8_PUBLISHER_FILE ) )->register();
}

// A file that didn't make it onto the server (a half-finished upload)
// switches the plugin off with a notice rather than taking the site down.
// Divi support is optional: without it the rest still runs.
$x8_publisher_missing = [];
foreach ( [
	'class-auth.php'          => true,
	'class-image-handler.php' => true,
	'class-seo-handler.php'   => true,
	'class-publisher.php'     => true,
	'class-provisioning.php'  => true,
	'class-divi.php'          => false,
	'class-rest-api.php'      => true,
	'class-admin.php'         => true,
	'class-plugin.php'        => true,
] as $x8_publisher_file => $x8_publisher_required ) {
	if ( is_readable( X8_PUBLISHER_DIR . 'includes/' . $x8_publisher_file ) ) {
		require_once X8_PUBLISHER_DIR . 'includes/' . $x8_publisher_file;
	} else {
		$x8_publisher_missing[ $x8_publisher_file ] = $x8_publisher_required;
	}
}

if ( $x8_publisher_missing ) {
	$x8_publisher_stopped = in_array( true, $x8_publisher_missing, true );
	error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		'X8 Marketing Publisher: missing includes/%s; %s',
		implode( ', includes/', array_keys( $x8_publisher_missing ) ),
		$x8_publisher_stopped ? 'the plugin is switched off until they are uploaded.' : 'Divi support is off until it is uploaded.'
	) );
	add_action( 'admin_notices', static function () use ( $x8_publisher_missing, $x8_publisher_stopped ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>X8 Marketing Publisher is missing files:</strong> <code>%1$s</code>. %2$s Upload them into <code>%3$s</code>, or install the latest version from Dashboard &rarr; Updates.</p></div>',
			esc_html( implode( ', ', array_keys( $x8_publisher_missing ) ) ),
			$x8_publisher_stopped ? 'The plugin is switched off until they are there; the rest of the site is unaffected.' : 'Divi support is off until it is there; everything else works.',
			esc_html( dirname( plugin_basename( X8_PUBLISHER_FILE ) ) . '/includes/' )
		);
	} );
	if ( $x8_publisher_stopped ) {
		return;
	}
}

Plugin::instance();
