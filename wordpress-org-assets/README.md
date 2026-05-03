# WordPress.org directory visuals (local staging)

Put your **final** PNG/JPEG exports here while you work. This folder is **not** included in the end-user plugin zip (`scripts/build-release.sh` excludes it).

When you are ready for the live plugin page, copy these files into your plugin’s **SVN `assets/`** branch on WordPress.org (same filenames):

| File | Size | Notes |
|------|------|--------|
| `icon-128x128.png` | 128 × 128 | Normal icon ([required filename](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/#filenames)) |
| `icon-256x256.png` | 256 × 256 | HiDPI icon (same art as 128×128) |
| `banner-772x250.png` or `.jpg` | 772 × 250 | Standard header banner |
| `banner-1544x500.png` or `.jpg` | 1544 × 500 | HiDPI banner (2× layout) |

Screenshots (`screenshot-1.png`, …): `scripts/prepare-wordpress-org-svn-working-copy.sh` copies `.github/readme-assets/members-table-filters.png` to SVN `assets/screenshot-1.png` when that file exists. The WordPress.org listing uses the `== Screenshots ==` section in root `readme.txt`.

See `docs/DESIGN-BRIEF-WP-DIRECTORY-ASSETS.md` for creative direction.

Official specs: [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
