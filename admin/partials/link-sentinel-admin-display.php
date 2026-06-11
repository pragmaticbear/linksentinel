<?php

/**
 * Provide a admin area view for the plugin
 *
 * @link       https://www.pragmaticbear.com
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/admin/partials
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="wrap rfx-dashboard">
    <h1><?php esc_html_e('LinkSentinel', 'linksentinel'); ?></h1>

    <?php
    // Last scan information.
    $link_sentinel_last_started = get_option('rfx_scan_last_started');
    $link_sentinel_last_type = get_option('rfx_scan_last_type');
    if (!empty($link_sentinel_last_started)) {
        $link_sentinel_type_label = ('manual' === $link_sentinel_last_type) ? __('Manual', 'linksentinel') : __('Automatic', 'linksentinel');
        echo '<p style="margin: 8px 0 16px; color:#50575e;">' . esc_html__('Last scan:', 'linksentinel') . ' ' . esc_html($link_sentinel_last_started) . ' (' . esc_html($link_sentinel_type_label) . ')</p>';
    }
    ?>

    <!-- Top card: Manual scan controls. -->
    <div class="postbox" style="padding:16px 24px; margin-bottom:20px;">
        <h2 style="margin-top:0;"><?php esc_html_e('Manual Scan', 'linksentinel'); ?></h2>
        <p><?php esc_html_e('Run a manual scan of your internal links. Results appear below once processing completes.', 'linksentinel'); ?>
        </p>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" id="rfx-start-scan"
                class="button button-primary"><?php esc_html_e('Scan Now', 'linksentinel'); ?></button>
            <span id="rfx-scan-feedback" style="display:none; margin-left:10px; align-self:center;"></span>
        </div>
        <!-- Progress bar container (hidden by default) -->
        <div id="rfx-scan-status" class="notice notice-info" style="display:none; padding:10px; margin-top:12px;">
            <p style="margin:0; display:flex; align-items:center; gap:8px;"><span class="spinner is-active"
                    style="float:none; visibility:visible;"></span><strong><?php esc_html_e('Scan in progress', 'linksentinel'); ?></strong>
                <span id="rfx-scan-status-text"></span></p>
            <div style="background:#e5e5e5; height:8px; border-radius:4px; margin-top:8px; overflow:hidden;">
                <div id="rfx-scan-progress" style="height:8px; width:0; background:#2271b1;"></div>
            </div>
        </div>
    </div>

    <?php
    /*
     * Generate tab navigation with counts for pending and broken items.
     */
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live counts from the plugin's custom table for display.
    $link_sentinel_pending_count_nav = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}rfx_link_monitor WHERE resolution_status = %s AND ( http_status < %d OR http_status IS NULL )", 'pending', 400));
    $link_sentinel_broken_count_nav = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}rfx_link_monitor WHERE resolution_status = %s AND http_status >= %d", 'pending', 400));
    // phpcs:enable
    ?>
    <h2 class="nav-tab-wrapper">
        <a href="#resolved" class="nav-tab nav-tab-active"><?php esc_html_e('Resolved Links', 'linksentinel'); ?></a>
        <a href="#pending"
            class="nav-tab"><?php
            printf(
                /* translators: %d: number of pending redirects. */
                esc_html__('Pending Redirects (%d)', 'linksentinel'),
                absint($link_sentinel_pending_count_nav)
            );
            ?></a>
        <a href="#broken"
            class="nav-tab"><?php
            printf(
                /* translators: %d: number of broken links. */
                esc_html__('Broken Links (%d)', 'linksentinel'),
                absint($link_sentinel_broken_count_nav)
            );
            ?></a>
        <a href="#settings" class="nav-tab"><?php esc_html_e('Settings', 'linksentinel'); ?></a>
    </h2>

    <!-- Resolved tab content -->
    <div id="resolved" class="tab-content" style="display:block;">
        <form method="post">
            <?php
            // Build export URL with nonce for CSV download.
            $link_sentinel_export_nonce = wp_create_nonce('rfx_export_resolved_csv');
            $link_sentinel_export_url = add_query_arg([
                'action' => 'rfx_export_resolved_csv',
                '_wpnonce' => $link_sentinel_export_nonce,
            ], admin_url('admin-post.php'));

            // Nonce and URL for clearing the resolved table.
            $link_sentinel_clear_nonce = wp_create_nonce('rfx_clear_resolved_links');
            $link_sentinel_clear_url = add_query_arg([
                'action' => 'rfx_clear_resolved_links',
                '_wpnonce' => $link_sentinel_clear_nonce,
            ], admin_url('admin-post.php'));
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin-top:10px;"><?php esc_html_e('Resolved Links', 'linksentinel'); ?></h3>
                <div style="display:flex; gap:8px;">
                    <a href="<?php echo esc_url($link_sentinel_clear_url); ?>" class="button"
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clear all resolved links? This action cannot be undone.', 'linksentinel')); ?>');"><?php esc_html_e('Clear Table', 'linksentinel'); ?></a>
                    <a href="<?php echo esc_url($link_sentinel_export_url); ?>"
                        class="button"><?php esc_html_e('Download CSV', 'linksentinel'); ?></a>
                </div>
            </div>
            <?php
            $link_sentinel_resolved_table = new Link_Sentinel_Resolved_Links_List_Table();
            $link_sentinel_resolved_table->set_scope('all');
            $link_sentinel_resolved_table->prepare_items();
            $link_sentinel_resolved_table->display();
            ?>
        </form>
    </div>

    <!-- Pending redirects tab content -->
    <div id="pending" class="tab-content" style="display:none;">
        <form method="post">
            <?php
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count from the plugin's custom table for display.
            $link_sentinel_pending_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}rfx_link_monitor WHERE resolution_status = %s AND final_url <> '' AND ( http_status < %d OR http_status IS NULL )", 'pending', 400));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only success notice; no state is changed.
            if (isset($_GET['pending-cleared']) && '1' === sanitize_text_field(wp_unslash($_GET['pending-cleared']))) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Pending redirects cleared.', 'linksentinel') . '</p></div>';
            }
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin-top:10px;"><?php esc_html_e('Pending Redirects', 'linksentinel'); ?></h3>
                <?php if ($link_sentinel_pending_count > 0):
                    $link_sentinel_resolve_all_nonce = wp_create_nonce('rfx_resolve_all_nonce');
                    $link_sentinel_clear_pending_nonce = wp_create_nonce('rfx_clear_pending_redirects');
                    $link_sentinel_clear_pending_url = add_query_arg([
                        'action' => 'rfx_clear_pending_redirects',
                        '_wpnonce' => $link_sentinel_clear_pending_nonce,
                    ], admin_url('admin-post.php'));
                    ?>
                    <div style="display:flex; gap:8px;">
                        <a href="<?php echo esc_url($link_sentinel_clear_pending_url); ?>" class="button button-secondary"
                            onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clear all pending redirects? This cannot be undone.', 'linksentinel')); ?>');"><?php esc_html_e('Clear Table', 'linksentinel'); ?></a>
                        <button type="button" id="rfx-resolve-all" class="button button-secondary"
                            data-nonce="<?php echo esc_attr($link_sentinel_resolve_all_nonce); ?>"
                            data-total="<?php echo esc_attr($link_sentinel_pending_count); ?>"
                            style="margin-bottom:4px;"><?php esc_html_e('Resolve All', 'linksentinel'); ?></button>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            $link_sentinel_pend_table = new Link_Sentinel_Pending_Links_List_Table();
            $link_sentinel_pend_table->prepare_items();
            $link_sentinel_pend_table->display();
            ?>
        </form>
    </div>

    <!-- Broken links tab content -->
    <div id="broken" class="tab-content" style="display:none;">
        <form method="post">
            <?php
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count from the plugin's custom table for display.
            $link_sentinel_broken_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM {$wpdb->prefix}rfx_link_monitor WHERE resolution_status = %s AND http_status >= %d", 'pending', 400));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only success notice; no state is changed.
            if (isset($_GET['broken-cleared']) && '1' === sanitize_text_field(wp_unslash($_GET['broken-cleared']))) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Broken links cleared.', 'linksentinel') . '</p></div>';
            }
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin-top:10px;"><?php esc_html_e('Broken Links', 'linksentinel'); ?></h3>
                <?php if ($link_sentinel_broken_count > 0):
                    $link_sentinel_clear_broken_nonce = wp_create_nonce('rfx_clear_broken_links');
                    $link_sentinel_clear_broken_url = add_query_arg([
                        'action' => 'rfx_clear_broken_links',
                        '_wpnonce' => $link_sentinel_clear_broken_nonce,
                    ], admin_url('admin-post.php'));
                    ?>
                    <a href="<?php echo esc_url($link_sentinel_clear_broken_url); ?>" class="button button-secondary"
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clear all broken links? This cannot be undone.', 'linksentinel')); ?>');"><?php esc_html_e('Clear Table', 'linksentinel'); ?></a>
                <?php endif; ?>
            </div>
            <?php
            $link_sentinel_broken_table = new Link_Sentinel_Broken_Links_List_Table();
            $link_sentinel_broken_table->prepare_items();
            $link_sentinel_broken_table->display();
            ?>
        </form>
    </div>

    <!-- Settings tab content -->
    <div id="settings" class="tab-content" style="display:none;">
        <h3><?php esc_html_e('Settings', 'linksentinel'); ?></h3>
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only success notice; no state is changed.
        if (isset($_GET['settings-updated']) && 'true' === sanitize_text_field(wp_unslash($_GET['settings-updated']))) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings updated.', 'linksentinel') . '</p></div>';
        }
        // Fetch current settings to pre-populate form.
        $link_sentinel_settings = get_option('rfx_settings', []);
        $link_sentinel_current_types = (isset($link_sentinel_settings['post_types']) && is_array($link_sentinel_settings['post_types'])) ? $link_sentinel_settings['post_types'] : ['post', 'page'];
        $link_sentinel_external_redirects = get_option('rfx_follow_external_redirects', '0');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('rfx_save_settings', 'rfx_settings_nonce'); ?>
            <input type="hidden" name="action" value="rfx_save_settings" />
            <table class="form-table" role="presentation">
                <tbody>

                    <!-- Automatic permanent redirect resolution option. -->
                    <?php $link_sentinel_auto_resolve_setting = (isset($link_sentinel_settings['auto_resolve_permanent']) && $link_sentinel_settings['auto_resolve_permanent']); ?>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Auto-resolve permanent redirects', 'linksentinel'); ?></th>
                        <td>
                            <label><input type="checkbox" name="auto_resolve_permanent" value="1" <?php checked(true, $link_sentinel_auto_resolve_setting); ?> />
                                <?php esc_html_e('Automatically update links that return a 301 or 308 status when scanning.', 'linksentinel'); ?></label>
                            <p class="description">
                                <?php esc_html_e('When enabled, permanently redirected links will be updated without requiring manual review.', 'linksentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- External redirect resolution toggle. -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Resolve external redirects (slower)', 'linksentinel'); ?>
                        </th>
                        <td>
                            <label><input type="checkbox" name="follow_external_redirects" value="1" <?php checked('1', $link_sentinel_external_redirects); ?> />
                                <?php esc_html_e('Follow and resolve external redirect chains (may be slower and can cause timeouts on some hosts).', 'linksentinel'); ?></label>
                            <p class="description">
                                <?php esc_html_e('Enable this if you need to trace redirects that leave your domain. Leave it off to keep scans faster.', 'linksentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Post types field -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Post types to scan', 'linksentinel'); ?></th>
                        <td>
                            <?php
                            $link_sentinel_public_types = get_post_types(['public' => true], 'objects');
                            foreach ($link_sentinel_public_types as $link_sentinel_type) {
                                printf(
                                    '<label style="display:inline-block; margin-right:10px;"><input type="checkbox" name="post_types[]" value="%s" %s /> %s</label>',
                                    esc_attr($link_sentinel_type->name),
                                    checked(in_array($link_sentinel_type->name, $link_sentinel_current_types, true), true, false),
                                    esc_html($link_sentinel_type->labels->singular_name)
                                );
                            }
                            ?>
                            <p class="description">
                                <?php esc_html_e('Select which post types should be included in scans. Defaults to posts and pages.', 'linksentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Scheduled scans section -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Automatic Scanning', 'linksentinel'); ?></th>
                        <td>
                            <?php $link_sentinel_enable_scheduled = !empty($link_sentinel_settings['enable_scheduled_scans']); ?>
                            <label><input type="checkbox" name="enable_scheduled_scans" value="1" <?php checked(true, $link_sentinel_enable_scheduled); ?> />
                                <?php esc_html_e('Enable automatic scheduled scans', 'linksentinel'); ?></label>
                            <p class="description">
                                <?php esc_html_e('When enabled, scans will run automatically according to the schedule below.', 'linksentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Scan frequency -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Scan Frequency', 'linksentinel'); ?></th>
                        <td>
                            <?php
                            $link_sentinel_current_frequency = isset($link_sentinel_settings['scan_frequency']) ? $link_sentinel_settings['scan_frequency'] : 'daily';
                            $link_sentinel_frequencies = [
                                'daily' => __('Daily', 'linksentinel'),
                                'twicedaily' => __('Twice Daily', 'linksentinel'),
                                'weekly' => __('Weekly', 'linksentinel'),
                            ];
                            ?>
                            <select name="scan_frequency">
                                <?php foreach ($link_sentinel_frequencies as $link_sentinel_value => $link_sentinel_label): ?>
                                    <option value="<?php echo esc_attr($link_sentinel_value); ?>" <?php selected($link_sentinel_current_frequency, $link_sentinel_value); ?>><?php echo esc_html($link_sentinel_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('How often automatic scans should run.', 'linksentinel'); ?></p>
                        </td>
                    </tr>

                    <!-- Scan time -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Scan Time', 'linksentinel'); ?></th>
                        <td>
                            <?php $link_sentinel_current_time = isset($link_sentinel_settings['scan_time']) ? $link_sentinel_settings['scan_time'] : '02:00'; ?>
                            <input type="time" name="scan_time" value="<?php echo esc_attr($link_sentinel_current_time); ?>" />
                            <p class="description">
                                <?php esc_html_e('What time of day scans should run (24-hour format). Default is 02:00 (2:00 AM).', 'linksentinel'); ?>
                            </p>

                            <?php
                            // Show next scheduled scan
                            $link_sentinel_next_scheduled = wp_next_scheduled('rfx_scheduled_scan');
                            if ($link_sentinel_next_scheduled) {
                                // Display in WordPress timezone with timezone abbreviation
                                $link_sentinel_next_run = wp_date('Y-m-d H:i:s T', $link_sentinel_next_scheduled);
                                echo '<p class="description"><strong>' . sprintf(
                                    /* translators: %s: date and time of the next scheduled scan. */
                                    esc_html__('Next scheduled scan: %s', 'linksentinel'),
                                    esc_html($link_sentinel_next_run)
                                ) . '</strong></p>';
                            } elseif ($link_sentinel_enable_scheduled) {
                                echo '<p class="description"><em>' . esc_html__('Save settings to schedule the next scan.', 'linksentinel') . '</em></p>';
                            }

                            // Show current WordPress timezone for reference
                            $link_sentinel_wp_timezone = wp_timezone();
                            $link_sentinel_timezone_name = $link_sentinel_wp_timezone->getName();
                            echo '<p class="description">' . sprintf(
                                /* translators: %s: site timezone name. */
                                esc_html__('Times are based on your site timezone: %s', 'linksentinel'),
                                esc_html($link_sentinel_timezone_name)
                            ) . '</p>';
                            ?>
                        </td>
                    </tr>

                    <!-- Scan batch size -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Scan Batch Size', 'linksentinel'); ?></th>
                        <td>
                            <?php $link_sentinel_current_batch_size = isset($link_sentinel_settings['scan_batch_size']) ? (int) $link_sentinel_settings['scan_batch_size'] : 25; ?>
                            <input type="number" name="scan_batch_size"
                                value="<?php echo esc_attr($link_sentinel_current_batch_size); ?>" min="5" max="100" />
                            <p class="description">
                                <?php esc_html_e('Number of posts to process in each batch during scans (5-100). Default is 25.', 'linksentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Resolve All batching controls -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Resolve All batching', 'linksentinel'); ?></th>
                        <td>
                            <?php
                            $link_sentinel_resolve_all_custom = !empty($link_sentinel_settings['resolve_all_custom']);
                            $link_sentinel_resolve_all_batch_size = isset($link_sentinel_settings['resolve_all_batch_size']) ? (int) $link_sentinel_settings['resolve_all_batch_size'] : 5;
                            $link_sentinel_resolve_all_cooldown = isset($link_sentinel_settings['resolve_all_cooldown']) ? (int) $link_sentinel_settings['resolve_all_cooldown'] : 0;
                            ?>
                            <label><input type="checkbox" name="resolve_all_custom" value="1" <?php checked(true, $link_sentinel_resolve_all_custom); ?> />
                                <?php esc_html_e('Use custom batch size and cooldown for Resolve All', 'linksentinel'); ?></label>
                            <p class="description" style="margin-bottom:8px;">
                                <?php esc_html_e('When enabled, the values below override the default Resolve All pacing (useful for slow or rate-limited hosts).', 'linksentinel'); ?>
                            </p>

                            <div style="display:flex; flex-wrap:wrap; gap:12px;">
                                <label><?php esc_html_e('Links per batch', 'linksentinel'); ?><br /><input
                                        type="number" name="resolve_all_batch_size"
                                        value="<?php echo esc_attr($link_sentinel_resolve_all_batch_size); ?>" min="1" max="50"
                                        style="width:120px;" /></label>
                                <label><?php esc_html_e('Cooldown (seconds)', 'linksentinel'); ?><br /><input
                                        type="number" name="resolve_all_cooldown"
                                        value="<?php echo esc_attr($link_sentinel_resolve_all_cooldown); ?>" min="0"
                                        max="<?php echo esc_attr(15 * MINUTE_IN_SECONDS); ?>" step="5"
                                        style="width:140px;" /></label>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Example: 50 links with a 120-second cooldown processes 50 redirects, pauses for 2 minutes, then continues automatically. Leave cooldown at 0 for back-to-back batches.', 'linksentinel'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Clean uninstall option -->
                    <?php $link_sentinel_delete_on_uninstall = ! empty( $link_sentinel_settings['delete_data_on_uninstall'] ); ?>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Clean uninstall', 'linksentinel' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( true, $link_sentinel_delete_on_uninstall ); ?> />
                                <?php esc_html_e( 'Delete all plugin data (including scan history) when uninstalling.', 'linksentinel' ); ?></label>
                            <p class="description">
                                <?php esc_html_e( 'When enabled, the link monitor table and all scan data will be permanently removed if you delete the plugin. Leave unchecked to preserve historical data.', 'linksentinel' ); ?>
                            </p>
                        </td>
                    </tr>

                </tbody>
            </table>
            <p><button type="submit"
                    class="button button-primary"><?php esc_html_e('Save Settings', 'linksentinel'); ?></button></p>
        </form>
    </div>

</div>
<p style="margin-top:20px; text-align:center; color:#777; font-size:13px;">&copy;<?php echo esc_html( current_time( 'Y' ) ); ?> <a
        href="https://www.pragmaticbear.com" target="_blank"
        rel="noopener"><?php esc_html_e('Pragmatic Bear', 'linksentinel'); ?></a>.</p>
