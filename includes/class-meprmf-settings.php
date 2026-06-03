<?php
/**
 * Plugin settings (stored in wp_options).
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

    /** @var string */
    const OPTION_DATE_CUSTOM_FIELDS_USE_RANGE = 'meprmf_date_custom_fields_use_range';

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
     * When no snippet/filter overrides, use the stored option (default: on).
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
     * @return bool
     */
    public static function is_date_custom_fields_use_range_enabled()
    {
        if (defined('MEPRMF_DATE_CUSTOM_FIELDS_USE_RANGE')) {
            return (bool) MEPRMF_DATE_CUSTOM_FIELDS_USE_RANGE;
        }

        return '1' === get_option(self::OPTION_DATE_CUSTOM_FIELDS_USE_RANGE, '1');
    }

    /**
     * Persist date-range preference.
     *
     * @param bool $enabled Enabled state.
     * @return void
     */
    public static function set_date_custom_fields_use_range_enabled($enabled)
    {
        update_option(self::OPTION_DATE_CUSTOM_FIELDS_USE_RANGE, $enabled ? '1' : '0', false);
        Meprmf_Members_Provider::clear_filter_fields_cache();
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $enabled = ! empty($_POST['enabled']);
        self::set_date_custom_fields_use_range_enabled($enabled);

        wp_send_json_success([ 'enabled' => $enabled ]);
    }
}
