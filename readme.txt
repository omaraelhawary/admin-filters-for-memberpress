=== Admin Filters for MemberPress ===
Contributors: omarelhawary
Tags: memberpress, members, admin, filters, membership
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds filters to the MemberPress Members, Subscriptions, and Transactions admin lists. Requires MemberPress.

== Description ==

**Admin Filters for MemberPress** extends the **MemberPress -> Members**, **Subscriptions**, **Lifetimes**, and **Transactions** admin screens with extra filters: MemberPress address fields (when your site captures them), every MemberPress registration **Settings -> Fields** field, and any further **user meta** filters you add with the `meprmf_members_meta_filters_fields` filter (for example in a small custom plugin).

This plugin is an independent project. It is **not** affiliated with, endorsed by, or sponsored by MemberPress.

= Requirements =

* WordPress 5.6 or newer and PHP 8.1 or newer.
* A working install of **MemberPress**. This extension does not ship MemberPress and cannot run without it.

= Privacy =

Filtering reads values you or your administrators submit on those admin lists (standard admin `GET` requests) and builds SQL `EXISTS` conditions on `wp_usermeta` scoped to each list query. No data is sent to external services by this plugin.

= What you get =

* Extra filter controls on the **MemberPress -> Members**, **Subscriptions**, **Lifetimes**, and **Transactions** admin lists so you can narrow rows by address, registration fields, and (optionally) other stored member data you wire in with code.
* On every supported list, additional filters query MemberPress tables (memberships, access, subscriptions, dates) and list-specific fields such as transaction status, gateway, and member status — not only wp_usermeta.
* Each list still works like MemberPress; this plugin only adds filtering options for administrators.
* A **Filters** card above the list where you add one filter at a time — pick a field, a comparison (is, contains, is after, is between, is in the last, is empty, ...) and a value — and choose whether rows must match **all** filters or **any** of them.
* **Saved views** (Filters card): name and reload common filter combinations on each list screen, either shared with the other administrators or private to you, and optionally marked as the view a screen opens with. Views include plugin panel params and native MemberPress toolbar params (`status`, `membership`, `gateway`, transaction date fields, gifting `type` when applicable).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/admin-filters-for-memberpress` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Ensure **MemberPress** is already installed and active. If MemberPress is inactive, this plugin does nothing.

== Screenshots ==

1. MemberPress **Members** admin list with the **Filters** card open: each filter is a field, comparison, and value row -- **Member status is Active members**, **Registered is in the last 12 months**, **Total spent is at least 1** -- above the filtered list.

== Frequently Asked Questions ==

= Does this plugin include MemberPress? =

No. You must purchase and install MemberPress separately. This plugin only adds filters to the supported MemberPress admin lists when MemberPress is active.

= Where do I use the filters? =

In the WordPress admin, open **MemberPress -> Members** (or **Subscriptions**, **Lifetimes**, or **Transactions**). In the **Filters** card above the table, click **+ Add filter**, pick a field, set the comparison and value, then click **Apply filters** to refresh the list. Use **Saved views** to save or load named filter combinations on that screen -- shared with the other administrators, or private to you. **Set as default** makes one of your views the one that screen opens with.

= What can I filter members by? =

* **Address** fields when your site collects them in MemberPress (for example country, city, postal code), including when address is captured on the account page.
* Every field you configure under **MemberPress -> Settings -> Fields** (registration / profile style fields).
* **Extra user meta** only if a developer adds filter definitions using the `meprmf_members_meta_filters_fields` filter hook (for data stored in `wp_usermeta` that is not already covered).
* On **Transactions**, the transaction **Amount** and the **Subscription** it belongs to; on **Subscriptions**, how many completed transactions the subscription has.

= Does this change my public website or checkout? =

No. It only affects those **admin** MemberPress list screens. Visitors and the front of your site are unchanged.

= Is member data sent to a third-party service? =

No. Filtering runs inside your WordPress install and database. See the **Privacy** note in the description above.

= What happens if MemberPress is turned off? =

The plugin waits quietly. Once MemberPress is active again, the filters show on the supported lists as before.

= Where do I get support for this plugin? =

Use the [Support forum](https://wordpress.org/support/plugin/admin-filters-for-memberpress/) on WordPress.org for **Admin Filters for MemberPress**.

= Where are the plugin settings? =

Open **MemberPress -> Admin Filters**. From there you choose which of the four lists show filters, turn the **Filters** card on or off, set whether custom date fields start as a from / to range, and pick who may create shared saved views. Turning the **Filters** card off removes the filter UI. It does not bring back the inline toolbar used before 2.1.0.

= How do developers extend the filters? =

* Filter hook for extra meta-based filter definitions: `meprmf_members_meta_filters_fields`.
* Optional UI hook (show or remove the **Filters** card): `meprmf_use_floating_members_panel`.
* Per-screen on / off hook: `meprmf_screen_filters_enabled`.
* Code wins over the Settings screen. **MemberPress -> Admin Filters** sets the site-wide value, and the hooks and constants run after it, so a snippet that forces a value keeps that value whatever the screen says.
* Source and issues: see **Plugin URI** and **GitHub URI** in the main plugin file header (`admin-filters-for-memberpress.php`).

== Changelog ==

= 2.3.0 =

* Settings screen at **MemberPress -> Admin Filters**: choose which of the four lists show filters, turn the **Filters** card on or off, set whether custom date fields start as a from / to range, and pick who may create shared saved views.
* Turning a list off there stops this plugin on that list, down to the columns and the query conditions. Saved views for that list stay in the database and come back when you turn it on again.
* Creating a shared saved view now needs the capability chosen on that screen. The default is administrators who can manage options. Private views stay open to any administrator who can use the filters, and views already saved are untouched.
* Code still wins over the screen: a hook or a constant runs after the saved value and takes precedence.

= 2.2.1 =

* Performance: opening a Members, Transactions, Subscriptions or Lifetimes list no longer does the work of collecting every filterable field before checking whether you have a default view set. Sites where nobody uses a default view save that work on every list screen load. No change to what the filters do.

= 2.2.0 =

* **Transactions:** filter by **Amount** (is at least / is at most / is between, in your MemberPress currency) and by **Subscription** — the gateway subscription id shown in the list's Subscr. column, with contains, does not contain, is, is not, is empty and is not empty.
* **Subscriptions:** filter by **Transaction count** (is at least / is at most / is between). Counts the same completed transactions MemberPress shows in the row's Transactions column, so the filter and the number agree.
* **Saved views now belong to someone.** A view records the administrator who saved it and whether it is **shared** with the other administrators or **private** to its owner. A private view is not shown to anyone else.
* **Deleting another administrator's shared view is refused** with a message saying why, instead of silently removing it for everyone. So is saving over one under the same name. Administrators who can manage options may still do both.
* **Default view:** mark one saved view per screen as the view that screen opens with. It is per administrator, an explicit filter in the address bar always wins, and clearing the filters shows the unfiltered list rather than re-applying the default.
* Views saved before this release keep working for everyone exactly as they did: they read as shared and unowned, nobody loses a view, and nothing in the database is rewritten.

= 2.1.1 =

* Fixed: a native MemberPress toolbar filter left on its **All** option (All Memberships, All Statuses, All Gateways, or the transaction date range's All) no longer shows an active-filter chip. It restricts nothing, so removing it changed no rows.
* Screenshot and its caption updated to the query-builder Filters card.

= 2.1.0 =

* **Query-builder Filters card** on all four list screens: add one filter at a time as a field, comparison, and value row instead of scanning a grid of every available field.
* **Comparisons per field type:** choice (is, is not, is empty), text (contains, does not contain, is, is empty), date (is after, is before, is between, is in the last, is not in the last), number (is at least, is at most, is between).
* **Match all or any:** rows can require every filter or just one of them.
* **Saved presets are now Saved views,** with the same site-wide storage and hooks.
* Active-filter chips read the same wording as the row that produced them, so an exact match and a substring match are no longer shown identically.
* Filters stay bookmarkable: each row reads from and writes to plain query parameters.

= 2.0.0 =

* **Saved filter presets** (floating panel): site-wide named presets on all four list screens; presets now include native MemberPress toolbar params (status, membership, gateway, transaction date fields, gifting type) in addition to plugin panel params. Stored in wp_options meprmf_filter_presets, per screen. Load, save (upsert by name), and delete from the panel. Filter hooks meprmf_filter_presets and meprmf_max_filter_presets_per_screen (default 25 per screen).
* **Add-on passthrough filters:** Course, Circle, Directory (Members); Coupon and Gift type (Transactions) when the corresponding add-ons are active.
* **Members activity filters:** registered date range, last login range, total spent min/max, on trial.
* **Corporate type** filter on Members when MemberPress Corporate is active.
* **Coupon** filter on Lifetimes (mpml_coupon).
* Hooks: meprmf_members_addon_filters_fields, meprmf_members_activity_filters_fields, meprmf_native_toolbar_params, meprmf_corporate_type_predicate.
* **Floating panel:** Apply preserves active filters on hidden fields; badge reflects visible panel edits; focus trap while the panel is open.
* **Performance:** filter hook meprmf_use_inactive_access_predicate to skip the heaviest Members inactive-access predicate.
* **List-table scoping:** predicates still apply when get_current_screen() is unavailable (custom admin bootstraps).

= 1.9.1 =

* Safer list-table scoping: predicates only when the matching MemberPress list_table() caller and WP_Screen align.
* Members member-since uses EXISTS on mepr_members; transaction status includes Confirmed; row-scoped Access labels on non-Members lists.
* Date custom fields (Settings -> Fields): filter by from/to date range instead of one exact date; per-admin toggle in the Filters panel customize UI (user meta meprmf_date_custom_fields_use_range, default on). Filter hook meprmf_custom_date_fields_use_range; constant MEPRMF_DATE_CUSTOM_FIELDS_USE_RANGE for site-wide override.

= 1.9.0 =

* **Core table filters on all four lists:** membership, access, subscription status, expires range, and member-since range on Transactions, Subscriptions, and Lifetimes (mpmt_*, mpms_*, mpml_*), with row-scoped SQL.
* **Screen-specific panel filters:** Members — member status (active / inactive / expired / non-members); Transactions and Lifetimes — transaction status, gateway, created date range; Subscriptions and Lifetimes — gateway.
* Hooks meprmf_transactions_core_filters_fields, meprmf_subscriptions_core_filters_fields, and meprmf_lifetimes_core_filters_fields for extensions.
* meprmf_mepr_predicate_fragments runs on every supported list context.

= 1.8.0 =

* **Members list — MemberPress table filters:** filter by membership (product), active/inactive access (transactions), subscription status, expires date range, and member-since date range via EXISTS on mepr_transactions, mepr_subscriptions, and mepr_members.
* Hooks meprmf_members_core_filters_fields and meprmf_mepr_predicate_fragments for extensions.
* Debug panel shows both meta and MemberPress table predicate fragments when WP_DEBUG is on.

= 1.7.0 =

* **Subscriptions, Lifetimes, and Transactions:** the same address and **Settings → Fields** meta filters as on **Members**, scoped to each list’s user column (`mepr_list_table_args`).
* **Floating Filters panel:** print panel markup in `admin_footer` on supported screens so MemberPress toolbar markup stays valid; filter `meprmf_use_floating_meta_filters_panel` to control the panel per screen (Members still respects `meprmf_use_floating_members_panel`).
* **Build:** minify floating-panel JS and toolbar CSS with esbuild when building the release zip (`npm run build` from `build-release.sh`).

= 1.6.8 =

* Floating Filters panel: when the set of filter query params changes (for example after enabling MemberPress **Show on Account** or **Show on Signup** for address), reset saved field visibility so new address filters are not left hidden by an older `localStorage` whitelist.
* Members provider: document address toggles explicitly; add a unit test for signup-only address capture.

= 1.6.7 =

* Improve escaping in Members filter controls (WordPress Plugin Check / PHPCS).
* Replace the languages directory placeholder with a non-hidden `index.php` so release zips avoid dotfiles flagged by Plugin Check.
* Drop redundant `load_plugin_textdomain()`; WordPress.org installs load translations automatically (WordPress 4.6+).
* Clarify read-only admin `GET` usage for script loading and filter query parameters where static analysis required it.
* Toolbar renderer: clearer attribute handling and inline documentation for SQL / predicate behavior; document WordPress.org banner/icon layout under `wordpress-org-assets/`.
* README (GitHub): structure and screenshot guidance refresh.

= 1.6.6 =

* WordPress.org packaging: add `readme.txt`, align the text domain with the plugin slug (`admin-filters-for-memberpress`), and clarify third-party / trademark disclaimer.
* Remove the `Requires Plugins: memberpress` header because MemberPress is not distributed from the wordpress.org plugin directory (dependency is documented here instead).
* Track a `languages/` directory for translation drops (initial placeholder before the 1.6.7 `index.php` layout).
* Release hygiene: tighten `.gitignore`, extend `scripts/build-release.sh`, and align `readme.txt` / README notes with the zip build and WordPress.org upload flow.
* WordPress.org review: set Plugin URI to the plugin directory listing; correct GitHub repository URL in plugin header and developer metadata (`composer.json` / README where applicable).
* Prefix compliance: rename the custom extension filter from `mepr_members_meta_filters_fields` to `meprmf_members_meta_filters_fields`. If you added filters in code, update your `add_filter` hook name.

= 1.6.5 =

* Floating **Filters** panel on **MemberPress -> Members** (field visibility in the browser via `localStorage`; filter `meprmf_use_floating_members_panel` to use the previous inline toolbar).
* Address filters when MemberPress captures address on the **account** page only (not only at checkout).
* Refine filter control rendering for the floating panel.
* Style: toggle control layout and icon dimensions on the Members filters UI.
* README guidance for when to use extra user-meta filters vs MemberPress **Settings -> Fields**; slightly smaller Members toolbar typography for alignment.

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

= 2.3.0 =

Adds a Settings screen at MemberPress -> Admin Filters. All four options keep the behavior you have now, with one exception: creating a shared saved view now needs the capability set on that screen, which defaults to administrators who can manage options. Saved views and filter URLs are untouched, and no database migration runs.

= 2.2.1 =

Performance only: the default-view check no longer collects every filterable field before reading whether a default view is set. No behaviour changes.

= 2.2.0 =

Adds Amount and Subscription filters on Transactions, a Transaction count filter on Subscriptions, and saved views that belong to an administrator (shared or private) with an optional default view per screen. Existing filter URLs and saved views keep working -- older views read as shared and unowned. No database migration.

= 2.1.1 =

Fixes an active-filter chip shown for a native MemberPress toolbar filter left on **All**, which restricted nothing. No other behaviour changes.

= 2.1.0 =

Redesigned Filters card: filters are now added one at a time as field, comparison, and value. Existing bookmarked filter URLs and saved presets keep working. No database migration.

= 2.0.0 =

Major release: saved filter presets (including native toolbar params), add-on passthrough filters, Members activity filters, Corporate type, Lifetimes coupon, floating-panel UX fixes, and inactive-access performance hook. Hard-refresh admin or reset Customize visibility if new fields do not appear.

= 1.9.1 =

Patch release: safer list-table scoping, custom date from/to ranges, Confirmed transaction status, and clearer row-scoped Access labels. No database migration.

= 1.9.0 =

Feature release: core table filters and screen-specific fields (member status, transaction status, gateway, created dates) on Transactions, Subscriptions, and Lifetimes as well as Members. No database migration.

= 1.8.0 =

Feature release: Members list filters for membership, access (active/inactive), subscription status, and date ranges on MemberPress tables. No database migration.

= 1.7.0 =

Feature release: filters on Subscriptions, Lifetimes, and Transactions admin lists in addition to Members. No database migration.

= 1.6.7 =

Maintenance release: output hardening, languages folder layout for WordPress.org checks, and translation loading alignment. No settings migration.

= 1.6.6 =

Text domain matches the plugin slug; rename custom MO/PO to admin-filters-for-memberpress-* if needed. If you used the old meta-filters hook, change it to meprmf_members_meta_filters_fields. Plugin URI and GitHub URI in the plugin header were updated.

= 1.6.5 =

Floating Filters panel and account-page address visibility. No database migration; clear browser storage only if you need to reset panel field visibility.

= 1.6.2 =

Requires PHP 8.1 or newer. Upgrade PHP before updating the plugin if you are still on 7.4.

= 1.6.1 =

Replaced folder memberpress-members-meta-filters with admin-filters-for-memberpress. Deactivate, remove the old folder, install the new path, activate. MemberPress settings unchanged. Old hook mepr_members_meta_filters_fields is now meprmf_members_meta_filters_fields.

= 1.5.0 =

Adds address filter query parameters (`mpf_state`, `mpf_zip`, etc.). Bookmarks or saved admin URLs from 1.4.0 still work for country/city; no migration required.

= 1.4.0 =

Declares WordPress 5.6+ and PHP 7.4+ in the plugin header. Ensure your host meets PHP 7.4 before updating from 1.3.0.

= 1.3.0 =

First install: no prior version. Requires MemberPress.
