<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      5.0.1
 * @package    LinkSentinel
 * @subpackage LinkSentinel/includes
 */

defined( 'ABSPATH' ) || exit;

class Link_Sentinel
{

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    5.0.1
     * @access   protected
     * @var      Link_Sentinel_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * Define the core functionality of the plugin.
     *
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     * @since    5.0.1
     */
    public function __construct()
    {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_scheduled_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - Link_Sentinel_Loader. Orchestrates the hooks of the plugin.
     * - Link_Sentinel_Admin. Defines all hooks for the admin area.
     * - Link_Sentinel_Public. Defines all hooks for the public side of the site.
     *
     * @since    5.0.1
     * @access   private
     */
    private function load_dependencies()
    {

        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-link-sentinel-loader.php';

        /**
         * The class responsible for database interactions.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-link-sentinel-db.php';

        /**
         * The class responsible for scanning logic.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-link-sentinel-scanner.php';

        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-link-sentinel-admin.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-link-sentinel-ajax.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-link-sentinel-list-tables.php';

        /**
         * The class responsible for defining all actions that occur in the public-facing
         * side of the site.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-link-sentinel-public.php';

        $this->loader = new Link_Sentinel_Loader();
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    5.0.1
     * @access   private
     */
    private function define_admin_hooks()
    {
        $plugin_admin = new Link_Sentinel_Admin();

        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');

        // Legacy redirects
        $this->loader->add_action('admin_init', $plugin_admin, 'handle_legacy_redirects');
        $this->loader->add_action('admin_menu', $plugin_admin, 'remove_legacy_menus', 999);

        // Settings
        $this->loader->add_action('admin_post_rfx_save_settings', $plugin_admin, 'save_settings');

        // Clear table actions
        $this->loader->add_action('admin_post_rfx_clear_pending_redirects', $plugin_admin, 'clear_pending_redirects');
        $this->loader->add_action('admin_post_rfx_clear_resolved_links', $plugin_admin, 'clear_resolved_links');
        $this->loader->add_action('admin_post_rfx_clear_broken_links', $plugin_admin, 'clear_broken_links');

        // Export
        $this->loader->add_action('admin_post_rfx_export_resolved_csv', $plugin_admin, 'export_resolved_csv');

        // AJAX
        $this->loader->add_action('wp_ajax_rfx_start_scan', 'Link_Sentinel_Ajax', 'start_scan');
        $this->loader->add_action('wp_ajax_rfx_step_scan', 'Link_Sentinel_Ajax', 'step_scan');
        $this->loader->add_action('wp_ajax_rfx_scan_status', 'Link_Sentinel_Ajax', 'scan_status');
        $this->loader->add_action('wp_ajax_rfx_resolve_link', 'Link_Sentinel_Ajax', 'resolve_link');
        $this->loader->add_action('wp_ajax_rfx_test_db_connection', 'Link_Sentinel_Ajax', 'test_db_connection');
        $this->loader->add_action('wp_ajax_rfx_resolve_all', 'Link_Sentinel_Ajax', 'resolve_all');
        $this->loader->add_action('wp_ajax_rfx_change_link', 'Link_Sentinel_Ajax', 'change_link');
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     *
     * @since    5.0.1
     * @access   private
     */
    private function define_public_hooks()
    {
        $plugin_public = new Link_Sentinel_Public();

        $this->loader->add_filter('http_request_args', $plugin_public, 'tighten_timeouts', 10, 2);
        $this->loader->add_filter('link_sentinel_follow_external_redirects', $plugin_public, 'filter_follow_external_redirects', 9, 2);
    }

    /**
     * Define scheduled hooks.
     */
    private function define_scheduled_hooks()
    {
        // Register the 'weekly' cron interval so wp_schedule_event accepts it.
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-link-sentinel-activator.php';
        $this->loader->add_filter('cron_schedules', 'Link_Sentinel_Activator', 'add_cron_schedules');

        $this->loader->add_action('rfx_scheduled_scan', 'Link_Sentinel_Scanner', 'handle_scheduled_scan');

        // Activate the plugin for newly created sites on multisite networks.
        $this->loader->add_action('wp_initialize_site', $this, 'activate_new_site', 10, 1);

        // DB Upgrade check
        $this->loader->add_action('plugins_loaded', $this, 'check_db_version');
    }

    /**
     * Run activation for a newly created site on a multisite network.
     *
     * @param WP_Site $new_site The newly created site object.
     */
    public function activate_new_site( $new_site )
    {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_file = plugin_basename( dirname( __DIR__ ) . '/link-sentinel.php' );
        if ( ! is_plugin_active_for_network( $plugin_file ) ) {
            return;
        }
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-link-sentinel-activator.php';
        switch_to_blog( $new_site->blog_id );
        Link_Sentinel_Activator::activate();
        restore_current_blog();
    }

    /**
     * Check DB version and run activation if needed.
     */
    public function check_db_version()
    {
        if (get_option('rfx_db_version') !== (defined('LINKSENTINEL_DB_VERSION') ? LINKSENTINEL_DB_VERSION : '7')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-link-sentinel-activator.php';
            Link_Sentinel_Activator::activate();
        }
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    5.0.1
     */
    public function run()
    {
        $this->loader->run();
    }

}
