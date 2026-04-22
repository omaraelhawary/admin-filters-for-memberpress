=== Admin Filters for MemberPress ===
Contributors: omarelhawary
Tags: memberpress, members, admin, filters, membership
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.6.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds address, MemberPress custom fields, and optional user-meta filters to the MemberPress Members admin list. Uses MemberPress hooks only.

== Description ==

**Admin Filters for MemberPress** extends the **MemberPress → Members** admin screen with extra filters: MemberPress address fields (when your site captures them), every MemberPress registration **Settings → Fields** field, and optional **user meta** filters you configure under **MemberPress → Member list filters**.

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

Filter configuration is stored in the WordPress options table under a fixed option name; routine updates do not remove it.

= Where do I configure extra user-meta filters? =

**MemberPress → Member list filters** (MemberPress admin capability required).

== Changelog ==

= 1.6.7 =

* Improve escaping in Members filter controls and on the Member list filters settings screen (WordPress Plugin Check / PHPCS).
* Replace the languages directory placeholder with a non-hidden `index.php` so release zips avoid dotfiles flagged by Plugin Check.
* Drop redundant `load_plugin_textdomain()`; WordPress.org installs load translations automatically (WordPress 4.6+).
* Clarify read-only admin `GET` usage for script loading and filter query parameters where static analysis required it.

= 1.6.6 =

* WordPress.org packaging: add `readme.txt`, align the text domain with the plugin slug (`admin-filters-for-memberpress`), and clarify third-party / trademark disclaimer.
* Remove the `Requires Plugins: memberpress` header because MemberPress is not distributed from the wordpress.org plugin directory (dependency is documented here instead).

= 1.6.5 =

* Floating Filters panel UI tweaks; address filters when account address is enabled without checkout address.

(Older releases: see the project `README.md` or GitHub releases for full history.)

== Upgrade Notice ==

= 1.6.7 =

Maintenance release: output hardening, languages folder layout for WordPress.org checks, and translation loading alignment. No settings migration.

= 1.6.6 =

Text domain changed to match the plugin slug. If you ship custom translations, rename MO/PO files to `admin-filters-for-memberpress-*`.
