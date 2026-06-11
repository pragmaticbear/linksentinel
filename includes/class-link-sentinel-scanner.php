<?php

/**
 * Core scanning and link resolution logic.
 *
 * @since      5.0.1
 *
 * @package    LinkSentinel
 * @subpackage LinkSentinel/includes
 */

defined( 'ABSPATH' ) || exit;

class Link_Sentinel_Scanner
{

    /**
     * Write a debug message to the PHP error log when WP_DEBUG is enabled.
     *
     * @param string $message Message to log.
     */
    private static function log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, gated behind WP_DEBUG.
            error_log($message);
        }
    }

    /**
     * Retrieve Resolve All batching preferences derived from settings.
     *
     * @return array
     */
    public static function get_resolve_all_preferences()
    {
        $settings = get_option('rfx_settings', []);
        $custom_resolve = !empty($settings['resolve_all_custom']);

        $default_batch = 5;
        if ($custom_resolve) {
            $custom_batch = isset($settings['resolve_all_batch_size']) ? (int) $settings['resolve_all_batch_size'] : $default_batch;
            $default_batch = max(1, $custom_batch);
        }

        $default_delay_ms = 800;
        $custom_cooldown = 0;
        if ($custom_resolve) {
            $custom_cooldown = isset($settings['resolve_all_cooldown']) ? (int) $settings['resolve_all_cooldown'] : 0;
            $custom_cooldown = max(0, min(15 * MINUTE_IN_SECONDS, $custom_cooldown));
            $default_delay_ms = $custom_cooldown * 1000;
        }

        $resolve_all_batch = (int) apply_filters('link_sentinel_resolve_all_batch_size', $default_batch, false);
        // Back-compat for the pre-5.6 filter name.
        $resolve_all_batch = (int) apply_filters_deprecated('rfx_resolve_all_batch_size', [$resolve_all_batch, false], '5.6', 'link_sentinel_resolve_all_batch_size');
        $resolve_all_batch = max(1, $resolve_all_batch);

        $resolve_all_delay = (int) apply_filters('link_sentinel_resolve_all_request_delay_ms', $default_delay_ms, false);
        // Back-compat for the pre-5.6 filter name.
        $resolve_all_delay = (int) apply_filters_deprecated('rfx_resolve_all_request_delay_ms', [$resolve_all_delay, false], '5.6', 'link_sentinel_resolve_all_request_delay_ms');
        if ($resolve_all_delay < 0) {
            $resolve_all_delay = 0;
        }

        return [
            'batch' => $resolve_all_batch,
            'delay_ms' => $resolve_all_delay,
            'custom' => $custom_resolve,
            'cooldown_seconds' => $custom_cooldown,
        ];
    }

    /**
     * Handle scheduled scan execution.
     */
    public static function handle_scheduled_scan()
    {
        // Prevent concurrent scans
        if (get_transient('rfx_scheduled_scan_lock')) {
            return;
        }

        // Bail out if a manual scan is currently active to prevent
        // concurrent scans from corrupting shared state.
        $manual_state = self::get_scan_state();
        if ($manual_state['active']) {
            return;
        }

        // Set lock for 2 hours to accommodate large sites (5000+ posts).
        set_transient('rfx_scheduled_scan_lock', time(), 2 * HOUR_IN_SECONDS);

        try {
            self::run_automatic_scan();
        } finally {
            delete_transient('rfx_scheduled_scan_lock');
        }
    }

    /**
     * Run an automatic scan in the background.
     */
    public static function run_automatic_scan()
    {
        // Check if manual scan is already running
        $state = self::get_scan_state();
        if ($state['active']) {
            return; // Don't interfere with manual scans
        }

        $total = Link_Sentinel_DB::count_scannable_posts();
        if (0 === $total) {
            return;
        }

        $settings = get_option('rfx_settings', []);
        $batch_size = isset($settings['scan_batch_size']) ? (int) $settings['scan_batch_size'] : 25;
        $batch_size = max(5, min(100, $batch_size));

        $started_at = current_time('mysql');

        // Set scan metadata
        update_option('rfx_scan_last_started', $started_at, false);
        update_option('rfx_scan_last_type', 'automatic', false);

        // Process all posts in batches
        $context = [
            'settings' => $settings,
            'auto_resolve' => !empty($settings['auto_resolve_permanent']),
            'hash_supported' => Link_Sentinel_DB::supports_hash(),
        ];

        $processed = 0;
        $last_id = 0;

        // Use WordPress performance optimizations
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);
        wp_suspend_cache_invalidation(true);

        try {
            do {
                $ids = Link_Sentinel_DB::get_scannable_post_ids_paged($last_id, $batch_size);
                if (empty($ids)) {
                    break;
                }

                foreach ($ids as $post_id) {
                    self::process_single_post((int) $post_id, $context);
                    $last_id = (int) $post_id;
                    $processed++;

                    // Respect time limits for long-running scans
                    if ($processed % 50 === 0) {
                        // Refresh the scheduled scan lock so it does not
                        // expire mid-scan on large sites.
                        set_transient('rfx_scheduled_scan_lock', time(), 2 * HOUR_IN_SECONDS);

                        // Allow other processes to run
                        usleep(100000); // 0.1 second pause every 50 posts
                    }
                }

            } while (count($ids) === $batch_size);

        } finally {
            wp_suspend_cache_invalidation(false);
            wp_defer_comment_counting(false);
            wp_defer_term_counting(false);
        }

        update_option('rfx_scan_last_finished', current_time('mysql'), false);
    }

    /**
     * Helper to determine if a URL is internal (same domain or a relative path).
     *
     * @param string $url The URL to examine.
     * @return bool True if internal, false otherwise.
     */
    public static function is_internal_link($url)
    {
        if (empty($url)) {
            return false;
        }

        if (0 === strpos($url, '//')) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        if (isset($url[0]) && '/' === $url[0]) {
            return true;
        }

        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $site_parts = wp_parse_url(home_url());
        $site_host = is_array($site_parts) && !empty($site_parts['host']) ? $site_parts['host'] : '';
        $site_scheme = is_array($site_parts) && !empty($site_parts['scheme']) ? strtolower($site_parts['scheme']) : '';
        $site_port_explicit = is_array($site_parts) && array_key_exists('port', (array) $site_parts);
        $site_port_value = $site_port_explicit ? (int) $site_parts['port'] : null;

        $link_parts = wp_parse_url($url);
        $link_host = is_array($link_parts) && !empty($link_parts['host']) ? $link_parts['host'] : '';
        $link_scheme = is_array($link_parts) && !empty($link_parts['scheme']) ? strtolower($link_parts['scheme']) : '';
        $link_port_explicit = is_array($link_parts) && array_key_exists('port', (array) $link_parts);
        $link_port_value = $link_port_explicit ? (int) $link_parts['port'] : null;

        if ('' === $link_host || '' === $site_host) {
            return false;
        }

        $hosts = apply_filters('link_sentinel_internal_hosts', [$site_host]);
        // Back-compat for the pre-5.6 filter name.
        $hosts = apply_filters_deprecated('rfx_internal_hosts', [$hosts], '5.6', 'link_sentinel_internal_hosts');
        if (!is_array($hosts)) {
            $hosts = [$site_host];
        }
        if (!in_array($site_host, $hosts, true)) {
            $hosts[] = $site_host;
        }

        $normalize_host = static function ($host) {
            $host = strtolower((string) $host);
            if ('' === $host) {
                return '';
            }
            if (0 === strpos($host, 'www.')) {
                $host = substr($host, 4);
            }
            return $host;
        };

        $normalize_port = static function ($port, $scheme) {
            if (null === $port) {
                if ('https' === $scheme) {
                    return 443;
                }
                if ('http' === $scheme) {
                    return 80;
                }
                return null;
            }
            return (int) $port;
        };

        $link_host_normalized = $normalize_host($link_host);
        $site_host_normalized = $normalize_host($site_host);

        foreach ($hosts as $candidate) {
            $candidate_value = trim((string) $candidate);
            if ('' === $candidate_value) {
                continue;
            }

            if (false !== strpos($candidate_value, '://') || 0 === strpos($candidate_value, '//')) {
                $candidate_parts = wp_parse_url($candidate_value);
            } else {
                $candidate_parts = wp_parse_url('//' . ltrim($candidate_value, '/'));
            }

            $candidate_host = $candidate_value;
            $candidate_scheme = '';
            $candidate_port_explicit = false;
            $candidate_port_value = null;

            if (is_array($candidate_parts)) {
                if (!empty($candidate_parts['host'])) {
                    $candidate_host = $candidate_parts['host'];
                }
                if (!empty($candidate_parts['scheme'])) {
                    $candidate_scheme = strtolower($candidate_parts['scheme']);
                }
                if (array_key_exists('port', (array) $candidate_parts)) {
                    $candidate_port_explicit = true;
                    $candidate_port_value = (int) $candidate_parts['port'];
                }
            }

            if ($link_host_normalized !== $normalize_host($candidate_host)) {
                continue;
            }

            $candidate_is_primary = ($normalize_host($candidate_host) === $site_host_normalized);
            $ports_conflict = false;

            if ($candidate_port_explicit && $link_port_explicit) {
                $ports_conflict = ($candidate_port_value !== $link_port_value);
            } elseif ($candidate_port_explicit && !$link_port_explicit) {
                $expected_port = $candidate_port_value;
                $default_link_port = $normalize_port(null, $link_scheme ?: $candidate_scheme ?: $site_scheme);
                if (null !== $default_link_port && $expected_port !== $default_link_port) {
                    $ports_conflict = true;
                }
            } elseif (!$candidate_port_explicit && $link_port_explicit && $candidate_is_primary && $site_port_explicit) {
                $ports_conflict = ($link_port_value !== $site_port_value);
            }

            if ($ports_conflict) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Attempt to canonicalize an internal URL using built-in WordPress routing.
     *
     * @param string $url Raw URL (may be relative).
     * @return string|false Canonical absolute URL, or false if unresolved.
     */
    public static function canonical_internal_url($url)
    {
        if (!self::is_internal_link($url)) {
            return false;
        }

        $absolute = (0 === strpos($url, 'http://') || 0 === strpos($url, 'https://'))
            ? $url
            : home_url($url);

        $home_parts = wp_parse_url(home_url());
        $url_parts = wp_parse_url($absolute);

        if (empty($url_parts['host']) || (!empty($home_parts['host']) && $home_parts['host'] !== $url_parts['host'])) {
            return false;
        }

        // Map to posts/pages/custom post types when possible.
        $post_id = url_to_postid($absolute);
        if ($post_id) {
            $permalink = get_permalink($post_id);
            if ($permalink) {
                // SSRF protection: Verify the resolved permalink is still internal
                if (self::is_internal_link($permalink)) {
                    return $permalink;
                }
            }
        }

        // Attachments.
        $path = isset($url_parts['path']) ? trim($url_parts['path'], '/') : '';
        if ('' === $path) {
            return $absolute;
        }

        $path_bits = explode('/', $path);
        if (count($path_bits) === 2 && 'attachment' === $path_bits[0]) {
            $attachment = get_page_by_path($path_bits[1], OBJECT, 'attachment');
            if ($attachment) {
                $link = get_attachment_link($attachment);
                if ($link && !is_wp_error($link)) {
                    // SSRF protection: Verify the resolved attachment link is still internal
                    if (self::is_internal_link($link)) {
                        return $link;
                    }
                }
            }
        }

        // Generic taxonomy resolution with memoized lookups.
        static $public_taxonomies = null;
        static $taxonomy_slug_cache = [];

        if (null === $public_taxonomies) {
            $public_taxonomies = get_taxonomies(['public' => true]);
        }

        $slug = end($path_bits);
        foreach ($public_taxonomies as $taxonomy) {
            if (!isset($taxonomy_slug_cache[$taxonomy])) {
                $taxonomy_slug_cache[$taxonomy] = [];
            }
            if (array_key_exists($slug, $taxonomy_slug_cache[$taxonomy])) {
                $term = $taxonomy_slug_cache[$taxonomy][$slug];
            } else {
                $term = get_term_by('slug', $slug, $taxonomy);
                $taxonomy_slug_cache[$taxonomy][$slug] = $term ? $term : false;
            }
            if ($term && !is_wp_error($term)) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link)) {
                    // SSRF protection: Verify the resolved term link is still internal
                    if (self::is_internal_link($term_link)) {
                        return $term_link;
                    }
                }
            }
        }

        return $absolute;
    }

    /**
     * Check whether an internal URL was originally specified without a scheme/host.
     *
     * @param string $url URL to inspect.
     * @return bool True when the URL appears to be relative to the current site.
     */
    public static function internal_url_is_relative($url)
    {
        if ('' === $url) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (false === $parts) {
            return false;
        }

        return empty($parts['scheme']) && empty($parts['host']);
    }

    /**
     * Determine whether two internal URLs reference the same destination.
     *
     * @param string $original Original URL discovered in content.
     * @param string $resolved URL resolved via HTTP/canonical lookup.
     * @return bool True when both URLs target the same internal resource.
     */
    public static function internal_urls_equivalent($original, $resolved)
    {
        static $home_parts = null;

        if (null === $home_parts) {
            $parsed = wp_parse_url(home_url());
            $home_parts = false === $parsed ? [] : $parsed;
        }

        $normalize = static function ($url) use ($home_parts) {
            $url = trim((string) $url);
            if ('' === $url) {
                return '';
            }

            $hash_pos = strpos($url, '#');
            if (false !== $hash_pos) {
                $url = substr($url, 0, $hash_pos);
            }

            $parts = wp_parse_url($url);
            if (false === $parts) {
                return $url;
            }

            $host = '';
            if (!empty($parts['host'])) {
                $host = strtolower($parts['host']);
            } elseif (!empty($home_parts['host'])) {
                $host = strtolower($home_parts['host']);
            }

            $scheme = '';
            if (!empty($parts['scheme'])) {
                $scheme = strtolower($parts['scheme']);
            } elseif (!empty($home_parts['scheme'])) {
                $scheme = strtolower($home_parts['scheme']);
            }

            $port = null;
            if (isset($parts['port'])) {
                $port = (int) $parts['port'];
            } elseif (isset($home_parts['port'])) {
                $port = (int) $home_parts['port'];
            } elseif ('' !== $scheme) {
                if ('https' === $scheme) {
                    $port = 443;
                } elseif ('http' === $scheme) {
                    $port = 80;
                }
            }

            $default_port = null;
            if ('' !== $scheme) {
                if ('https' === $scheme) {
                    $default_port = 443;
                } elseif ('http' === $scheme) {
                    $default_port = 80;
                }
            }

            $path = isset($parts['path']) ? $parts['path'] : '';
            $query = isset($parts['query']) ? $parts['query'] : '';

            if ('' === $path) {
                $path = '/';
            } elseif ('/' !== $path[0]) {
                $path = '/' . $path;
            }

            $path = rtrim($path, '/');
            if ('' === $path) {
                $path = '/';
            }

            $base_path = '';
            if (isset($home_parts['path'])) {
                $trimmed_base = trim($home_parts['path'], '/');
                if ('' !== $trimmed_base) {
                    $base_path = '/' . $trimmed_base;
                }
            }

            if ('' !== $base_path && 0 !== strpos($path, $base_path)) {
                $path = trailingslashit($base_path) . ltrim($path, '/');
            }

            $port_suffix = '';
            if (null !== $port && $port !== $default_port) {
                $port_suffix = ':' . $port;
            }

            return $host . $port_suffix . $path . ('' !== $query ? '?' . $query : '');
        };

        return $normalize($original) === $normalize($resolved);
    }

    /**
     * Check if two URLs represent a self-redirect.
     *
     * @param string $original Original URL.
     * @param string $final    Final resolved URL.
     * @return bool True if this is a self-redirect that should be ignored.
     */
    public static function is_self_redirect($original, $final)
    {
        if (self::internal_urls_equivalent($original, $final)) {
            return true;
        }

        $orig_without_fragment = $original;
        $hash_pos = strpos($original, '#');
        if (false !== $hash_pos) {
            $orig_without_fragment = substr($original, 0, $hash_pos);
        }

        $final_without_fragment = $final;
        $hash_pos = strpos($final, '#');
        if (false !== $hash_pos) {
            $final_without_fragment = substr($final, 0, $hash_pos);
        }

        if (self::internal_urls_equivalent($orig_without_fragment, $final_without_fragment)) {
            return true;
        }

        $orig_parts = wp_parse_url($original);
        $final_parts = wp_parse_url($final);

        if (false === $orig_parts || false === $final_parts) {
            return false;
        }

        // Both URLs must point at the same host (ignoring a leading "www.")
        // to qualify as a self-redirect.  A relative URL implicitly belongs
        // to the site host.  Without this check a redirect to a different
        // domain with an identical path (e.g. /about ->
        // https://other-site.com/about) would be silently ignored instead
        // of being logged for review.
        $strip_www = static function ($host) {
            $host = strtolower((string) $host);
            return (0 === strpos($host, 'www.')) ? substr($host, 4) : $host;
        };
        $home_host  = wp_parse_url(home_url(), PHP_URL_HOST);
        $orig_host  = !empty($orig_parts['host']) ? $orig_parts['host'] : $home_host;
        $final_host = !empty($final_parts['host']) ? $final_parts['host'] : $home_host;
        if ($strip_www($orig_host) !== $strip_www($final_host)) {
            return false;
        }

        $orig_path = isset($orig_parts['path']) ? rtrim($orig_parts['path'], '/') : '';
        $final_path = isset($final_parts['path']) ? rtrim($final_parts['path'], '/') : '';

        if ('' === $orig_path) {
            $orig_path = '/';
        }
        if ('' === $final_path) {
            $final_path = '/';
        }

        $orig_path_normalized = strtolower($orig_path);
        $final_path_normalized = strtolower($final_path);

        $orig_query = isset($orig_parts['query']) ? $orig_parts['query'] : '';
        $final_query = isset($final_parts['query']) ? $final_parts['query'] : '';

        if ($orig_path_normalized !== $final_path_normalized || $orig_query !== $final_query) {
            return false;
        }

        return true;
    }

    /**
     * Preserve fragment from original URL and append to final URL.
     *
     * @param string $original_url Original URL that may contain a fragment.
     * @param string $final_url    Final resolved URL without fragment.
     * @return string Final URL with original fragment appended if present.
     */
    public static function preserve_fragment($original_url, $final_url)
    {
        if (empty($original_url) || empty($final_url)) {
            return $final_url;
        }

        $hash_pos = strpos($original_url, '#');
        if (false === $hash_pos) {
            return $final_url;
        }

        $fragment = substr($original_url, $hash_pos);

        $final_hash_pos = strpos($final_url, '#');
        if (false !== $final_hash_pos) {
            $final_url = substr($final_url, 0, $final_hash_pos);
        }

        return $final_url . $fragment;
    }

    /**
     * Normalize URL for consistent comparison and replacement.
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL.
     */
    public static function normalize_url_for_replacement($url)
    {
        if (empty($url)) {
            return $url;
        }

        $parts = wp_parse_url($url);
        if (false === $parts) {
            return $url;
        }

        $path = isset($parts['path']) ? $parts['path'] : '';
        if ('' !== $path && '/' !== $path) {
            $path = rtrim($path, '/');
        }

        $normalized = '';
        if (isset($parts['scheme'])) {
            $normalized .= $parts['scheme'] . '://';
        } elseif (isset($parts['host'])) {
            $normalized .= '//';
        }

        if (isset($parts['user'])) {
            $normalized .= $parts['user'];
            if (isset($parts['pass'])) {
                $normalized .= ':' . $parts['pass'];
            }
            $normalized .= '@';
        }

        if (isset($parts['host'])) {
            $normalized .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        $normalized .= $path;

        if (isset($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $normalized .= '#' . $parts['fragment'];
        }

        if (empty($normalized) && !empty($url)) {
            return $url;
        }

        return $normalized;
    }

    /**
     * Replace an href attribute value within post content.
     *
     * @param string $content     Original post content.
     * @param string $original    Original href attribute value from the markup.
     * @param string $replacement New URL that should replace the original value.
     * @param int    $limit       Maximum replacements. 0 = unlimited (default).
     * @return string Updated content.
     */
    public static function replace_href_value($content, $original, $replacement, $limit = 0)
    {
        if ('' === $content || '' === $original) {
            return $content;
        }

        $replacement_raw = wp_specialchars_decode($replacement, ENT_QUOTES);
        $replacement_attr = esc_attr($replacement_raw);

        $original_normalized = self::normalize_url_for_replacement($original);
        $decoded_original    = wp_specialchars_decode($original, ENT_QUOTES);
        $decoded_normalized  = wp_specialchars_decode($original_normalized, ENT_QUOTES);
        $search_variations   = array_unique(array_filter([
            $original,
            $original_normalized,
            $decoded_original,
            $decoded_normalized,
            // HTML-entity-encoded variants handle URLs stored with decoded ampersands
            // whose href attributes in content use &amp; instead of &.
            esc_attr($decoded_original),
            esc_attr($decoded_normalized),
        ]));

        $total_replaced = 0;

        foreach ($search_variations as $value) {
            if ( $limit > 0 && $total_replaced >= $limit ) {
                break;
            }

            $quoted = preg_quote($value, '/');
            $patterns = [
                '/href\s*=\s*"' . $quoted . '"/i',
                "/href\\s*=\\s*'" . $quoted . "'/i",
            ];
            foreach ($patterns as $pattern) {
                if ( $limit > 0 && $total_replaced >= $limit ) {
                    break;
                }

                $remaining = ( $limit > 0 ) ? ( $limit - $total_replaced ) : -1;

                // Escape $ and \ in the replacement to prevent preg_replace
                // from interpreting $N sequences as backreferences.
                $safe_replacement = str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $replacement_attr );
                // phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting -- Temporarily mutes E_WARNING from preg_replace() on edge-case patterns so AJAX JSON output is not corrupted; the original level is restored immediately and nothing is disclosed.
                $old_error_reporting = error_reporting(error_reporting() & ~E_WARNING);
                $updated = preg_replace($pattern, 'href="' . $safe_replacement . '"', $content, $remaining, $count);
                error_reporting($old_error_reporting);
                // phpcs:enable

                if (is_string($updated)) {
                    $content = $updated;
                    $total_replaced += $count;
                } elseif (preg_last_error() !== PREG_NO_ERROR) {
                    self::log(sprintf(
                        '[LinkSentinel] Regex error in replace_href_value: %s',
                        array_flip(get_defined_constants(true)['pcre'])[preg_last_error()] ?? 'Unknown'
                    ));
                }
            }
        }

        return $content;
    }

    /**
     * Persist post content changes without triggering standard save_post side effects.
     *
     * By default the original post_modified timestamp is preserved so that
     * purely cosmetic link-fix edits do not surface the post in "recently
     * modified" views.  Use the {@see 'rfx_preserve_post_modified'} filter
     * to change this behavior.
     *
     * @param WP_Post $post        Post object being updated.
     * @param string  $new_content New post content.
     */
    public static function commit_post_content($post, $new_content)
    {
        if (!$post instanceof WP_Post) {
            return;
        }
        if ($post->post_content === $new_content) {
            return;
        }

        $preserve = (bool) apply_filters( 'link_sentinel_preserve_post_modified', true, $post );
        // Back-compat for the pre-5.6 filter name.
        $preserve = (bool) apply_filters_deprecated( 'rfx_preserve_post_modified', [ $preserve, $post ], '5.6', 'link_sentinel_preserve_post_modified' );

        $update = [
            'ID' => $post->ID,
            'post_content' => $new_content,
        ];

        if ( $preserve ) {
            $update['post_modified']     = $post->post_modified;
            $update['post_modified_gmt'] = $post->post_modified_gmt;
            $update['edit_date']         = true;
        }

        $result = wp_update_post(wp_slash($update), true, false);
        if (is_wp_error($result)) {
            self::log(sprintf(
                '[LinkSentinel] Failed to update post ID %d: %s',
                $post->ID,
                $result->get_error_message()
            ));
            return;
        }

        clean_post_cache($post->ID);
        do_action('link_sentinel_post_content_updated', $post->ID, $new_content);
        // Back-compat for the pre-5.6 action name.
        do_action_deprecated('rfx_post_content_updated', [$post->ID, $new_content], '5.6', 'link_sentinel_post_content_updated');
    }

    /**
     * Find the final destination of a URL without blindly following all redirects.
     *
     * @param string $url The original URL.
     * @return array|false Array with keys: final_url, status_code, status_message, first_hop_code, is_permanent, origin. False on connection failure.
     */
    public static function get_final_destination_url($url)
    {
        static $memo = [];
        static $memo_max = 500;

        $cache_key = md5((string) $url);
        $transient_key = 'rfx_resolve_' . $cache_key;

        if (array_key_exists($cache_key, $memo)) {
            return $memo[$cache_key];
        }

        $cached = get_transient($transient_key);
        if (false !== $cached) {
            $memo[$cache_key] = $cached;
            return $cached;
        }

        $store_and_return = static function ($value) use (&$memo, &$memo_max, $cache_key, $transient_key, $url) {
            if (count($memo) >= $memo_max) {
                // Evict oldest entries (first half) to keep memory bounded.
                $memo = array_slice($memo, (int) ($memo_max / 2), null, true);
            }
            $memo[$cache_key] = $value;
            if ($value && is_array($value)) {
                $ttl = (int) apply_filters('link_sentinel_resolve_cache_ttl', DAY_IN_SECONDS, $url, $value);
                // Back-compat for the pre-5.6 filter name.
                $ttl = (int) apply_filters_deprecated('rfx_resolve_cache_ttl', [$ttl, $url, $value], '5.6', 'link_sentinel_resolve_cache_ttl');
                if ($ttl > 0) {
                    set_transient($transient_key, $value, $ttl);
                }
            }
            return $value;
        };

        $is_internal = self::is_internal_link($url);
        $was_relative = ($is_internal && self::internal_url_is_relative($url));

        $follow_external = (bool) apply_filters('link_sentinel_follow_external_redirects', false, $url);
        // Back-compat for the pre-5.6 filter name.
        $follow_external = (bool) apply_filters_deprecated('rfx_follow_external_redirects', [$follow_external, $url], '5.6', 'link_sentinel_follow_external_redirects');
        if (!$is_internal && !$follow_external) {
            return $store_and_return([
                'final_url' => esc_url_raw($url),
                'status_code' => 0,
                'status_message' => __('External skipped', 'linksentinel'),
                'first_hop_code' => null,
                'is_permanent' => false,
                'origin' => 'external-skipped',
            ]);
        }

        $url_for_request = $url;
        $hash_pos = strpos($url_for_request, '#');
        if (false !== $hash_pos) {
            $url_for_request = substr($url_for_request, 0, $hash_pos);
        }

        $max_hops = 0;
        if ($is_internal) {
            $max_hops = 10;
        } elseif ($follow_external) {
            $filtered = (int) apply_filters('link_sentinel_external_max_hops', 5, $url);
            // Back-compat for the pre-5.6 filter name.
            $filtered = (int) apply_filters_deprecated('rfx_external_max_hops', [$filtered, $url], '5.6', 'link_sentinel_external_max_hops');
            $max_hops = max(0, min(10, $filtered));
        }
        $timeout = (float) apply_filters('link_sentinel_remote_request_timeout', $is_internal ? 1.5 : 2.0, $url);
        // Back-compat for the pre-5.6 filter name.
        $timeout = (float) apply_filters_deprecated('rfx_remote_request_timeout', [$timeout, $url], '5.6', 'link_sentinel_remote_request_timeout');
        $timeout = max(1, $timeout);
        $current = $url_for_request;
        $current_abs = (0 === strpos($current, 'http://') || 0 === strpos($current, 'https://')) ? $current : home_url($current);
        $first_hop_code = null;
        $status_message = '';
        $final_url_abs = $current_abs;
        $status_code = 0;
        $visited_urls = []; // Track visited URLs for redirect loop detection

        $headers = [
            'User-Agent' => 'WordPress/LinkSentinel/' . LINKSENTINEL_VERSION,
        ];

        for ($i = 0; $i < $max_hops; $i++) {
            // Normalize URL for redirect loop detection (handle http/https and trailing slashes)
            $normalized_current = self::normalize_url_for_loop_detection($current_abs);
            $visited_urls[$normalized_current] = true;
            
            $resp = wp_remote_head(
                $current_abs,
                [
                    'redirection'      => 0,
                    'timeout'          => $timeout,
                    'headers'          => $headers,
                    '_rfx_scan_request' => true,
                ]
            );
            if (is_wp_error($resp)) {
                $resp = wp_remote_get(
                    $current_abs,
                    [
                        'redirection'      => 0,
                        'timeout'          => $timeout,
                        'headers'          => $headers,
                        'body'             => null,
                        '_rfx_scan_request' => true,
                    ]
                );
                if (is_wp_error($resp)) {
                    return $store_and_return(false);
                }
            }

            $code = (int) wp_remote_retrieve_response_code($resp);
            $status_message = wp_remote_retrieve_response_message($resp);

            if (null === $first_hop_code) {
                $first_hop_code = $code;
            }

            if ($code >= 300 && $code < 400) {
                $loc = wp_remote_retrieve_header($resp, 'location');
                if (empty($loc)) {
                    $status_code = $code;
                    $final_url_abs = $current_abs;
                    break;
                }

                // Build absolute URL from relative location
                if (0 !== strpos($loc, 'http://') && 0 !== strpos($loc, 'https://')) {
                    $parsed = wp_parse_url($current_abs);
                    if (empty($parsed['scheme']) || empty($parsed['host'])) {
                        $final_url_abs = $current_abs;
                        $status_code = $code;
                        break;
                    }
                    $prefix = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
                    if (0 === strpos($loc, '/')) {
                        $next_url = $prefix . $loc;
                    } else {
                        $base = isset($parsed['path']) ? trailingslashit(dirname($parsed['path'])) : '/';
                        $next_url = $prefix . '/' . ltrim($base . $loc, '/');
                    }
                } else {
                    $next_url = $loc;
                }
                
                // Check for redirect loop detection using normalized URLs
                $normalized_next = self::normalize_url_for_loop_detection($next_url);
                if (isset($visited_urls[$normalized_next])) {
                    // Redirect loop detected - return current URL with loop status
                    return $store_and_return([
                        'final_url' => esc_url_raw($current_abs),
                        'status_code' => $code,
                        'status_message' => __('Redirect loop detected', 'linksentinel'),
                        'first_hop_code' => $first_hop_code,
                        'is_permanent' => in_array((int) $first_hop_code, [301, 308], true),
                        'origin' => 'redirect-loop',
                    ]);
                }
                
                $current_abs = $next_url;
                continue;
            }

            $status_code = $code;
            $final_url_abs = $current_abs;
            break;
        }

        if (0 === $status_code && !empty($current_abs)) {
            $status_code = $first_hop_code ? $first_hop_code : 0;
            $final_url_abs = $current_abs;
        }

        if ($is_internal && (null === $first_hop_code || 200 === $first_hop_code)) {
            $canonical = self::canonical_internal_url($url);
            if ($canonical) {
                if (self::is_internal_link($canonical) && $was_relative) {
                    $final = wp_make_link_relative($canonical);
                } else {
                    $final = $canonical;
                }
                $changed = !self::internal_urls_equivalent($url, $final);
                return $store_and_return([
                    'final_url' => esc_url_raw($final),
                    'status_code' => 200,
                    'status_message' => __('Canonical', 'linksentinel'),
                    'first_hop_code' => $changed ? 301 : 200,
                    'is_permanent' => $changed,
                    'origin' => 'canonical',
                ]);
            }
        }

        $final_url = $final_url_abs;
        if ($is_internal && $final_url_abs) {
            if ($was_relative) {
                $final_url = wp_make_link_relative($final_url_abs);
                if ('' === $final_url) {
                    $final_url = '/';
                }
            } else {
                $final_url = $final_url_abs;
            }
        }

        $is_permanent = in_array((int) $first_hop_code, [301, 308], true);

        return $store_and_return([
            'final_url' => esc_url_raw($final_url),
            'status_code' => $status_code,
            'status_message' => $status_message,
            'first_hop_code' => $first_hop_code,
            'is_permanent' => $is_permanent,
            'origin' => 'http',
        ]);
    }

    /**
     * Normalize URL for redirect loop detection to handle http/https and trailing slashes.
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL for comparison.
     */
    public static function normalize_url_for_loop_detection($url)
    {
        if (empty($url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (false === $parts) {
            return strtolower(trim($url));
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) ? $parts['query'] : '';

        // Normalize path - remove trailing slashes except for root
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        } elseif (empty($path)) {
            $path = '/';
        }

        // Build normalized URL without scheme differences
        $normalized = $host;
        
        // Add port if non-standard
        if ($port && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $normalized .= ':' . $port;
        }
        
        $normalized .= $path;
        
        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    /**
     * Extract unique href values from anchor tags in post content.
     *
     * Uses DOMDocument for robust parsing. Falls back to regex
     * when DOMDocument cannot load the markup.
     *
     * @param string $content Post content (HTML).
     * @return string[] Unique, non-empty href values.
     */
    public static function extract_links_from_content( $content )
    {
        if ( empty( $content ) ) {
            return [];
        }

        $use_internal_errors = libxml_use_internal_errors( true );

        $doc = new DOMDocument();
        $loaded = $doc->loadHTML(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>'
            . $content
            . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors( $use_internal_errors );

        if ( ! $loaded ) {
            return self::extract_links_regex_fallback( $content );
        }

        $links = [];
        $anchors = $doc->getElementsByTagName( 'a' );
        foreach ( $anchors as $anchor ) {
            $href = $anchor->getAttribute( 'href' );
            if ( '' !== $href ) {
                $links[] = $href;
            }
        }

        if ( empty( $links ) ) {
            return self::extract_links_regex_fallback( $content );
        }

        return array_unique( array_filter( $links ) );
    }

    /**
     * Regex fallback for link extraction when DOMDocument fails.
     *
     * @param string $content Post content.
     * @return string[]
     */
    private static function extract_links_regex_fallback( $content )
    {
        $pattern = '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/iu';
        if ( preg_match_all( $pattern, $content, $matches ) ) {
            return array_unique( array_filter( $matches[2] ) );
        }
        return [];
    }

    /**
     * Process a single post: find internal links, resolve them and log issues.
     *
     * @param int   $post_id Post ID to scan.
     * @param array $context Optional context (settings, hash support, etc.).
     */
    public static function process_single_post($post_id, $context = [])
    {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return;
        }

        $content = $post->post_content;
        $updated_content = $content;

        // Capture the modification timestamp at read time so we can
        // detect concurrent edits before writing back (optimistic lock).
        $post_modified_at_read = $post->post_modified;

        $settings = (isset($context['settings']) && is_array($context['settings'])) ? $context['settings'] : get_option('rfx_settings', []);
        $auto_resolve = isset($context['auto_resolve']) ? (bool) $context['auto_resolve'] : !empty($settings['auto_resolve_permanent']);
        $hash_supported = isset($context['hash_supported']) ? (bool) $context['hash_supported'] : Link_Sentinel_DB::supports_hash();

        $links_found = self::extract_links_from_content( $content );
        if ( empty( $links_found ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';

        foreach ($links_found as $url) {
            $url = trim($url);

            // Skip empty links or pure anchor links (e.g. "#section").
            // We do not want to flag internal anchors as broken unless the page itself is broken.
            if ('' === $url || 0 === strpos($url, '#')) {
                continue;
            }

            // Skip non-HTTP schemes (mailto:, tel:, javascript:, etc.).
            $scheme = wp_parse_url($url, PHP_URL_SCHEME);
            if ($scheme && !in_array(strtolower($scheme), ['http', 'https'], true)) {
                continue;
            }

            // Skip external links completely.
            // We only want to scan and fix internal links.
            if (!self::is_internal_link($url)) {
                continue;
            }

            $parsed_path = wp_parse_url($url, PHP_URL_PATH);
            if (is_string($parsed_path)) {
                if (isset($parsed_path[0]) && $parsed_path[0] !== '/') {
                    $parsed_path = '/' . $parsed_path;
                }
                if (0 === strpos($parsed_path, '/wp-admin') || 0 === strpos($parsed_path, '/wp-login')) {
                    continue;
                }
            }

            $hash = $hash_supported ? Link_Sentinel_DB::get_url_hash($url) : '';
            $link_status = self::get_final_destination_url($url);
            if (!$link_status) {
                continue;
            }

            $status = (int) $link_status['status_code'];
            $first_hop = isset($link_status['first_hop_code']) ? (int) $link_status['first_hop_code'] : 0;
            $final_url = $link_status['final_url'];
            $is_redirect = ($first_hop >= 300 && $first_hop < 400);
            $is_permanent = !empty($link_status['is_permanent']);

            if ($status >= 400) {
                // Check for existing entries (both pending and resolved) to prevent re-logging manually fixed links
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
                if ($hash_supported) {
                    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND url_hash = %s LIMIT 1", $post_id, $hash));
                } else {
                    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s LIMIT 1", $post_id, $url));
                }
                // phpcs:enable
                if (!$existing_id) {
                    Link_Sentinel_DB::log_link_issue($post_id, $url, '', $status, $link_status['status_message'], 'pending');
                }
                continue;
            }

            $urls_match = self::internal_urls_equivalent($url, $final_url);

            if (!$urls_match && self::is_self_redirect($url, $final_url)) {
                continue;
            }

            if (!$urls_match) {
                if ($is_redirect) {
                    $is_perm = ($is_permanent && (301 === $first_hop || 308 === $first_hop));
                    if ($is_perm && $auto_resolve && $status >= 200 && $status < 400) {
                        $orig_is_absolute = (0 === strpos($url, 'http://') || 0 === strpos($url, 'https://'));
                        $final_is_relative = (isset($final_url[0]) && '/' === $final_url[0] && !(isset($final_url[1]) && '/' === $final_url[1]));
                        if ($orig_is_absolute && $final_is_relative) {
                            continue;
                        }
                        $final_url_with_fragment = self::preserve_fragment($url, $final_url);
                        $updated_content = self::replace_href_value($updated_content, $url, $final_url_with_fragment);

                        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
                        if ($hash_supported) {
                            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND url_hash = %s LIMIT 1", $post_id, $hash));
                        } else {
                            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s LIMIT 1", $post_id, $url));
                        }
                        // phpcs:enable
                        if (!$existing_id) {
                            Link_Sentinel_DB::log_link_issue($post_id, $url, $final_url_with_fragment, 301, __('Auto-fixed (Permanent Redirect)', 'linksentinel'), 'resolved');
                        } else {
                            // Update existing pending entry to resolved so it doesn't become orphaned.
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
                            $wpdb->update(
                                $table_name,
                                [
                                    'resolution_status' => 'resolved',
                                    'final_url'         => $final_url_with_fragment,
                                    'http_status'       => $first_hop,
                                    'status_message'    => __('Auto-fixed (Permanent Redirect)', 'linksentinel'),
                                    'resolution_date'   => current_time('mysql'),
                                ],
                                ['id' => (int) $existing_id],
                                ['%s', '%s', '%d', '%s', '%s'],
                                ['%d']
                            );
                        }
                    } else {
                        // Check for existing entries (both pending and resolved) to prevent re-logging manually fixed links
                        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
                        if ($hash_supported) {
                            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND url_hash = %s LIMIT 1", $post_id, $hash));
                        } else {
                            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s LIMIT 1", $post_id, $url));
                        }
                        // phpcs:enable
                        if (!$existing_id) {
                            $msg = $is_perm ? __('Permanent Redirect', 'linksentinel') : __('Temporary Redirect', 'linksentinel');
                            Link_Sentinel_DB::log_link_issue($post_id, $url, $final_url, $first_hop, $msg, 'pending');
                        }
                    }
                } else {
                    if ($auto_resolve) {
                        $orig_is_absolute = (0 === strpos($url, 'http://') || 0 === strpos($url, 'https://'));
                        $final_is_relative = (isset($final_url[0]) && '/' === $final_url[0] && !(isset($final_url[1]) && '/' === $final_url[1]));
                        if ($orig_is_absolute && $final_is_relative) {
                            continue;
                        }
                        $final_url_with_fragment = self::preserve_fragment($url, $final_url);
                        $updated_content = self::replace_href_value($updated_content, $url, $final_url_with_fragment);
                        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name built from $wpdb->prefix and a literal.
                        if ($hash_supported) {
                            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND url_hash = %s LIMIT 1", $post_id, $hash));
                        } else {
                            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s LIMIT 1", $post_id, $url));
                        }
                        // phpcs:enable
                        if (!$existing_id) {
                            Link_Sentinel_DB::log_link_issue($post_id, $url, $final_url_with_fragment, 200, __('Auto-fixed (Canonicalized)', 'linksentinel'), 'resolved');
                        } else {
                            // Update existing pending entry to resolved so it doesn't become orphaned.
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
                            $wpdb->update(
                                $table_name,
                                [
                                    'resolution_status' => 'resolved',
                                    'final_url'         => $final_url_with_fragment,
                                    'http_status'       => 200,
                                    'status_message'    => __('Auto-fixed (Canonicalized)', 'linksentinel'),
                                    'resolution_date'   => current_time('mysql'),
                                ],
                                ['id' => (int) $existing_id],
                                ['%s', '%s', '%d', '%s', '%s'],
                                ['%d']
                            );
                        }
                    }
                }
            }
        }

        if ($updated_content !== $content) {
            // Re-fetch the post to check whether someone else modified it
            // between our read and now (optimistic concurrency control).
            // Flush the object cache first so we read from the database.
            clean_post_cache($post_id);
            $fresh_post = get_post($post_id);
            if ($fresh_post && $fresh_post->post_modified !== $post_modified_at_read) {
                self::log(sprintf(
                    '[LinkSentinel] Skipping update for post ID %d: content was modified concurrently (read at %s, now %s). Links will be caught on the next scan.',
                    $post_id,
                    $post_modified_at_read,
                    $fresh_post->post_modified
                ));
                return;
            }

            self::commit_post_content($post, $updated_content);
            $post->post_content = $updated_content;
        }
    }

    /**
     * Hydrate the current scan state from a single consolidated option.
     *
     * @return array
     */
    public static function get_scan_state()
    {
        $raw = get_option( 'rfx_manual_scan_state', [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        // Migrate from legacy per-key options on first read after upgrade.
        if ( empty( $raw ) && get_option( 'rfx_manual_scan_active', false ) !== false ) {
            $raw = [
                'active'     => get_option( 'rfx_manual_scan_active', 0 ),
                'total'      => get_option( 'rfx_manual_scan_total', 0 ),
                'processed'  => get_option( 'rfx_manual_scan_processed', 0 ),
                'last_id'    => get_option( 'rfx_manual_scan_last_id', 0 ),
                'batch_size' => get_option( 'rfx_manual_scan_batch_size', 25 ),
                'token'      => get_option( 'rfx_manual_scan_token', '' ),
                'started_at' => get_option( 'rfx_manual_scan_started_at', '' ),
            ];
        }

        $state = wp_parse_args( $raw, [
            'active'     => false,
            'total'      => 0,
            'processed'  => 0,
            'last_id'    => 0,
            'batch_size' => 25,
            'token'      => '',
            'started_at' => '',
        ] );

        $state['active']     = (bool) $state['active'];
        $state['total']      = (int) $state['total'];
        $state['processed']  = (int) $state['processed'];
        $state['last_id']    = (int) $state['last_id'];
        $state['batch_size'] = max( 5, min( 100, (int) ( $state['batch_size'] ?: 25 ) ) );
        $state['token']      = (string) $state['token'];
        $state['started_at'] = (string) $state['started_at'];

        // Detect and auto-reset abandoned scans.
        if ( $state['active'] && ! empty( $state['started_at'] ) ) {
            // started_at may be a Unix timestamp (int) or a legacy MySQL datetime
            // string produced by current_time('mysql') in WordPress timezone.
            if ( is_numeric( $state['started_at'] ) ) {
                $started_ts = (int) $state['started_at'];
            } else {
                $dt = date_create( $state['started_at'], wp_timezone() );
                $started_ts = $dt ? $dt->getTimestamp() : false;
            }
            /** @var int $threshold Maximum seconds a scan may run before considered stale. Default 1800 (30 min). */
            $threshold = (int) apply_filters( 'link_sentinel_scan_stale_threshold', 1800 );
            // Back-compat for the pre-5.6 filter name.
            $threshold = (int) apply_filters_deprecated( 'rfx_scan_stale_threshold', [ $threshold ], '5.6', 'link_sentinel_scan_stale_threshold' );
            if ( false !== $started_ts && ( time() - $started_ts ) > $threshold ) {
                self::log( sprintf(
                    '[LinkSentinel] Stale manual scan detected (started %s, threshold %ds). Auto-resetting.',
                    $state['started_at'],
                    $threshold
                ) );
                self::reset_scan_state();
                return self::get_scan_state();
            }
        }

        return $state;
    }

    /**
     * Persist the full scan state atomically as a single option.
     *
     * @param array $state Scan state array.
     */
    public static function save_scan_state( $state )
    {
        update_option( 'rfx_manual_scan_state', $state, false );
    }

    /**
     * Clear scan progress and release the single-flight lock.
     */
    public static function reset_scan_state()
    {
        delete_transient( 'rfx_manual_scan_lock' );
        delete_option( 'rfx_manual_scan_state' );

        // Clean up legacy individual options from prior versions.
        foreach ( [
            'rfx_manual_scan_active', 'rfx_manual_scan_total',
            'rfx_manual_scan_processed', 'rfx_manual_scan_last_id',
            'rfx_manual_scan_batch_size', 'rfx_manual_scan_token',
            'rfx_manual_scan_started_at', 'rfx_manual_scan_progress_interval',
        ] as $legacy_key ) {
            delete_option( $legacy_key );
        }
    }

    /**
     * Mark the current scan as complete and release the transient lock.
     *
     * @param int $total Total number of posts that were part of the scan.
     */
    public static function complete_scan( $total )
    {
        delete_transient( 'rfx_manual_scan_lock' );
        self::save_scan_state( [
            'active'    => false,
            'total'     => max( 0, (int) $total ),
            'processed' => max( 0, (int) $total ),
            'last_id'   => 0,
            'token'     => '',
        ] );
        update_option( 'rfx_scan_last_finished', current_time( 'mysql' ), false );
    }
}
