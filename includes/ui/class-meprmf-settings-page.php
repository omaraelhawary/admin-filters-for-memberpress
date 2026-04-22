<?php
/**
 * MemberPress → Member list filters settings page.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Settings registration and render.
 */
class Meprmf_Settings_Page
{

    /**
     * Register option and settings section.
     *
     * @return void
     */
    public static function register()
    {
        register_setting(
            'meprmf_settings_group',
            MEPRMF_OPTION_ADDITIONAL,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize_additional_filters_option' ],
                'default'           => [],
            ]
        );
    }

    /**
     * Sanitize saved additional filter rows.
     *
     * @param mixed $value Raw option value.
     * @return array<int, array<string, mixed>>
     */
    public static function sanitize_additional_filters_option($value)
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
    public static function render()
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
}
