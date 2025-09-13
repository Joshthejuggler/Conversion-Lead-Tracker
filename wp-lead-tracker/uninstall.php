<?php
/**
 * Uninstall cleanup for WP Lead Tracker
 *
 * Deletes plugin data when the plugin is deleted from WordPress.
 * Runs in a limited context (the main plugin file is NOT loaded).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Single-site cleanup (and the common case even on multisite when not network-uninstalling).
 */
function wplt_cleanup_single_site() {
	global $wpdb;

	// Drop custom table.
	$table_name = $wpdb->prefix . 'wplt_lead_events';
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

	// Delete plugin options.
	delete_option( 'wplt_settings' );

	// Clear any scheduled events just in case.
	wp_clear_scheduled_hook( 'wplt_monthly_report_event' );
}

/**
 * Multisite-safe cleanup: if network-uninstall, run on each site.
 * If not multisite or not a network uninstall, just clean the current site.
 */
if ( is_multisite() && defined( 'WP_UNINSTALL_PLUGIN' ) && isset( $GLOBALS['wpdb'] ) ) {
	// If the plugin was network-activated and is being uninstalled network-wide,
	// iterate all sites and clean each one.
	$network_wide = ( isset( $_GET['networkwide'] ) && '1' === $_GET['networkwide'] ) || ( isset( $_POST['networkwide'] ) && '1' === $_POST['networkwide'] );

	if ( $network_wide ) {
		$sites = get_sites( [ 'fields' => 'ids' ] );
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			wplt_cleanup_single_site();
			restore_current_blog();
		}
	} else {
		// Regular uninstall on a single site within a multisite network.
		wplt_cleanup_single_site();
	}
} else {
	// Not multisite.
	wplt_cleanup_single_site();
}
