<?php
/**
 * Settings screen under MemberPress for the site-wide plugin options.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Settings page (Settings API) for Meprmf_Settings::OPTION_KEY.
 */
class Meprmf_Settings_Page
{

    /** @var string Admin ?page= value for this screen. */
    const MENU_SLUG = 'meprmf-settings';

    /** @var string Settings API option group. */
    const OPTION_GROUP = 'meprmf_settings_group';

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function init()
    {
        // Priority 20: MemberPress registers its own top-level menu first.
        add_action('admin_menu', [ __CLASS__, 'register_menu' ], 20);
        add_action('admin_init', [ __CLASS__, 'register_settings' ]);
    }

    /**
     * Add the submenu under MemberPress.
     *
     * The capability is manage_options because wp-admin/options.php requires it for the save
     * request; a lower one would render a page whose Save button fails.
     *
     * @return void
     */
    public static function register_menu()
    {
        add_submenu_page(
            'memberpress',
            __('Admin Filters', 'admin-filters-for-memberpress'),
            __('Admin Filters', 'admin-filters-for-memberpress'),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    /**
     * Register the option, its section, and its fields.
     *
     * @return void
     */
    public static function register_settings()
    {
        register_setting(
            self::OPTION_GROUP,
            Meprmf_Settings::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
                'default'           => Meprmf_Settings::defaults(),
            ]
        );

        add_settings_section(
            'meprmf_settings_main',
            '',
            [ __CLASS__, 'render_section_intro' ],
            self::MENU_SLUG
        );

        $fields = [
            'enabled_screens'          => __('Screens with filters', 'admin-filters-for-memberpress'),
            'floating_panel_enabled'   => __('Filter panel', 'admin-filters-for-memberpress'),
            'date_range_default'       => __('Custom date fields', 'admin-filters-for-memberpress'),
            'shared_preset_capability' => __('Who can create shared views', 'admin-filters-for-memberpress'),
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                'meprmf_field_' . $key,
                $label,
                [ __CLASS__, 'render_' . $key . '_field' ],
                self::MENU_SLUG,
                'meprmf_settings_main'
            );
        }
    }

    /**
     * Capability choices for shared saved views: keys are capabilities, values are labels.
     *
     * @return array<string, string>
     */
    public static function capability_choices()
    {
        $choices = [
            'manage_options' => __('Administrators (manage_options)', 'admin-filters-for-memberpress'),
        ];

        if (class_exists('MeprUtils') && method_exists('MeprUtils', 'get_mepr_admin_capability')) {
            $mepr_cap = (string) MeprUtils::get_mepr_admin_capability();
            if ('' !== $mepr_cap && ! isset($choices[ $mepr_cap ])) {
                /* translators: %s: capability name. */
                $choices[ $mepr_cap ] = sprintf(__('Anyone who can use the filters (%s)', 'admin-filters-for-memberpress'), $mepr_cap);
            }
        }

        return $choices;
    }

    /**
     * Sanitize the posted settings.
     *
     * Every key is written on every save. An unchecked checkbox is absent from the POST array,
     * so merging only the submitted keys would let the accessor defaults switch it back on.
     *
     * @param mixed $input Raw posted value.
     * @return array<string, mixed>
     */
    public static function sanitize_settings($input)
    {
        $input    = is_array($input) ? $input : [];
        $defaults = Meprmf_Settings::defaults();

        $allowed_pages = [];
        foreach (Meprmf_Screen::supported_page_contexts() as $ctx) {
            $allowed_pages[] = $ctx->get_page();
        }

        $screens   = [];
        $submitted = isset($input['enabled_screens']) && is_array($input['enabled_screens']) ? $input['enabled_screens'] : [];
        foreach ($submitted as $page) {
            $page = sanitize_text_field((string) $page);
            if (in_array($page, $allowed_pages, true) && ! in_array($page, $screens, true)) {
                $screens[] = $page;
            }
        }

        $capability = isset($input['shared_preset_capability']) ? sanitize_text_field((string) $input['shared_preset_capability']) : '';
        if (! array_key_exists($capability, self::capability_choices())) {
            $capability = (string) $defaults['shared_preset_capability'];
        }

        return [
            'enabled_screens'          => $screens,
            'floating_panel_enabled'   => ! empty($input['floating_panel_enabled']),
            'date_range_default'       => ! empty($input['date_range_default']),
            'shared_preset_capability' => $capability,
        ];
    }

