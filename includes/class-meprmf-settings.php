<?php
/**
 * Plugin settings (per-admin user meta; site-wide constant override).
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
            return true;
        }

        if (! function_exists('get_user_meta')) {
            return true;
        }

        $stored = get_user_meta($user_id, self::USER_META_DATE_CUSTOM_FIELDS_USE_RANGE, true);
        if (false === $stored || '' === $stored) {
            return true;
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
