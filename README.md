# MemberPress Members Meta Filters

Adds Country, City, MemberPress custom fields, and optional extra user-meta filters to the MemberPress Members admin list. Uses MemberPress hooks only — no core files are modified.

- **Contributors:** Omar ElHawary
- **Requires Plugins:** [MemberPress](https://memberpress.com/)
- **Version:** 1.3.0
- **License:** GPLv2 or later
- **Text Domain:** `memberpress-members-meta-filters`

## Features

- Filter the Members list by **Country** (`mepr-address-country`) and **City** (`mepr-address-city`).
- Automatically expose every **MemberPress custom field** (MemberPress → Settings → Fields) as a filter:
  - `dropdown`, `radios` → single-choice (exact match)
  - `multiselect`, `checkboxes` → single-choice (substring match against the stored serialized value)
  - `checkbox` → checked / not set
  - `text`, `email`, `url`, `tel`, `date`, `textarea`, `file` → "contains" search
- Add unlimited **custom user-meta filters** through a dedicated settings page (text, single choice, or checkbox).
- Compact collapsible toolbar when six or more filters are active, so the Members list stays usable.
- All filtering is applied as `EXISTS` subqueries on `wp_usermeta` via the `mepr_list_table_args` filter — no queries on other MemberPress list tables are touched.

## Installation

1. Copy the `memberpress-members-meta-filters` folder into `wp-content/plugins/`.
2. Activate **MemberPress Members Meta Filters** from the Plugins screen.
3. MemberPress must already be active; the plugin does nothing if `MeprUtils` or `MeprOptions` are missing.

## Usage

### Built-in and custom-field filters

Open **MemberPress → Members**. Country, City, and every configured MemberPress custom field appear next to the search box. Select or type a value and press the Members list's existing **Go** button.

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

- `mepr_table_controls_search` — renders the filter controls inside the Members toolbar.
- `mepr_list_table_args` — appends `EXISTS ( SELECT 1 FROM {$wpdb->usermeta} ... )` fragments, scoped to the `u` alias used by `MeprUser::list_table()`.
- The `admin_menu` and `admin_init` hooks register the settings page and option (`meprmf_additional_filters`) only for users with MemberPress admin capability.

## Changelog

### 1.3.0

- Initial public version.

## License

GPL v2 or later. See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
