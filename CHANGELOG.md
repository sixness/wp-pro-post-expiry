# Changelog

## 1.3.0 — 2026-11-23
- Redirect after expiry: send visitors of expired posts to a page of your choice (default in Settings, or a per-post URL in the Post Expiry box). Works together with Draft / Private / Trash / Move to category, including at the old address of drafted or trashed posts. 301 or 302; WPML sends visitors to the page in their language.
- `post-expired` body class on expired posts' pages (added live by the countdown time, also on cached pages).
- New filter `wppe_redirect_url` to change the target in code.
## 1.2.0 — 2026-11-18
- Updates from GitHub: new releases show "Update now" on the Plugins page.
- "Check for updates" link under the plugin name.

## 1.1.0 — 2026-11-18
- Live countdown: `[wppe_countdown]` shortcode and optional automatic display before/after content.
- Countdown label and expired message editable in Settings, translatable with WPML String Translation.

## 1.0.1 — 2026-11-18
- Fix: Quick Edit no longer clears an expiry date when the Expires field was left untouched.

## 1.0.0 — 2026-11-18
- First release: expiry date & time per post (edit screen, "Expires" column, Quick Edit).
- After expiry: Draft, Private, Trash, or move to a category (removing all others).
- Choose post types, WPML translations follow the original (yes/no), check every 5/15/60 minutes.
