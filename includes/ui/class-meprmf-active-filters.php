<?php
/**
 * Active filter chips shown above MemberPress list tables.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds and renders the removable chip row summarising active filters.
 */
class Meprmf_Active_Filters
{

    /**
     * Human labels for the fixed set of MemberPress native toolbar params.
     *
     * Kept in sync with {@see Meprmf_Native_Params::for_context()}. Anything not listed
     * here falls back to a humanised version of the key itself.
     *
     * @return array<string, string>
     */
    public static function native_param_labels()
    {
        return [
            'status'            => __('Status', 'admin-filters-for-memberpress'),
            'membership'        => __('Membership', 'admin-filters-for-memberpress'),
            'gateway'           => __('Gateway', 'admin-filters-for-memberpress'),
            'type'              => __('Gift type', 'admin-filters-for-memberpress'),
            'date_range_filter' => __('Date range', 'admin-filters-for-memberpress'),
            'date_start'        => __('Date from', 'admin-filters-for-memberpress'),
            'date_end'          => __('Date to', 'admin-filters-for-memberpress'),
            'date_field'        => __('Date field', 'admin-filters-for-memberpress'),
        ];
    }

    /**
     * Phrase appended to a field label for each operator.
     *
     * Every operator keeps its word, because a text field offers both `is` and `contains`
     * and dropping the word would render two different filters as the same chip. Wording
     * comes from the query builder's own operator labels, so a chip and the row that
     * produced it read the same.
     *
     * @param string $operator Operator token.
     * @return string
     */
    private static function operator_phrase($operator)
    {
        $operator = (string) $operator;
        if ('' === $operator) {
            return '';
        }

        $labels = Meprmf_Util::get_operator_labels();

        return isset($labels[ $operator ]) ? (string) $labels[ $operator ] : '';
    }

    /**
     * Map a raw value to its display label using a catalog entry's options, when it has any.
     *
     * @since 2.1.0
     * @param array<string, mixed> $entry Catalog entry.
     * @param string               $value Raw value.
     * @return string
     */
    private static function option_label(array $entry, $value)
    {
        if (empty($entry['options']) || ! is_array($entry['options'])) {
            return (string) $value;
        }

        foreach ($entry['options'] as $option) {
            if (! is_array($option) || ! isset($option['v'])) {
                continue;
            }
            if ((string) $option['v'] === (string) $value) {
                return isset($option['l']) ? (string) $option['l'] : (string) $value;
            }
        }

        return (string) $value;
    }

    /**
     * Build the chip rows for the current request.
     *
     * Pure: everything it needs arrives as arguments, so the grouping and labelling
     * rules can be tested without a request or a database.
     *
     * The rows are read from the same field catalog the query builder is built from, so a
     * chip covers exactly the params one row owns — value, both bounds, the operator and the
     * relative window — and removing the chip therefore removes the whole filter.
     *
     * @param array<int, array<string, mixed>> $valid       Normalized field definitions.
     * @param array<int, string>               $native_keys Native toolbar param keys.
     * @param array<string, mixed>             $request     Request map (usually $_GET).
     * @return array<int, array{label: string, value: string, text: string, params: array<int, string>}>
     */
    public static function build_chips(array $valid, array $native_keys, array $request)
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog($valid);
        $chips   = [];
        $handled = [];

        // One field per catalog entry, for operator gating: which operators the engine will
        // actually honour depends on the field's type, not on what the row selector offers.
        $by_base = [];
        foreach ($valid as $field) {
            if (! is_array($field)) {
                continue;
            }
            $base = Meprmf_Util::range_base_param($field);
            if ('' !== $base && ! isset($by_base[ $base ])) {
                $by_base[ $base ] = $field;
            }
        }

        foreach ($catalog as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (self::entry_params($entry) as $param) {
                $handled[ $param ] = true;
            }

            $base  = isset($entry['param']) ? (string) $entry['param'] : '';
            $field = isset($by_base[ $base ]) ? $by_base[ $base ] : [];

            $chip = self::build_entry_chip($entry, $field, $request);
            if (null !== $chip) {
                $chips[] = $chip;
            }
        }

        foreach ($native_keys as $key) {
            $key = Meprmf_Util::sanitize_param((string) $key);
            if ('' === $key || isset($handled[ $key ])) {
                continue;
            }
            $raw = self::read_param($request, $key);
            if ('' === $raw) {
                continue;
            }

            $labels = self::native_param_labels();
            $label  = isset($labels[ $key ]) ? $labels[ $key ] : ucfirst(str_replace('_', ' ', $key));

            // A native toolbar control only ever means equality, so it reads with the `is` word
            // the builder rows use rather than a punctuation style of its own.
            $chips[] = self::chip(
                $label,
                self::operator_phrase('is'),
                self::resolve_native_value($catalog, $key, $raw),
                [ $key ]
            );
        }

