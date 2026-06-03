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
        return $default;
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

require_once dirname(__DIR__) . '/includes/class-meprmf-util.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen-context.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen.php';
