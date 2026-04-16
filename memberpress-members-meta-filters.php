<?php
/**
 * Plugin Name: MemberPress Members Meta Filters
 * Description: Adds address (country, state, city, zip, address lines), MemberPress custom fields, and optional extra user-meta filters to the MemberPress Members admin list. Uses MemberPress hooks only.
 * Version: 1.5.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Omar ElHawray
 * Author URI: https://www.omarelhawray.com
 * GitHub URI: https://github.com/omarelhawray/memberpress-members-meta-filters
 * Requires Plugins: memberpress
 * License: GPLv2 or later
 * Text Domain: memberpress-members-meta-filters
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/** @var string Option name for manually configured filters. */
if (! defined('MEPRMF_OPTION_ADDITIONAL')) {
    define('MEPRMF_OPTION_ADDITIONAL', 'meprmf_additional_filters');
}

/** @var string Plugin version for asset cache-busting. */
if (! defined('MEPRMF_VERSION')) {
    define('MEPRMF_VERSION', '1.5.0');
}

/** @var int Maximum number of additional filter rows shown/stored on the settings page. */
if (! defined('MEPRMF_MAX_ROWS')) {
    define('MEPRMF_MAX_ROWS', 25);
}

/**
 * URL to a file under this plugin directory.
 *
 * @param string $relative Path relative to the main plugin file (e.g. `assets/foo.css`).
 * @return string
 */
function meprmf_plugin_url($relative)
{
    return plugins_url($relative, __FILE__);
}

/**
 * Bootstrap after plugins load.
 */
