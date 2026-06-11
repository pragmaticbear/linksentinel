# LinkSentinel

> Scan your WordPress site's internal links for redirects and broken destinations — auto-fix permanent redirects, queue the rest for review.

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)

LinkSentinel keeps your site healthy by scanning the internal links in your
posts and pages. It detects redirects and broken destinations without blindly
following every hop, so you can focus on the links that actually need attention.

## Features

- **Auto-fix permanent redirects** — links returning HTTP `301`/`308` are
  rewritten to their final destination automatically.
- **Review queue** — temporary redirects (`302`/`307`) and broken links are
  queued for manual review rather than changed silently.
- **Scheduled scans** — daily, twice-daily, or weekly, with timezone-aware cron
  control and live progress feedback.
- **Dashboard** — a full interface under **Tools → LinkSentinel** for resolved
  links, pending redirects, broken links, settings, and CSV export.
- **Resilient bulk fixes** — "Resolve All" batching with configurable
  links-per-batch and cooldown controls stays stable on rate-limited hosts.
- **Multisite aware** — network activation runs the setup routine for every site.

## Requirements

| | |
|---|---|
| WordPress | 5.8 or higher (tested up to 6.9) |
| PHP | 7.4 or higher |
| License | GPL-2.0-or-later |

## Installation

1. Copy the `link-sentinel` folder into `wp-content/plugins/`, or upload the
   plugin ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **LinkSentinel** through the **Plugins** menu.
3. Go to **Tools → LinkSentinel** and click **Start Scan** to scan existing
   content. Configure automatic scans under the **Settings** tab.

## Usage

After the first scan, the dashboard groups results into:

- **Resolved** — links that were automatically updated (exportable as CSV).
- **Pending redirects** — temporary redirects awaiting your decision.
- **Broken links** — destinations that failed to resolve.

Automatic scheduled scans can be enabled, paced, and disabled from the
**Settings** tab.

## FAQ

**Does it scan external links?**
Not yet — LinkSentinel currently focuses on internal links within your site's
domain. External link scanning is on the roadmap.

**What about images and media?**
Only anchor (`<a>`) tags are scanned today. Image (`<img>`) and other media
support is planned for a future release.

**Can I disable automatic scans?**
Yes — uncheck *Enable automatic scheduled scans* under
**Tools → LinkSentinel → Settings**.

## Contributing

Issues and pull requests are welcome. Please open an issue to discuss
significant changes before submitting a PR.

## License

LinkSentinel is free software, released under the
[GNU General Public License v2.0 or later](LICENSE).

Built by [Pragmatic Bear](https://www.pragmaticbear.com).
