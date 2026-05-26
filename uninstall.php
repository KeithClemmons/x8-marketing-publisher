<?php
/**
 * Uninstall handler — runs on plugin deletion.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop logs table.
$table = $wpdb->prefix . 'x8_publisher_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore

// Delete all x8_publisher_* options.
$options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'x8_publisher_%'"
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Clear scheduled cron.
wp_clear_scheduled_hook( 'x8_publisher_cleanup_logs' );