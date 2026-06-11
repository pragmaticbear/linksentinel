<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/admin
 */

defined( 'ABSPATH' ) || exit;

class Link_Sentinel_Admin
{

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    5.0.1
     */
    public function enqueue_styles($hook)
    {
        if (strpos($hook, 'link-sentinel') === false) {
            return;
        }
        wp_enqueue_style('linksentinel-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css', [], LINKSENTINEL_VERSION, 'all');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    5.0.1
     */
    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'link-sentinel') === false) {
            return;
        }

        $prefs = Link_Sentinel_Scanner::get_resolve_all_preferences();

        wp_enqueue_script('linksentinel-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin-main.js', ['jquery'], LINKSENTINEL_VERSION, true);
        wp_localize_script('linksentinel-admin', 'RFXAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rfx_start_scan_nonce'),
            'resolve_all_batch' => $prefs['batch'],
            'resolve_all_delay' => $prefs['delay_ms'],
            'resolve_all_custom' => $prefs['custom'] ? 1 : 0,
            'labels' => [
                'inProgress' => __('Scan in progress', 'linksentinel'),
                'completed'  => __('Scan complete', 'linksentinel'),
            ],
        ]);
    }

    /**
     * Register the administration menu for this plugin into the WordPress Dashboard menu.
     *
     * @since    5.0.1
     */
    public function add_plugin_admin_menu()
    {
        add_management_page(
            __('LinkSentinel', 'linksentinel'),
            __('LinkSentinel', 'linksentinel'),
            'manage_options',
            'link-sentinel',
            [$this, 'display_plugin_admin_page']
        );
    }

    /**
     * Redirect legacy slugs and clean up old submenu entries.
     */
    public function handle_legacy_redirects()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect of a legacy admin page slug; no state is changed.
        if (isset($_GET['page']) && 'link-health-monitor' === sanitize_key(wp_unslash($_GET['page']))) {
            wp_safe_redirect(admin_url('tools.php?page=link-sentinel'));
            exit;
        }
    }

    /**
     * Remove legacy submenu pages.
     */
    public function remove_legacy_menus()
    {
        remove_submenu_page('tools.php', 'link-health-monitor');
    }

    /**
     * Render the settings page for this plugin.
     *
     * @since    5.0.1
     */
    public function display_plugin_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'linksentinel'));
        }

        // Include the partial for the dashboard
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/partials/link-sentinel-admin-display.php';
    }

    /**
     * Save settings.
     */
    public function save_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'linksentinel'));
        }

        $nonce = isset($_POST['rfx_settings_nonce']) ? sanitize_text_field(wp_unslash($_POST['rfx_settings_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'rfx_save_settings')) {
            wp_die(esc_html__('Invalid nonce.', 'linksentinel'));
        }

        // Post types selected (array of slugs).
        $post_types = [];
        if (isset($_POST['post_types']) && is_array($_POST['post_types'])) {
            $raw_post_types = array_map('sanitize_key', wp_unslash($_POST['post_types']));
            foreach ($raw_post_types as $slug) {
                if (post_type_exists($slug)) {
                    $post_types[] = $slug;
                }
            }
        }
        if (empty($post_types)) {
            // Fallback to posts and pages if nothing selected.
            $post_types = ['post', 'page'];
        }
        $post_types = array_values(array_unique($post_types));

        // Determine whether automatic resolution of permanent redirects is enabled.
        $auto_resolve = ! empty($_POST['auto_resolve_permanent']) ? 1 : 0;

        // Scheduled scan settings
        $enable_scheduled = ! empty($_POST['enable_scheduled_scans']) ? 1 : 0;
        $scan_frequency = isset($_POST['scan_frequency']) ? sanitize_text_field(wp_unslash($_POST['scan_frequency'])) : 'daily';
        $scan_time = isset($_POST['scan_time']) ? sanitize_text_field(wp_unslash($_POST['scan_time'])) : '02:00';

        // Validate frequency
        if (!in_array($scan_frequency, ['daily', 'twicedaily', 'weekly'], true)) {
            $scan_frequency = 'daily';
        }

        // Validate time format (HH:MM)
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $scan_time)) {
            $scan_time = '02:00';
        }

        // Validate scan batch size
        $scan_batch_size = isset($_POST['scan_batch_size']) ? (int) $_POST['scan_batch_size'] : 25;
        $scan_batch_size = max(5, min(100, $scan_batch_size));

        $resolve_all_custom = ! empty($_POST['resolve_all_custom']) ? 1 : 0;
        $resolve_all_batch_size = isset($_POST['resolve_all_batch_size']) ? (int) $_POST['resolve_all_batch_size'] : 5;
        $resolve_all_batch_size = max(1, min(50, $resolve_all_batch_size));
        $resolve_all_cooldown = isset($_POST['resolve_all_cooldown']) ? (int) $_POST['resolve_all_cooldown'] : 0;
        $resolve_all_cooldown = max(0, min(15 * MINUTE_IN_SECONDS, $resolve_all_cooldown));

        $delete_data_on_uninstall = ! empty( $_POST['delete_data_on_uninstall'] ) ? 1 : 0;

        $settings = [
            'post_types' => $post_types,
            'auto_resolve_permanent' => $auto_resolve,
            'enable_scheduled_scans' => $enable_scheduled,
            'scan_frequency' => $scan_frequency,
            'scan_time' => $scan_time,
            'scan_batch_size' => $scan_batch_size,
            'resolve_all_custom' => $resolve_all_custom,
            'resolve_all_batch_size' => $resolve_all_batch_size,
            'resolve_all_cooldown' => $resolve_all_cooldown,
            'delete_data_on_uninstall' => $delete_data_on_uninstall,
        ];
        update_option('rfx_settings', $settings);

        // Update cron schedule - ensure activator class is loaded
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-link-sentinel-activator.php';
        Link_Sentinel_Activator::schedule_scans();

        $follow_external = (isset($_POST['follow_external_redirects']) && (int) $_POST['follow_external_redirects'] === 1) ? '1' : '0';
        update_option('rfx_follow_external_redirects', $follow_external);

        // Redirect back to the plugin page with a success flag.
        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('tools.php?page=link-sentinel#settings')));
        exit;
    }

    public function clear_pending_redirects()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'linksentinel'));
        }

        check_admin_referer('rfx_clear_pending_redirects');

        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE resolution_status = %s AND ( http_status < %d OR http_status IS NULL )",
                'pending',
                400
            )
        );
        // phpcs:enable

        $redirect_url = add_query_arg(
            [
                'page' => 'link-sentinel',
                'tab' => 'pending',
                'pending-cleared' => '1',
            ],
            admin_url('tools.php')
        );
        // Preserve the Pending tab after redirect; the JS reads the hash to reopen the same tab.
        $redirect_url .= '#pending';

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function clear_resolved_links()
    {
        // Only administrators may clear the table.
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'linksentinel'));
        }
        // Verify nonce for security.
        check_admin_referer('rfx_clear_resolved_links');
        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';
        // Delete all resolved rows.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
        $wpdb->delete($table_name, ['resolution_status' => 'resolved'], ['%s']);
        // Redirect back to the Resolved tab with a query flag to display a notice (optional).
        $redirect_url = add_query_arg(['page' => 'link-sentinel', 'tab' => 'resolved', 'cleared' => '1'], admin_url('tools.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function clear_broken_links()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'linksentinel'));
        }

        check_admin_referer('rfx_clear_broken_links');

        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
        $wpdb->query($wpdb->prepare("DELETE FROM {$table_name} WHERE resolution_status = %s AND http_status >= %d", 'pending', 400));

        $redirect_url = add_query_arg(
            [
                'page' => 'link-sentinel',
                'tab' => 'broken',
                'broken-cleared' => '1',
            ],
            admin_url('tools.php')
        );
        $redirect_url .= '#broken';
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function export_resolved_csv()
    {
        // Only administrators can export data.
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this export.', 'linksentinel'));
        }
        // Verify the nonce to protect against CSRF.
        check_admin_referer('rfx_export_resolved_csv');
        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=resolved-links-' . gmdate('Y-m-d_H-i-s') . '.csv');
        $output = fopen('php://output', 'w');
        if (false === $output) {
            wp_die(esc_html__('Unable to open output stream for CSV export.', 'linksentinel'));
        }
        fputcsv($output, ['ID', 'Post ID', 'Original URL', 'Final URL', 'HTTP Status', 'Action', 'Scan Date', 'Resolution Date']);

        $last_id = 0;
        $batch = 500;

        while (true) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, post_id, original_url, final_url, http_status, status_message, scan_date, resolution_date FROM $table_name WHERE resolution_status = %s AND id > %d ORDER BY id ASC LIMIT %d",
                    'resolved',
                    $last_id,
                    $batch
                ),
                ARRAY_A
            );
            // phpcs:enable

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['post_id'],
                    self::sanitize_csv_value($row['original_url']),
                    self::sanitize_csv_value($row['final_url']),
                    $row['http_status'],
                    self::sanitize_csv_value($row['status_message']),
                    $row['scan_date'],
                    $row['resolution_date'],
                ]);
                $last_id = (int) $row['id'];
            }
            fflush($output);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV directly to php://output; WP_Filesystem does not apply.
        fclose($output);
        exit;
    }

    /**
     * Sanitize a value for CSV output to prevent formula injection.
     *
     * Prefixes values starting with =, +, -, or @ with a tab character
     * so spreadsheet applications do not interpret them as formulas.
     *
     * @param string $value Raw value.
     * @return string Sanitized value.
     */
    private static function sanitize_csv_value($value)
    {
        if (is_string($value) && isset($value[0]) && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "\t" . $value;
        }
        return $value;
    }
}
