<div align="center">

<img src=".github/readme-assets/logo.png" width="128" height="128" alt="Admin Filters for MemberPress — plugin icon">

# Admin Filters for MemberPress

**Filter the MemberPress Members admin list** by address, MemberPress custom fields (Settings → Fields), and optional extra **user-meta** filters — using MemberPress hooks only; no core files are modified.

[![PHPUnit](https://github.com/omarelhawray/admin-filters-for-memberpress/actions/workflows/phpunit.yml/badge.svg)](https://github.com/omarelhawray/admin-filters-for-memberpress/actions/workflows/phpunit.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759B?logo=wordpress&logoColor=white)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

[GitHub repository](https://github.com/omarelhawray/admin-filters-for-memberpress) · [MemberPress](https://memberpress.com/) (required; install separately — not on WordPress.org)

</div>

---

## At a glance

| | |
| --- | --- |
| **Contributors** | Omar ElHawary — [WordPress.org profile](https://profiles.wordpress.org/omarelhawary/) |
| **Requires** | WordPress 5.6+, PHP 8.1+, active [MemberPress](https://memberpress.com/) |
| **Current release** | 1.6.7 (see plugin header in `admin-filters-for-memberpress.php`) |
| **Text domain** | `admin-filters-for-memberpress` (matches the plugin slug) |
| **License** | GPLv2 or later |

The plugin lives in **`admin-filters-for-memberpress/`** with bootstrap **`admin-filters-for-memberpress.php`**. Custom translation files that used the old domain `memberpress-members-meta-filters` should be renamed to `admin-filters-for-memberpress-{locale}.mo`.

## Screenshots

Add or replace images under [`.github/readme-assets/`](.github/readme-assets/).

### Members list — floating Filters panel

![MemberPress Members screen with the Filters panel open — address fields, custom fields, and Apply filters](.github/readme-assets/members-table-filters.png)

## Features

- Filter the Members list by the six built-in MemberPress address fields: **Country**, **State / Province**, **City**, **Zip / Postal code**, and **Address lines 1 & 2**. Address filters appear when MemberPress has address capture enabled for **signup / checkout** (`show_address_fields`) **or** for the **account** page (`show_address_on_account`), unless you override with the `meprmf_include_address_filters` hook.
- Automatically expose every **MemberPress custom field** (MemberPress → Settings → Fields) as a filter:
  - `dropdown`, `radios` → single-choice (exact match)
  - `multiselect`, `checkboxes` → single-choice (substring match against the stored serialized value)
  - `checkbox` → checked / not set
  - `text`, `email`, `url`, `tel`, `date`, `textarea`, `file` → "contains" search
- Add unlimited **custom user-meta filters** through **MemberPress → Member list filters** (text, single choice, or checkbox).
- **Members** list: floating **Filters** panel (customize which fields show; preferences in the browser via `localStorage`). The previous inline / collapsible toolbar is still available by filtering `meprmf_use_floating_members_panel` to false.
- Filtering is applied as `EXISTS` subqueries on `wp_usermeta` via the `mepr_list_table_args` filter, scoped to the `u` alias used by `MeprUser::list_table()`.
- With `WP_DEBUG` enabled, predicate SQL fragments can be echoed at the bottom of the Members screen for administrators (see `includes/ui/class-meprmf-debug-panel.php`).

## Installation

1. Copy the `admin-filters-for-memberpress` folder into `wp-content/plugins/` (or clone the repo into that path).
2. Activate **Admin Filters for MemberPress** from the Plugins screen.
3. MemberPress must already be active; the plugin does nothing if `MeprUtils` or `MeprOptions` are missing.

### Upgrading from `memberpress-members-meta-filters`

If you previously used the old directory name `memberpress-members-meta-filters/` and `memberpress-members-meta-filters.php`, deactivate the plugin, remove the old folder, upload or clone this plugin as `admin-filters-for-memberpress/`, then activate again. WordPress stores settings by option name, not folder name, so your filter configuration is preserved.

## Usage

### Built-in and custom-field filters

Open **MemberPress → Members**. Open the **Filters** control, set values in the panel, then click **Apply filters** (or press Enter in a text field). MemberPress **Go** still runs the native search / membership row; it does not read the plugin panel fields. To hide the floating panel and use the previous inline toolbar, add `add_filter( 'meprmf_use_floating_members_panel', '__return_false' );`.

### Configuring extra filters (Member list filters)

Use **MemberPress → Member list filters** only when you need to filter by **data that another plugin (or custom code) saves** on each member. MemberPress’s own **Settings → Fields** still appear on the Members list automatically; you do not duplicate them here.

**Simplest setup (what most sites need)**

1. **Field name (technical)** — the exact internal name your other tool uses (often one word, e.g. `company_type`). If you are not sure, ask whoever set up that plugin.
2. **Filter label** — what you want admins to read on the Members screen (e.g. “Company type”).
3. **Filter type** — leave **Search box — type part of a value**.
4. **Dropdown choices** — leave **empty**. Save.

Then on **MemberPress → Members**, open **Filters**, type part of a value, and click **Apply filters**.

**Dropdown setup (fixed list only)**

If each member’s value must be one of a known list (e.g. LLC, Corporation), choose **Dropdown — member must match one listed value** and fill **Dropdown choices**, one per line, like: `LLC|Limited liability company` (left = saved value, right = label shown).

**Checkbox option**

Use **Checkbox — only members who checked it** when the stored value is a simple on/off flag (same idea as a checked box in other plugins).

Empty rows are ignored when saving. Up to 25 extra filters can be configured.

## Extending with code

All filter definitions pass through the `mepr_members_meta_filters_fields` filter, so you can add, remove, or reorder filters programmatically:

```php
add_filter( 'mepr_members_meta_filters_fields', function ( $fields ) {
    $fields[] = [
        'param'    => 'mpf_ext_referrer',
        'meta_key' => 'signup_referrer',
        'label'    => __( 'Referrer', 'your-textdomain' ),
        'type'     => 'text',
        'match'    => 'like',
    ];
    return $fields;
} );
```

Each field supports:

- `param` — `[a-z0-9_]` only; also used as the `$_GET` key.
- `meta_key` — the `wp_usermeta.meta_key` to match.
- `label` — visible label.
- `type` — `country`, `text`, `select`, or `checkbox`.
- `options` — `value => label` map, required for `select`.
- `match` — `exact`, `like`, or `contains` (defaults: `exact` for country/select, `like` otherwise).

Other available hooks:

- `meprmf_compact_filters_threshold` (int, default `6`) — number of filters that triggers the compact collapsible layout.
- `meprmf_settings_trailing_blank_rows` (int) — number of empty rows shown on the settings page.

## How it works

- `mepr_table_controls_search` — renders the filter controls inside the Members toolbar (`Meprmf_Toolbar_Renderer`).
- `mepr_list_table_args` — appends `EXISTS ( SELECT 1 FROM {$wpdb->usermeta} ... )` fragments (`Meprmf_Predicate_Builder`), scoped to `u.ID`.
- `admin_menu` / `admin_init` — register the settings page and option (`meprmf_additional_filters`) for users with MemberPress admin capability.

The procedural API (`meprmf_*` functions) in `compat/legacy-functions.php` delegates to classes in `includes/` so existing snippets and `remove_action` calls keep working.

## Development

### Requirements

- PHP 8.1+
- [Composer](https://getcomposer.org/) (for PHPUnit)

### Unit tests

From the plugin directory:

```bash
composer install
vendor/bin/phpunit
```

Tests use `tests/bootstrap-unit.php` (no full WordPress test database required). They cover utilities, screen detection, MemberPress field mapping, and safe no-op behavior when `$_GET['page']` is not the Members screen (so migration-style queries that call `mepr_list_table_args` without a Members page are not altered).

### Continuous integration

GitHub Actions (`.github/workflows/phpunit.yml`) runs `composer install` and `vendor/bin/phpunit` on PHP 8.1–8.3.

### Release zip (end users / WordPress.org upload)

Run this from the **plugin root** — the directory that contains `admin-filters-for-memberpress.php` and the `scripts/` folder (not from `wp-content/plugins` unless you use the path below).

```bash
cd /path/to/admin-filters-for-memberpress
bash scripts/build-release.sh
```

(`bash` avoids needing `chmod +x`.) Writes `dist/admin-filters-for-memberpress-<version>.zip` (version from the main plugin header), excluding tests, Composer, CI, `docs/`, `wordpress-org-assets/`, and `scripts/`. Put WordPress.org icons/banners in **`wordpress-org-assets/`** (see that folder’s README), then copy them to SVN `assets/` when you publish the listing.

## Changelog

### 1.6.7

- **Plugin Check / PHPCS:** escape filter control attributes at output sites; escape settings “Filter %d” title and badge; document or scope ignores for dynamic SQL identifiers, MemberPress hook name, and internal field arrays that use a `meta_key` schema key (not `WP_Query` meta clauses).
- **`languages/`:** remove `.gitkeep` (hidden file in zip); add `languages/index.php` with `ABSPATH` guard.
- **i18n:** remove redundant `load_plugin_textdomain()` (WordPress.org + WP 4.6+ auto-load).
- **Admin `GET`:** inline PHPCS directives for read-only `$_GET` use when enqueuing assets and when reading filter params.

### 1.6.6

- **WordPress.org:** add root `readme.txt`, align text domain with plugin slug, explicit non-affiliation wording for MemberPress / Caseproof; remove invalid `Requires Plugins: memberpress` header (MemberPress is not a wordpress.org plugin slug).
- **`languages/`** directory tracked for translation drops.

### Earlier releases

Full line-by-line history (1.6.5 through 1.3.0, upgrade notes, and older refactors) is kept in **[readme.txt](readme.txt)** so it stays aligned with the WordPress.org listing. Bump **Current release** in the table above when you ship a new version.

## License

GPL v2 or later. See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
