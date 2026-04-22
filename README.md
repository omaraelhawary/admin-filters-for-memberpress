# Admin Filters for MemberPress

Adds address (country, state, city, zip, address lines), MemberPress custom fields (MemberPress → Settings → Fields), and optional extra **user-meta** filters to the **MemberPress → Members** admin list. Uses MemberPress hooks only — no core files are modified.

The plugin lives in the folder **`admin-filters-for-memberpress`** with bootstrap file **`admin-filters-for-memberpress.php`**. The **text domain** stays `memberpress-members-meta-filters` so existing translations and `load_plugin_textdomain` paths keep working. The GitHub repository is [admin-filters-for-memberpress](https://github.com/omarelhawray/admin-filters-for-memberpress).

- **Contributors:** Omar ElHawary
- **Requires Plugins:** [MemberPress](https://memberpress.com/)
- **Requires at least:** 5.6
- **Requires PHP:** 8.1
- **Version:** 1.6.5
- **License:** GPLv2 or later
- **Text Domain:** `memberpress-members-meta-filters`

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

### Configuring additional user-meta filters

Go to **MemberPress → Member list filters** to add filters for meta keys written by other plugins or by your own code.

For each row, provide:

| Field           | Purpose                                                                          |
| --------------- | -------------------------------------------------------------------------------- |
| User meta key   | The exact `meta_key` stored in `wp_usermeta`.                                    |
| Filter label    | Label shown in the Members toolbar.                                              |
| Filter type     | `Text contains`, `Single choice (exact match)`, or `Checkbox is checked`.        |
| Choices         | Required for `Single choice`. One per line: `stored_value|Optional label`.       |

Empty rows are ignored when saving. Up to 25 additional filters can be configured.

### Filter types reference

- **Text contains** — substring match (`LIKE %value%`). Best for free-form values (names, notes, URLs).
- **Single choice (exact match)** — renders a `<select>`; matches the stored meta value exactly.
- **Checkbox is checked** — matches users whose meta value is `on` or `1` (MemberPress's stored checked value).

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

## Changelog

### 1.6.5

- **Floating panel:** align Filters **dashicon** with label/badge; **Apply** / **Clear** now save `meprmf_panel_open` = false so the panel reopens closed after reload; customize list **checkbox** / **Done** layout (remove panel `overflow-x` clipping).
- **Address filters:** shown when MemberPress **Show on Account** is enabled even if signup/checkout address row is off (`show_address_on_account` without `show_address_fields`).

### 1.6.4

- **Members floating panel:** fix layout — panel `max-width: 100%` was resolving against the narrow toggle wrapper and crushing the panel; inputs also pick up wp-admin `.regular-text { width: 25em }`. Panel width is now viewport-based; grid items and fields use `min-width: 0` and full-width fields inside the panel.

### 1.6.3

- **Members list:** floating **Filters** panel (toggle, grid, **Apply filters**, **Clear**, **Customize**) with per-browser `localStorage` (`meprmf_panel_open`, `meprmf_visible_filters`). Use **Apply filters** (or Enter in a field) to apply; MemberPress **Go** does not submit these controls. Disable with `add_filter( 'meprmf_use_floating_members_panel', '__return_false' );` to restore the previous inline / collapsible toolbar.

### 1.6.2

- **Requires PHP** raised to **8.1** (plugin header, Composer, and CI). PHPUnit workflow matrix is PHP 8.1–8.3 only.

### 1.6.1

- **Rename** plugin directory to `admin-filters-for-memberpress` and bootstrap file to `admin-filters-for-memberpress.php` to match the product name. Text domain and option keys are unchanged.

### 1.6.0

- **Rebrand** display name to **Admin Filters for MemberPress**. Update GitHub repository to [admin-filters-for-memberpress](https://github.com/omarelhawray/admin-filters-for-memberpress).
- **Refactor** into `includes/` (Plugin, Screen, Members provider, Predicate builder, Toolbar, Settings, Debug) and `compat/legacy-functions.php` for backward-compatible `meprmf_*` functions.
- **Tests:** PHPUnit unit suite (`tests/unit/`) and GitHub Actions workflow.
- **Debug:** optional footer output of SQL predicate fragments on the Members list when `WP_DEBUG` is on (`Meprmf_Debug_Panel`).

### 1.5.0

- Added built-in filters for **State / Province**, **Zip / Postal code**, **Address line 1**, and **Address line 2** (previously only Country and City were supported).
- Built-in address filters now inherit MemberPress' translated field labels when available.
- Address filters are gated behind MemberPress' `show_address_fields` option and a new `meprmf_include_address_filters` hook so sites not capturing addresses don't see empty controls.
- Extracted the address filter set into `meprmf_get_address_filter_fields()` for clarity.

### 1.4.0

- Added a `MemberPress → Member list filters` settings page for configuring unlimited custom user-meta filters (text contains / single choice / checkbox).
- Settings sanitizer now reports duplicate meta keys, `select` filters missing options, and duplicate option values via admin notices.
- Added translation loading (`load_plugin_textdomain`).
- Registered an uninstall cleanup that deletes the plugin option when the plugin is removed.
- Declared `Requires at least: 5.6` and `Requires PHP: 7.4` in the plugin header.

### 1.3.0

- Initial public version.

## License

GPL v2 or later. See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
