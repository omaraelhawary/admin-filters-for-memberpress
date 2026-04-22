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

if (! function_exists('apply_filters')) {
    /**
     * @param string $hook_name Hook name.
     * @param mixed  $value     Default value.
     * @param mixed  ...$args  Extra args.
     * @return mixed
     */
    function apply_filters($hook_name, $value, ...$args)
    {
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

require_once dirname(__DIR__) . '/includes/class-meprmf-util.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen-context.php';
require_once dirname(__DIR__) . '/includes/screen/class-meprmf-screen.php';
