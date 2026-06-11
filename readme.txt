=== LinkSentinel ===
Contributors: pragmaticbear
Tags: links, redirects, broken links, 301, 302
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 5.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scan internal links for redirects and broken destinations. Auto-fix 301/308 permanent redirects and queue the rest for review.

== Description ==

**LinkSentinel** keeps your site healthy by scanning internal links in your posts and pages. It detects redirects and broken destinations without following all hops blindly, letting you focus on what matters:

* Auto-fixes links that are permanently redirected (HTTP 301/308) while leaving temporary redirects and broken links for review.
* Runs scheduled scans (daily/twice daily/weekly) with timezone-aware cron control and live progress feedback.
* Provides a full dashboard under **Tools &rarr; LinkSentinel** for resolved links, pending redirects, broken links, CSV export and settings.
* Customizable Resolve All batching with cooldown controls keeps bulk fixes stable even on rate-limited hosts.

== Installation ==

1. Upload the `link-sentinel` folder to the `/wp-content/plugins/` directory, or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Tools &rarr; LinkSentinel** and click **Scan Now** to scan existing content. Configure automatic scans in the Settings tab to run on your preferred schedule.

== Frequently Asked Questions ==

= Does this plugin scan external links? =
Not yet. The plugin currently focuses on internal links within your site's domain. External link scanning is on the roadmap.

= What about images and media files? =
Currently only anchor (`<a>`) tags are scanned. Support for images (`<img>` tags) and other media is planned for a future release.

= Can I disable automatic scans? =
Yes. Go to **Tools &rarr; LinkSentinel &rarr; Settings** and uncheck "Enable automatic scheduled scans". You can also adjust the frequency and time to suit your needs.

== Changelog ==

= 5.6 =
* **Compliance**: Addressed WordPress Plugin Check findings — escaped remaining admin output, added translators comments and ordered i18n placeholders, and unslashed/sanitized all request input.
* **Developer**: Public hooks renamed from `rfx_*` to `link_sentinel_*` (e.g. `link_sentinel_follow_external_redirects`). The old `rfx_*` names still work but are deprecated and will be removed in a future release.
* **Developer**: Debug logging is now only written when `WP_DEBUG` is enabled.
* Removed the manual `load_plugin_textdomain()` call; WordPress loads translations automatically.

= 5.5 =
* **Bug Fix**: Redirects to a different domain with an identical path are no longer misclassified as self-redirects; they are now logged for review.
* **Bug Fix**: Starting a manual scan no longer resets a scan already in progress (e.g. one started by another admin).
* **Bug Fix**: The Change Link form now accepts site-relative paths (e.g. `/about-us/`) in addition to full http(s) URLs.
* **Stability**: The scan progress loop now stops with a clear message after repeated batch failures instead of retrying indefinitely.

= 5.4 =
* First public, open-source release under GPL-2.0-or-later.
* Security: reviewed and hardened AJAX handlers and database queries.
* Added a translation template (`languages/link-sentinel.pot`) and tidied translatable strings for full internationalization support.
* Hardened files against direct access and improved inline documentation.

= 5.2.2 =
* **Critical Bug Fix**: Fixed race condition where manually fixed broken links would become "stuck" in the pending queue during subsequent scans.
* **Improved Duplicate Detection**: Enhanced scanner logic to prevent re-logging manually resolved links regardless of resolution status.
* **Database Efficiency**: Optimized duplicate detection queries for better performance.
* **Stability**: Prevented infinite redirect loops with improved URL normalization.

= 5.0.1 =
* Batch processing for manual scans.
* Auto-fix permanently redirected links (301/308).
* Queue temporary redirects and broken links for review.
* Scheduled scans with timezone-aware cron control.
* Dashboard with progress indicators and CSV export.

= 4.6 - 2025-11-17 =
* Added: Resolve All batching controls in settings with checkbox gating, custom links-per-batch, and cooldown inputs to prevent rate limits.
* Added: Shared preference helper so PHP and JavaScript stay in sync when honoring user pacing choices.
* Improved: Resolve All localization, timers, and AJAX handler now respect saved batch/cooldown limits to avoid regressions.

= 4.4 - 2025-11-17 =
* Added: A throttled mode for hosts with strict rate limits — single-item batches, longer pacing, and tighter server-side throttles to prevent 429 errors.
* Added: Resolve All diagnostics (browser console + `rfxTestDbConnection()`) for fast root-cause analysis.
* Improved: Resolve All batching now self-tunes based on step durations and memory ceilings; logging hardened with safer output buffering.
* Updated: Documentation and admin UI messaging for the throttled workflow and recommended troubleshooting steps.

= 3.5 - 2025-11-12 =
* Introduced timezone-aware scheduled scans with daily/twice daily/weekly frequency controls.
* Added progress indicators, scan history, and concurrency protection between manual and scheduled runs.

= 1.9 - 2025-10-22 =
* Added "Resolved By" tracking for manual actions plus table wrapping fixes and accountability improvements.

== Upgrade Notice ==

= 5.4 =
First open-source release (GPL-2.0-or-later) with security hardening, improved documentation, and translation support. Recommended for all users.