add_action(
    'plugins_loaded',
    static function () {
        load_plugin_textdomain(
            'memberpress-members-meta-filters',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        if (! class_exists('MeprUtils') || ! class_exists('MeprOptions')) {
            return;
        }

        add_action('mepr_table_controls_search', 'meprmf_render_meta_filters', 20, 2);
        add_filter('mepr_list_table_args', 'meprmf_filter_members_list_args', 10, 1);
        add_action('admin_menu', 'meprmf_register_admin_menu', 30);
        add_action('admin_init', 'meprmf_register_settings');
        add_action('admin_enqueue_scripts', 'meprmf_admin_enqueue_scripts');
    },
    20
);

/**
 * Only the Members screen uses alias `u` for wp_users in MeprUser::list_table().
 * Other MemberPress list tables reuse mepr_list_table_args and different aliases — do not run there.
 *
 * @return bool
 */
function meprmf_is_members_admin_list_request()
{
    return is_admin()
        && isset($_GET['page']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        && 'memberpress-members' === sanitize_text_field(wp_unslash($_GET['page']));
}

/**
 * Capability aligned with MemberPress admin menus.
 *
 * @return bool
 */
function meprmf_current_user_can_filter()
{
    return current_user_can(MeprUtils::get_mepr_admin_capability());
}

/**
 * Register submenu under MemberPress.
 *
 * @return void
 */
function meprmf_register_admin_menu()
{
    if (! meprmf_current_user_can_filter()) {
        return;
    }

    add_submenu_page(
        'memberpress',
        __('Member list filters', 'memberpress-members-meta-filters'),
        __('Member list filters', 'memberpress-members-meta-filters'),
        MeprUtils::get_mepr_admin_capability(),
        'meprmf-settings',
        'meprmf_render_settings_page'
    );
}

/**
 * Register option and sanitization.
 *
 * @return void
 */
function meprmf_register_settings()
{
    register_setting(
        'meprmf_settings_group',
        MEPRMF_OPTION_ADDITIONAL,
        [
            'type'              => 'array',
            'sanitize_callback' => 'meprmf_sanitize_additional_filters_option',
            'default'           => [],
        ]
    );
}

/**
 * Load admin styles/scripts on relevant screens.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function meprmf_admin_enqueue_scripts($hook_suffix)
{
    if ('memberpress_page_meprmf-settings' === $hook_suffix) {
        wp_enqueue_style(
            'meprmf-admin-settings',
            meprmf_plugin_url('assets/meprmf-admin-settings.css'),
            [],
            MEPRMF_VERSION
        );
        wp_enqueue_script(
            'meprmf-admin-settings',
            meprmf_plugin_url('assets/meprmf-admin-settings.js'),
            [],
            MEPRMF_VERSION,
            true
        );
        return;
    }

    if (
        meprmf_current_user_can_filter()
        && isset($_GET['page']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        && 'memberpress-members' === sanitize_text_field(wp_unslash($_GET['page']))
    ) {
        wp_enqueue_style(
            'meprmf-members-toolbar',
            meprmf_plugin_url('assets/meprmf-members-toolbar.css'),
            [],
            MEPRMF_VERSION
        );
    }
}

/**
 * Sanitize saved additional filter rows.
 *
 * @param mixed $value Raw option value.
 * @return array<int, array<string, mixed>>
 */
function meprmf_sanitize_additional_filters_option($value)
{
    if (! is_array($value)) {
        return [];
    }

    $out               = [];
    $seen_meta_keys    = [];
    $collisions        = [];
    $missing_choices   = [];
    $duplicate_choices = [];

    foreach ($value as $row) {
        if (! is_array($row)) {
            continue;
        }
        $meta_key = isset($row['meta_key']) ? sanitize_text_field((string) $row['meta_key']) : '';
        $label    = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
        $ftype    = isset($row['filter_type']) ? sanitize_key((string) $row['filter_type']) : 'text';
        $opts_raw = isset($row['options_text']) ? (string) $row['options_text'] : '';

        if ('' === $meta_key || '' === $label) {
            continue;
        }

        if (isset($seen_meta_keys[ $meta_key ])) {
            $collisions[] = $meta_key;
            continue;
        }
        $seen_meta_keys[ $meta_key ] = true;

        if (! in_array($ftype, [ 'text', 'select', 'checkbox' ], true)) {
            $ftype = 'text';
        }

        $options     = [];
        $saw_dup_key = false;
        if ('select' === $ftype && '' !== $opts_raw) {
            foreach (preg_split("/\r\n|\n|\r/", $opts_raw) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                if (false !== strpos($line, '|')) {
                    $parts = explode('|', $line, 2);
                    $vk    = sanitize_text_field(trim($parts[0]));
                    $vl    = isset($parts[1]) ? sanitize_text_field(trim($parts[1])) : $vk;
                } else {
                    $vk = sanitize_text_field($line);
                    $vl = $vk;
                }
                if ('' !== $vk) {
                    if (array_key_exists($vk, $options)) {
                        $saw_dup_key = true;
                    }
                    $options[ $vk ] = $vl;
                }
            }
        }

        if ('select' === $ftype && empty($options)) {
            $missing_choices[] = $label;
        }

        if ($saw_dup_key) {
            $duplicate_choices[] = $label;
        }

        $out[] = [
            'meta_key'     => $meta_key,
            'label'        => $label,
            'filter_type'  => $ftype,
            'options'      => $options,
            'options_text' => $opts_raw,
        ];
    }

    if (! empty($collisions)) {
        add_settings_error(
            'meprmf_messages',
            'meprmf_duplicate_meta_keys',
            sprintf(
                /* translators: %s: comma-separated list of duplicated meta keys */
                esc_html__('Duplicate meta key(s) ignored: %s. Each filter must target a unique user meta key.', 'memberpress-members-meta-filters'),
                esc_html(implode(', ', array_unique($collisions)))
            ),
            'warning'
        );
    }

    if (! empty($missing_choices)) {
        add_settings_error(
            'meprmf_messages',
            'meprmf_missing_choices',
            sprintf(
                /* translators: %s: comma-separated list of filter labels */
                esc_html__('“Single choice” filter(s) without options will not appear until you add choices: %s.', 'memberpress-members-meta-filters'),
                esc_html(implode(', ', array_unique($missing_choices)))
            ),
            'warning'
        );
    }

    if (! empty($duplicate_choices)) {
        add_settings_error(
            'meprmf_messages',
            'meprmf_duplicate_choices',
            sprintf(
                /* translators: %s: comma-separated list of filter labels */
                esc_html__('Duplicate option value(s) were merged in: %s.', 'memberpress-members-meta-filters'),
                esc_html(implode(', ', array_unique($duplicate_choices)))
            ),
            'info'
        );
    }

    return $out;
}

/**
 * Render settings page (MemberPress → Member list filters).
 *
 * @return void
 */
function meprmf_render_settings_page()
{
    if (! current_user_can(MeprUtils::get_mepr_admin_capability())) {
        wp_die(esc_html__('You do not have permission to access this page.', 'memberpress-members-meta-filters'));
    }

    if (isset($_GET['settings-updated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        add_settings_error(
            'meprmf_messages',
            'meprmf_message',
            __('Settings saved.', 'memberpress-members-meta-filters'),
            'success'
        );
    }

    $saved = get_option(MEPRMF_OPTION_ADDITIONAL, []);
    if (! is_array($saved)) {
        $saved = [];
    }

    $max_rows    = MEPRMF_MAX_ROWS;
    $saved_count = count($saved);
    // Fewer spare rows when many filters are already configured (less scrolling).
    if ($saved_count >= 10) {
        $min_trailing_blank = 2;
    } elseif ($saved_count >= 6) {
        $min_trailing_blank = 3;
    } else {
        $min_trailing_blank = 5;
    }
    $min_trailing_blank = (int) apply_filters('meprmf_settings_trailing_blank_rows', $min_trailing_blank, $saved_count);
    $rows               = $saved;
    $target_count       = min(
        $max_rows,
        max(count($saved) + $min_trailing_blank, $min_trailing_blank)
    );
    while (count($rows) < $target_count) {
        $rows[] = [
            'meta_key'     => '',
            'label'        => '',
            'filter_type'  => 'text',
            'options_text' => '',
        ];
    }

    $members_url     = admin_url('admin.php?page=memberpress-members');
    $mepr_fields_url = admin_url('admin.php?page=memberpress-options');

    ?>
    <div class="wrap meprmf-settings-wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="notice notice-info">
            <p>
                <?php esc_html_e('Configure extra filters for user meta keys (for example values saved by other plugins). Filters appear on the Members screen next to search.', 'memberpress-members-meta-filters'); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: open link MemberPress settings, 2: close link, 3: open link Members, 4: close link */
                    esc_html__('Registration fields you set in %1$sMemberPress → Settings → Fields%2$s are added automatically. Open %3$sMemberPress → Members%4$s to use the filters.', 'memberpress-members-meta-filters'),
                    '<a href="' . esc_url($mepr_fields_url) . '">',
                    '</a>',
                    '<a href="' . esc_url($members_url) . '">',
                    '</a>'
                );
                ?>
            </p>
        </div>

        <?php settings_errors('meprmf_messages'); ?>

        <div class="meprmf-settings-layout">
            <div class="meprmf-settings-main">
                <form action="options.php" method="post" class="meprmf-settings-form">
                    <?php settings_fields('meprmf_settings_group'); ?>

                    <h2 class="title screen-reader-text">
                        <?php esc_html_e('Additional filters', 'memberpress-members-meta-filters'); ?>
                    </h2>

                    <?php
                    foreach (array_slice($rows, 0, $max_rows) as $i => $row) {
                        $mk   = isset($row['meta_key']) ? (string) $row['meta_key'] : '';
                        $lb   = isset($row['label']) ? (string) $row['label'] : '';
                        $ft   = isset($row['filter_type']) ? (string) $row['filter_type'] : 'text';
                        $otxt = isset($row['options_text']) ? (string) $row['options_text'] : '';
                        $num  = (int) $i + 1;
                        ?>
                        <div class="meprmf-filter-card">
                            <div class="meprmf-filter-card__head">
                                <h3 class="meprmf-filter-card__title">
                                    <?php
                                    printf(
                                        /* translators: %d: filter row number */
                                        esc_html__('Filter %d', 'memberpress-members-meta-filters'),
                                        $num
                                    );
                                    ?>
                                </h3>
                                <span class="meprmf-filter-card__badge" aria-hidden="true"><?php echo (int) $num; ?></span>
                            </div>
                            <div class="meprmf-filter-card__body">
                                <div class="meprmf-filter-card__grid">
                                    <div class="meprmf-field">
                                        <label for="meprmf-meta-key-<?php echo (int) $i; ?>">
                                            <?php esc_html_e('User meta key', 'memberpress-members-meta-filters'); ?>
                                        </label>
                                        <input
                                            id="meprmf-meta-key-<?php echo (int) $i; ?>"
                                            type="text"
                                            class="regular-text"
                                            name="<?php echo esc_attr(MEPRMF_OPTION_ADDITIONAL); ?>[<?php echo (int) $i; ?>][meta_key]"
                                            value="<?php echo esc_attr($mk); ?>"
                                            placeholder="<?php esc_attr_e('e.g. my_custom_meta', 'memberpress-members-meta-filters'); ?>"
                                            autocomplete="off"
                                        />
                                        <p class="description">
                                            <?php esc_html_e('Must match the key stored in the WordPress usermeta table.', 'memberpress-members-meta-filters'); ?>
                                        </p>
                                    </div>
                                    <div class="meprmf-field">
                                        <label for="meprmf-label-<?php echo (int) $i; ?>">
                                            <?php esc_html_e('Filter label', 'memberpress-members-meta-filters'); ?>
                                        </label>
                                        <input
                                            id="meprmf-label-<?php echo (int) $i; ?>"
                                            type="text"
                                            class="regular-text"
                                            name="<?php echo esc_attr(MEPRMF_OPTION_ADDITIONAL); ?>[<?php echo (int) $i; ?>][label]"
                                            value="<?php echo esc_attr($lb); ?>"
                                            placeholder="<?php esc_attr_e('Shown in the Members list toolbar', 'memberpress-members-meta-filters'); ?>"
                                            autocomplete="off"
                                        />
                                    </div>
                                    <div class="meprmf-field">
                                        <label for="meprmf-type-<?php echo (int) $i; ?>">
                                            <?php esc_html_e('Filter type', 'memberpress-members-meta-filters'); ?>
                                        </label>
                                        <select
                                            id="meprmf-type-<?php echo (int) $i; ?>"
                                            name="<?php echo esc_attr(MEPRMF_OPTION_ADDITIONAL); ?>[<?php echo (int) $i; ?>][filter_type]"
                                        >
                                            <option value="text" <?php selected($ft, 'text'); ?>><?php esc_html_e('Text contains', 'memberpress-members-meta-filters'); ?></option>
                                            <option value="select" <?php selected($ft, 'select'); ?>><?php esc_html_e('Single choice (exact match)', 'memberpress-members-meta-filters'); ?></option>
                                            <option value="checkbox" <?php selected($ft, 'checkbox'); ?>><?php esc_html_e('Checkbox is checked', 'memberpress-members-meta-filters'); ?></option>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('Use “Single choice” when the stored value must match one of a fixed set of options.', 'memberpress-members-meta-filters'); ?>
                                        </p>
                                    </div>
                                    <div class="meprmf-field meprmf-field--full meprmf-options-field">
                                        <label for="meprmf-options-<?php echo (int) $i; ?>">
                                            <?php esc_html_e('Choices (single choice only)', 'memberpress-members-meta-filters'); ?>
                                        </label>
                                        <textarea
                                            id="meprmf-options-<?php echo (int) $i; ?>"
                                            name="<?php echo esc_attr(MEPRMF_OPTION_ADDITIONAL); ?>[<?php echo (int) $i; ?>][options_text]"
                                            rows="4"
                                            class="large-text code"
                                            placeholder="<?php esc_attr_e('stored_value|Optional label&#10;other_value|Another label', 'memberpress-members-meta-filters'); ?>"
                                        ><?php echo esc_textarea($otxt); ?></textarea>
                                        <p class="description">
                                            <?php esc_html_e('One option per line. Use value|Label or a single value per line. Required when filter type is “Single choice”.', 'memberpress-members-meta-filters'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                    ?>

                    <div class="meprmf-settings-actions">
                        <?php submit_button(__('Save filters', 'memberpress-members-meta-filters'), 'primary', 'submit', false); ?>
                        <span class="description">
                            <?php
                            printf(
                                /* translators: %d: maximum number of configurable filters */
                                esc_html__('Up to %d additional filters. Empty rows are ignored when saving.', 'memberpress-members-meta-filters'),
                                (int) $max_rows
                            );
                            ?>
                        </span>
                    </div>
                </form>
            </div>

            <div class="meprmf-settings-sidebar">
                <div class="postbox">
                    <h2 class="hndle"><?php esc_html_e('Tips', 'memberpress-members-meta-filters'); ?></h2>
                    <div class="inside">
                        <p><?php esc_html_e('Text contains is best for free-form values (names, notes, URLs).', 'memberpress-members-meta-filters'); ?></p>
                        <p><?php esc_html_e('Single choice fits dropdown-style data where the meta value must equal one stored option.', 'memberpress-members-meta-filters'); ?></p>
                        <p><?php esc_html_e('Checkbox is checked looks for MemberPress-style checked values (on / 1).', 'memberpress-members-meta-filters'); ?></p>
                        <p>
                            <a class="button button-secondary" href="<?php echo esc_url($members_url); ?>">
                                <?php esc_html_e('Open Members list', 'memberpress-members-meta-filters'); ?>
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Build filter field rows from saved additional filters option.
 *
 * @return array<int, array<string, mixed>>
 */
function meprmf_get_additional_filter_fields()
{
    $saved = get_option(MEPRMF_OPTION_ADDITIONAL, []);
    if (! is_array($saved) || empty($saved)) {
        return [];
    }

    $fields = [];
    foreach ($saved as $row) {
        if (empty($row['meta_key']) || empty($row['label'])) {
            continue;
        }

        $meta_key = (string) $row['meta_key'];
        $label    = (string) $row['label'];
        $ftype    = isset($row['filter_type']) ? sanitize_key((string) $row['filter_type']) : 'text';

        $prefix = 'mpf_ext_';
        $param  = $prefix . sanitize_key(str_replace('-', '_', $meta_key));
        if (strlen($param) <= strlen($prefix)) {
            // Suffix was stripped to nothing by sanitize_key(); unusable param.
            continue;
        }

        if ('select' === $ftype) {
            $options = isset($row['options']) && is_array($row['options']) ? $row['options'] : [];
            if (empty($options)) {
                continue;
            }
            $fields[] = [
                'param'    => $param,
                'meta_key' => $meta_key,
                'label'    => $label,
                'type'     => 'select',
                'match'    => 'exact',
                'options'  => $options,
            ];
        } elseif ('checkbox' === $ftype) {
            $fields[] = [
                'param'    => $param,
                'meta_key' => $meta_key,
                'label'    => $label,
                'type'     => 'checkbox',
                'match'    => 'exact',
            ];
        } else {
            $fields[] = [
                'param'    => $param,
                'meta_key' => $meta_key,
                'label'    => $label,
                'type'     => 'text',
                'match'    => 'like',
            ];
        }
    }

    return $fields;
}

/**
 * Map a MemberPress custom field definition to a filter field row, or null to skip.
 *
 * @param object $cf Custom field object from MeprOptions.
 * @return array<string, mixed>|null
 */
function meprmf_map_mepr_custom_field_to_filter($cf)
{
    if (empty($cf->field_key) || empty($cf->field_name)) {
        return null;
    }

    $prefix = 'mpf_';
    $param  = $prefix . sanitize_key(str_replace('-', '_', $cf->field_key));
    // Require at least 2 suffix chars so custom-field params don't collide
    // with built-in single-word address params like `mpf_zip`.
    if (strlen($param) < strlen($prefix) + 2) {
        return null;
    }

    $field_key = (string) $cf->field_key;
    $label     = (string) $cf->field_name;
    $ftype     = isset($cf->field_type) ? (string) $cf->field_type : 'text';

    $option_rows_to_map = static function ($cf_obj) {
        $options = [];
        if (empty($cf_obj->options) || ! is_array($cf_obj->options)) {
            return $options;
        }
        foreach ($cf_obj->options as $option) {
            if (empty($option->option_value)) {
                continue;
            }
            $options[ (string) $option->option_value ] = (string) $option->option_name;
        }
        return $options;
    };

    // Single-choice fields: exact match on meta value.
    if (in_array($ftype, [ 'dropdown', 'radios' ], true)) {
        $options = $option_rows_to_map($cf);
        if (empty($options)) {
            return null;
        }
        return [
            'param'    => $param,
            'meta_key' => $field_key,
            'label'    => $label,
            'type'     => 'select',
            'match'    => 'exact',
            'options'  => $options,
        ];
    }

    // Multi-value stored as serialized array: match if value appears in stored string (LIKE).
    if (in_array($ftype, [ 'multiselect', 'checkboxes' ], true)) {
        $options = $option_rows_to_map($cf);
        if (empty($options)) {
            return null;
        }
        return [
            'param'    => $param,
            'meta_key' => $field_key,
            'label'    => $label,
            'type'     => 'select',
            'match'    => 'contains',
            'options'  => $options,
        ];
    }

    // Single checkbox: MemberPress stores checked as "on" when posted; unchecked removes meta.
    if ('checkbox' === $ftype) {
        return [
            'param'    => $param,
            'meta_key' => $field_key,
            'label'    => $label,
            'type'     => 'checkbox',
            'match'    => 'exact',
        ];
    }

    // Free-text style fields: substring search.
    if (in_array($ftype, [ 'text', 'email', 'url', 'tel', 'date', 'textarea', 'file' ], true)) {
        return [
            'param'    => $param,
            'meta_key' => $field_key,
            'label'    => $label,
            'type'     => 'text',
            'match'    => 'like',
        ];
    }

    return null;
}

/**
 * Built-in filters for the six MemberPress address fields.
 *
 * @param object $opts MeprOptions instance.
 * @return array<int, array<string, mixed>>
 */
function meprmf_get_address_filter_fields($opts)
{
    /**
     * Toggle the built-in address filters.
     * Default: show them when address capture is enabled, hide otherwise.
     *
     * @param bool   $enabled
     * @param object $opts
     */
    $enabled = (bool) apply_filters(
        'meprmf_include_address_filters',
        ! empty($opts->show_address_fields),
        $opts
    );

    if (! $enabled) {
        return [];
    }

    $country_label = __('Country', 'memberpress-members-meta-filters');
    $state_label   = __('State / Province', 'memberpress-members-meta-filters');
    $city_label    = __('City', 'memberpress-members-meta-filters');
    $zip_label     = __('Zip / Postal code', 'memberpress-members-meta-filters');
    $addr1_label   = __('Address line 1', 'memberpress-members-meta-filters');
    $addr2_label   = __('Address line 2', 'memberpress-members-meta-filters');

    // Prefer MemberPress' own labels if they've been (re)translated.
    if (! empty($opts->address_fields) && is_array($opts->address_fields)) {
        foreach ($opts->address_fields as $af) {
            if (empty($af->field_key) || empty($af->field_name)) {
                continue;
            }
            switch ($af->field_key) {
                case 'mepr-address-one':
                    $addr1_label = (string) $af->field_name;
                    break;
                case 'mepr-address-two':
                    $addr2_label = (string) $af->field_name;
                    break;
                case 'mepr-address-city':
                    $city_label = (string) $af->field_name;
                    break;
                case 'mepr-address-country':
                    $country_label = (string) $af->field_name;
                    break;
                case 'mepr-address-state':
                    $state_label = (string) $af->field_name;
                    break;
                case 'mepr-address-zip':
                    $zip_label = (string) $af->field_name;
                    break;
            }
        }
    }

    return [
        [
            'param'    => 'mpf_country',
            'meta_key' => 'mepr-address-country',
            'label'    => $country_label,
            'type'     => 'country',
            'match'    => 'exact',
        ],
        [
            'param'    => 'mpf_state',
            'meta_key' => 'mepr-address-state',
            'label'    => $state_label,
            'type'     => 'text',
            'match'    => 'like',
        ],
        [
            'param'    => 'mpf_city',
            'meta_key' => 'mepr-address-city',
            'label'    => $city_label,
            'type'     => 'text',
            'match'    => 'like',
        ],
        [
            'param'    => 'mpf_zip',
            'meta_key' => 'mepr-address-zip',
            'label'    => $zip_label,
            'type'     => 'text',
            'match'    => 'like',
        ],
        [
            'param'    => 'mpf_address_one',
            'meta_key' => 'mepr-address-one',
            'label'    => $addr1_label,
            'type'     => 'text',
            'match'    => 'like',
        ],
        [
            'param'    => 'mpf_address_two',
            'meta_key' => 'mepr-address-two',
            'label'    => $addr2_label,
            'type'     => 'text',
            'match'    => 'like',
        ],
    ];
}

/**
 * Field definitions: filterable. Each item:
 * - param: (string) HTML id + $_GET key; use only [a-z0-9_].
 * - meta_key: (string) usermeta key.
 * - label: (string) visible label.
 * - type: 'country' | 'text' | 'select' | 'checkbox'.
 * - options: (array) optional value => label map for type `select`.
 * - match: (string) optional: 'exact' | 'like' | 'contains' (default: exact for country/select when omitted, like for text).
 *
 * @return array<int, array<string, mixed>>
 */
function meprmf_get_filter_fields()
{
    static $cached = null;
    if (null !== $cached) {
        return $cached;
    }

    $opts = MeprOptions::fetch();

    $fields = meprmf_get_address_filter_fields($opts);

    if (! empty($opts->custom_fields) && is_array($opts->custom_fields)) {
        foreach ($opts->custom_fields as $cf) {
            $mapped = meprmf_map_mepr_custom_field_to_filter($cf);
            if (null !== $mapped) {
                $fields[] = $mapped;
            }
        }
    }

    $fields = array_merge($fields, meprmf_get_additional_filter_fields());

    /**
     * Add/remove/reorder filters or append manual meta keys.
     *
     * @param array $fields Field definitions.
     */
    $fields = apply_filters('mepr_members_meta_filters_fields', $fields);

    $cached = $fields;
    return $cached;
}

/**
 * Resolve SQL match mode for a field.
 *
 * @param array<string, mixed> $field Field definition.
 * @return string 'exact'|'like'|'contains'
 */
function meprmf_get_field_match_mode(array $field)
{
    if (! empty($field['match']) && is_string($field['match'])) {
        $m = $field['match'];
        if (in_array($m, [ 'exact', 'like', 'contains' ], true)) {
            return $m;
        }
    }

    $type = isset($field['type']) ? (string) $field['type'] : 'text';
    if ('country' === $type || 'select' === $type) {
        return 'exact';
    }

    return 'like';
}

/**
 * Sanitize a HTML id / $_GET key to [a-z0-9_]. Null-safe.
 *
 * @param mixed $param Raw param.
 * @return string
 */
function meprmf_sanitize_param($param)
{
    if (! is_string($param) || '' === $param) {
        return '';
    }
    $out = preg_replace('/[^a-z0-9_]/', '', $param);
    return is_string($out) ? $out : '';
}

/**
 * Read a scalar value from $_GET for the given param.
 * Returns '' if missing or if the value is not scalar (e.g. array).
 *
 * @param string $param Param name.
 * @return string
 */
function meprmf_get_request_value($param)
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (! isset($_GET[ $param ])) {
        return '';
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $value = wp_unslash($_GET[ $param ]);
    if (! is_scalar($value)) {
        return '';
    }
    return sanitize_text_field((string) $value);
}

/**
 * Output one filter control (select or search input).
 *
 * @param array<string, mixed> $field Field definition.
 * @param bool                 $compact When true, show visible label and wrap in a grid cell.
 * @return void
 */
function meprmf_render_single_filter_control(array $field, $compact)
{
    $param = meprmf_sanitize_param(isset($field['param']) ? $field['param'] : '');
    if ('' === $param) {
        return;
    }

    $label   = isset($field['label']) ? (string) $field['label'] : '';
    $current = meprmf_get_request_value($param);

    if ($compact) {
        echo '<div class="meprmf-meta-filters__cell">';
        echo '<label class="meprmf-meta-filters__cell-label" for="' . esc_attr($param) . '">' . esc_html($label) . '</label>';
    } else {
        echo '<label class="screen-reader-text" for="' . esc_attr($param) . '">' . esc_html($label) . '</label>';
    }

    if ('country' === $field['type']) {
        $countries = MeprUtils::countries(true);
        echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '" name="' . esc_attr($param) . '">';
        echo '<option value="">' . esc_html__('— Country —', 'memberpress-members-meta-filters') . '</option>';
        foreach ($countries as $code => $name) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($code),
                selected($current, (string) $code, false),
                esc_html($name)
            );
        }
        echo '</select>';
    } elseif ('checkbox' === $field['type']) {
        echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '" name="' . esc_attr($param) . '">';
        echo '<option value="">' . esc_html(sprintf('— %s —', $label)) . '</option>';
        printf(
            '<option value="1" %s>%s</option>',
            selected($current, '1', false),
            esc_html__('Checked', 'memberpress-members-meta-filters')
        );
        echo '</select>';
    } elseif ('select' === $field['type'] && ! empty($field['options']) && is_array($field['options'])) {
        echo '<select class="mepr_filter_field" id="' . esc_attr($param) . '" name="' . esc_attr($param) . '">';
        echo '<option value="">' . esc_html(sprintf('— %s —', $label)) . '</option>';
        foreach ($field['options'] as $value => $opt_label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string) $value),
                selected($current, (string) $value, false),
                esc_html((string) $opt_label)
            );
        }
        echo '</select>';
    } else {
        $placeholder = $compact
            ? __('Contains…', 'memberpress-members-meta-filters')
            : $label;
        printf(
            '<input type="search" class="mepr_filter_field regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />',
            esc_attr($param),
            esc_attr($current),
            esc_attr($placeholder)
        );
    }

    if ($compact) {
        echo '</div>';
    }
}

