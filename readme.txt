=== Admin Filters for MemberPress ===
Contributors: omarelhawary
Tags: memberpress, members, admin, filters, membership
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.6.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds address and MemberPress custom-field filters to the MemberPress Members admin list. Extra meta filters can be added in code. Uses MemberPress hooks only.

== Description ==

**Admin Filters for MemberPress** extends the **MemberPress → Members** admin screen with extra filters: MemberPress address fields (when your site captures them), every MemberPress registration **Settings → Fields** field, and any further **user meta** filters you add with the `mepr_members_meta_filters_fields` filter (for example in a small custom plugin).

This plugin is an independent project. It is **not** affiliated with, endorsed by, or sponsored by MemberPress, Caseproof, LLC, or their brands. **MemberPress** is a trademark of Caseproof, LLC.

**Requirements**

* WordPress 5.6 or newer and PHP 8.1 or newer.
* A working install of **MemberPress** (commercial plugin from Caseproof). This extension does not ship MemberPress and cannot run without it.

**Privacy**

Filtering reads values you or your administrators submit on the Members list (standard admin `GET` requests) and builds SQL `EXISTS` conditions on `wp_usermeta` scoped to the list query. No data is sent to external services by this plugin.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/admin-filters-for-memberpress` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Ensure **MemberPress** is already installed and active. If MemberPress is inactive, this plugin does nothing.

== Frequently Asked Questions ==

= Does this plugin include MemberPress? =

No. You must purchase and install MemberPress separately from Caseproof.

= Will my settings be lost if I update? =

MemberPress field and address settings stay in MemberPress. Custom filters you add in PHP use your own code; keep that snippet in a child theme or plugin so updates to this extension do not remove it.

= How do I filter by user meta from another plugin? =

Add a field definition with the `mepr_members_meta_filters_fields` filter (see the GitHub README for a copy-paste example). You need the exact `meta_key` that plugin stores in `wp_usermeta`.

== Changelog ==

This section follows the Version line in the main plugin file on the default branch. Version labels 1.6.3 and 1.6.4 were never used as semver bumps here (development went from 1.6.2 to 1.6.5). Commits that shipped on main while the header still read 1.5.0, before 1.6.1, are grouped under 1.5.0 so the git history is complete.

= 1.6.7 =

* Improve escaping in Members filter controls (WordPress Plugin Check / PHPCS).
* Replace the languages directory placeholder with a non-hidden `index.php` so release zips avoid dotfiles flagged by Plugin Check.
* Drop redundant `load_plugin_textdomain()`; WordPress.org installs load translations automatically (WordPress 4.6+).
* Clarify read-only admin `GET` usage for script loading and filter query parameters where static analysis required it.
* Toolbar renderer: clearer attribute handling and inline documentation for SQL / predicate behavior; document WordPress.org banner/icon layout under `wordpress-org-assets/`.
* README (GitHub): structure and screenshot guidance refresh — same stable tag **1.6.7** in the plugin header until the next release.

= 1.6.6 =

* WordPress.org packaging: add `readme.txt`, align the text domain with the plugin slug (`admin-filters-for-memberpress`), and clarify third-party / trademark disclaimer.
* Remove the `Requires Plugins: memberpress` header because MemberPress is not distributed from the wordpress.org plugin directory (dependency is documented here instead).
* Track a `languages/` directory for translation drops (initial placeholder before the 1.6.7 `index.php` layout).
* Release hygiene: tighten `.gitignore`, extend `scripts/build-release.sh`, and align `readme.txt` / README notes with the zip build and WordPress.org upload flow.

= 1.6.5 =

* Floating **Filters** panel on **MemberPress → Members** (field visibility in the browser via `localStorage`; filter `meprmf_use_floating_members_panel` to use the previous inline toolbar).
* Address filters when MemberPress captures address on the **account** page only (not only at checkout).
* Refine filter control rendering for the floating panel.
* Style: toggle control layout and icon dimensions on the Members filters UI.
* README guidance for when to use extra user-meta filters vs MemberPress **Settings → Fields**; slightly smaller Members toolbar typography for alignment.

= 1.6.2 =

* Raise minimum PHP to **8.1** (was 7.4). WordPress **5.6+** requirement unchanged.
* Run PHPUnit / CI on PHP 8.1 through 8.3.
* Skipped semver labels: 1.6.3 and 1.6.4 were not published from this repository; the next version after 1.6.2 was 1.6.5.

= 1.6.1 =

* Rebrand and paths: plugin folder **`admin-filters-for-memberpress`**, main file **`admin-filters-for-memberpress.php`** (formerly *MemberPress Members Meta Filters* / `memberpress-members-meta-filters.php`).
* Refactor monolithic bootstrap into **`includes/`** classes with a compatibility layer in **`compat/legacy-functions.php`** so existing `meprmf_*` snippets keep working.
* Add **`uninstall.php`** for option cleanup on delete; remove redundant `register_uninstall_hook` usage.
* Add PHPUnit suite, `phpunit.xml.dist`, and GitHub Actions workflow.
* Consolidate filter field definition validation (duplicate `meta_key` rows, select rows missing choices, duplicate dropdown keys) and related helpers.

= 1.5.0 =

* Full MemberPress **address** filters: **state/province**, **zip/postal code**, and **address lines 1 & 2** (in addition to country and city).
* Filter hook **`meprmf_include_address_filters`** to control when built-in address filters are shown (default follows MemberPress address capture settings).
* Prefer MemberPress-configured address field labels when available.
* Plugin header: Author URI and GitHub repository URI.

* Pre-1.6.1 while the header still read 1.5.0 (monolithic memberpress-members-meta-filters.php): README sync — metadata and changelog match the shipped 1.4.0/1.5.0 feature set; document all six address filter fields.
* Uninstall: move option cleanup to `uninstall.php` and remove redundant `register_uninstall_hook` usage.
* Refactor: replace magic numbers with named constants; document maximum lengths enforced when validating filter `param` values.
* Refactor: extract `meprmf_normalize_filter_fields()` (and related helpers) for shared filter field validation.
* Merge `develop` (pull request #1): consolidate validation and handling for configured meta filters ahead of the rebrand.

= 1.4.0 =

* Declare **Requires at least: 5.6** and **Requires PHP: 7.4** in the plugin header.
* Guard plugin constants with `defined()` checks so they are not redefined.
* Load a text domain from the **`languages/`** directory.
* On uninstall, remove leftover plugin options when the plugin is deleted from wp-admin (`uninstall.php`).
* Stricter sanitization when normalizing filter field definitions: skip duplicate `meta_key` rows, require choices for select-type rows, and handle duplicate option keys in dropdown definitions.

= 1.3.0 =

* Initial release (as *MemberPress Members Meta Filters*): filters on the MemberPress **Members** admin list for **country**, **city**, and **MemberPress custom fields** (dropdown, radios, multiselect, checkboxes, checkbox, and text-style field types mapped to sensible controls).
* Apply list constraints via **`EXISTS`** subqueries on **`wp_usermeta`** through **`mepr_list_table_args`**, scoped to the Members list query.
* Compact collapsible filter layout when many filters are active (threshold filterable in later releases).

== Upgrade Notice ==

= 1.6.7 =

Maintenance release: output hardening, languages folder layout for WordPress.org checks, and translation loading alignment. No settings migration.

= 1.6.6 =

Text domain changed to match the plugin slug. If you ship custom translations, rename MO/PO files to `admin-filters-for-memberpress-*`.

= 1.6.5 =

Floating Filters panel and account-page address visibility. No database migration; clear browser storage only if you need to reset panel field visibility.

= 1.6.2 =

Requires PHP 8.1 or newer. Upgrade PHP before updating the plugin if you are still on 7.4.

= 1.6.1 =

Folder and main PHP file were renamed from `memberpress-members-meta-filters`. Deactivate, remove the old folder, install `admin-filters-for-memberpress/`, then activate again. MemberPress **Settings → Fields** and address options are unchanged; keep custom `mepr_members_meta_filters_fields` snippets in your own theme or plugin.

= 1.5.0 =

Adds address filter query parameters (`mpf_state`, `mpf_zip`, etc.). Bookmarks or saved admin URLs from 1.4.0 still work for country/city; no migration required.

= 1.4.0 =

Declares WordPress 5.6+ and PHP 7.4+ in the plugin header. Ensure your host meets PHP 7.4 before updating from 1.3.0.

= 1.3.0 =

First install: no prior version. Requires MemberPress.
