<?php
/**
 * PHPUnit bootstrap for unit tests without a full WordPress test install.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (! function_exists('sanitize_key')) {
    /**
     * @param string $key Key.
     * @return string
     */
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (! function_exists('sanitize_text_field')) {
    /**
     * @param string $str String.
     * @return string
     */
    function sanitize_text_field($str)
    {
        return trim(wp_strip_all_tags((string) $str));
    }
}

if (! function_exists('wp_strip_all_tags')) {
    /**
     * @param string $str String.
     * @return string
     */
    function wp_strip_all_tags($str)
    {
        return strip_tags((string) $str);
    }
}

if (! isset($GLOBALS['meprmf_test_filters'])) {
    $GLOBALS['meprmf_test_filters'] = [];
}

if (! function_exists('add_filter')) {
    /**
     * @param string   $hook_name Hook name.
     * @param callable $callback  Callback.
     * @return true
     */
    function add_filter($hook_name, $callback)
    {
        $GLOBALS['meprmf_test_filters'][ $hook_name ][] = $callback;
        return true;
    }
}

if (! function_exists('apply_filters')) {
    /**
     * @param string $hook_name Hook name.
     * @param mixed  $value     Default value.
     * @param mixed  ...$args   Extra args.
     * @return mixed
     */
    function apply_filters($hook_name, $value, ...$args)
    {
        foreach ($GLOBALS['meprmf_test_filters'][ $hook_name ] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }
}

if (! function_exists('add_action')) {
    /**
     * @param string   $hook_name Hook name.
     * @param callable $callback  Callback.
     * @return true
     */
    function add_action($hook_name, $callback)
    {
        $GLOBALS['meprmf_test_actions'][ $hook_name ][] = $callback;
        return true;
    }
}

if (! function_exists('register_setting')) {
    /**
     * @param string               $group   Option group.
     * @param string               $option  Option name.
     * @param array<string, mixed> $args    Args.
     * @return true
     */
    function register_setting($group, $option, $args = [])
    {
        $GLOBALS['meprmf_test_registered_settings'][ $option ] = [ 'group' => $group, 'args' => $args ];
        return true;
    }
}

if (! function_exists('add_settings_section')) {
    /**
     * @param string   $id       Section id.
     * @param string   $title    Title.
     * @param callable $callback Render callback.
     * @param string   $page     Page slug.
     * @return true
     */
    function add_settings_section($id, $title, $callback, $page)
    {
        $GLOBALS['meprmf_test_settings_sections'][ $page ][] = $id;
        return true;
    }
}

if (! function_exists('add_settings_field')) {
    /**
     * @param string   $id       Field id.
     * @param string   $title    Title.
     * @param callable $callback Render callback.
     * @param string   $page     Page slug.
     * @param string   $section  Section id.
     * @return true
     */
    function add_settings_field($id, $title, $callback, $page, $section = 'default')
    {
        $GLOBALS['meprmf_test_settings_fields'][ $page ][] = $id;
        return true;
    }
}

if (! function_exists('add_submenu_page')) {
    /**
     * @param string   $parent_slug Parent menu slug.
     * @param string   $page_title  Page title.
     * @param string   $menu_title  Menu title.
     * @param string   $capability  Capability.
     * @param string   $menu_slug   Menu slug.
     * @param callable $callback    Render callback.
     * @return string
     */
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = null)
    {
        $GLOBALS['meprmf_test_submenus'][] = [ 'parent' => $parent_slug, 'cap' => $capability, 'slug' => $menu_slug ];
        return (string) $menu_slug;
    }
}

