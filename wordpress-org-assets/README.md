# WordPress.org directory visuals (local staging)

Put your **final** PNG/JPEG exports here while you work. This folder is **not** included in the end-user plugin zip (`scripts/build-release.sh` excludes it).

When you are ready for the live plugin page, copy these files into your plugin’s **SVN `assets/`** branch on WordPress.org (same filenames):

| File | Size | Notes |
|------|------|--------|
| `icon-128.png` | 128 × 128 | Required-style icon |
| `icon-256.png` | 256 × 256 | HiDPI icon (same art as 128) |
| `banner-772x250.png` or `.jpg` | 772 × 250 | Standard header banner |
| `banner-1544x500.png` or `.jpg` | 1544 × 500 | HiDPI banner (2× layout) |

Screenshots (`screenshot-1.png`, …) can also live here until you add them to SVN `assets/` and restore the `== Screenshots ==` block in `readme.txt`.

See `docs/DESIGN-BRIEF-WP-DIRECTORY-ASSETS.md` for creative direction.

Official specs: [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
