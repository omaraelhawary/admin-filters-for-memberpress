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

    /** Maximum length for a sanitized filter param (usermeta alias is mpf_um_ + param, MySQL limit 64). */
    const PARAM_MAX_LENGTH = 32;

    /** Longest date-range suffix appended to a base param (`_from`). */
    const DATE_RANGE_SUFFIX_LENGTH = 5;

    /** Suffix appended to a base param to carry its comparison operator. */
    const OPERATOR_SUFFIX = '__op';

    /** Length of {@see OPERATOR_SUFFIX}. */
    const OPERATOR_SUFFIX_LENGTH = 4;

    /** Default operator, meaning "use the field's own match mode" (pre-2.1 behaviour). */
    const OPERATOR_DEFAULT = '';

    /** Operators that take no value and so must bypass the empty-value skip. */
    const VALUELESS_OPERATORS = [ 'is_empty', 'is_not_empty' ];

    /**
     * Sanitize a HTML id / $_GET key to [a-z0-9_], capped at {@see PARAM_MAX_LENGTH}. Null-safe.
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
        if (! is_string($out) || '' === $out) {
            return '';
        }

        return substr($out, 0, self::PARAM_MAX_LENGTH);
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
     * @return string 'exact'|'like'|'contains'|'range'
     */
    public static function get_field_match_mode(array $field)
    {
        if (! empty($field['match']) && is_string($field['match'])) {
            $m = $field['match'];
            if (in_array($m, [ 'exact', 'like', 'contains', 'range' ], true)) {
                return $m;
            }
        }

        $type = isset($field['type']) ? (string) $field['type'] : 'text';
        if ('date_range' === $type) {
            return 'range';
        }
        if ('country' === $type || 'select' === $type || 'date' === $type) {
            return 'exact';
        }

        return 'like';
    }

    /**
     * Supported comparison operators, keyed by GET value.
     *
     * The empty default keeps pre-2.1 URLs and saved presets behaving exactly as before:
     * with no operator present the field falls back to {@see get_field_match_mode()}.
     *
     * @return array<int, string>
     */
    public static function get_operators()
    {
        return [
            'is',
            'is_not',
            'contains',
            'not_contains',
            'is_empty',
            'is_not_empty',
            'is_one_of',
        ];
    }

    /**
     * Whether a field type accepts an operator selector.
     *
     * Dates, date ranges and checkboxes have their own comparison semantics and are
     * left alone; operators apply to the text / select / country family.
     *
     * @param array<string, mixed> $field Field definition.
     * @return bool
     */
    public static function field_supports_operators(array $field)
    {
        $type = isset($field['type']) ? (string) $field['type'] : 'text';

        return ! in_array($type, [ 'date', 'date_range', 'checkbox' ], true);
    }

    /**
     * GET param name carrying the operator for a base param.
     *
     * Truncates the base the same way {@see date_range_param_names()} does so the
     * result still fits {@see PARAM_MAX_LENGTH}.
     *
     * @param string $base_param Base param name.
     * @return string
     */
    public static function operator_param_name($base_param)
    {
        $base = self::sanitize_param($base_param);
        if ('' === $base) {
            return '';
        }

        $max_base = self::PARAM_MAX_LENGTH - self::OPERATOR_SUFFIX_LENGTH;
        if (strlen($base) > $max_base) {
            $base = substr($base, 0, $max_base);
        }

        return self::sanitize_param($base . self::OPERATOR_SUFFIX);
    }

    /**
     * Read and validate the operator for a base param.
     *
     * @param string $base_param Base param name.
     * @return string One of {@see get_operators()}, or {@see OPERATOR_DEFAULT}.
     */
    public static function get_field_operator($base_param)
    {
        $op_param = self::operator_param_name($base_param);
        if ('' === $op_param) {
            return self::OPERATOR_DEFAULT;
        }

        $raw = self::get_request_value($op_param);
        if ('' === $raw || ! in_array($raw, self::get_operators(), true)) {
            return self::OPERATOR_DEFAULT;
        }

        return $raw;
    }

    /**
     * Whether a GET param carries an operator rather than a filter value.
     *
     * @param string $param Param name.
     * @return bool
     */
    public static function is_operator_param($param)
    {
        $param = self::sanitize_param($param);
        if ('' === $param || strlen($param) <= self::OPERATOR_SUFFIX_LENGTH) {
            return false;
        }

        return substr($param, -self::OPERATOR_SUFFIX_LENGTH) === self::OPERATOR_SUFFIX;
    }

    /**
     * Whether a base param currently carries an operator that constrains without a value.
     *
     * Used so the active-filter badge still counts "is empty" / "is not empty", which
     * are real constraints even though the value box next to them is blank.
     *
     * @param string $base_param Base param name.
     * @return bool
     */
    public static function has_valueless_operator($base_param)
    {
        return in_array(self::get_field_operator($base_param), self::VALUELESS_OPERATORS, true);
    }

    /**
     * Read a multi-value request param as a list of non-empty strings.
     *
     * Accepts both `?p[]=a&p[]=b` (multi-select) and `?p=a,b` (hand-written or bookmarked
     * URL), so an `is_one_of` filter survives being typed by hand.
     *
     * @param string $param Param name.
     * @return array<int, string>
     */
    public static function get_request_values($param)
    {
        $param = self::sanitize_param($param);
        if ('' === $param) {
            return [];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter query args on admin list screens.
        if (! isset($_GET[ $param ])) {
            return [];
        }

        $raw = wp_unslash($_GET[ $param ]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Key restricted via sanitize_param(); values sanitized below.

        $parts = [];
        if (is_array($raw)) {
            foreach ($raw as $one) {
                if (is_scalar($one)) {
                    $parts[] = (string) $one;
                }
            }
        } elseif (is_scalar($raw)) {
            $parts = explode(',', (string) $raw);
        }

        $out = [];
        foreach ($parts as $part) {
            $clean = sanitize_text_field(trim($part));
            if ('' !== $clean && ! in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * Parse a Y-m-d date from request input, or null when invalid.
     *
     * @param mixed $raw Raw value.
     * @return string|null
     */
    public static function parse_date_param($raw)
    {
        if (! is_scalar($raw)) {
            return null;
        }
        $raw = sanitize_text_field((string) $raw);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        return $raw;
    }

    /**
     * Map WordPress {@see get_option( 'date_format' )} to MySQL STR_TO_DATE format.
     *
     * @param string $php_format PHP date format.
     * @return string
     */
    public static function wordpress_date_format_to_mysql_str_to_date($php_format)
    {
        $tokens = [
            'F' => '%M',
            'M' => '%b',
            'l' => '%W',
            'D' => '%a',
            'S' => '',
            'd' => '%d',
            'j' => '%e',
            'm' => '%m',
            'n' => '%c',
            'Y' => '%Y',
            'y' => '%y',
            'g' => '%l',
            'G' => '%k',
            'h' => '%h',
            'H' => '%H',
            'i' => '%i',
            's' => '%s',
            'a' => '%p',
            'A' => '%p',
        ];

        $out    = '';
        $format = (string) $php_format;
        $len    = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $char = $format[ $i ];
            if ('\\' === $char && ( $i + 1 ) < $len) {
                $out .= $format[ ++$i ];
                continue;
            }
            $out .= $tokens[ $char ] ?? $char;
        }

        return $out;
    }

    /**
     * STR_TO_DATE patterns for MemberPress custom date usermeta values.
     *
     * @return array<int, string>
     */
    public static function usermeta_date_mysql_formats()
    {
        $formats = [ '%Y-%m-%d' ];

        $wp_format = function_exists('get_option') ? get_option('date_format') : 'F j, Y';
        if (is_string($wp_format) && '' !== $wp_format) {
            $mysql = self::wordpress_date_format_to_mysql_str_to_date($wp_format);
            if ('' !== $mysql && ! in_array($mysql, $formats, true)) {
                $formats[] = $mysql;
            }
            // MemberPress may store either unpadded (j) or zero-padded (d) day.
            if (false !== strpos($wp_format, 'j') && '' !== $mysql) {
                $padded = str_replace('%e', '%d', $mysql);
                if (! in_array($padded, $formats, true)) {
                    $formats[] = $padded;
                }
            }
        }

        /**
         * MySQL STR_TO_DATE patterns used when parsing date custom field usermeta.
         *
         * @since 1.9.0
         * @param array<int, string> $formats Default ISO + site date format.
         */
        return apply_filters('meprmf_usermeta_date_mysql_formats', $formats);
    }

    /**
     * SQL expression that parses a usermeta date string using site formats.
     *
     * Not passed through $wpdb->prepare(); contains literal STR_TO_DATE format % tokens.
     *
     * @param string $column_sql Fully-qualified column SQL (e.g. alias.meta_value).
     * @return string
     */
    public static function usermeta_date_value_sql_expr($column_sql)
    {
        $parts = [];
        foreach (self::usermeta_date_mysql_formats() as $fmt) {
            $parts[] = "STR_TO_DATE({$column_sql}, '" . esc_sql($fmt) . "')";
        }

        if (1 === count($parts)) {
            return $parts[0];
        }

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    /**
     * Quote one scalar for SQL via $wpdb->prepare('%s') without embedding format literals.
     *
     * @param wpdb   $wpdb  Database object.
     * @param string $value Scalar value.
     * @return string Quoted SQL literal.
     */
    public static function wpdb_quote_scalar($wpdb, $value)
    {
        return $wpdb->prepare('%s', $value);
    }

    /**
     * Possible stored usermeta values for one Y-m-d filter date.
     *
     * @param string $ymd Filter value (Y-m-d).
     * @return array<int, string>
     */
    public static function usermeta_date_exact_match_values($ymd)
    {
        $values = [ $ymd ];
        $dt     = DateTime::createFromFormat('Y-m-d', $ymd);
        if (! $dt) {
            return $values;
        }

        $ts = $dt->getTimestamp();
        $wp_format = function_exists('get_option') ? get_option('date_format', 'F j, Y') : 'F j, Y';
        if (! is_string($wp_format) || '' === $wp_format) {
            $wp_format = 'F j, Y';
        }

        if (function_exists('wp_date')) {
            $values[] = wp_date($wp_format, $ts);
        } elseif (function_exists('date_i18n')) {
            $values[] = date_i18n($wp_format, $ts);
        } else {
            $values[] = gmdate($wp_format, $ts);
        }

        if (false !== strpos($wp_format, 'j')) {
            $padded_format = str_replace('j', 'd', $wp_format);
            if ($padded_format !== $wp_format) {
                if (function_exists('wp_date')) {
                    $values[] = wp_date($padded_format, $ts);
                } elseif (function_exists('date_i18n')) {
                    $values[] = date_i18n($padded_format, $ts);
                } else {
                    $values[] = gmdate($padded_format, $ts);
                }
            }
        }

        return array_values(array_unique(array_filter($values, static function ($v) {
            return is_string($v) && '' !== $v;
        })));
    }

    /**
     * Validate, sanitize, and dedupe filter field definitions.
     *
     * @param array<string, mixed> $field Field definition.
     * @return array<int, string>
     */
    public static function collect_field_request_params(array $field)
    {
        $param = self::sanitize_param(isset($field['param']) ? $field['param'] : '');
        if ('' === $param) {
            return [];
        }

        if ('date_range' === ( isset($field['type']) ? (string) $field['type'] : '' )) {
            $range = self::date_range_param_names($param);
            $out   = [];
            if ('' !== $range['from']) {
                $out[] = $range['from'];
            }
            if ('' !== $range['to']) {
                $out[] = $range['to'];
            }
            return $out;
        }

        $out = [ $param ];
        if (self::field_supports_operators($field)) {
            $op_param = self::operator_param_name($param);
            if ('' !== $op_param && $op_param !== $param) {
                $out[] = $op_param;
            }
        }

        return $out;
    }

    /**
     * From / to GET param names for a date_range field base param.
     *
     * @param string $base_param Sanitized base param.
     * @return array{from: string, to: string}
     */
    public static function date_range_param_names($base_param)
    {
        $base = self::sanitize_param($base_param);
        if ('' === $base) {
            return [
                'from' => '',
                'to'   => '',
            ];
        }

        $max_base = self::PARAM_MAX_LENGTH - self::DATE_RANGE_SUFFIX_LENGTH;
        if (strlen($base) > $max_base) {
            $base = substr($base, 0, $max_base);
        }

        return [
            'from' => self::sanitize_param($base . '_from'),
            'to'   => self::sanitize_param($base . '_to'),
        ];
    }

    /**
     * Apply date-range preferences and expand date_range rows into from/to fields.
     *
     * @param array<int, array<string, mixed>> $fields Raw field definitions.
     * @return array<int, array<string, mixed>>
     */
    public static function finalize_meta_filter_fields(array $fields)
    {
        $out = [];

        foreach ($fields as $field) {
            $type = isset($field['type']) ? (string) $field['type'] : '';

            if ('date' === $type) {
                $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
                $cf_stub  = '' !== $meta_key ? (object) [ 'field_key' => $meta_key ] : null;
                /**
                 * Use from/to date pickers instead of a single exact date for MemberPress date custom fields.
                 *
                 * @since 1.1.0
                 * @param bool        $use_range Default false (single exact date).
                 * @param object|null $cf        MemberPress custom field object, or stub with field_key.
                 */
                $use_range = (bool) apply_filters('meprmf_custom_date_fields_use_range', false, $cf_stub);
                if ($use_range) {
                    $field['type'] = 'date_range';
                    unset($field['match']);
                }
            }

            if ('date_range' === ( isset($field['type']) ? (string) $field['type'] : '' )) {
                foreach (self::expand_date_range_field($field) as $part) {
                    $out[] = $part;
                }
                continue;
            }

            $out[] = $field;
        }

        return $out;
    }

    /**
     * Split one date_range field into separate from / to date filter rows.
     *
     * @param array<string, mixed> $field date_range field definition.
     * @return array<int, array<string, mixed>>
     */
    public static function expand_date_range_field(array $field)
    {
        $base = self::sanitize_param(isset($field['param']) ? $field['param'] : '');
        $meta = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ('' === $base || '' === $meta) {
            return [ $field ];
        }

        $range = self::date_range_param_names($base);
        if ('' === $range['from'] || '' === $range['to']) {
            return [ $field ];
        }

        $label = isset($field['label']) ? (string) $field['label'] : $base;

        return [
            [
                'param'           => $range['from'],
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Filter field config array key, not a DB query.
                'meta_key'        => $meta,
                'label'           => sprintf(
                    /* translators: %s: filter label */
                    __('%s (from)', 'admin-filters-for-memberpress'),
                    $label
                ),
                'type'            => 'date',
                'match'           => 'exact',
                'date_range_of'   => $base,
                'date_range_part' => 'from',
            ],
            [
                'param'           => $range['to'],
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Filter field config array key, not a DB query.
                'meta_key'        => $meta,
                'label'           => sprintf(
                    /* translators: %s: filter label */
                    __('%s (to)', 'admin-filters-for-memberpress'),
                    $label
                ),
                'type'            => 'date',
                'match'           => 'exact',
                'date_range_of'   => $base,
                'date_range_part' => 'to',
            ],
        ];
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

    /**
     * Validate, sanitize, and dedupe core MemberPress table filter field definitions.
     *
     * @param array<int, array<string, mixed>> $fields Raw field definitions.
     * @return array<int, array<string, mixed>>
     */
    public static function normalize_core_filter_fields(array $fields)
    {
        $valid   = [];
        $seen    = [];
        $sources = [ 'mepr_transaction', 'mepr_subscription', 'mepr_member' ];

        foreach ($fields as $field) {
            if (empty($field['param']) || empty($field['label']) || empty($field['type']) || empty($field['source'])) {
                continue;
            }

            $source = (string) $field['source'];
            if (! in_array($source, $sources, true)) {
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

    /**
     * Validate passthrough (native GET) filter field definitions.
     *
     * @param array<int, array<string, mixed>> $fields Raw field definitions.
     * @return array<int, array<string, mixed>>
     */
    public static function normalize_passthrough_filter_fields(array $fields)
    {
        $valid = [];
        $seen  = [];

        foreach ($fields as $field) {
            if (empty($field['param']) || empty($field['label']) || empty($field['type']) || empty($field['source'])) {
                continue;
            }

            if ('native' !== (string) $field['source']) {
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
