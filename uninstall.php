<?php
/**
 * Uninstall script for LinkSentinel.
 *
 * This file is called automatically by WordPress when the plugin is
 * uninstalled via the Plugins screen.  It removes plugin options and
 * optionally drops the `rfx_link_monitor` table if the user enabled
 * the "Clean uninstall" setting.
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up all plugin data for a single site.
 */
function link_sentinel_uninstall_single_site() {
    // Read the setting BEFORE deleting options.
    $settings   = get_option( 'rfx_settings', [] );
    $drop_table = ! empty( $settings['delete_data_on_uninstall'] );

    // Remove all plugin options.
    delete_option( 'rfx_scan_last_started' );
    delete_option( 'rfx_scan_last_type' );
    delete_option( 'rfx_settings' );
    delete_option( 'rfx_db_version' );
    delete_option( 'rfx_follow_external_redirects' );
    delete_option( 'rfx_manual_scan_active' );
    delete_option( 'rfx_manual_scan_processed' );
    delete_option( 'rfx_manual_scan_total' );
    delete_option( 'rfx_manual_scan_last_id' );
    delete_option( 'rfx_manual_scan_token' );
    delete_option( 'rfx_manual_scan_batch_size' );
    delete_option( 'rfx_manual_scan_progress_interval' );
    delete_option( 'rfx_manual_scan_started_at' );
    delete_option( 'rfx_manual_scan_state' );
    delete_option( 'rfx_scan_last_finished' );

    // Clean up transients.
    delete_transient( 'rfx_scheduled_scan_lock' );
    delete_transient( 'rfx_manual_scan_lock' );

    // Clean up resolve cache transients (pattern-based cleanup).
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup of plugin transients.
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rfx_resolve_%' OR option_name LIKE '_transient_timeout_rfx_resolve_%'" );

    // Clear any lingering cron jobs.
    wp_clear_scheduled_hook( 'rfx_scheduled_scan' );
    wp_clear_scheduled_hook( 'rfx_nightly_scan' );

    // Drop the custom table only if the user opted in via Settings.
    if ( $drop_table ) {
        $table_name = $wpdb->prefix . 'rfx_link_monitor';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- User opted in to dropping the plugin's own table on uninstall; name built from $wpdb->prefix and a literal.
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
    }
}

// Run cleanup for each site on multisite, or just once on single-site.
if ( is_multisite() ) {
    $link_sentinel_site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
    foreach ( $link_sentinel_site_ids as $link_sentinel_site_id ) {
        switch_to_blog( $link_sentinel_site_id );
        link_sentinel_uninstall_single_site();
        restore_current_blog();
    }
} else {
    link_sentinel_uninstall_single_site();
}
