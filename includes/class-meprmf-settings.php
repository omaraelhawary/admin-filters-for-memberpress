<?php
/**
 * Plugin settings (site-wide option, per-admin user meta, constant override).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Settings helpers.
 */
class Meprmf_Settings
{

    /** @var string Per-user preference (1 = from/to range, 0 = single exact date). */
    const USER_META_DATE_CUSTOM_FIELDS_USE_RANGE = 'meprmf_date_custom_fields_use_range';

    /** @var string Site-wide settings option (one array, autoloaded). */
    const OPTION_KEY = 'meprmf_settings';

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function init()
    {
        add_filter('meprmf_custom_date_fields_use_range', [ __CLASS__, 'apply_date_range_option' ], 1, 2);
        add_action('wp_ajax_meprmf_save_date_range_pref', [ __CLASS__, 'ajax_save_date_range_pref' ]);
    }

    /**
     * When no snippet/filter overrides, use the stored per-user preference (default: on).
     *
     * @param bool        $use_range Current value.
     * @param object|null $cf        MemberPress custom field object or stub.
     * @return bool
     */
    public static function apply_date_range_option($use_range, $cf)
    {
        if ($use_range) {
            return true;
        }

        return self::is_date_custom_fields_use_range_enabled();
    }

    /**
     * Whether date custom fields use from / to pickers instead of one exact date.
     *
     * @param int|null $user_id WordPress user id; defaults to current user.
     * @return bool
     */
    public static function is_date_custom_fields_use_range_enabled($user_id = null)
    {
        if (defined('MEPRMF_DATE_CUSTOM_FIELDS_USE_RANGE')) {
            return (bool) MEPRMF_DATE_CUSTOM_FIELDS_USE_RANGE;
        }

        if (null === $user_id) {
            $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        }

        if ($user_id <= 0) {
            return (bool) self::get_setting('date_range_default');
        }

        if (! function_exists('get_user_meta')) {
            return (bool) self::get_setting('date_range_default');
        }

        $stored = get_user_meta($user_id, self::USER_META_DATE_CUSTOM_FIELDS_USE_RANGE, true);
        if (false === $stored || '' === $stored) {
            return (bool) self::get_setting('date_range_default');
        }

        return '1' === (string) $stored;
    }

    /**
     * Persist date-range preference for one admin user.
     *
     * @param bool     $enabled Enabled state.
     * @param int|null $user_id WordPress user id; defaults to current user.
     * @return void
     */
    public static function set_date_custom_fields_use_range_enabled($enabled, $user_id = null)
    {
        if (null === $user_id) {
            $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        }

        if ($user_id <= 0 || ! function_exists('update_user_meta')) {
            return;
        }

        update_user_meta($user_id, self::USER_META_DATE_CUSTOM_FIELDS_USE_RANGE, $enabled ? '1' : '0');
        Meprmf_Members_Provider::clear_filter_fields_cache();
    }

    /**
     * Default value for every key in the site-wide settings option.
     *
     * @return array<string, mixed>
     */
    public static function defaults()
    {
        return [
            'enabled_screens'          => [
                Meprmf_Screen::PAGE_MEMBERS,
                Meprmf_Screen::PAGE_SUBSCRIPTIONS,
                Meprmf_Screen::PAGE_LIFETIMES,
                Meprmf_Screen::PAGE_TRANSACTIONS,
            ],
            'floating_panel_enabled'   => true,
            'date_range_default'       => true,
            'shared_preset_capability' => 'manage_options',
        ];
    }

    /**
     * Stored settings merged over the defaults, so a partial saved option keeps newer keys.
     *
     * @return array<string, mixed>
     */
    public static function get_settings()
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION_KEY, []) : [];
        if (! is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    /**
     * One settings value.
     *
     * @param string $key Settings key.
     * @return mixed Null when the key is unknown.
     */
    public static function get_setting($key)
    {
        $settings = self::get_settings();

        return array_key_exists($key, $settings) ? $settings[ $key ] : null;
    }

    /**
     * Whether the Settings page has filters turned on for one list screen.
     *
     * @param string $page Admin page slug, e.g. memberpress-members.
     * @return bool
     */
    public static function is_screen_enabled($page)
    {
        $enabled = self::get_setting('enabled_screens');

        return is_array($enabled) && in_array((string) $page, $enabled, true);
    }

    /**
     * Whether the floating filter panel is turned on site-wide.
     *
     * @return bool
     */
    public static function is_floating_panel_enabled()
    {
        return (bool) self::get_setting('floating_panel_enabled');
    }

    /**
     * Capability required to create a shared saved view.
     *
     * @return string
     */
    public static function shared_preset_capability()
    {
        $cap = (string) self::get_setting('shared_preset_capability');

        return '' === $cap ? 'manage_options' : $cap;
    }

    /**
     * Whether this admin may create a shared saved view.
     *
     * @return bool
     */
    public static function current_user_can_create_shared_preset()
    {
        $can = function_exists('current_user_can') ? (bool) current_user_can(self::shared_preset_capability()) : false;

        /** This filter is documented in includes/class-meprmf-presets.php */
        return (bool) apply_filters('meprmf_can_manage_others_views', $can);
    }

    /**
     * Save preference from the floating panel customize UI.
     *
     * @return void
     */
    public static function ajax_save_date_range_pref()
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            wp_send_json_error([ 'message' => 'forbidden' ], 403);
        }

        check_ajax_referer('meprmf_date_range_pref', 'nonce');

        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id <= 0) {
            wp_send_json_error([ 'message' => 'not_logged_in' ], 401);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $enabled = ! empty($_POST['enabled']);
        self::set_date_custom_fields_use_range_enabled($enabled, $user_id);

        wp_send_json_success([ 'enabled' => $enabled ]);
    }
}
