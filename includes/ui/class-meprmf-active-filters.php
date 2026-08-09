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
     * `is` and `contains` return an empty phrase: they are the plain reading of
     * "Label: value" and adding words would only make the chip longer.
     *
     * @param string $operator Operator key.
     * @return array{phrase: string, needs_value: bool}
     */
    private static function operator_phrase($operator)
    {
        switch ($operator) {
            case 'is_not':
                return [ 'phrase' => __('is not', 'admin-filters-for-memberpress'), 'needs_value' => true ];
            case 'not_contains':
                return [ 'phrase' => __('does not contain', 'admin-filters-for-memberpress'), 'needs_value' => true ];
            case 'is_empty':
                return [ 'phrase' => __('is empty', 'admin-filters-for-memberpress'), 'needs_value' => false ];
            case 'is_not_empty':
                return [ 'phrase' => __('is not empty', 'admin-filters-for-memberpress'), 'needs_value' => false ];
            case 'is_one_of':
                return [ 'phrase' => __('is one of', 'admin-filters-for-memberpress'), 'needs_value' => true ];
            default:
                return [ 'phrase' => '', 'needs_value' => true ];
        }
    }

    /**
     * Map a raw value to its display label using a field's options, when it has any.
     *
     * @param array<string, mixed> $field Field definition.
     * @param string               $value Raw value.
     * @return string
     */
    private static function value_label(array $field, $value)
    {
        if (empty($field['options']) || ! is_array($field['options'])) {
            return $value;
        }

        foreach ($field['options'] as $opt_value => $opt_label) {
            if ((string) $opt_value === $value) {
                return (string) $opt_label;
            }
        }

        return $value;
    }

    /**
     * Build the chip rows for the current request.
     *
     * Pure: everything it needs arrives as arguments, so the grouping and labelling
     * rules can be tested without a request or a database.
     *
     * @param array<int, array<string, mixed>> $valid       Normalized field definitions.
     * @param array<int, string>               $native_keys Native toolbar param keys.
     * @param array<string, mixed>             $request     Request map (usually $_GET).
     * @return array<int, array{label: string, value: string, params: array<int, string>}>
     */
    public static function build_chips(array $valid, array $native_keys, array $request)
    {
        $chips       = [];
        $range_seen  = [];
        $handled     = [];

        foreach ($valid as $field) {
            $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
            if ('' === $param) {
                continue;
            }

            $label = isset($field['label']) ? (string) $field['label'] : $param;
            $type  = isset($field['type']) ? (string) $field['type'] : 'text';

            // A date range renders as one chip even though it owns two params.
            $group = isset($field['date_range_of']) ? (string) $field['date_range_of'] : '';
            if ('' !== $group) {
                if (isset($range_seen[ $group ])) {
                    continue;
                }
                $chip = self::build_range_chip($valid, $group, $request);
                if (null !== $chip) {
                    $range_seen[ $group ] = true;
                    $chips[]              = $chip;
                }
                continue;
            }

            if ('date_range' === $type) {
                $range = Meprmf_Util::date_range_param_names($param);
                $chip  = self::range_chip_from_params($label, $range['from'], $range['to'], $request);
                if (null !== $chip) {
                    $chips[] = $chip;
                }
                continue;
            }

            $op_param = Meprmf_Util::field_supports_operators($field)
                ? Meprmf_Util::operator_param_name($param)
                : '';
            $operator = ('' !== $op_param && isset($request[ $op_param ]))
                ? (string) $request[ $op_param ]
                : '';
            if (! in_array($operator, Meprmf_Util::get_operators(), true)) {
                $operator = '';
            }

            $raw = isset($request[ $param ]) && is_scalar($request[ $param ])
                ? trim((string) $request[ $param ])
                : '';

            $meta = self::operator_phrase($operator);

            // "is empty" / "is not empty" constrain the list with no value at all.
            if (! $meta['needs_value'] && '' === $raw) {
                $chips[]             = [
                    'label'  => trim($label . ' ' . $meta['phrase']),
                    'value'  => '',
                    'params' => array_values(array_filter([ $param, $op_param ])),
                ];
                $handled[ $param ]   = true;
                continue;
            }

            if ('' === $raw) {
                continue;
            }

            $value = ('is_one_of' === $operator)
                ? implode(', ', array_map(
                    static function ($one) use ($field) {
                        return self::value_label($field, $one);
                    },
                    array_filter(array_map('trim', explode(',', $raw)), 'strlen')
                ))
                : self::value_label($field, $raw);

            $chips[]           = [
                'label'  => trim($label . ('' !== $meta['phrase'] ? ' ' . $meta['phrase'] : '')),
                'value'  => $value,
                'params' => array_values(array_filter([ $param, $op_param ])),
            ];
            $handled[ $param ] = true;
        }

        foreach ($native_keys as $key) {
            $key = Meprmf_Util::sanitize_param((string) $key);
            if ('' === $key || isset($handled[ $key ])) {
                continue;
            }
            if (! isset($request[ $key ]) || ! is_scalar($request[ $key ])) {
                continue;
            }
            $raw = trim((string) $request[ $key ]);
            if ('' === $raw) {
                continue;
            }

            $labels = self::native_param_labels();
            $label  = isset($labels[ $key ]) ? $labels[ $key ] : ucfirst(str_replace('_', ' ', $key));

            $chips[] = [
                'label'  => $label,
                'value'  => self::resolve_native_value($valid, $key, $raw),
                'params' => [ $key ],
            ];
        }

        return $chips;
    }

    /**
     * Resolve a native param value to a friendlier label when a panel field knows it.
     *
     * The native `membership` param carries a product id; the panel's own membership
     * field already holds id => name options for the same screen, so reuse them rather
     * than showing a bare number.
     *
     * @param array<int, array<string, mixed>> $valid Field definitions.
     * @param string                           $key   Native param key.
     * @param string                           $raw   Raw value.
     * @return string
     */
    private static function resolve_native_value(array $valid, $key, $raw)
    {
        if ('membership' !== $key) {
            return $raw;
        }

        foreach ($valid as $field) {
            $param = isset($field['param']) ? (string) $field['param'] : '';
            if (! preg_match('/_product$/', $param)) {
                continue;
            }
            $label = self::value_label($field, $raw);
            if ($label !== $raw) {
                return $label;
            }
        }

        return $raw;
    }

    /**
     * Build one chip for a from/to pair sharing a date_range_of group.
     *
     * @param array<int, array<string, mixed>> $valid   Field definitions.
     * @param string                           $group   date_range_of value.
     * @param array<string, mixed>             $request Request map.
     * @return array{label: string, value: string, params: array<int, string>}|null
     */
    private static function build_range_chip(array $valid, $group, array $request)
    {
        $label = $group;
        $from  = '';
        $to    = '';

        foreach ($valid as $field) {
            if (! isset($field['date_range_of']) || (string) $field['date_range_of'] !== $group) {
                continue;
            }
            $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
            $part  = isset($field['date_range_part']) ? (string) $field['date_range_part'] : '';
            if ('from' === $part) {
                $from = $param;
            } elseif ('to' === $part) {
                $to = $param;
            }
            if (isset($field['label']) && '' !== (string) $field['label']) {
                // Strip the " from" / " to" suffix the expanded fields carry.
                $label = trim(preg_replace('/\s+(from|to)$/i', '', (string) $field['label']));
            }
        }

        return self::range_chip_from_params($label, $from, $to, $request);
    }

    /**
     * Build one chip from an explicit from/to param pair.
     *
     * @param string               $label   Display label.
     * @param string               $from    From param name.
     * @param string               $to      To param name.
     * @param array<string, mixed> $request Request map.
     * @return array{label: string, value: string, params: array<int, string>}|null
     */
    private static function range_chip_from_params($label, $from, $to, array $request)
    {
        $from_val = ('' !== $from && isset($request[ $from ]) && is_scalar($request[ $from ]))
            ? trim((string) $request[ $from ])
            : '';
        $to_val   = ('' !== $to && isset($request[ $to ]) && is_scalar($request[ $to ]))
            ? trim((string) $request[ $to ])
            : '';

        if ('' === $from_val && '' === $to_val) {
            return null;
        }

        if ('' !== $from_val && '' !== $to_val) {
            /* translators: 1: start date, 2: end date. */
            $value = sprintf(__('%1$s to %2$s', 'admin-filters-for-memberpress'), $from_val, $to_val);
        } elseif ('' !== $from_val) {
            /* translators: %s: start date. */
            $value = sprintf(__('from %s', 'admin-filters-for-memberpress'), $from_val);
        } else {
            /* translators: %s: end date. */
            $value = sprintf(__('until %s', 'admin-filters-for-memberpress'), $to_val);
        }

        return [
            'label'  => $label,
            'value'  => $value,
            'params' => array_values(array_filter([ $from, $to ])),
        ];
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
        $valid = self::with_country_options($valid);

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

        echo '<div class="meprmf-active-filters">';
        printf(
            '<span class="meprmf-active-filters__label">%s</span>',
            esc_html__('Filtering by:', 'admin-filters-for-memberpress')
        );

        foreach ($chips as $chip) {
            $text = ('' !== $chip['value'])
                /* translators: 1: filter label, 2: filter value. */
                ? sprintf(__('%1$s: %2$s', 'admin-filters-for-memberpress'), $chip['label'], $chip['value'])
                : $chip['label'];

            printf(
                '<a class="meprmf-active-filters__chip" href="%1$s"><span class="meprmf-active-filters__chip-text">%2$s</span><span class="meprmf-active-filters__chip-x" aria-hidden="true">&times;</span><span class="screen-reader-text">%3$s</span></a>',
                esc_url(remove_query_arg($chip['params'])),
                esc_html($text),
                /* translators: %s: filter description. */
                esc_html(sprintf(__('Remove filter %s', 'admin-filters-for-memberpress'), $text))
            );
        }

        if (count($chips) > 1) {
            printf(
                '<a class="meprmf-active-filters__clear" href="%s">%s</a>',
                esc_url(remove_query_arg(array_unique($all_params))),
                esc_html__('Clear all', 'admin-filters-for-memberpress')
            );
        }

        echo '</div>';
    }

    /**
     * Fill country fields with the MemberPress country list so chips show names, not codes.
     *
     * @param array<int, array<string, mixed>> $valid Field definitions.
     * @return array<int, array<string, mixed>>
     */
    private static function with_country_options(array $valid)
    {
        if (! class_exists('MeprUtils') || ! method_exists('MeprUtils', 'countries')) {
            return $valid;
        }

        $countries = null;
        foreach ($valid as $i => $field) {
            $type = isset($field['type']) ? (string) $field['type'] : '';
            if ('country' !== $type || ! empty($field['options'])) {
                continue;
            }
            if (null === $countries) {
                $countries = MeprUtils::countries(true);
            }
            if (is_array($countries)) {
                $valid[ $i ]['options'] = $countries;
            }
        }

        return $valid;
    }
}