/**
 * Renders extra filter controls (reuses MemberPress `.mepr_filter_field` + Go button behavior).
 *
 * @param string $search_term Unused.
 * @param int    $perpage     Unused.
 * @return void
 */
function meprmf_render_meta_filters($search_term, $perpage)
{
    if (! meprmf_is_members_admin_list_request() || ! meprmf_current_user_can_filter()) {
        return;
    }

    $fields = meprmf_get_filter_fields();
    if (empty($fields)) {
        return;
    }

    $valid = [];
    $seen  = [];
    foreach ($fields as $field) {
        if (empty($field['param']) || empty($field['meta_key']) || empty($field['label']) || empty($field['type'])) {
            continue;
        }
        $param = meprmf_sanitize_param($field['param']);
        if ('' === $param) {
            continue;
        }
        if (isset($seen[ $param ])) {
            continue;
        }
        if ('select' === $field['type'] && ( empty($field['options']) || ! is_array($field['options']) )) {
            continue;
        }
        $seen[ $param ] = true;
        $valid[]        = $field;
    }

    if (empty($valid)) {
        return;
    }

    $count     = count($valid);
    $threshold = (int) apply_filters('meprmf_compact_filters_threshold', 6);
    $compact   = $count >= $threshold;

    if ($compact) {
        echo '<div class="mepr-filter-by meprmf-meta-filters meprmf-meta-filters--compact">';
        echo '<details class="meprmf-meta-filters__details" open>';
        printf(
            '<summary class="meprmf-meta-filters__summary"><span class="meprmf-meta-filters__summary-text">%s</span> <span class="meprmf-meta-filters__count">(%d)</span></summary>',
            esc_html__('Member filters', 'memberpress-members-meta-filters'),
            (int) $count
        );
        echo '<div class="meprmf-meta-filters__grid">';
        foreach ($valid as $field) {
            meprmf_render_single_filter_control($field, true);
        }
        echo '</div></details></div>';
        return;
    }

    echo '<span class="mepr-filter-by meprmf-meta-filters">';

    foreach ($valid as $field) {
        meprmf_render_single_filter_control($field, false);
    }

    echo '</span>';
}

