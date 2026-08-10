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

    /** Operators whose value lives in the field's `_from` / `_to` bounds, not in its own param. */
    const RANGE_OPERATORS = [ 'after', 'before', 'between', 'in_last', 'not_in_last' ];

    /** Range operators whose bounds are resolved from a relative window at query-build time. */
    const RELATIVE_OPERATORS = [ 'in_last', 'not_in_last' ];

    /** Units accepted by the relative window unit param. */
    const RELATIVE_UNITS = [ 'days', 'weeks', 'months', 'years' ];

    /**
     * Suffix carrying the relative window magnitude.
     *
     * Deliberately short: the suffix is subtracted from {@see PARAM_MAX_LENGTH}, so a long
     * suffix truncates the base further and makes two long bases collide.
     */
    const RELATIVE_N_SUFFIX = '__n';

    /** Suffix carrying the relative window unit. */
    const RELATIVE_UNIT_SUFFIX = '__u';

    /** Length of {@see RELATIVE_N_SUFFIX} / {@see RELATIVE_UNIT_SUFFIX}. */
    const RELATIVE_SUFFIX_LENGTH = 3;

    /** GET param carrying the AND / OR match mode for this plugin's WHERE fragments. */
    const MATCH_MODE_PARAM = 'meprmf_match';

    /** Field group: membership, access, statuses. */
    const GROUP_MEMBERSHIP = 'membership';

    /** Field group: every date and date range. */
    const GROUP_DATES = 'dates';

    /** Field group: aggregates such as total spent. */
    const GROUP_ACTIVITY = 'activity';

    /** Field group: courses, circles, directories. */
    const GROUP_CONTENT_ACCESS = 'content_access';

    /** Field group: the MemberPress address fields. */
    const GROUP_LOCATION = 'location';

    /** Field group: MemberPress → Settings → Fields. */
    const GROUP_CUSTOM_FIELDS = 'custom_fields';

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
            'after',
            'before',
            'between',
            'in_last',
            'not_in_last',
            'at_least',
            'at_most',
        ];
    }

    /**
     * Operators offered for one field, by field type.
     *
     * Single source of truth for the operator `<select>`: a text field is never offered
     * `after`, and a date field is never offered `contains`. `between` is shared by the
     * date and number families — for both it means "fill both bounds of the pair".
     *
     * @param array<string, mixed> $field Field definition.
     * @return array<int, string>
     */
    public static function get_operators_for_field(array $field)
    {
        $type = isset($field['type']) ? (string) $field['type'] : 'text';

        if ('checkbox' === $type) {
            $operators = [];
        } elseif ('date' === $type || 'date_range' === $type) {
            $operators = [ 'is', 'after', 'before', 'between', 'in_last', 'not_in_last' ];
        } elseif ('number' === $type) {
            $operators = [ 'is', 'is_not', 'at_least', 'at_most', 'between' ];
        } else {
            // Text / select / country keep the full pre-existing list so old URLs,
            // presets and the current operator selector behave exactly as before.
            $operators = [ 'is', 'is_not', 'contains', 'not_contains', 'is_empty', 'is_not_empty', 'is_one_of' ];
        }

        // Emptiness only means something for usermeta-backed fields, where "no row at all"
        // is representable; core MemberPress columns have no such state.
        if (( 'date' === $type || 'date_range' === $type || 'number' === $type ) && empty($field['source'])) {
            $operators = array_merge($operators, self::VALUELESS_OPERATORS);
        }

        /**
         * Operators offered for one filter field.
         *
         * @since 2.1.0
         * @param array<int, string>   $operators Operator tokens from {@see get_operators()}.
         * @param array<string, mixed> $field     Field definition.
         */
        $operators = apply_filters('meprmf_field_operators', $operators, $field);

        if (! is_array($operators)) {
            return [];
        }

        return array_values(array_intersect(self::get_operators(), $operators));
    }

    /**
     * Translated labels for every operator token, keyed by token.
     *
     * The query-builder row reads as a sentence ("Registered · is after · 2026-01-01"), so
     * the labels are lower-case phrases rather than sentence-case button text.
     *
     * @since 2.1.0
     * @return array<string, string>
     */
    public static function get_operator_labels()
    {
        $labels = [
            'is'           => __('is', 'admin-filters-for-memberpress'),
            'is_not'       => __('is not', 'admin-filters-for-memberpress'),
            'contains'     => __('contains', 'admin-filters-for-memberpress'),
            'not_contains' => __('does not contain', 'admin-filters-for-memberpress'),
            'is_empty'     => __('is empty', 'admin-filters-for-memberpress'),
            'is_not_empty' => __('is not empty', 'admin-filters-for-memberpress'),
            'is_one_of'    => __('is one of', 'admin-filters-for-memberpress'),
            'after'        => __('is after', 'admin-filters-for-memberpress'),
            'before'       => __('is before', 'admin-filters-for-memberpress'),
            'between'      => __('is between', 'admin-filters-for-memberpress'),
            'in_last'      => __('is in the last', 'admin-filters-for-memberpress'),
            'not_in_last'  => __('is not in the last', 'admin-filters-for-memberpress'),
            'at_least'     => __('is at least', 'admin-filters-for-memberpress'),
            'at_most'      => __('is at most', 'admin-filters-for-memberpress'),
        ];

        /**
         * Labels shown in the query-builder operator selector.
         *
         * @since 2.1.0
         * @param array<string, string> $labels Operator token => label.
         */
        return apply_filters('meprmf_operator_labels', $labels);
    }

    /**
     * Translated names for the relative window units, keyed by token.
     *
     * @since 2.1.0
     * @return array<string, string>
     */
    public static function get_relative_unit_labels()
    {
        $labels = [
            'days'   => __('days', 'admin-filters-for-memberpress'),
            'weeks'  => __('weeks', 'admin-filters-for-memberpress'),
            'months' => __('months', 'admin-filters-for-memberpress'),
            'years'  => __('years', 'admin-filters-for-memberpress'),
        ];

        /**
         * Labels shown in the relative window unit selector.
         *
         * @since 2.1.0
         * @param array<string, string> $labels Unit token => label.
         */
        $labels = apply_filters('meprmf_relative_unit_labels', $labels);

        $out = [];
        foreach (self::RELATIVE_UNITS as $unit) {
            $out[ $unit ] = isset($labels[ $unit ]) ? (string) $labels[ $unit ] : $unit;
        }

        return $out;
    }

    /**
     * Whether a field accepts an operator selector at all.
     *
     * @param array<string, mixed> $field Field definition.
     * @return bool
     */
    public static function field_supports_operators(array $field)
    {
        return ! empty(self::get_operators_for_field($field));
    }

    /**
     * Whether an operator takes its value(s) from the field's `_from` / `_to` bounds.
     *
     * @param string $operator Operator token.
     * @return bool
     */
    public static function is_range_operator($operator)
    {
        return in_array((string) $operator, self::RANGE_OPERATORS, true);
    }

    /**
     * Translated headings for the {@see field_group()} keys.
     *
     * @return array<string, string>
     */
    public static function get_group_labels()
    {
        $labels = [
            self::GROUP_MEMBERSHIP     => __('Membership', 'admin-filters-for-memberpress'),
            self::GROUP_DATES          => __('Dates', 'admin-filters-for-memberpress'),
            self::GROUP_ACTIVITY       => __('Activity', 'admin-filters-for-memberpress'),
            self::GROUP_CONTENT_ACCESS => __('Content access', 'admin-filters-for-memberpress'),
            self::GROUP_LOCATION       => __('Location', 'admin-filters-for-memberpress'),
            self::GROUP_CUSTOM_FIELDS  => __('Custom fields', 'admin-filters-for-memberpress'),
        ];

        /**
         * Group headings shown in the add-filter popover.
         *
         * @since 2.1.0
         * @param array<string, string> $labels Group key => heading.
         */
        return apply_filters('meprmf_field_group_labels', $labels);
    }

    /**
     * Resolve the popover group for one field.
     *
     * @param array<string, mixed> $field Field definition.
     * @return string One of the GROUP_* keys.
     */
    public static function field_group(array $field)
    {
        $group = isset($field['group']) ? (string) $field['group'] : '';
        $type  = isset($field['type']) ? (string) $field['type'] : '';

        if ('' === $group || ! isset(self::get_group_labels()[ $group ])) {
            // Unset or unknown, so bucket it rather than drop it from the popover and
            // leave the field unreachable. Dates are obvious from the type; everything
            // else is by definition outside our catalog.
            $group = ( 'date' === $type || 'date_range' === $type )
                ? self::GROUP_DATES
                : self::GROUP_CUSTOM_FIELDS;
        }

        /**
         * Group for one filter field.
         *
         * @since 2.1.0
         * @param string               $group Group key.
         * @param array<string, mixed> $field Field definition.
         */
        return (string) apply_filters('meprmf_field_group', $group, $field);
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
     * With no field passed the operator is validated against the whole vocabulary; pass the
     * field to also reject operators that its type does not offer. An absent or rejected
     * operator returns {@see OPERATOR_DEFAULT}, which keeps pre-2.1 URLs and presets on the
     * field's own match mode.
     *
     * @param string               $base_param Base param name.
     * @param array<string, mixed> $field      Optional field definition, for type gating.
     * @return string One of {@see get_operators()}, or {@see OPERATOR_DEFAULT}.
     */
    public static function get_field_operator($base_param, array $field = [])
    {
        $op_param = self::operator_param_name($base_param);
        if ('' === $op_param) {
            return self::OPERATOR_DEFAULT;
        }

        $allowed = empty($field) ? self::get_operators() : self::get_operators_for_field($field);

        $raw = self::get_request_value($op_param);
        if ('' === $raw || ! in_array($raw, $allowed, true)) {
            return self::OPERATOR_DEFAULT;
        }

        return $raw;
    }

    /**
     * GET param names carrying the relative window ("in the last N units") for a base param.
     *
     * @param string $base_param Base param name.
     * @return array{n: string, unit: string}
     */
    public static function relative_param_names($base_param)
    {
        $base = self::sanitize_param($base_param);
        if ('' === $base) {
            return [
                'n'    => '',
                'unit' => '',
            ];
        }

        $max_base = self::PARAM_MAX_LENGTH - self::RELATIVE_SUFFIX_LENGTH;
        if (strlen($base) > $max_base) {
            $base = substr($base, 0, $max_base);
        }

        return [
            'n'    => self::sanitize_param($base . self::RELATIVE_N_SUFFIX),
            'unit' => self::sanitize_param($base . self::RELATIVE_UNIT_SUFFIX),
        ];
    }

    /**
     * Read the relative window for a base param, or null when it is unusable.
     *
     * @param string $base_param Base param name.
     * @return array{n: int, unit: string}|null
     */
    public static function get_relative_window($base_param)
    {
        $params = self::relative_param_names($base_param);
        if ('' === $params['n']) {
            return null;
        }

        $raw = self::get_request_value($params['n']);
        if (! is_numeric($raw)) {
            return null;
        }

        $n = (int) $raw;
        if ($n < 1) {
            return null;
        }

        $unit = self::get_request_value($params['unit']);
        if (! in_array($unit, self::RELATIVE_UNITS, true)) {
            $unit = 'days';
        }

        return [
            'n'    => min($n, 9999),
            'unit' => $unit,
        ];
    }

    /**
     * Concrete Y-m-d bounds for a relative window, resolved against the site timezone now.
     *
     * Resolved at query-build time, so a bookmarked "in the last 30 days" stays relative
     * instead of freezing to the day it was bookmarked.
     *
     * @param int    $n    Magnitude (>= 1).
     * @param string $unit One of {@see RELATIVE_UNITS}.
     * @return array{from: string, to: string}
     */
    public static function resolve_relative_bounds($n, $unit)
    {
        $n    = max(1, (int) $n);
        $unit = in_array((string) $unit, self::RELATIVE_UNITS, true) ? (string) $unit : 'days';

        $spec = [
            'days'   => 'P%dD',
            'weeks'  => 'P%dW',
            'months' => 'P%dM',
            'years'  => 'P%dY',
        ];

        $tz  = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);

        return [
            'from' => $now->sub(new DateInterval(sprintf($spec[ $unit ], $n)))->format('Y-m-d'),
            'to'   => $now->format('Y-m-d'),
        ];
    }

    /**
     * Effective range bounds for a range field, after applying its operator.
     *
     * `after` fills the lower bound only, `before` the upper bound only, `between` both, and
     * `in_last` / `not_in_last` resolve both from the relative window. `not_in_last` returns
     * the same bounds with negate=true; the caller inverts the whole predicate.
     *
     * @param string               $base_param Range base param (e.g. mpm_exp, mpf_birthday).
     * @param array<string, mixed> $field      Optional field definition, for operator gating.
     * @return array{from: string|null, to: string|null, negate: bool}
     */
    public static function resolve_range_bounds($base_param, array $field = [])
    {
        $range = self::date_range_param_names($base_param);
        $from  = self::parse_date_param(self::get_request_value($range['from']));
        $to    = self::parse_date_param(self::get_request_value($range['to']));

        $operator = self::get_field_operator($base_param, $field);
        $negate   = false;

        if ('after' === $operator || 'before' === $operator) {
            // A single-date field has one input (the base param itself), so that one date
            // is the bound the operator names.
            if (null === $from && null === $to) {
                $from = self::parse_date_param(self::get_request_value($base_param));
                $to   = $from;
            }
            if ('after' === $operator) {
                $to = null;
            } else {
                $from = null;
            }
        } elseif (in_array($operator, self::RELATIVE_OPERATORS, true)) {
            $window = self::get_relative_window($base_param);
            if (null === $window) {
                $from = null;
                $to   = null;
            } else {
                $bounds = self::resolve_relative_bounds($window['n'], $window['unit']);
                $from   = $bounds['from'];
                $to     = $bounds['to'];
                $negate = ('not_in_last' === $operator);
            }
        }

        return [
            'from'   => $from,
            'to'     => $to,
            'negate' => $negate,
        ];
    }

    /**
     * How this plugin's WHERE fragments are combined: `all` (AND) or `any` (OR).
     *
     * Defaults to `all` so every existing URL and saved preset keeps its meaning.
     *
     * @param Meprmf_Screen_Context|null $ctx Screen context, for the per-screen hook.
     * @return string `all`|`any`
     */
    public static function get_match_mode($ctx = null)
    {
        $mode = ('any' === self::get_request_value(self::MATCH_MODE_PARAM)) ? 'any' : 'all';

        /**
         * Match mode used to combine this plugin's WHERE fragments.
         *
         * @since 2.1.0
         * @param string                     $mode `all` (AND) or `any` (OR).
         * @param Meprmf_Screen_Context|null $ctx  Screen context.
         */
        $mode = apply_filters('meprmf_match_mode', $mode, $ctx);

        return ('any' === $mode) ? 'any' : 'all';
    }

    /**
     * Whether a GET param carries operator metadata rather than a filter value.
     *
     * Covers the operator suffix and the relative-window suffixes: none of them is a
     * filter value on its own, so callers counting active filters must skip them.
     *
     * @param string $param Param name.
     * @return bool
     */
    public static function is_operator_param($param)
    {
        $param = self::sanitize_param($param);
        if ('' === $param) {
            return false;
        }

        foreach ([ self::OPERATOR_SUFFIX, self::RELATIVE_N_SUFFIX, self::RELATIVE_UNIT_SUFFIX ] as $suffix) {
            $len = strlen($suffix);
            if (strlen($param) > $len && substr($param, -$len) === $suffix) {
                return true;
            }
        }

        return false;
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
     * Base param a field's operator and range bounds hang off.
     *
     * A field that is one part of a range pair (a date from/to, a numeric min/max) shares
     * one operator with its sibling, stored on the pair's base param.
     *
     * @param array<string, mixed> $field Field definition.
     * @return string
     */
    public static function range_base_param(array $field)
    {
        foreach ([ 'date_range_of', 'range_of' ] as $key) {
            if (empty($field[ $key ])) {
                continue;
            }
            $base = self::sanitize_param((string) $field[ $key ]);
            if ('' !== $base) {
                return $base;
            }
        }

        return self::sanitize_param(isset($field['param']) ? $field['param'] : '');
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

        $type = isset($field['type']) ? (string) $field['type'] : '';
        $out  = [];

        if ('date_range' === $type) {
            $range = self::date_range_param_names($param);
            if ('' !== $range['from']) {
                $out[] = $range['from'];
            }
            if ('' !== $range['to']) {
                $out[] = $range['to'];
            }
        } else {
            $out[] = $param;
        }

        // A single date field gets `between`, which resolves through the same `_from` / `_to`
        // bounds as a range field, so those two names have to be known params as well or an
        // applied "is between" is neither cleared on Apply nor saved into a preset. A field
        // that is one half of a pair is skipped: its sibling already owns the other bound.
        if ('date' === $type && empty($field['range_of']) && empty($field['date_range_of'])) {
            foreach (self::date_range_param_names($param) as $bound) {
                if ('' !== $bound && ! in_array($bound, $out, true)) {
                    $out[] = $bound;
                }
            }
        }

        if (! self::field_supports_operators($field)) {
            return $out;
        }

        $base     = self::range_base_param($field);
        $op_param = self::operator_param_name($base);
        if ('' !== $op_param && ! in_array($op_param, $out, true)) {
            $out[] = $op_param;
        }

        if ('date' === $type || 'date_range' === $type) {
            foreach (self::relative_param_names($base) as $relative) {
                if ('' !== $relative && ! in_array($relative, $out, true)) {
                    $out[] = $relative;
                }
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
        $group = self::field_group($field);

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
                'group'           => $group,
                'date_range_of'   => $base,
                'date_range_part' => 'from',
                'range_of'        => $base,
                'range_part'      => 'from',
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
                'group'           => $group,
                'date_range_of'   => $base,
                'date_range_part' => 'to',
                'range_of'        => $base,
                'range_part'      => 'to',
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
            $field['group'] = self::field_group($field);
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
            $field['group'] = self::field_group($field);
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
            $field['group'] = self::field_group($field);
            $valid[]        = $field;
        }

        return $valid;
    }
}
