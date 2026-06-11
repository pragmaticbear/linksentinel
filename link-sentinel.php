<?php
/**
 * Plugin Name:       LinkSentinel
 * Description:       Scan internal links for redirects & breakage. Auto-fix 301/308 permanently redirected links; queue 302/307 and broken links for review. Includes a dashboard with progress indicators, automatic scheduled scans, and CSV export for resolved links.
 * Version:           5.6
 * Author:            Pragmatic Bear
 * Author URI:        https://www.pragmaticbear.com
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linksentinel
 * Domain Path:       /languages
 */

/*
 * Main bootstrap file for the LinkSentinel plugin.
 *
 * This file sets up plugin defaults, registers hooks and loads the
 * administrative interface.  It is intentionally lean and avoids
 * hard-coding any environment-specific configuration.  When introducing
 * new functionality, keep the bootstrap minimal and gate any privileged
 * actions behind appropriate WordPress capability checks.
 */

defined('ABSPATH') || die('No direct access allowed.');

// Define a version constant for internal use.
if (!defined('LINKSENTINEL_VERSION')) {
    define('LINKSENTINEL_VERSION', '5.6');
}

// Define DB version constant
if (!defined('LINKSENTINEL_DB_VERSION')) {
    define('LINKSENTINEL_DB_VERSION', '7');
}

/**
 * The code that runs during plugin activation.
 *
 * When network-activated on a multisite installation, iterates
 * over all existing sites and runs the activation routine for each.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
function link_sentinel_activate( $network_wide = false )
{
    require_once plugin_dir_path(__FILE__) . 'includes/class-link-sentinel-activator.php';

    if ( is_multisite() && $network_wide ) {
        $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            Link_Sentinel_Activator::activate();
            restore_current_blog();
        }
    } else {
        Link_Sentinel_Activator::activate();
    }
}

/**
 * The code that runs during plugin deactivation.
 *
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 */
function link_sentinel_deactivate( $network_wide = false )
{
    require_once plugin_dir_path(__FILE__) . 'includes/class-link-sentinel-activator.php';

    if ( is_multisite() && $network_wide ) {
        $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            Link_Sentinel_Activator::deactivate();
            restore_current_blog();
        }
    } else {
        Link_Sentinel_Activator::deactivate();
    }
}

register_activation_hook(__FILE__, 'link_sentinel_activate');
register_deactivation_hook(__FILE__, 'link_sentinel_deactivate');

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path(__FILE__) . 'includes/class-link-sentinel.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    5.0.1
 */
function link_sentinel_run()
{
    $plugin = new Link_Sentinel();
    $plugin->run();
}
link_sentinel_run();
