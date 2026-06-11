<?php

/**
 * List Table classes for LinkSentinel.
 *
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/admin
 */

defined( 'ABSPATH' ) || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Base list table class to display resolved links with scope (current or previous scans).
 */
class Link_Sentinel_Resolved_Links_List_Table extends WP_List_Table
{
    protected $scope = 'all';
    public function __construct()
    {
        parent::__construct([
            'singular' => 'resolved_link',
            'plural' => 'resolved_links',
            'ajax' => false,
        ]);
    }
    /**
     * Set the scope for the table.  Accepts 'current', 'previous', or 'all'.
     *
     * @param string $scope Scope string.
     */
    public function set_scope($scope)
    {
        $this->scope = in_array($scope, ['current', 'previous', 'all'], true) ? $scope : 'all';
    }
    public function get_columns()
    {
        return [
            'original_url' => __('Original URL', 'linksentinel'),
            'final_url' => __('Corrected To', 'linksentinel'),
            'status_message' => __('Action Taken', 'linksentinel'),
            'resolved_by' => __('Resolved By', 'linksentinel'),
            'post_id' => __('Found In', 'linksentinel'),
            'resolution_date' => __('Date Corrected', 'linksentinel'),
        ];
    }
    public function get_sortable_columns()
    {
        return ['resolution_date' => ['resolution_date', true]];
    }
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'original_url':
                return '<code>' . esc_html($item['original_url']) . '</code>';
            case 'final_url':
                return '<code>' . esc_html($item['final_url']) . '</code>';
            case 'status_message':
                return esc_html($item['status_message']);
            case 'resolved_by':
                // The resolved_by_user_id is already present in $item from
                // the SELECT * query in prepare_items(); no extra DB call needed.
                $uid = isset($item['resolved_by_user_id']) ? (int) $item['resolved_by_user_id'] : 0;
                if ($uid > 0) {
                    $u = get_userdata($uid);
                    if ($u) {
                        $name = $u->display_name ? $u->display_name : $u->user_login;
                        $url = admin_url('user-edit.php?user_id=' . $uid);
                        return sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($name));
                    }
                }
                return '—';
            case 'post_id':
                $post_title = get_the_title($item['post_id']);
                if (empty($post_title)) {
                    $post_title = sprintf(
                        /* translators: %d: post ID. */
                        __('Post #%d', 'linksentinel'),
                        $item['post_id']
                    );
                }
                $edit_link = get_edit_post_link($item['post_id']);
                return sprintf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($post_title));
            case 'resolution_date':
                /*
                 * Display a dash when the resolution date is empty or contains a
                 * zeroed value.  WordPress and MySQL can sometimes return
                 * '0000-00-00 00:00:00' or other zero dates for uninitialized
                 * datetime columns.  Trim the value and check the prefix to
                 * catch any such placeholder.  If a valid timestamp exists,
                 * return it verbatim (escaped for output).
                 */
                $date = isset($item['resolution_date']) ? trim($item['resolution_date']) : '';
                if (empty($date)) {
                    return '&mdash;';
                }
                // Treat any date string starting with '0000-00-00' as empty.
                if (0 === strpos($date, '0000-00-00')) {
                    return '&mdash;';
                }
                return esc_html($date);
            default:
                return '';
        }
    }
    public function no_items()
    {
        esc_html_e('No resolved items yet.', 'linksentinel');
    }
    public function prepare_items()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort order for display; no state is changed.
        $raw_order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $order     = ( 'asc' === strtolower( $raw_order ) ) ? 'ASC' : 'DESC';
        $current_page = $this->get_pagenum();
        $last_started = get_option('rfx_scan_last_started');
        // The scope clause is built exclusively from the literal SQL fragments
        // below; user input only ever enters the query via placeholders.
        $scope_sql = '';
        $params = ['resolved'];
        if ('current' === $this->scope && !empty($last_started)) {
            $scope_sql = ' AND resolution_date IS NOT NULL AND resolution_date >= %s';
            $params[] = $last_started;
        } elseif ('previous' === $this->scope && !empty($last_started)) {
            $scope_sql = ' AND (resolution_date IS NULL OR resolution_date < %s)';
            $params[] = $last_started;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name from $wpdb->prefix and a literal, $scope_sql is literal SQL with placeholders, $order is validated against a whitelist above.
        // Total items
        $total_items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$table_name} WHERE resolution_status = %s{$scope_sql}", $params));
        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $offset = ($current_page - 1) * $per_page;
        // Retrieve items for the current page.
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} WHERE resolution_status = %s{$scope_sql} ORDER BY resolution_date {$order} LIMIT %d OFFSET %d", array_merge($params, [$per_page, $offset])), ARRAY_A);
        // phpcs:enable
        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }
}

