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
        return $single ? $val : [ $val ];
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

if (! function_exists('get_current_screen')) {
    /**
     * @return object|null
     */
    function get_current_screen()
    {
        return $GLOBALS['meprmf_test_current_screen'] ?? null;
    }
}

if (! function_exists('delete_metadata')) {
    /**
     * @param string $meta_type   Meta type.
     * @param int    $object_id   Object id.
     * @param string $meta_key    Meta key.
     * @param mixed  $meta_value  Meta value.
     * @param bool   $delete_all  Delete all matching rows.
     * @return bool
     */
    function delete_metadata($meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false)
    {
        unset($meta_value);
        if ('user' !== $meta_type || ! $delete_all || '' === $meta_key) {
            return false;
        }
        foreach (array_keys($GLOBALS['meprmf_test_user_meta']) as $user_id) {
            unset($GLOBALS['meprmf_test_user_meta'][ $user_id ][ $meta_key ]);
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

require_once dirname(__DIR__) . '/includes/class-meprmf-util.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen-context.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen.php';