if (! function_exists('__')) {
    /**
     * @param string $text Text.
     * @return string
     */
    function __($text)
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    /**
     * @param string $text Text.
     * @return string
     */
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (! function_exists('esc_html__')) {
    /**
     * @param string $text Text.
     * @return string
     */
    function esc_html__($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (! function_exists('esc_url')) {
    /**
     * @param string $url URL.
     * @return string
     */
    function esc_url($url)
    {
        return htmlspecialchars((string) $url, ENT_QUOTES);
    }
}

if (! function_exists('remove_query_arg')) {
    /**
     * @param array<int, string>|string $keys Query args to drop.
     * @return string
     */
    function remove_query_arg($keys)
    {
        return 'admin.php?page=memberpress-members';
    }
}

if (! function_exists('is_admin')) {
    /**
     * @return bool
     */
    function is_admin()
    {
        return true;
    }
}

if (! function_exists('wp_unslash')) {
    /**
     * @param mixed $value Value.
     * @return mixed
     */
    function wp_unslash($value)
    {
        return is_array($value) ? $value : stripslashes((string) $value);
    }
}

if (! isset($GLOBALS['meprmf_test_options'])) {
    $GLOBALS['meprmf_test_options'] = [];
}

if (! function_exists('get_option')) {
    /**
     * @param string $option  Option name.
     * @param mixed  $default Default.
     * @return mixed
     */
    function get_option($option, $default = false)
    {
        if ('date_format' === $option) {
            return 'F j, Y';
        }
        if (array_key_exists($option, $GLOBALS['meprmf_test_options'])) {
            return $GLOBALS['meprmf_test_options'][ $option ];
        }
        return $default;
    }
}

if (! function_exists('update_option')) {
    /**
     * @param string $option Option name.
     * @param mixed  $value  Value.
     * @return true
     */
    function update_option($option, $value)
    {
        $GLOBALS['meprmf_test_options'][ $option ] = $value;
        return true;
    }
}

if (! function_exists('wp_generate_password')) {
    /**
     * @param int  $length              Length.
     * @param bool $special_chars       Special chars.
     * @param bool $extra_special_chars Extra special.
     * @return string
     */
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
    {
        unset($special_chars, $extra_special_chars);
        $GLOBALS['meprmf_preset_id_counter'] = (int) ( $GLOBALS['meprmf_preset_id_counter'] ?? 0 ) + 1;
        return 'test' . str_pad((string) $GLOBALS['meprmf_preset_id_counter'], max(1, $length - 4), '0', STR_PAD_LEFT);
    }
}

if (! function_exists('wp_date')) {
    /**
     * @param string $format    Format.
     * @param int    $timestamp Timestamp.
     * @return string
     */
    function wp_date($format, $timestamp)
    {
        return gmdate($format, $timestamp);
    }
}

if (! function_exists('mysql2date')) {
    /**
     * @param string $format    Format.
     * @param string $date      MySQL date string.
     * @param bool   $translate Translate month/day names.
     * @return string|false
     */
    function mysql2date($format, $date, $translate = true)
    {
        unset($translate);
        $timestamp = strtotime((string) $date . ' UTC');
        if (false === $timestamp) {
            return false;
        }
        return gmdate((string) $format, $timestamp);
    }
}

if (! function_exists('wp_timezone')) {
    /**
     * @return DateTimeZone
     */
    function wp_timezone()
    {
        return new DateTimeZone('UTC');
    }
}

if (! function_exists('esc_sql')) {
    /**
     * @param string $data Data.
     * @return string
     */
    function esc_sql($data)
    {
        return addslashes((string) $data);
    }
}

if (! isset($GLOBALS['meprmf_test_user_meta'])) {
    $GLOBALS['meprmf_test_user_meta'] = [];
}

if (! isset($GLOBALS['meprmf_test_current_user_id'])) {
    $GLOBALS['meprmf_test_current_user_id'] = 0;
}

if (! function_exists('get_current_user_id')) {
    /**
     * @return int
     */
    function get_current_user_id()
    {
        return (int) $GLOBALS['meprmf_test_current_user_id'];
    }
}

if (! function_exists('get_user_meta')) {
    /**
     * A key holding an array stands for several meta rows (what add_user_meta() writes).
     *
     * @param int    $user_id User id.
     * @param string $key     Meta key.
     * @param bool   $single  Single value.
     * @return mixed
     */
    function get_user_meta($user_id, $key, $single = false)
    {
        $user_id = (int) $user_id;
        $bucket  = $GLOBALS['meprmf_test_user_meta'][ $user_id ] ?? [];
        if (! isset($bucket[ $key ])) {
            return $single ? '' : [];
        }
        $val = $bucket[ $key ];
        if ($single) {
            return is_array($val) ? ( $val[0] ?? '' ) : $val;
        }

        return is_array($val) ? array_values($val) : [ $val ];
    }
}

if (! function_exists('update_user_meta')) {
    /**
     * @param int    $user_id User id.
     * @param string $key     Meta key.
     * @param mixed  $value   Value.
     * @return true
     */
    function update_user_meta($user_id, $key, $value)
    {
        $user_id = (int) $user_id;
        if (! isset($GLOBALS['meprmf_test_user_meta'][ $user_id ])) {
            $GLOBALS['meprmf_test_user_meta'][ $user_id ] = [];
        }
        $GLOBALS['meprmf_test_user_meta'][ $user_id ][ $key ] = $value;
        return true;
    }
}

if (! function_exists('add_user_meta')) {
    /**
     * Appends a row rather than replacing the key, in insertion order.
     *
     * @param int    $user_id User id.
     * @param string $key     Meta key.
     * @param mixed  $value   Value.
     * @param bool   $unique  Refuse when the key already has a row.
     * @return bool
     */
    function add_user_meta($user_id, $key, $value, $unique = false)
    {
        $user_id = (int) $user_id;
        $bucket  =& $GLOBALS['meprmf_test_user_meta'][ $user_id ];
        if (! is_array($bucket)) {
            $bucket = [];
        }
        if (! isset($bucket[ $key ])) {
            $bucket[ $key ] = [ $value ];
            return true;
        }
        if ($unique) {
            return false;
        }
        $bucket[ $key ] = is_array($bucket[ $key ]) ? $bucket[ $key ] : [ $bucket[ $key ] ];
        $bucket[ $key ][] = $value;

        return true;
    }
}

if (! function_exists('get_current_screen')) {
    /**
     * @return object|null
     */
    function get_current_screen()
    {
        return $GLOBALS['meprmf_test_current_screen'] ?? null;
    }
}

if (! function_exists('meprmf_test_meta_without_value')) {
    /**
     * One meta key's remaining rows once every row equal to $value is dropped.
     *
     * @param mixed $stored Stored value (scalar for one row, array for several).
     * @param mixed $value  Row value to drop.
     * @return array<int, mixed>
     */
    function meprmf_test_meta_without_value($stored, $value)
    {
        $rows = is_array($stored) ? array_values($stored) : [ $stored ];

        return array_values(
            array_filter(
                $rows,
                static function ($row) use ($value) {
                    return (string) $row !== (string) $value;
                }
            )
        );
    }
}

if (! function_exists('delete_metadata')) {
    /**
     * @param string $meta_type   Meta type.
     * @param int    $object_id   Object id.
     * @param string $meta_key    Meta key.
     * @param mixed  $meta_value  Row value to match, or '' for every row.
     * @param bool   $delete_all  Delete all matching rows.
     * @return bool
     */
    function delete_metadata($meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false)
    {
        if ('user' !== $meta_type || ! $delete_all || '' === $meta_key) {
            return false;
        }
        foreach (array_keys($GLOBALS['meprmf_test_user_meta']) as $user_id) {
            if ('' === $meta_value || ! isset($GLOBALS['meprmf_test_user_meta'][ $user_id ][ $meta_key ])) {
                unset($GLOBALS['meprmf_test_user_meta'][ $user_id ][ $meta_key ]);
                continue;
            }
            // Value-matched like the real function: a row holding a different id survives.
            $remaining = meprmf_test_meta_without_value(
                $GLOBALS['meprmf_test_user_meta'][ $user_id ][ $meta_key ],
                $meta_value
            );
            if ([] === $remaining) {
                unset($GLOBALS['meprmf_test_user_meta'][ $user_id ][ $meta_key ]);
                continue;
            }
            $GLOBALS['meprmf_test_user_meta'][ $user_id ][ $meta_key ] = $remaining;
        }
        return true;
    }
}

if (! function_exists('delete_option')) {
    /**
     * @param string $option Option name.
     * @return bool
     */
    function delete_option($option)
    {
        unset($GLOBALS['meprmf_test_options'][ $option ]);
        return true;
    }
}

if (! class_exists('MeprUtils', false)) {
    /**
     * Minimal MemberPress utility stub for unit tests.
     *
     * Two test files used to define this themselves behind a class_exists
     * guard, with different method sets. Whichever ran first won and the other
     * guard silently skipped, so PresetsTest saw a MeprUtils with no
     * get_mepr_admin_capability() and errored on it in CI (PR #37).
     */
    class MeprUtils
    {
        /**
         * @return string
         */
        public static function db_now()
        {
            return '2026-05-19 12:00:00';
        }

        /**
         * @return string
         */
        public static function db_lifetime()
        {
            return '0000-00-00 00:00:00';
        }

        /**
         * @return string
         */
        public static function get_mepr_admin_capability()
        {
            return array_key_exists('meprmf_test_mepr_admin_cap', $GLOBALS)
                ? (string) $GLOBALS['meprmf_test_mepr_admin_cap']
                : 'mepr_test_admin';
        }
    }
}

if (! class_exists('MeprTransaction', false)) {
    /**
     * Minimal MemberPress transaction stub for unit tests.
     */
    class MeprTransaction
    {
        public static $payment_str = 'payment';

        public static $sub_account_str = 'sub_account';

        public static $woo_txn_str = 'wc_transaction';

        public static $fallback_str = 'fallback';

        public static $complete_str = 'complete';

        public static $subscription_confirmation_str = 'subscription_confirmation';

        public static $confirmed_str = 'confirmed';

        public static $pending_str = 'pending';

        public static $refunded_str = 'refunded';

        public static $failed_str = 'failed';
    }
}

if (! class_exists('MeprSubscription', false)) {
    /**
     * Minimal MemberPress subscription stub for unit tests.
     */
    class MeprSubscription
    {
        public static $active_str = 'active';

        public static $pending_str = 'pending';

        public static $cancelled_str = 'cancelled';

        public static $suspended_str = 'suspended';
    }
}

if (! isset($GLOBALS['meprmf_test_posts'])) {
    $GLOBALS['meprmf_test_posts'] = [];
}

if (! function_exists('get_post')) {
    /**
     * @param int $post_id Post id.
     * @return object|null
     */
    function get_post($post_id)
    {
        $post_id = (int) $post_id;
        return $GLOBALS['meprmf_test_posts'][ $post_id ] ?? null;
    }
}

if (! class_exists('MeprCoupon', false)) {
    /**
     * Minimal coupon stub for unit tests.
     */
    class MeprCoupon
    {
        public static $cpt = 'memberpresscoupon';
    }
}

if (! isset($GLOBALS['meprmf_test_post_types'])) {
    $GLOBALS['meprmf_test_post_types'] = [];
}

if (! function_exists('post_type_exists')) {
    /**
     * @param string $post_type Post type.
     * @return bool
     */
    function post_type_exists($post_type)
    {
        return in_array((string) $post_type, $GLOBALS['meprmf_test_post_types'] ?? [], true);
    }
}

if (! function_exists('get_posts')) {
    /**
     * @param array<string, mixed> $args Query args.
     * @return array<int, object>
     */
    function get_posts($args = [])
    {
        unset($args);
        return $GLOBALS['meprmf_test_posts'] ?? [];
    }
}

if (! isset($GLOBALS['meprmf_test_user_caps'])) {
    $GLOBALS['meprmf_test_user_caps'] = [];
}

if (! isset($GLOBALS['meprmf_test_redirects'])) {
    $GLOBALS['meprmf_test_redirects'] = [];
}

if (! function_exists('delete_user_meta')) {
    /**
     * @param int    $user_id User id.
     * @param string $key     Meta key.
     * @param mixed  $value   Row value to match, or '' for every row.
     * @return true
     */
    function delete_user_meta($user_id, $key, $value = '')
    {
        $user_id = (int) $user_id;
        if ('' === $value || ! isset($GLOBALS['meprmf_test_user_meta'][ $user_id ][ $key ])) {
            unset($GLOBALS['meprmf_test_user_meta'][ $user_id ][ $key ]);
            return true;
        }

        $GLOBALS['meprmf_test_user_meta'][ $user_id ][ $key ] = meprmf_test_meta_without_value(
            $GLOBALS['meprmf_test_user_meta'][ $user_id ][ $key ],
            $value
        );
        if ([] === $GLOBALS['meprmf_test_user_meta'][ $user_id ][ $key ]) {
            unset($GLOBALS['meprmf_test_user_meta'][ $user_id ][ $key ]);
        }

        return true;
    }
}

if (! function_exists('current_user_can')) {
    /**
     * @param string $cap Capability.
     * @return bool
     */
    function current_user_can($cap)
    {
        return ! empty($GLOBALS['meprmf_test_user_caps'][ (string) $cap ]);
    }
}

if (! function_exists('wp_doing_ajax')) {
    /**
     * @return bool
     */
    function wp_doing_ajax()
    {
        return ! empty($GLOBALS['meprmf_test_doing_ajax']);
    }
}

if (! function_exists('wp_doing_cron')) {
    /**
     * @return bool
     */
    function wp_doing_cron()
    {
        return false;
    }
}

if (! function_exists('admin_url')) {
    /**
     * @param string $path Path.
     * @return string
     */
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (! function_exists('add_query_arg')) {
    /**
     * Supports only the (array $args, string $url) form this plugin uses.
     *
     * @param array<string, string> $args Args.
     * @param string                $url  Base url.
     * @return string
     */
    function add_query_arg($args, $url = '')
    {
        $parts = is_array($args) ? $args : [];
        return (string) $url . ( false === strpos((string) $url, '?') ? '?' : '&' ) . http_build_query($parts);
    }
}

if (! function_exists('wp_safe_redirect')) {
    /**
     * Records the redirect instead of sending it, so a test can assert on the target.
     *
     * @param string $location Target.
     * @return true
     */
    function wp_safe_redirect($location)
    {
        $GLOBALS['meprmf_test_redirects'][] = (string) $location;
        throw new \RuntimeException('meprmf_test_redirect:' . (string) $location);
    }
}

require_once dirname(__DIR__) . '/includes/class-meprmf-util.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen-context.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen.php';
// Screen contexts read the site-wide option, so the settings class must be loadable everywhere.
require_once dirname(__DIR__) . '/includes/class-meprmf-settings.php';