    /**
     * Section intro copy.
     *
     * @return void
     */
    public static function render_section_intro()
    {
        echo '<p>' . esc_html__('These options set the site-wide defaults. A code filter or constant in a snippet or theme overrides whatever is set here.', 'admin-filters-for-memberpress') . '</p>';
    }

    /**
     * Checkbox per list screen.
     *
     * @return void
     */
    public static function render_enabled_screens_field()
    {
        $enabled = Meprmf_Settings::get_setting('enabled_screens');
        $enabled = is_array($enabled) ? $enabled : [];

        $labels = [
            Meprmf_Screen::PAGE_MEMBERS       => __('Members', 'admin-filters-for-memberpress'),
            Meprmf_Screen::PAGE_TRANSACTIONS  => __('Transactions', 'admin-filters-for-memberpress'),
            Meprmf_Screen::PAGE_SUBSCRIPTIONS => __('Subscriptions', 'admin-filters-for-memberpress'),
            Meprmf_Screen::PAGE_LIFETIMES     => __('Lifetimes', 'admin-filters-for-memberpress'),
        ];

        echo '<fieldset>';
        foreach ($labels as $page => $label) {
            printf(
                '<label style="display:block"><input type="checkbox" name="%1$s[enabled_screens][]" value="%2$s"%3$s> %4$s</label>',
                esc_attr(Meprmf_Settings::OPTION_KEY),
                esc_attr($page),
                in_array($page, $enabled, true) ? ' checked="checked"' : '',
                esc_html($label)
            );
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Filters, columns, and saved views load only on the screens you check here.', 'admin-filters-for-memberpress') . '</p>';
    }

    /**
     * Floating panel on / off.
     *
     * @return void
     */
    public static function render_floating_panel_enabled_field()
    {
        self::render_checkbox(
            'floating_panel_enabled',
            __('Show the filter panel', 'admin-filters-for-memberpress'),
            __('Turn this off to remove the filter UI on every list screen. It does not bring back the inline toolbar from before 2.1.0. MemberPress\'s own search and Filter rows are unaffected.', 'admin-filters-for-memberpress')
        );
    }

    /**
     * Date custom fields default to a range.
     *
     * @return void
     */
    public static function render_date_range_default_field()
    {
        self::render_checkbox(
            'date_range_default',
            __('Default custom date fields to a from / to range', 'admin-filters-for-memberpress'),
            __('Each admin can still switch a date field to one exact date from the filter panel.', 'admin-filters-for-memberpress')
        );
    }

    /**
     * Capability dropdown for creating shared saved views.
     *
     * @return void
     */
    public static function render_shared_preset_capability_field()
    {
        $current = Meprmf_Settings::shared_preset_capability();

        printf('<select name="%1$s[shared_preset_capability]">', esc_attr(Meprmf_Settings::OPTION_KEY));
        foreach (self::capability_choices() as $capability => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($capability),
                selected($current, $capability, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('A shared view is visible to every admin who can use the filters. Private views are not affected.', 'admin-filters-for-memberpress') . '</p>';
    }

    /**
     * One boolean checkbox with a description.
     *
     * @param string $key         Settings key.
     * @param string $label       Checkbox label.
     * @param string $description Text under the checkbox.
     * @return void
     */
    private static function render_checkbox($key, $label, $description)
    {
        printf(
            '<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label>',
            esc_attr(Meprmf_Settings::OPTION_KEY),
            esc_attr($key),
            Meprmf_Settings::get_setting($key) ? ' checked="checked"' : '',
            esc_html($label)
        );
        echo '<p class="description">' . esc_html($description) . '</p>';
    }

    /**
     * Render the settings screen.
     *
     * @return void
     */
    public static function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Admin Filters', 'admin-filters-for-memberpress') . '</h1>';
        // This page's parent is memberpress, not options-general.php, so wp-admin does not
        // print the save notice for us.
        settings_errors();
        echo '<form action="options.php" method="post">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::MENU_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