/**
 * Pending links list table.
 */
class Link_Sentinel_Pending_Links_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'pending_link',
            'plural' => 'pending_links',
            'ajax' => false,
        ]);
    }
    public function get_columns()
    {
        return [
            'post_id' => __('Post', 'linksentinel'),
            'original_url' => __('Original URL', 'linksentinel'),
            'final_url' => __('Detected URL', 'linksentinel'),
            'http_status' => __('HTTP', 'linksentinel'),
            'status_message' => __('Note', 'linksentinel'),
            'scan_date' => __('Date Scanned', 'linksentinel'),
        ];
    }
    public function get_sortable_columns()
    {
        return ['scan_date' => ['scan_date', true]];
    }
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'post_id':
                $post_title = get_the_title($item['post_id']);
                if (empty($post_title)) {
                    $post_title = sprintf(
                        /* translators: %d: post ID. */
                        __('Post #%d', 'linksentinel'),
                        $item['post_id']
                    );
                }
                $edit_link = get_edit_post_link($item['post_id']);
                return sprintf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($post_title));
            case 'original_url':
                return '<code>' . esc_html($item['original_url']) . '</code>';
            case 'final_url':
                return !empty($item['final_url']) ? '<code>' . esc_html($item['final_url']) . '</code>' : '';
            case 'http_status':
                return esc_html($item['http_status']);
            case 'status_message':
                return esc_html($item['status_message']);
            case 'scan_date':
                return esc_html($item['scan_date']);
            default:
                return '';
        }
    }
    public function no_items()
    {
        esc_html_e('Nothing to review. Great job!', 'linksentinel');
    }
    public function prepare_items()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort order for display; no state is changed.
        $raw_order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $order     = ( 'asc' === strtolower( $raw_order ) ) ? 'ASC' : 'DESC';
        $current_page = $this->get_pagenum();
        /*
         * Only include pending items that have not been resolved and have HTTP status codes below 400.
         * Broken links (status >= 400) are displayed exclusively in the Broken Links table.
         */
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name from $wpdb->prefix and a literal, $order is validated against a whitelist above.
        $total_items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE resolution_status = %s AND ( http_status < %d OR http_status IS NULL )", 'pending', 400));
        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $offset = ($current_page - 1) * $per_page;
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE resolution_status = %s AND ( http_status < %d OR http_status IS NULL ) ORDER BY scan_date $order LIMIT %d OFFSET %d",
                'pending',
                400,
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable
        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    /**
     * Custom column output for the original URL column.
     *
     * Displays the original URL as code and adds a "Resolve Now" row action
     * when there is a detected final URL available.  The action passes
     * the record ID and a nonce via data attributes for the JS handler.
     *
     * @param array  $item The current row data.
     * @return string HTML output for the column.
     */
    public function column_original_url($item)
    {
        $value = '<code>' . esc_html($item['original_url']) . '</code>';
        $actions = [];
        // Only show a resolve action if we have a final URL to replace with.
        if (!empty($item['final_url'])) {
            // Use a global nonce for all rows; verification happens server side.
            $nonce = wp_create_nonce('rfx_resolve_link_nonce');
            $actions['resolve'] = sprintf(
                '<a href="#" class="rfx-resolve-link" data-id="%1$s" data-nonce="%2$s">%3$s</a>',
                esc_attr($item['id']),
                esc_attr($nonce),
                esc_html__('Resolve Now', 'linksentinel')
            );
        }
        return $value . $this->row_actions($actions);
    }
}

