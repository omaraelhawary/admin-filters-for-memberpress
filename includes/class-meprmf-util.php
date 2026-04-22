<?php
/**
 * Shared helpers (no WordPress except where noted).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Static helpers for sanitization and field normalization.
 */
class Meprmf_Util
{

    /**
     * Sanitize a HTML id / $_GET key to [a-z0-9_]. Null-safe.
     *
     * @param mixed $param Raw param.
     * @return string
     */
    public static function sanitize_param($param)
    {
        if (! is_string($param) || '' === $param) {
            return '';
        }
        $out = preg_replace('/[^a-z0-9_]/', '', $param);
        return is_string($out) ? $out : '';
    }

    /**
     * Read a scalar value from $_GET for the given param.
     *
     * @param string $param Param name.
     * @return string
     */
    public static function get_request_value($param)
    {
        $param = self::sanitize_param($param);
        if ('' === $param) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter query args on admin list screens.
        if (! isset($_GET[ $param ])) {
            return '';
        }
        $value = wp_unslash($_GET[ $param ]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Key restricted via sanitize_param(); value sanitized below.
        if (! is_scalar($value)) {
            return '';
        }
        return sanitize_text_field((string) $value);
    }

    /**
     * Resolve SQL match mode for a field.
     *
     * @param array<string, mixed> $field Field definition.
     * @return string 'exact'|'like'|'contains'
     */
    public static function get_field_match_mode(array $field)
    {
        if (! empty($field['match']) && is_string($field['match'])) {
            $m = $field['match'];
            if (in_array($m, [ 'exact', 'like', 'contains' ], true)) {
                return $m;
            }
        }

        $type = isset($field['type']) ? (string) $field['type'] : 'text';
        if ('country' === $type || 'select' === $type) {
            return 'exact';
        }

        return 'like';
    }

    /**
     * Validate, sanitize, and dedupe filter field definitions.
     *
     * @param array<int, array<string, mixed>> $fields Raw field definitions.
     * @return array<int, array<string, mixed>>
     */
    public static function normalize_filter_fields(array $fields)
    {
        $valid = [];
        $seen  = [];

        foreach ($fields as $field) {
            if (empty($field['param']) || empty($field['meta_key']) || empty($field['label']) || empty($field['type'])) {
                continue;
            }

            $param = self::sanitize_param($field['param']);
            if ('' === $param || isset($seen[ $param ])) {
                continue;
            }

            if ('select' === $field['type'] && ( empty($field['options']) || ! is_array($field['options']) )) {
                continue;
            }

            $seen[ $param ] = true;
            $field['param'] = $param;
            $valid[]        = $field;
        }

        return $valid;
    }
}
