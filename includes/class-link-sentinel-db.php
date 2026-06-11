<?php

/**
 * Database interactions.
 *
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/includes
 */

defined( 'ABSPATH' ) || exit;

class Link_Sentinel_DB {

    /**
     * Log or update a link issue in the database without creating duplicates.
     *
     * @param int    $post_id               The post ID containing the link.
     * @param string $original_url          The original URL found in content.
     * @param string $final_url             The final resolved URL (optional).
     * @param int    $http_status           HTTP status code from the request.
     * @param string $status_message        Human-readable status message.
     * @param string $resolution_status     Either 'pending' or 'resolved'.
     * @param int    $resolved_by_user_id   Optional user ID responsible for the resolution.
     *
     * @return int Inserted/updated row ID.
     */
    public static function log_link_issue( $post_id, $original_url, $final_url, $http_status, $status_message, $resolution_status = 'pending', $resolved_by_user_id = 0 ) {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'rfx_link_monitor';
        $post_id         = absint( $post_id );
        $hash_supported  = self::supports_hash();
        $url_hash        = $hash_supported ? self::get_url_hash( $original_url ) : null;
        $now             = current_time( 'mysql' );
        $is_resolved     = ( 'resolved' === $resolution_status );
        $resolved_user   = $is_resolved ? absint( $resolved_by_user_id ) : 0;
        $resolution_date = $is_resolved ? $now : null;

        $data = [
            'post_id'             => $post_id,
            'original_url'        => $original_url,
            'final_url'           => $final_url,
            'http_status'         => (int) $http_status,
            'status_message'      => $status_message,
            'resolution_status'   => $resolution_status,
            'scan_date'           => $now,
            'resolution_date'     => $resolution_date,
            'resolved_by_user_id' => $resolved_user,
        ];

        $formats = [ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' ];

        if ( $hash_supported ) {
            $data['url_hash'] = $url_hash;
            $formats[]        = '%s';
        }

        // Attempt to find an existing row for this post + URL combination.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
        if ( $hash_supported ) {
            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND url_hash = %s LIMIT 1", $post_id, $url_hash ) );
        } else {
            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s LIMIT 1", $post_id, $original_url ) );
        }

        if ( $existing_id ) {
            $wpdb->update( $table_name, $data, [ 'id' => (int) $existing_id ], $formats, [ '%d' ] );
            return (int) $existing_id;
        }

        $wpdb->insert( $table_name, $data, $formats );
        return (int) $wpdb->insert_id;
        // phpcs:enable
    }

    /**
     * Generate a deterministic hash for the supplied URL.
     *
     * @param string $url URL to hash.
     * @return string
     */
    public static function get_url_hash( $url ) {
        // Normalize the URL through the scanner before hashing so the same
        // link always yields the same hash regardless of minor formatting
        // differences (e.g. trailing slashes). Activation backfills existing
        // rows through this same path, keeping stored hashes consistent.
        $normalized = Link_Sentinel_Scanner::normalize_url_for_replacement( (string) $url );
        return md5( $normalized );
    }

    /**
     * Determine whether the link monitor table has a url_hash column.
     *
     * @return bool
     */
    public static function supports_hash() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'rfx_link_monitor';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on the plugin's own table; result is memoized in a static.
        $cache = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'url_hash' ) );
        return $cache;
    }

    /**
     * Return the sanitized list of post types that should be scanned.
     *
     * @return string[]
     */
    public static function get_scannable_post_types() {
        $settings   = get_option( 'rfx_settings', [] );
        $post_types = [];

        if ( ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
            foreach ( $settings['post_types'] as $type ) {
                $type = sanitize_key( $type );
                if ( post_type_exists( $type ) ) {
                    $post_types[] = $type;
                }
            }
        }

        if ( empty( $post_types ) ) {
            $post_types = [ 'post', 'page' ];
        }

        $post_types = apply_filters( 'link_sentinel_scannable_post_types', $post_types );
        // Back-compat for the pre-5.6 filter name.
        $post_types = apply_filters_deprecated( 'rfx_scannable_post_types', [ $post_types ], '5.6', 'link_sentinel_scannable_post_types' );

        if ( ! is_array( $post_types ) ) {
            $post_types = [ 'post', 'page' ];
        }

        $sanitized = [];
        foreach ( $post_types as $type ) {
            $type = sanitize_key( $type );
            if ( post_type_exists( $type ) ) {
                $sanitized[] = $type;
            }
        }

        if ( empty( $sanitized ) ) {
            $sanitized = [ 'post', 'page' ];
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Count the number of posts eligible for scanning.
     *
     * @return int
     */
    public static function count_scannable_posts() {
        global $wpdb;
        $post_types   = self::get_scannable_post_types();
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a literal list of %s placeholders for the IN() clause built from a server-side count; all values go through prepare().
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)", $post_types ) );
    }

    /**
     * Retrieve the next batch of post IDs using an ID cursor.
     *
     * @param int $after_id Last processed post ID.
     * @param int $limit    Maximum number of IDs to load.
     * @return int[]
     */
    public static function get_scannable_post_ids_paged( $after_id, $limit ) {
        global $wpdb;
        $post_types   = self::get_scannable_post_types();
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $params       = array_merge( $post_types, [ (int) $after_id, (int) $limit ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a literal list of %s placeholders for the IN() clause; the count cannot be known statically, so the replacement count is validated at runtime via $params.
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d", $params ) ) );
    }
}
