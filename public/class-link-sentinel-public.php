<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/public
 */

defined( 'ABSPATH' ) || exit;

class Link_Sentinel_Public
{

    /**
     * Tighten HTTP timeouts for resolve runs.
     *
     * @param array  $args Request arguments.
     * @param string $url  Request URL.
     * @return array Modified arguments.
     */
    public function tighten_timeouts($args, $url)
    {
        // Only apply timeout caps during LinkSentinel scan operations.
        if (empty($args['_rfx_scan_request'])) {
            return $args;
        }

        $home = home_url();
        $parsed_home = wp_parse_url($home);
        $parsed_url  = wp_parse_url($url);
        $internal    = (
            isset($parsed_home['host'], $parsed_url['host'])
            && strtolower($parsed_home['host']) === strtolower($parsed_url['host'])
        );

        $cap = $internal ? 1.5 : 2.0;
        if (!isset($args['timeout']) || (float) $args['timeout'] > $cap) {
            $args['timeout'] = $cap;
        }
        return $args;
    }

    /**
     * Filter to determine if external redirects should be followed.
     *
     * @param bool   $enabled Whether to follow external redirects.
     * @param string $url     The URL being checked.
     * @return bool
     */
    public function filter_follow_external_redirects($enabled, $url = '')
    {
        $opt = get_option('rfx_follow_external_redirects', '');
        if ($opt === '1')
            return true;
        if ($opt === '0')
            return false;
        return (bool) $enabled;
    }
}
