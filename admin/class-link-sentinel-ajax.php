<?php

/**
 * The AJAX functionality of the plugin.
 *
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/admin
 */

defined( 'ABSPATH' ) || exit;

class Link_Sentinel_Ajax
{

    /**
     * Start a manual scan.
     *
     * Resets scan state and returns the total number of items to scan.
     */
    public static function start_scan()
    {
        check_ajax_referer('rfx_start_scan_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'linksentinel')]);
        }

        // Bail out if a scheduled scan is currently running to prevent
        // concurrent scans from corrupting shared state.
        if (get_transient('rfx_scheduled_scan_lock')) {
            wp_send_json_error(['message' => __('A scheduled scan is currently running. Please wait for it to finish.', 'linksentinel')]);
        }

        // Bail out if a manual scan is already running (e.g. started by
        // another admin) so we don't reset its state and invalidate its
        // token mid-scan.  Stale scans are auto-reset by get_scan_state().
        $existing_state = Link_Sentinel_Scanner::get_scan_state();
        if ($existing_state['active']) {
            wp_send_json_error(['message' => __('A scan is already in progress. Please wait for it to finish.', 'linksentinel')]);
        }

        // Reset any previous scan state.
        Link_Sentinel_Scanner::reset_scan_state();

        // Count total items to scan.
        $total = Link_Sentinel_DB::count_scannable_posts();

        if (0 === $total) {
            wp_send_json_error(['message' => __('No posts found to scan.', 'linksentinel')]);
        }

        $token    = wp_generate_password( 12, false );
        $settings = get_option( 'rfx_settings', [] );
        $batch_size = isset( $settings['scan_batch_size'] ) ? (int) $settings['scan_batch_size'] : 25;

        Link_Sentinel_Scanner::save_scan_state( [
            'active'     => true,
            'processed'  => 0,
            'total'      => $total,
            'last_id'    => 0,
            'token'      => $token,
            'started_at' => time(),
            'batch_size' => $batch_size,
        ] );

        update_option( 'rfx_scan_last_started', current_time( 'mysql' ), false );
        update_option( 'rfx_scan_last_type', 'manual', false );

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of items to scan. */
                __('Starting scan of %d items...', 'linksentinel'),
                $total
            ),
            'total' => $total,
            'token' => $token,
        ]);
    }

    /**
     * Process a single batch of the manual scan.
     */
    public static function step_scan()
    {
        // Verify nonce and permissions.
        check_ajax_referer('rfx_start_scan_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'linksentinel')]);
        }

        // Server-side rate limiting: minimum 200ms between step requests per user.
        $rate_key       = 'rfx_step_rate_' . get_current_user_id();
        $last_step_time = get_transient( $rate_key );
        if ( false !== $last_step_time ) {
            $elapsed_ms = ( microtime( true ) - (float) $last_step_time ) * 1000;
            if ( $elapsed_ms < 200 ) {
                wp_send_json_error( [
                    'message' => __( 'Too many requests. Please slow down.', 'linksentinel' ),
                    'code'    => 429,
                ] );
            }
        }
        set_transient( $rate_key, microtime( true ), 30 );

        // Validate scan token and state.
        $state = Link_Sentinel_Scanner::get_scan_state();

        $submitted_token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if (empty($submitted_token) || $submitted_token !== $state['token']) {
            wp_send_json_error(['message' => __('Invalid scan token.', 'linksentinel')]);
        }

        if (!$state['active']) {
            wp_send_json_error(['message' => __('No active scan found.', 'linksentinel')]);
        }

        $processed  = $state['processed'];
        $total      = $state['total'];
        $last_id    = $state['last_id'];
        $batch_size = $state['batch_size'];

        // Get next batch of posts.
        $post_ids = Link_Sentinel_DB::get_scannable_post_ids_paged($last_id, $batch_size);

        if (empty($post_ids)) {
            // No more posts; complete the scan.
            Link_Sentinel_Scanner::complete_scan($total);
            wp_send_json_success([
                'done' => true,
                'processed' => $total,
                'total' => $total,
                'message' => __('Scan complete!', 'linksentinel'),
            ]);
        }

        // Process the batch.
        foreach ($post_ids as $post_id) {
            Link_Sentinel_Scanner::process_single_post($post_id);
            $last_id = $post_id;
            $processed++;
        }

        // Update state atomically.
        $state['processed'] = $processed;
        $state['last_id']   = $last_id;
        Link_Sentinel_Scanner::save_scan_state( $state );

        // Calculate progress percentage.
        $percentage = ($total > 0) ? round(($processed / $total) * 100) : 0;

        wp_send_json_success([
            'done' => false,
            'processed' => $processed,
            'total' => $total,
            'percentage' => $percentage,
            'message' => sprintf(
                /* translators: 1: number of items processed, 2: total number of items, 3: progress percentage. */
                __('Processed %1$d of %2$d items (%3$d%%)...', 'linksentinel'),
                $processed,
                $total,
                $percentage
            ),
        ]);
    }

    /**
     * Provide scan status for polling.
     */
    public static function scan_status()
    {
        check_ajax_referer('rfx_start_scan_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'linksentinel')]);
        }

        $state = Link_Sentinel_Scanner::get_scan_state();
        if ( ! $state['active'] ) {
            wp_send_json_success(['active' => false, 'message' => __('No active scan.', 'linksentinel')]);
        }

        $token     = $state['token'];
        $processed = $state['processed'];
        $total     = $state['total'];
        $percentage = ($total > 0) ? round(($processed / $total) * 100) : 0;

        wp_send_json_success([
            'active' => true,
            'running' => true,
            'token' => $token,
            'processed' => $processed,
            'total' => $total,
            'percentage' => $percentage,
            'message' => sprintf(
                /* translators: %d: progress percentage. */
                __('Scanning... %d%%', 'linksentinel'),
                $percentage
            ),
        ]);
    }

    /**
     * Resolve a single pending link.
     */
    public static function resolve_link()
    {
        check_ajax_referer('rfx_resolve_link_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'linksentinel')]);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid ID.', 'linksentinel')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rfx_link_monitor';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        if (!$row) {
            wp_send_json_error(['message' => __('Record not found.', 'linksentinel')]);
        }

        if (empty($row['final_url'])) {
            wp_send_json_error(['message' => __('No final URL available to resolve.', 'linksentinel')]);
        }

        $post = get_post($row['post_id']);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'linksentinel')]);
        }

        $content = $post->post_content;
        $updated_content = Link_Sentinel_Scanner::replace_href_value($content, $row['original_url'], $row['final_url'], 1);

        if ($updated_content !== $content) {
            Link_Sentinel_Scanner::commit_post_content($post, $updated_content);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
            $wpdb->update(
                $table,
                [
                    'resolution_status' => 'resolved',
                    'status_message' => __('Manually resolved', 'linksentinel'),
                    'resolution_date' => current_time('mysql'),
                    'resolved_by_user_id' => get_current_user_id(),
                ],
                ['id' => $id],
                ['%s', '%s', '%s', '%d'],
                ['%d']
            );
            wp_send_json_success(['message' => __('Link updated successfully.', 'linksentinel')]);
        } else {
            // Link already removed or changed in the post — auto-resolve this
            // record and any duplicates for the same post + URL.
            $resolve_data = [
                'resolution_status' => 'resolved',
                'status_message' => __('Link already removed from post', 'linksentinel'),
                'resolution_date' => current_time('mysql'),
                'resolved_by_user_id' => get_current_user_id(),
            ];
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
            $wpdb->update($table, $resolve_data, ['id' => $id], ['%s', '%s', '%s', '%d'], ['%d']);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET resolution_status = 'resolved', status_message = %s, resolution_date = %s, resolved_by_user_id = %d WHERE post_id = %d AND original_url = %s AND resolution_status = 'pending'",
                $resolve_data['status_message'],
                $resolve_data['resolution_date'],
                $resolve_data['resolved_by_user_id'],
                $row['post_id'],
                $row['original_url']
            ));
            // phpcs:enable
            wp_send_json_success([
                'message' => __('The link was already removed or changed in the post. The record has been resolved.', 'linksentinel'),
                'auto_resolved' => true,
            ]);
        }
    }

    /**
     * Test database connection and return rich diagnostics.
     */
    public static function test_db_connection()
    {
        check_ajax_referer('rfx_start_scan_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'linksentinel')]);
        }

        global $wpdb;
        $table_name   = $wpdb->prefix . 'rfx_link_monitor';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema diagnostics.
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name );

        $diagnostics = [
            'table_exists'         => $table_exists,
            'total_records'        => 0,
            'pending_records'      => 0,
            'can_read'             => false,
            'last_error'           => $wpdb->last_error,
            'memory_limit'         => ini_get( 'memory_limit' ),
            'max_execution_time'   => ini_get( 'max_execution_time' ),
            'current_memory_usage' => size_format( memory_get_usage( true ) ),
            'peak_memory_usage'    => size_format( memory_get_peak_usage( true ) ),
        ];

        if ( $table_exists ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
            $diagnostics['total_records']   = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" );
            $diagnostics['pending_records'] = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(id) FROM {$table_name} WHERE resolution_status = %s", 'pending' )
            );
            // phpcs:enable
            $diagnostics['can_read'] = ( '' === $wpdb->last_error );
        }

        if ( $table_exists ) {
            wp_send_json_success( [
                'message'     => __( 'Table exists and is accessible.', 'linksentinel' ),
                'diagnostics' => $diagnostics,
            ] );
        } else {
            wp_send_json_error( [
                'message'     => __( 'Table missing. Try deactivating and reactivating the plugin.', 'linksentinel' ),
                'diagnostics' => $diagnostics,
            ] );
        }
    }

    /**
     * Resolve all pending redirects in batches.
     */
    public static function resolve_all()
    {
        check_ajax_referer('rfx_resolve_all_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'linksentinel')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rfx_link_monitor';

        // Get batch size from settings via helper
        $prefs = Link_Sentinel_Scanner::get_resolve_all_preferences();
        $batch_size = $prefs['batch'];

        // Fetch pending redirects that have a final_url
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE resolution_status = %s AND final_url <> '' AND ( http_status < %d OR http_status IS NULL ) LIMIT %d",
                'pending',
                400,
                $batch_size
            ),
            ARRAY_A
        );
        // phpcs:enable

        if (empty($rows)) {
            wp_send_json_success([
                'count' => 0,
                'message' => __('All done! No more pending redirects.', 'linksentinel'),
                'done' => true,
            ]);
        }

        $resolved_count = 0;
        $errors = 0;

        // Group rows by post_id so we fetch content once, apply all
        // replacements sequentially, then save once per post.  This
        // prevents stale-content bugs when multiple rows target the
        // same post within a single batch.
        $rows_by_post = [];
        foreach ($rows as $row) {
            $pid = (int) $row['post_id'];
            if (!isset($rows_by_post[$pid])) {
                $rows_by_post[$pid] = [];
            }
            $rows_by_post[$pid][] = $row;
        }

        $now     = current_time('mysql');
        $user_id = get_current_user_id();

        foreach ($rows_by_post as $post_id => $post_rows) {
            $post = get_post($post_id);
            if (!$post) {
                $errors += count($post_rows);
                continue;
            }

            $content         = $post->post_content;
            $updated_content = $content;

            foreach ($post_rows as $row) {
                $before = $updated_content;
                $updated_content = Link_Sentinel_Scanner::replace_href_value($updated_content, $row['original_url'], $row['final_url']);

                if ($updated_content !== $before) {
                    // The href was replaced in the in-memory copy of the
                    // content; mark this row resolved now. The post itself is
                    // saved once, after every row for this post is processed.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
                    $wpdb->update(
                        $table,
                        [
                            'resolution_status'  => 'resolved',
                            'status_message'     => __('Bulk resolved', 'linksentinel'),
                            'resolution_date'    => $now,
                            'resolved_by_user_id' => $user_id,
                        ],
                        ['id' => $row['id']],
                        ['%s', '%s', '%s', '%d'],
                        ['%d']
                    );
                    $resolved_count++;
                } else {
                    // Link was already updated or removed from the post (e.g. by auto-resolve
                    // during a scan, or by manual editing).  Mark as resolved to prevent infinite loops.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
                    $wpdb->update(
                        $table,
                        [
                            'resolution_status'  => 'resolved',
                            'status_message'     => __('Link already updated or removed from post', 'linksentinel'),
                            'resolution_date'    => $now,
                            'resolved_by_user_id' => $user_id,
                        ],
                        ['id' => $row['id']],
                        ['%s', '%s', '%s', '%d'],
                        ['%d']
                    );
                    $resolved_count++;
                }
            }

            // Save the post content once after all replacements for this post.
            if ($updated_content !== $content) {
                Link_Sentinel_Scanner::commit_post_content($post, $updated_content);
            }
        }

        // Check remaining count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
        $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table WHERE resolution_status = %s AND final_url <> '' AND ( http_status < %d OR http_status IS NULL )", 'pending', 400));

        // Get total from request or calculate it (processed so far + remaining)
        $client_processed = isset($_POST['processed']) ? (int) $_POST['processed'] : 0;
        $client_total = isset($_POST['total']) ? (int) $_POST['total'] : 0;
        
        // Calculate actual processed this step
        $processed_this_step = $resolved_count + $errors;
        $total_processed = $client_processed + $processed_this_step;
        
        // If client didn't send total, calculate it
        $total = ($client_total > 0) ? $client_total : ($total_processed + $remaining);

        wp_send_json_success([
            'count' => $resolved_count,
            'processed_step' => $processed_this_step,
            'processed' => $total_processed,
            'total' => $total,
            'errors' => $errors,
            'remaining' => $remaining,
            'done' => (0 === $remaining),
            'message' => sprintf(
                /* translators: 1: number of links resolved, 2: number of errors, 3: number of links remaining. */
                __('Resolved %1$d links (%2$d errors). %3$d remaining...', 'linksentinel'),
                $resolved_count,
                $errors,
                $remaining
            ),
        ]);
    }
    /**
     * Change a broken link to a new URL.
     */
    public static function change_link()
    {
        check_ajax_referer('rfx_change_link_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'linksentinel')]);
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $new_url = isset($_POST['new_url']) ? trim(esc_url_raw(wp_unslash($_POST['new_url']))) : '';

        if (!$id) {
            wp_send_json_error(['message' => __('Invalid ID.', 'linksentinel')]);
        }

        if (empty($new_url)) {
            wp_send_json_error(['message' => __('Please provide a valid URL.', 'linksentinel')]);
        }

        // Only allow http/https URLs (or site-relative paths starting with "/")
        // to prevent protocol injection.
        $scheme = wp_parse_url($new_url, PHP_URL_SCHEME);
        if ($scheme) {
            if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
                wp_send_json_error(['message' => __('Only http and https URLs are allowed.', 'linksentinel')]);
            }
        } elseif ('/' !== $new_url[0]) {
            wp_send_json_error(['message' => __('Please enter a full http(s) URL or a site-relative path starting with "/".', 'linksentinel')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rfx_link_monitor';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        if (!$row) {
            wp_send_json_error(['message' => __('Record not found.', 'linksentinel')]);
        }

        $post = get_post($row['post_id']);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'linksentinel')]);
        }

        $content = $post->post_content;
        // Use the original URL from the record to find and replace it with the new URL.
        $updated_content = Link_Sentinel_Scanner::replace_href_value($content, $row['original_url'], $new_url, 1);

        if ($updated_content !== $content) {
            Link_Sentinel_Scanner::commit_post_content($post, $updated_content);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
            $wpdb->update(
                $table,
                [
                    'resolution_status' => 'resolved',
                    'final_url' => $new_url, // Update the final URL to what the user entered
                    'status_message' => __('Manually changed', 'linksentinel'),
                    'resolution_date' => current_time('mysql'),
                    'resolved_by_user_id' => get_current_user_id(),
                ],
                ['id' => $id],
                ['%s', '%s', '%s', '%s', '%d'],
                ['%d']
            );
            wp_send_json_success(['message' => __('Link changed successfully.', 'linksentinel')]);
        } else {
            // Link already removed or changed in the post — auto-resolve this
            // record and any duplicates for the same post + URL.
            $resolve_data = [
                'resolution_status' => 'resolved',
                'status_message' => __('Link already removed from post', 'linksentinel'),
                'resolution_date' => current_time('mysql'),
                'resolved_by_user_id' => get_current_user_id(),
            ];
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
            $wpdb->update($table, $resolve_data, ['id' => $id], ['%s', '%s', '%s', '%d'], ['%d']);
            // Resolve any remaining duplicates for this post + URL.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET resolution_status = 'resolved', status_message = %s, resolution_date = %s, resolved_by_user_id = %d WHERE post_id = %d AND original_url = %s AND resolution_status = 'pending'",
                $resolve_data['status_message'],
                $resolve_data['resolution_date'],
                $resolve_data['resolved_by_user_id'],
                $row['post_id'],
                $row['original_url']
            ));
            // phpcs:enable
            wp_send_json_success([
                'message' => __('The link was already removed or changed in the post. The record has been resolved.', 'linksentinel'),
                'auto_resolved' => true,
            ]);
        }
    }
}
