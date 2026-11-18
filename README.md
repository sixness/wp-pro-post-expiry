# WP Pro Post Expiry

WordPress plugin by **WP Pro Design**. Give posts an expiry date & time; when it passes, the post is handled automatically.

- Set the expiry on the edit screen, in **Quick Edit**, and see it in an **Expires** column.
- After expiry: **Draft**, **Private**, **Trash**, or **move to a category** (all other categories removed, post stays published).
- Choose which **post types** it applies to.
- **WPML**: translations can expire together with the original.
- Check every **5 / 15 / 60** minutes. Works even when `DISABLE_WP_CRON` is set.
- **Countdown**: `[wppe_countdown]` shortcode or automatic display before/after content.
- **Updates from GitHub Releases** — "Update now" appears on the Plugins page.

Requires WordPress 6.0+ and PHP 7.4+.

## Install

1. Download `wp-pro-post-expiry.zip` from the [latest release](https://github.com/sixness/wp-pro-post-expiry/releases/latest).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
3. Configure under **Settings → Post Expiry**.

## Countdown shortcode

```
[wppe_countdown]
[wppe_countdown id="123" label="Ends in" expired="Sorry, this offer has ended"]
```

Without `id` it uses the current post (works in Elementor single and loop templates).

## Releasing a new version

1. In `wp-pro-post-expiry.php` bump **both** the `Version:` header and `WPPE_VERSION` to the same number.
2. Add a `## X.Y.Z — YYYY-MM-DD` section at the top of `CHANGELOG.md`.
3. Commit to `main` (locally or in GitHub's web editor).

The **Release** workflow then builds `wp-pro-post-expiry.zip`, creates Release `vX.Y.Z` with the changelog notes and attaches the zip. Sites pick it up within ~6 hours, or immediately via **Check for updates** under the plugin name.

## License

GPL-2.0-or-later