/**
 * Broken links list table.
 *
 * Displays only pending items where the HTTP status code is 400 or greater,
 * which indicates a client or server error.  Broken links require manual
 * intervention from the user.  Columns largely mirror the pending table
 * but omit the 'Detected URL' column since the final URL may be blank.
 */
class Link_Sentinel_Broken_Links_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'broken_link',
            'plural' => 'broken_links',
            'ajax' => false,
        ]);
    }
    public function get_columns()
    {
        /*
         * We insert a dedicated Change Link column immediately to the right of
         * the Original URL column.  This provides a consistent location for the
         * inline editing UI.  The order here determines column order on screen.
         */
        return [
            'post_id' => __('Post', 'linksentinel'),
            'original_url' => __('Original URL', 'linksentinel'),
            'change' => __('Change Link', 'linksentinel'),
            'http_status' => __('HTTP', 'linksentinel'),
            'status_message' => __('Note', 'linksentinel'),
            'scan_date' => __('Date Scanned', 'linksentinel'),
        ];
    }
    public function get_sortable_columns()
    {
        return ['scan_date' => ['scan_date', true]];
    }
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'post_id':
                $post_title = get_the_title($item['post_id']);
                if (empty($post_title)) {
                    $post_title = sprintf(
                        /* translators: %d: post ID. */
                        __('Post #%d', 'linksentinel'),
                        $item['post_id']
                    );
                }
                $edit_link = get_edit_post_link($item['post_id']);
                return sprintf('<a href="%s">%s</a>', esc_url($edit_link), esc_html($post_title));
            case 'original_url':
                // Only output the URL itself in this column.  Change actions are
                // rendered in a separate column to the right.
                return '<code>' . esc_html($item['original_url']) . '</code>';
            case 'http_status':
                return esc_html($item['http_status']);
            case 'status_message':
                return esc_html($item['status_message']);
            case 'scan_date':
                return esc_html($item['scan_date']);
            default:
                return '';
        }
    }
    public function no_items()
    {
        esc_html_e('No broken links found.', 'linksentinel');
    }

    /**
     * Custom output for the change column in the broken links table.
     *
     * We render a simple link labelled "Change".  When clicked, the
     * JavaScript will replace this cell with an inline form consisting
     * of a text input and a "Change" button.  The anchor stores the
     * record ID, nonce and original URL as data attributes for use by JS.
     *
     * @param array $item The current row.
     * @return string HTML for the Change column.
     */
    public function column_change($item)
    {
        $nonce = wp_create_nonce('rfx_change_link_nonce');
        return sprintf(
            '<a href="#" class="rfx-change-inline" data-id="%1$s" data-nonce="%2$s" data-original-url="%3$s">%4$s</a>',
            esc_attr($item['id']),
            esc_attr($nonce),
            esc_attr($item['original_url']),
            esc_html__('Change', 'linksentinel')
        );
    }
    public function prepare_items()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort order for display; no state is changed.
        $raw_order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
        $order     = ( 'asc' === strtolower( $raw_order ) ) ? 'ASC' : 'DESC';
        $current_page = $this->get_pagenum();
        // Only pending items with status >= 400 are considered broken.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name from $wpdb->prefix and a literal, $order is validated against a whitelist above.
        $total_items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name WHERE resolution_status = %s AND http_status >= %d", 'pending', 400));
        $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page]);
        $offset = ($current_page - 1) * $per_page;
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE resolution_status = %s AND http_status >= %d ORDER BY scan_date $order LIMIT %d OFFSET %d",
                'pending',
                400,
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable
        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }
}
