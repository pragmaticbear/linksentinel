# Contributing to LinkSentinel

Thanks for your interest in improving LinkSentinel! Issues and pull requests are
welcome. Please open an issue to discuss significant changes before submitting a
large PR.

## Reporting bugs

When filing a bug, please include:

- WordPress version, PHP version, and active theme.
- Steps to reproduce, expected behavior, and actual behavior.
- Any relevant errors from `wp-content/debug.log` (with `WP_DEBUG_LOG` enabled).
- Whether the site is multisite, and whether the scan was manual or scheduled.

Do **not** report security vulnerabilities in public issues — see
[SECURITY.md](SECURITY.md).

## Development setup

LinkSentinel has no build step and no external runtime dependencies.

1. Clone the repository into `wp-content/plugins/link-sentinel` of a local
   WordPress install (5.8+, PHP 7.4+).
2. Activate **LinkSentinel** from the Plugins screen.
3. Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php` while developing.

## Coding standards

This plugin follows the
[WordPress Coding Standards](https://developer.wordpress.org/coding-standards/).
Please keep contributions consistent with the surrounding code:

- Escape all output (`esc_html`, `esc_attr`, `esc_url`, …) and sanitize all input.
- Use `$wpdb->prepare()` for every query that includes variables.
- Verify a nonce and a capability (`current_user_can`) in every form/AJAX handler.
- Wrap all user-facing strings in i18n functions with the `link-sentinel` text
  domain, e.g. `__( 'Text', 'link-sentinel' )`.

If you add or change user-facing strings, regenerate the translation template:

```sh
wp i18n make-pot . languages/link-sentinel.pot --domain=link-sentinel
```

## Pull requests

- Branch from the default branch and keep PRs focused on a single concern.
- Describe what changed and why; link the related issue.
- Bump the version consistently in `link-sentinel.php` (`Version` header and
  `RFX_VERSION`) and `readme.txt` (`Stable tag`) when a release is warranted, and
  add a matching `== Changelog ==` entry.

## License

By contributing, you agree that your contributions are licensed under the
[GNU General Public License v2.0 or later](LICENSE), the same license as the
project.
