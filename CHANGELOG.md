# Changelog

All notable changes to LinkSentinel are documented here. This project adheres to
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The canonical
WordPress.org changelog lives in [`readme.txt`](readme.txt).

## [5.6]

### Changed
- Public hooks renamed from `rfx_*` to `link_sentinel_*` (e.g.
  `link_sentinel_follow_external_redirects`). The old `rfx_*` names still fire
  via `apply_filters_deprecated()` but are deprecated.
- Global bootstrap and uninstall functions renamed to the `link_sentinel_`
  prefix.
- Debug logging is now gated behind `WP_DEBUG`.
- Removed the manual `load_plugin_textdomain()` call; WordPress ≥ 4.6 loads
  translations automatically.

### Fixed
- WordPress Plugin Check compliance: escaped remaining admin output, added
  translators comments and ordered i18n placeholders, unslashed and sanitized
  all request input, and documented/justified direct custom-table queries.
- Declared compatibility with WordPress 7.0 in `readme.txt`.

## [5.4]

### Added
- First public, open-source release under GPL-2.0-or-later.
- Translation template (`languages/link-sentinel.pot`) and tidied translatable
  strings for full internationalization support.

### Changed
- Improved inline documentation across the codebase.

### Security
- Reviewed and hardened AJAX handlers and database queries.
- Added direct-access guards to all PHP files.

## [5.2.2]

### Fixed
- Race condition where manually fixed broken links would become "stuck" in the
  pending queue during subsequent scans.
- Infinite redirect loops, via improved URL normalization.

### Changed
- Enhanced scanner logic to prevent re-logging manually resolved links regardless
  of resolution status.
- Optimized duplicate-detection queries for better performance.

## [5.0.1]

### Added
- Batch processing for manual scans.
- Auto-fix for permanently redirected links (301/308).
- Review queue for temporary redirects and broken links.
- Scheduled scans with timezone-aware cron control.
- Dashboard with progress indicators and CSV export.

## [4.6] - 2025-11-17

### Added
- Resolve All batching controls in settings (checkbox gating, custom
  links-per-batch, and cooldown inputs) to prevent rate limits.
- Shared preference helper so PHP and JavaScript stay in sync when honoring user
  pacing choices.

### Changed
- Resolve All localization, timers, and AJAX handler now respect saved
  batch/cooldown limits.

## [4.4] - 2025-11-17

### Added
- A throttled mode for hosts with strict rate limits — single-item batches,
  longer pacing, and tighter server-side throttles to prevent 429 errors.
- Resolve All diagnostics (browser console + `rfxTestDbConnection()`).

### Changed
- Resolve All batching now self-tunes based on step durations and memory ceilings;
  logging hardened with safer output buffering.

## [3.5] - 2025-11-12

### Added
- Timezone-aware scheduled scans with daily/twice-daily/weekly frequency controls.
- Progress indicators, scan history, and concurrency protection between manual and
  scheduled runs.

## [1.9] - 2025-10-22

### Added
- "Resolved By" tracking for manual actions, plus table wrapping fixes and
  accountability improvements.
