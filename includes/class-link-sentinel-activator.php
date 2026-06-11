<?php

/**
 * Fired during plugin activation.
 *
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/includes
 */

defined( 'ABSPATH' ) || exit;

class Link_Sentinel_Activator {

	/**
	 * Create the link monitor table, populate hashes, set defaults and schedule cron.
	 *
	 * @since    5.0.1
	 */
	public static function activate() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'rfx_link_monitor';
        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            original_url text NOT NULL,
            url_hash char(32) NOT NULL DEFAULT '',
            final_url text,
            http_status int(11) DEFAULT NULL,
            status_message varchar(191) DEFAULT NULL,
            resolution_status varchar(20) NOT NULL DEFAULT 'pending',
            scan_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolution_date datetime DEFAULT NULL,
            resolved_by_user_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY http_status (http_status),
            KEY resolution_status (resolution_status),
            KEY post_status_hash (post_id, resolution_status, url_hash),
            KEY scan_date (scan_date),
            KEY resolution_date (resolution_date)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Populate url_hash for existing rows using the same normalization
        // logic as the runtime get_url_hash() to ensure hash consistency.
        if ( ! class_exists( 'Link_Sentinel_Scanner' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-link-sentinel-scanner.php';
        }
        if ( ! class_exists( 'Link_Sentinel_DB' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-link-sentinel-db.php';
        }

        $batch_size = 500;
        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time activation backfill on the plugin's own table; name built from $wpdb->prefix and a literal.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, original_url FROM $table_name WHERE url_hash = '' OR url_hash IS NULL LIMIT %d",
                    $batch_size
                ),
                ARRAY_A
            );
            // phpcs:enable

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $hash = Link_Sentinel_DB::get_url_hash( $row['original_url'] );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write during activation backfill.
                $wpdb->update(
                    $table_name,
                    [ 'url_hash' => $hash ],
                    [ 'id' => (int) $row['id'] ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }
        } while ( count( $rows ) === $batch_size );

        if ( defined( 'LINKSENTINEL_DB_VERSION' ) ) {
            update_option( 'rfx_db_version', LINKSENTINEL_DB_VERSION );
        }

        $settings = get_option( 'rfx_settings' );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $defaults = [
            'post_types'             => [ 'post', 'page' ],
            'auto_resolve_permanent' => 0,
            'scan_batch_size'        => 25,
            'enable_scheduled_scans' => 1,
            'scan_frequency'         => 'daily',
            'scan_time'              => '02:00',
        ];

        // Remove deprecated scheduling keys when upgrading.
        unset( $settings['scan_hour'], $settings['scan_minute'] );

        update_option( 'rfx_settings', wp_parse_args( $settings, $defaults ) );

        // Clear any scheduled cron jobs left by legacy versions.
        wp_clear_scheduled_hook( 'rfx_nightly_scan' );

        // Ensure custom cron intervals (e.g. 'weekly') are available during
        // activation, before the loader has registered the filter.
        if ( ! has_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] ) ) {
            add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
        }

        // Schedule new cron job if enabled
        self::schedule_scans();
	}
    
    /**
     * Register custom cron schedule intervals.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'linksentinel' ),
            ];
        }
        return $schedules;
    }

    public static function schedule_scans() {
        $settings = get_option( 'rfx_settings', [] );
        $enabled = ! empty( $settings['enable_scheduled_scans'] );
        $frequency = isset( $settings['scan_frequency'] ) ? $settings['scan_frequency'] : 'daily';
        $time = isset( $settings['scan_time'] ) ? $settings['scan_time'] : '02:00';
        
        // Clear existing schedule
        wp_clear_scheduled_hook( 'rfx_scheduled_scan' );
        
        if ( $enabled ) {
            // Parse time (format: HH:MM)
            $time_parts = explode( ':', $time );
            $hour = isset( $time_parts[0] ) ? (int) $time_parts[0] : 2;
            $minute = isset( $time_parts[1] ) ? (int) $time_parts[1] : 0;
            
            // Get WordPress timezone
            $wp_timezone = wp_timezone();
            
            // Create DateTime object in WordPress timezone for today at the specified time
            $next_run_dt = new DateTime( 'today', $wp_timezone );
            $next_run_dt->setTime( $hour, $minute, 0 );
            
            // Get current time in WordPress timezone
            $now_dt = new DateTime( 'now', $wp_timezone );
            
            // If the time has already passed today, schedule for tomorrow
            if ( $next_run_dt <= $now_dt ) {
                $next_run_dt->add( new DateInterval( 'P1D' ) ); // Add 1 day
            }
            
            // Convert to UTC timestamp for wp_schedule_event
            $next_run_utc = $next_run_dt->getTimestamp();
            
            wp_schedule_event( $next_run_utc, $frequency, 'rfx_scheduled_scan' );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'rfx_nightly_scan' );
        wp_clear_scheduled_hook( 'rfx_scheduled_scan' );
        // Remove consolidated scan state.
        delete_option( 'rfx_manual_scan_state' );
        // Clean up legacy individual options from prior versions.
        delete_option( 'rfx_manual_scan_active' );
        delete_option( 'rfx_manual_scan_processed' );
        delete_option( 'rfx_manual_scan_total' );
        delete_option( 'rfx_manual_scan_last_id' );
        delete_option( 'rfx_manual_scan_token' );
        delete_option( 'rfx_manual_scan_batch_size' );
        delete_option( 'rfx_manual_scan_progress_interval' );
        delete_option( 'rfx_manual_scan_started_at' );
    }
}