        // The mode changes what the whole row means, so it is only worth saying next to
        // filters it is actually combining — and it has to be removable with them.
        if (! empty($chips) && 'any' === self::read_param($request, Meprmf_Util::MATCH_MODE_PARAM)) {
            array_unshift(
                $chips,
                self::chip(
                    __('Matching any filter', 'admin-filters-for-memberpress'),
                    '',
                    '',
                    [ Meprmf_Util::MATCH_MODE_PARAM ]
                )
            );
        }

        return $chips;
    }

    /**
     * Build one chip for one catalog entry, or null when the entry has no active filter.
     *
     * @since 2.1.0
     * @param array<string, mixed> $entry   Catalog entry.
     * @param array<string, mixed> $field   Field definition behind the entry, for operator gating.
     * @param array<string, mixed> $request Request map.
     * @return array{label: string, value: string, text: string, params: array<int, string>}|null
     */
    private static function build_entry_chip(array $entry, array $field, array $request)
    {
        $names = self::entry_params($entry);
        $label = isset($entry['label']) ? trim((string) $entry['label']) : '';
        if (empty($names) || '' === $label) {
            return null;
        }

        $kind  = isset($entry['kind']) ? (string) $entry['kind'] : 'text';
        $unit  = isset($entry['unit']) ? (string) $entry['unit'] : '';
        $slots = isset($entry['params']) && is_array($entry['params']) ? $entry['params'] : [];

        $value = self::read_slot($slots, 'value', $request);
        $from  = self::read_slot($slots, 'from', $request);
        $to    = self::read_slot($slots, 'to', $request);
        $n     = self::read_slot($slots, 'n', $request);

        $operator = self::read_operator($slots, $field, $request);
        if ('' === $operator) {
            $operator = self::infer_operator($kind, $value, $from, $to, $n);
        }

        // "is empty" / "is not empty" constrain the list with no value at all.
        if (in_array($operator, Meprmf_Util::VALUELESS_OPERATORS, true)) {
            return self::chip($label, self::operator_phrase($operator), '', $names);
        }

        if (in_array($operator, Meprmf_Util::RELATIVE_OPERATORS, true)) {
            if (! is_numeric($n) || (int) $n < 1) {
                return null;
            }

            return self::chip(
                $label,
                self::operator_phrase($operator),
                self::relative_value((int) $n, self::read_slot($slots, 'u', $request)),
                $names
            );
        }

        if ('between' === $operator && ( '' !== $from || '' !== $to )) {
            return self::chip($label, '', self::bounds_value($kind, $unit, $from, $to), $names);
        }

        // A single-input row writes its one value to whichever bound the operator names.
        $raw = $value;
        if ('' === $raw) {
            $raw = in_array($operator, [ 'before', 'at_most' ], true) ? $to : $from;
        }
        if ('' === $raw) {
            return null;
        }

        return self::chip(
            $label,
            self::operator_phrase($operator),
            self::format_value($entry, $kind, $unit, $operator, $raw),
            $names
        );
    }

    /**
     * Assemble one chip and its display reading.
     *
     * @since 2.1.0
     * @param string             $label  Field label.
     * @param string             $phrase Operator phrase, or ''.
     * @param string             $value  Formatted value, or ''.
     * @param array<int, string> $params GET params the chip owns.
     * @return array{label: string, value: string, text: string, params: array<int, string>}
     */
    private static function chip($label, $phrase, $value, array $params)
    {
        $full = trim($label . ('' !== $phrase ? ' ' . $phrase : ''));
        $text = ('' === $value) ? $full : $full . ' ' . $value;

        return [
            'label'  => $full,
            'value'  => $value,
            'text'   => $text,
            'params' => $params,
        ];
    }

    /**
     * Every GET param one catalog entry owns, sanitized and deduped.
     *
     * @since 2.1.0
     * @param array<string, mixed> $entry Catalog entry.
     * @return array<int, string>
     */
    private static function entry_params(array $entry)
    {
        $slots = isset($entry['params']) && is_array($entry['params']) ? $entry['params'] : [];
        $out   = [];

        foreach ($slots as $name) {
            $name = Meprmf_Util::sanitize_param(is_scalar($name) ? (string) $name : '');
            if ('' !== $name && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Read one of an entry's param slots from the request.
     *
     * @since 2.1.0
     * @param array<string, mixed> $slots   Entry param map.
     * @param string               $slot    Slot key (value, from, to, n, u, op).
     * @param array<string, mixed> $request Request map.
     * @return string
     */
    private static function read_slot(array $slots, $slot, array $request)
    {
        return self::read_param($request, isset($slots[ $slot ]) ? (string) $slots[ $slot ] : '');
    }

    /**
     * Read one request param as a trimmed string, joining a multi-value param with commas.
     *
     * `?p[]=a&p[]=b` and `?p=a,b` mean the same thing to the engine, so they must mean the
     * same thing to a chip.
     *
     * @since 2.1.0
     * @param array<string, mixed> $request Request map.
     * @param string               $param   Param name.
     * @return string
     */
    private static function read_param(array $request, $param)
    {
        $param = (string) $param;
        if ('' === $param || ! isset($request[ $param ])) {
            return '';
        }

        $raw = $request[ $param ];

        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $one) {
                if (! is_scalar($one)) {
                    continue;
                }
                $one = trim((string) $one);
                if ('' !== $one) {
                    $parts[] = $one;
                }
            }

            return implode(',', $parts);
        }

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    /**
     * Read the operator for an entry, rejecting anything the engine would not honour.
     *
     * @since 2.1.0
     * @param array<string, mixed> $slots   Entry param map.
     * @param array<string, mixed> $field   Field definition, or [] when the entry has none.
     * @param array<string, mixed> $request Request map.
     * @return string Operator token, or ''.
     */
    private static function read_operator(array $slots, array $field, array $request)
    {
        $raw = self::read_slot($slots, 'op', $request);
        if ('' === $raw) {
            return '';
        }

        // A catalog entry added through the `meprmf_field_catalog` filter has no field to gate
        // against, so fall back to the whole vocabulary rather than dropping its operator.
        $allowed = empty($field) ? Meprmf_Util::get_operators() : Meprmf_Util::get_operators_for_field($field);

        return in_array($raw, $allowed, true) ? $raw : '';
    }

    /**
     * Operator implied by a pre-2.1 URL, which carries values but no operator param.
     *
     * Mirrors the query builder's own fallback so a bookmarked URL chips the way its row reads.
     *
     * @since 2.1.0
     * @param string $kind  Value-control family.
     * @param string $value Value param.
     * @param string $from  Lower bound param.
     * @param string $to    Upper bound param.
     * @param string $n     Relative magnitude param.
     * @return string
     */
    private static function infer_operator($kind, $value, $from, $to, $n)
    {
        if ('' !== $value) {
            return 'is';
        }
        if ('' !== $from && '' !== $to) {
            return 'between';
        }
        if ('' !== $from) {
            return ('number' === $kind) ? 'at_least' : 'after';
        }
        if ('' !== $to) {
            return ('number' === $kind) ? 'at_most' : 'before';
        }
        if ('' !== $n) {
            return 'in_last';
        }

        return '';
    }

    /**
     * Reading for a two-bound filter, including a half-open one.
     *
     * @since 2.1.0
     * @param string $kind Value-control family.
     * @param string $unit Unit glyph, or ''.
     * @param string $from Lower bound.
     * @param string $to   Upper bound.
     * @return string
     */
    private static function bounds_value($kind, $unit, $from, $to)
    {
        $a = ('' !== $from) ? self::format_bound($kind, $unit, $from) : '';
        $b = ('' !== $to) ? self::format_bound($kind, $unit, $to) : '';

        if ('' !== $a && '' !== $b) {
            $format = ('number' === $kind)
                /* translators: 1: lower bound, 2: upper bound. */
                ? __('%1$s–%2$s', 'admin-filters-for-memberpress')
                /* translators: 1: start date, 2: end date. */
                : __('%1$s – %2$s', 'admin-filters-for-memberpress');

            return sprintf($format, $a, $b);
        }

        if ('' !== $a) {
            /* translators: %s: lower bound. */
            return sprintf(__('from %s', 'admin-filters-for-memberpress'), $a);
        }

        /* translators: %s: upper bound. */
        return sprintf(__('until %s', 'admin-filters-for-memberpress'), $b);
    }

    /**
     * Reading for one bound value.
     *
     * @since 2.1.0
     * @param string $kind Value-control family.
     * @param string $unit Unit glyph, or ''.
     * @param string $raw  Raw bound value.
     * @return string
     */
    private static function format_bound($kind, $unit, $raw)
    {
        if ('date' === $kind) {
            return self::format_date($raw);
        }
        if ('number' === $kind) {
            return $unit . $raw;
        }

        return (string) $raw;
    }

    /**
     * Reading for a single-value filter.
     *
     * @since 2.1.0
     * @param array<string, mixed> $entry    Catalog entry.
     * @param string               $kind     Value-control family.
     * @param string               $unit     Unit glyph, or ''.
     * @param string               $operator Operator token.
     * @param string               $raw      Raw value.
     * @return string
     */
    private static function format_value(array $entry, $kind, $unit, $operator, $raw)
    {
        if ('is_one_of' === $operator) {
            $out = [];
            foreach (explode(',', $raw) as $one) {
                $one = trim($one);
                if ('' !== $one) {
                    $out[] = self::option_label($entry, $one);
                }
            }

            return implode(', ', $out);
        }

        if ('date' === $kind) {
            return self::format_date($raw);
        }
        if ('number' === $kind) {
            return $unit . $raw;
        }

        return self::option_label($entry, $raw);
    }

    /**
     * Reading for a relative window ("30 days").
     *
     * @since 2.1.0
     * @param int    $n    Magnitude.
     * @param string $unit Raw unit param.
     * @return string
     */
    private static function relative_value($n, $unit)
    {
        $labels = Meprmf_Util::get_relative_unit_labels();
        $label  = isset($labels[ $unit ]) ? $labels[ $unit ] : $labels['days'];

        /* translators: 1: number of units, 2: unit name (days, weeks, months, years). */
        return sprintf(__('%1$d %2$s', 'admin-filters-for-memberpress'), (int) $n, $label);
    }

    /**
     * Render a Y-m-d filter value in the site date format, or pass it through unchanged.
     *
     * A value that does not parse is shown raw: turning unrecognised input into a date would
     * claim the list is filtered by something it is not.
     *
     * @since 2.1.0
     * @param string $raw Raw value.
     * @return string
     */
    private static function format_date($raw)
    {
        $ymd = Meprmf_Util::parse_date_param($raw);
        if (null === $ymd || ! function_exists('mysql2date')) {
            return (string) $raw;
        }

        $format = function_exists('get_option') ? get_option('date_format') : '';
        if (! is_string($format) || '' === $format) {
            $format = 'F j, Y';
        }

        $out = mysql2date($format, $ymd);

        return (is_string($out) && '' !== $out) ? $out : (string) $raw;
    }

    /**
     * Resolve a native param value to a friendlier label when a catalog entry knows it.
     *
     * The native `membership` param carries a product id; the panel's own membership
     * field already holds id => name options for the same screen, so reuse them rather
     * than showing a bare number.
     *
     * @param array<int, array<string, mixed>> $catalog Field catalog.
     * @param string                           $key     Native param key.
     * @param string                           $raw     Raw value.
     * @return string
     */
    private static function resolve_native_value(array $catalog, $key, $raw)
    {
        if ('membership' !== $key) {
            return $raw;
        }

        foreach ($catalog as $entry) {
            if (! is_array($entry) || ! isset($entry['param'])) {
                continue;
            }
            if (! preg_match('/_product$/', (string) $entry['param'])) {
                continue;
            }
            $label = self::option_label($entry, $raw);
            if ($label !== $raw) {
                return $label;
            }
        }

        return $raw;
    }

    /**
     * Echo the chip row for the current screen.
     *
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @param Meprmf_Screen_Context            $ctx   Screen context.
     * @return void
     */
    public static function render(array $valid, Meprmf_Screen_Context $ctx)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter query args on admin list screens.
        $request = wp_unslash($_GET);
        if (! is_array($request)) {
            return;
        }

        $chips = self::build_chips($valid, Meprmf_Native_Params::for_context($ctx), $request);
        if (empty($chips)) {
            return;
        }

        $all_params = [];
        foreach ($chips as $chip) {
            foreach ($chip['params'] as $p) {
                $all_params[] = $p;
            }
        }

        // A <span> is required: this hook fires inside MemberPress's `<p class="mepr-search-box">`,
        // and the HTML parser ejects a block element from a `<p>`, which both drops the row into
        // `.tablenav` (zero width beside a full-width float) and splits the search box apart.
        echo '<span class="meprmf-active-filters">';
        printf(
            '<span class="meprmf-active-filters__label">%s</span>',
            esc_html__('Filtering by:', 'admin-filters-for-memberpress')
        );

        foreach ($chips as $chip) {
            printf(
                '<a class="meprmf-active-filters__chip" href="%1$s"><span class="meprmf-active-filters__chip-text">%2$s</span><span class="meprmf-active-filters__chip-x" aria-hidden="true">&times;</span><span class="screen-reader-text">%3$s</span></a>',
                esc_url(remove_query_arg($chip['params'])),
                esc_html($chip['text']),
                /* translators: %s: filter description. */
                esc_html(sprintf(__('Remove filter %s', 'admin-filters-for-memberpress'), $chip['text']))
            );
        }

        if (count($chips) > 1) {
            printf(
                '<a class="meprmf-active-filters__clear" href="%s">%s</a>',
                esc_url(remove_query_arg(array_unique($all_params))),
                esc_html__('Clear all', 'admin-filters-for-memberpress')
            );
        }

        echo '</span>';
    }
}
