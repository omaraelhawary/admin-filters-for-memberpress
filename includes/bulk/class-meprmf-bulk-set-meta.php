<?php
/**
 * Meta key / value validation for the set-user-meta bulk action.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validates what a bulk set-user-meta run is allowed to write.
 */
class Meprmf_Bulk_Set_Meta
{

    /**
     * Meta keys refused outright, whatever the value.
     *
     * @return array<int, string>
     */
    public static function blocked_keys()
    {
        $keys = [
            'wp_capabilities',
            'wp_user_level',
            'session_tokens',
        ];

        $prefix = self::blog_prefix();
        if ('' !== $prefix) {
            $keys[] = $prefix . 'capabilities';
            $keys[] = $prefix . 'user_level';
        }

        /**
         * Meta keys a bulk set-user-meta run may never write, matched exactly.
         *
         * @since 2.3.0
         * @param array<int, string> $keys Default deny list.
         */
        $keys = (array) apply_filters('meprmf_bulk_set_meta_blocked_keys', $keys);

        return array_values(array_unique(array_filter(array_map('strval', $keys), 'strlen')));
    }

    /**
     * Meta key prefixes refused outright.
     *
     * @return array<int, string>
     */
    public static function blocked_prefixes()
    {
        $prefixes = [
            'mepr_',
            'mepr-',
            'meprmf_',
        ];

        /**
         * Meta key prefixes a bulk set-user-meta run may never write.
         *
         * @since 2.3.0
         * @param array<int, string> $prefixes Default deny list.
         */
        $prefixes = (array) apply_filters('meprmf_bulk_set_meta_blocked_prefixes', $prefixes);

        return array_values(array_unique(array_filter(array_map('strval', $prefixes), 'strlen')));
    }

    /**
     * Validate one key / value pair for a bulk write.
     *
     * @param mixed $key   Raw meta key from the request.
     * @param mixed $value Raw meta value from the request.
     * @return array{success: bool, code?: string, key?: string, value?: string}
     */
    public static function validate($key, $value)
    {
        if (! is_string($key)) {
            return [
                'success' => false,
                'code'    => 'blocked_key',
            ];
        }

        $key = trim($key);
        if ('' === $key) {
            return [
                'success' => false,
                'code'    => 'empty_key',
            ];
        }

        /*
         * Matched case-insensitively, which is wider than the deny list reads. wp_usermeta's
         * meta_key column carries the table's _ci collation, so update_user_meta() looking up
         * `WP_CAPABILITIES` finds and overwrites the existing `wp_capabilities` row. A
         * case-sensitive test would hand every blocked key back through its own spelling.
         */
        $needle = strtolower($key);

        foreach (self::blocked_keys() as $blocked) {
            if ($needle === strtolower($blocked)) {
                return [
                    'success' => false,
                    'code'    => 'blocked_key',
                ];
            }
        }

        foreach (self::blocked_prefixes() as $prefix) {
            if (0 === strpos($needle, strtolower($prefix))) {
                return [
                    'success' => false,
                    'code'    => 'blocked_key',
                ];
            }
        }

        if (! is_scalar($value)) {
            return [
                'success' => false,
                'code'    => 'unsupported_value',
            ];
        }

        $value = sanitize_text_field((string) $value);
        if ('' === $value) {
            // A whitespace-only value survives sanitize_text_field() as an empty string, which
            // is also what get_user_meta() returns for a key that was never set. Writing it
            // would be indistinguishable from a no-op, so refuse it instead of guessing.
            return [
                'success' => false,
                'code'    => 'empty_value',
            ];
        }

        return [
            'success' => true,
            'key'     => $key,
            'value'   => $value,
        ];
    }

    /**
     * Site table prefix for the capability / user level meta keys, or empty when unavailable.
     *
     * @return string
     */
    private static function blog_prefix()
    {
        $wpdb = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_blog_prefix')) {
            return '';
        }

        return (string) $wpdb->get_blog_prefix();
    }
}