/**
 * Applies EXISTS subqueries on wp_usermeta for active filters.
 *
 * @param array<int, string> $args WHERE fragments for MeprDb::list_table.
 * @return array<int, string>
 */
function meprmf_filter_members_list_args($args)
{
    if (! meprmf_is_members_admin_list_request() || ! meprmf_current_user_can_filter()) {
        return $args;
    }

    $fields = meprmf_get_filter_fields();
    if (empty($fields)) {
        return $args;
    }

    global $wpdb;

    $seen = [];
    foreach ($fields as $field) {
        if (empty($field['param']) || empty($field['meta_key']) || empty($field['type'])) {
            continue;
        }

        $param = meprmf_sanitize_param($field['param']);
        if ('' === $param || isset($seen[ $param ])) {
            continue;
        }

        $raw = meprmf_get_request_value($param);
        if ('' === $raw) {
            continue;
        }

        $seen[ $param ] = true;

        $meta   = (string) $field['meta_key'];
        $alias  = 'mpf_um_' . $param;
        $ftype  = (string) $field['type'];
        $match  = meprmf_get_field_match_mode($field);

        if ('checkbox' === $ftype) {
            if ('1' !== $raw) {
                continue;
            }
            // MemberPress stores HTML checkbox as the string "on" when checked.
            $args[] = $wpdb->prepare(
                "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = u.ID AND {$alias}.meta_key = %s AND {$alias}.meta_value IN ('on', '1', 'true') )",
                $meta
            );
            continue;
        }

        if ('exact' === $match || 'country' === $ftype) {
            $args[] = $wpdb->prepare(
                "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = u.ID AND {$alias}.meta_key = %s AND {$alias}.meta_value = %s )",
                $meta,
                $raw
            );
            continue;
        }

        if ('contains' === $match) {
            // Match either a scalar equal to $raw, or an exact serialized-array
            // element equal to $raw (so value "a" doesn't match "ab").
            $serialized_needle = 's:' . strlen($raw) . ':"' . $wpdb->esc_like($raw) . '";';
            $args[] = $wpdb->prepare(
                "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = u.ID AND {$alias}.meta_key = %s AND ( {$alias}.meta_value = %s OR {$alias}.meta_value LIKE %s ) )",
                $meta,
                $raw,
                '%' . $serialized_needle . '%'
            );
            continue;
        }

        $like = '%' . $wpdb->esc_like($raw) . '%';
        $args[] = $wpdb->prepare(
            "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = u.ID AND {$alias}.meta_key = %s AND {$alias}.meta_value LIKE %s )",
            $meta,
            $like
        );
    }

    return $args;
}
