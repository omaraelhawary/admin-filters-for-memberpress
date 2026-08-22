<?php
/**
 * Query-builder filter card for MemberPress list table toolbars.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders the filter card shell (toolbar / body / footer bands).
 *
 * The rows inside the card are built in JS from the field catalog this class exports, so the
 * row markup has exactly one implementation and cannot drift between PHP and JS.
 */
class Meprmf_Toolbar_Renderer
{

    /**
     * Saved views offered as quick-filter pills in the empty state.
     *
     * @var int
     */
    const EMPTY_STATE_PILL_LIMIT = 4;

    /**
     * Field catalog for the add-filter popover and the row controls.
     *
     * Pure: everything comes from the passed field definitions, so the grouping and operator
     * rules are testable without a request. A from/to (or min/max) pair collapses into one
     * entry, because one row drives both of its params.
     *
     * @since 2.1.0
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @return array<int, array<string, mixed>>
     */
    public static function build_field_catalog(array $valid)
    {
        $groups = [];

        foreach ($valid as $field) {
            $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
            if ('' === $param) {
                continue;
            }
            $base = Meprmf_Util::range_base_param($field);
            if ('' === $base) {
                continue;
            }
            $groups[ $base ][] = $field;
        }

        $catalog = [];

        foreach ($groups as $base => $fields) {
            $first = $fields[0];
            $type  = isset($first['type']) ? (string) $first['type'] : 'text';
            $kind  = self::field_kind($type);
            // A pair has no single value param: its value(s) live in the two bound params.
            $pair  = count($fields) > 1 || 'date_range' === $type;

            $params = [];
            $op     = Meprmf_Util::operator_param_name($base);
            if ('' !== $op) {
                $params['op'] = $op;
            }

            if ($pair) {
                $bounds = Meprmf_Util::date_range_param_names($base);
                foreach ($fields as $part) {
                    $slot = self::range_slot($part);
                    $name = Meprmf_Util::sanitize_param(isset($part['param']) ? $part['param'] : '');
                    if ('' !== $slot && '' !== $name) {
                        $params[ $slot ] = $name;
                    }
                }
                // An unexpanded date_range field owns both bounds itself.
                if (! isset($params['from']) && '' !== $bounds['from']) {
                    $params['from'] = $bounds['from'];
                }
                if (! isset($params['to']) && '' !== $bounds['to']) {
                    $params['to'] = $bounds['to'];
                }
            } else {
                $params['value'] = Meprmf_Util::sanitize_param(isset($first['param']) ? $first['param'] : '');
                if ('date' === $kind) {
                    // `is between` on a single date resolves through the same bounds as a pair.
                    $bounds = Meprmf_Util::date_range_param_names($base);
                    if ('' !== $bounds['from']) {
                        $params['from'] = $bounds['from'];
                    }
                    if ('' !== $bounds['to']) {
                        $params['to'] = $bounds['to'];
                    }
                }
            }

            if ('date' === $kind) {
                $relative = Meprmf_Util::relative_param_names($base);
                if ('' !== $relative['n']) {
                    $params['n'] = $relative['n'];
                }
                if ('' !== $relative['unit']) {
                    $params['u'] = $relative['unit'];
                }
            }

            $entry = [
                'param' => $base,
                'label' => self::entry_label($fields, $base),
                'group' => Meprmf_Util::field_group($first),
                'kind'  => $kind,
                'pair'  => $pair,
                'ops'   => self::operator_options($first, $pair, $kind),
                'params' => $params,
            ];

            if ('choice' === $kind) {
                $entry['options'] = self::choice_options($first);
            }
            if (! empty($first['unit'])) {
                $entry['unit'] = (string) $first['unit'];
            }

            $catalog[] = $entry;
        }

        /**
         * Field catalog handed to the query-builder UI.
         *
         * @since 2.1.0
         * @param array<int, array<string, mixed>> $catalog Catalog entries.
         * @param array<int, array<string, mixed>> $valid   Normalized field definitions.
         */
        return apply_filters('meprmf_field_catalog', $catalog, $valid);
    }

    /**
     * How many catalog entries the current request has a filter for.
     *
     * Counts entries, not params, so a two-bound "is between" is one active filter — the same
     * arithmetic the JS row model does, which keeps the badge stable across the JS handover.
     *
     * @since 2.1.0
     * @param array<int, array<string, mixed>> $catalog Catalog from {@see build_field_catalog()}.
     * @return int
     */
    public static function count_active_entries(array $catalog)
    {
        $count = 0;

        foreach ($catalog as $entry) {
            $params = isset($entry['params']) && is_array($entry['params']) ? $entry['params'] : [];

            // "is empty" / "is not empty" constrain the list with no value at all.
            if (Meprmf_Util::has_valueless_operator((string) $entry['param'])) {
                ++$count;
                continue;
            }

            foreach ([ 'value', 'from', 'to', 'n' ] as $slot) {
                if (empty($params[ $slot ])) {
                    continue;
                }
                if ('' !== Meprmf_Util::get_request_value($params[ $slot ])) {
                    ++$count;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Element ids for one screen's card.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<string, string>
     */
    private static function card_ids(Meprmf_Screen_Context $ctx)
    {
        $sid = $ctx->get_storage_id();

        return [
            // Kept from the pre-2.1 panel so bookmarked anchors and `aria-controls` targets survive.
            'panel'   => 'meprmf-filter-panel--' . $sid,
            'toggle'  => 'meprmf-filter-toggle--' . $sid,
            'body'    => 'meprmf-qb-body--' . $sid,
            'popover' => 'meprmf-qb-popover--' . $sid,
            'search'  => 'meprmf-qb-search--' . $sid,
            'views'   => 'meprmf-qb-views--' . $sid,
        ];
    }

    /**
     * Renders the chip row and the card mount point.
     *
     * @param string $search_term Unused (MemberPress hook signature).
     * @param int    $perpage     Unused (MemberPress hook signature).
     * @return void
     */
    public static function render($search_term, $perpage)
    {
        unset($search_term, $perpage);

        $ctx = Meprmf_Screen::detect();
        if (null === $ctx || ! $ctx->supports_meta_filters_list() || ! Meprmf_Capabilities::current_user_can_filter()) {
            return;
        }

        $valid = Meprmf_Filter_Registry::get_normalized_fields_for_context($ctx);
        if (empty($valid)) {
            return;
        }

        /**
         * Whether to show the removable active-filter chip row above the list.
         *
         * @since 2.1.0
         * @param bool                  $show Default true.
         * @param Meprmf_Screen_Context $ctx  Screen context.
         */
        if (apply_filters('meprmf_show_active_filter_chips', true, $ctx)) {
            Meprmf_Active_Filters::render($valid, $ctx);
        }

        // The off switch also stops the assets from loading, so bail before the mount point:
        // an unmounted card would sit hidden in the footer with nothing to bring it to life.
        if (! Meprmf_Plugin::use_floating_filter_panel($ctx)) {
            return;
        }

        self::render_floating_filter_panel($valid, $ctx);
    }

    /**
     * Card mount point. The card itself is deferred — see Meprmf_Plugin::print_deferred_floating_filter_panels().
     *
     * MemberPress fires this hook inside `<p class="mepr-search-box">`, where block markup would
     * break the tablenav, so only an empty phrasing-level anchor is printed here.
     *
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @param Meprmf_Screen_Context            $ctx   Current screen context.
     * @return void
     */
    public static function render_floating_filter_panel(array $valid, Meprmf_Screen_Context $ctx)
    {
        $ids = self::card_ids($ctx);

        printf(
            '<span class="meprmf-qb-anchor" data-meprmf-panel-id="%s" hidden></span>',
            esc_attr($ids['panel'])
        );

        Meprmf_Plugin::queue_deferred_floating_filter_panel($valid, $ctx);
    }

    /**
     * Backward-compatible alias for the Members screen only.
     *
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @return void
     */
    public static function render_members_floating_panel(array $valid)
    {
        self::render_floating_filter_panel(
            $valid,
            new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID')
        );
    }

    /**
     * The card surface: printed in the admin_footer pool, then moved above the list by JS.
     *
     * Stays `hidden` until JS mounts it: with scripting off the rows, the popover and Apply are
     * all inert, so showing a dead card would be worse than leaving MemberPress's own search and
     * "Filter by … Go" controls (plus this plugin's server-rendered chips) to do the work.
     *
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @param Meprmf_Screen_Context            $ctx   Current screen context.
     * @return void
     */
    public static function echo_floating_filter_panel_surface(array $valid, Meprmf_Screen_Context $ctx)
    {
        $ids     = self::card_ids($ctx);
        $catalog = self::build_field_catalog($valid);
        $active  = self::count_active_entries($catalog);
        $mode    = Meprmf_Util::get_match_mode($ctx);

        printf(
            '<div id="%1$s" class="meprmf-qb" role="region" aria-labelledby="%2$s" data-meprmf-screen="%3$s" hidden>',
            esc_attr($ids['panel']),
            esc_attr($ids['toggle']),
            esc_attr($ctx->get_storage_id())
        );

        self::echo_toolbar_band($ids, $ctx, count($catalog), $active);
        self::echo_body_band($ids, $ctx, $mode);
        self::echo_footer_band();

        echo '</div>';
    }

    /**
     * Toolbar band: disclosure, add-filter popover, saved views.
     *
     * @param array<string, string> $ids     Element ids.
     * @param Meprmf_Screen_Context $ctx     Screen context.
     * @param int                   $n_field Catalog size, for the popover search placeholder.
     * @param int                   $active  Active filter count, for the disclosure badge.
     * @return void
     */
    private static function echo_toolbar_band(array $ids, Meprmf_Screen_Context $ctx, $n_field, $active)
    {
        echo '<div class="meprmf-qb__toolbar">';

        printf(
            '<button type="button" class="meprmf-qb__disclosure" id="%1$s" aria-expanded="true" aria-controls="%2$s">',
            esc_attr($ids['toggle']),
            esc_attr($ids['body'])
        );
        echo '<span class="meprmf-qb__caret" aria-hidden="true">&#9662;</span>';
        echo '<span class="meprmf-qb__disclosure-label">' . esc_html__('Filters', 'admin-filters-for-memberpress') . '</span>';
        printf(
            '<span class="meprmf-qb__count" data-meprmf-count aria-label="%1$s"%2$s>%3$d</span>',
            esc_attr(
                sprintf(
                    /* translators: %d: number of active filters. */
                    _n('%d active filter', '%d active filters', (int) $active, 'admin-filters-for-memberpress'),
                    (int) $active
                )
            ),
            $active > 0 ? '' : ' hidden',
            (int) $active
        );
        echo '</button>';

        echo '<span class="meprmf-qb__add-wrap">';
        printf(
            '<button type="button" class="meprmf-qb__add" aria-expanded="false" aria-haspopup="dialog" aria-controls="%s">',
            esc_attr($ids['popover'])
        );
        echo '<span class="meprmf-qb__add-glyph" aria-hidden="true">+</span> ';
        echo esc_html__('Add filter', 'admin-filters-for-memberpress');
        echo '</button>';

        printf(
            '<div class="meprmf-qb__popover" id="%1$s" role="dialog" aria-label="%2$s" hidden>',
            esc_attr($ids['popover']),
            esc_attr__('Add filter', 'admin-filters-for-memberpress')
        );
        echo '<div class="meprmf-qb__popover-search">';
        printf(
            '<label class="screen-reader-text" for="%1$s">%2$s</label>',
            esc_attr($ids['search']),
            esc_html__('Search filters', 'admin-filters-for-memberpress')
        );
        printf(
            '<input type="search" class="meprmf-qb__search" id="%1$s" autocomplete="off" placeholder="%2$s" />',
            esc_attr($ids['search']),
            esc_attr(
                sprintf(
                    /* translators: %d: number of available filter fields. */
                    _n('Search %d filter…', 'Search %d filters…', (int) $n_field, 'admin-filters-for-memberpress'),
                    (int) $n_field
                )
            )
        );
        echo '</div>';
        echo '<div class="meprmf-qb__popover-list" data-meprmf-popover-list></div>';
        echo '</div>';
        echo '</span>';

        echo '<span class="meprmf-qb__divider" aria-hidden="true"></span>';

        printf(
            '<label class="screen-reader-text" for="%1$s">%2$s</label>',
            esc_attr($ids['views']),
            esc_html__('Saved views', 'admin-filters-for-memberpress')
        );
        printf('<select class="meprmf-qb__views" id="%s" data-meprmf-views>', esc_attr($ids['views']));
        printf('<option value="">%s</option>', esc_html__('Saved views…', 'admin-filters-for-memberpress'));
        foreach (self::screen_presets($ctx) as $preset) {
            // `label` marks a private view; it is built server-side so this list and the JS
            // re-render after a save cannot word the same view differently.
            printf(
                '<option value="%1$s">%2$s</option>',
                esc_attr((string) $preset['id']),
                esc_html(isset($preset['label']) ? (string) $preset['label'] : (string) $preset['name'])
            );
        }
        echo '</select>';

        // Presets are site-wide and capped per screen, so a view has to be removable; stays
        // hidden until JS knows which view (if any) the current URL is.
        printf(
            '<button type="button" class="button-link meprmf-qb__delete-view" data-meprmf-delete-view hidden>%s</button>',
            esc_html__('Delete view', 'admin-filters-for-memberpress')
        );

        // Which view this screen opens with is per admin, so the button's wording is settled by
        // JS once it knows which view is selected.
        printf(
            '<button type="button" class="button-link meprmf-qb__default-view" data-meprmf-default-view hidden>%s</button>',
            esc_html__('Set as default', 'admin-filters-for-memberpress')
        );

        echo '<span class="meprmf-qb__spacer"></span>';
        echo '<span class="meprmf-qb__chips" data-meprmf-chips hidden></span>';
        echo '</div>';
    }

    /**
     * Body band: match toggle, rows (JS-rendered), empty state.
     *
     * @param array<string, string> $ids  Element ids.
     * @param Meprmf_Screen_Context $ctx  Screen context.
     * @param string                $mode Applied match mode (`all`|`any`).
     * @return void
     */
    private static function echo_body_band(array $ids, Meprmf_Screen_Context $ctx, $mode)
    {
        printf('<div class="meprmf-qb__body" id="%s">', esc_attr($ids['body']));

        echo '<div class="meprmf-qb__match" data-meprmf-match hidden>';
        echo '<span class="meprmf-qb__match-label" id="' . esc_attr($ids['body']) . '-match">';
        echo esc_html__('Match', 'admin-filters-for-memberpress');
        echo '</span>';
        printf(
            '<span class="meprmf-qb__segments" role="radiogroup" aria-labelledby="%s-match">',
            esc_attr($ids['body'])
        );
        $segments = [
            'all' => __('all filters', 'admin-filters-for-memberpress'),
            'any' => __('any filter', 'admin-filters-for-memberpress'),
        ];
        foreach ($segments as $value => $label) {
            $is_selected = ($value === $mode);
            printf(
                '<button type="button" class="meprmf-qb__segment%1$s" role="radio" aria-checked="%2$s" data-meprmf-match-mode="%3$s">%4$s</button>',
                $is_selected ? ' is-selected' : '',
                $is_selected ? 'true' : 'false',
                esc_attr($value),
                esc_html($label)
            );
        }
        echo '</span>';
        echo '</div>';

        echo '<div class="meprmf-qb__rows" data-meprmf-rows></div>';

        echo '<div class="meprmf-qb__empty" data-meprmf-empty>';
        echo '<p class="meprmf-qb__empty-text">' . esc_html(self::empty_state_text($ctx)) . '</p>';
        self::echo_quick_filter_pills($ctx);
        echo '</div>';

        echo '</div>';
    }

    /**
     * Footer band: Apply, Clear all, Save as view, pending-changes status.
     *
     * @return void
     */
    private static function echo_footer_band()
    {
        echo '<div class="meprmf-qb__footer">';
        printf(
            '<button type="button" class="button button-primary meprmf-qb__apply" data-meprmf-apply>%s</button>',
            esc_html__('Apply filters', 'admin-filters-for-memberpress')
        );
        printf(
            '<button type="button" class="button-link meprmf-qb__clear" data-meprmf-clear>%s</button>',
            esc_html__('Clear all', 'admin-filters-for-memberpress')
        );
        printf(
            '<button type="button" class="button-link meprmf-qb__save-view" data-meprmf-save-view>%s</button>',
            esc_html__('Save as view', 'admin-filters-for-memberpress')
        );
        printf(
            '<span class="meprmf-qb__status" data-meprmf-status role="status" hidden>%s</span>',
            esc_html__('Unapplied changes', 'admin-filters-for-memberpress')
        );
        echo '</div>';
    }

    /**
     * Quick-filter pills: the first few real saved views for this screen.
     *
     * Nothing is printed when the screen has no saved views — an invented pill would promise a
     * filter that does not exist.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return void
     */
    private static function echo_quick_filter_pills(Meprmf_Screen_Context $ctx)
    {
        $presets = array_slice(self::screen_presets($ctx), 0, self::EMPTY_STATE_PILL_LIMIT);
        if (empty($presets)) {
            return;
        }

        echo '<div class="meprmf-qb__pills" data-meprmf-pills>';
        foreach ($presets as $preset) {
            printf(
                '<button type="button" class="meprmf-qb__pill" data-meprmf-preset-id="%1$s">%2$s</button>',
                esc_attr((string) $preset['id']),
                esc_html((string) $preset['name'])
            );
        }
        echo '</div>';
    }

    /**
     * Saved views for one screen, skipping rows with no id or name.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<int, array<string, mixed>>
     */
    private static function screen_presets(Meprmf_Screen_Context $ctx)
    {
        $out = [];
        foreach (Meprmf_Presets::get_presets_for_screen($ctx->get_storage_id()) as $preset) {
            if (empty($preset['id']) || empty($preset['name'])) {
                continue;
            }
            $out[] = $preset;
        }

        return $out;
    }

    /**
     * Empty-state sentence, naming the rows of the current list.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return string
     */
    private static function empty_state_text(Meprmf_Screen_Context $ctx)
    {
        if ($ctx->is_transactions()) {
            return __('No filters yet — showing all transactions.', 'admin-filters-for-memberpress');
        }
        if ($ctx->is_subscriptions_recurring() || $ctx->is_lifetimes()) {
            return __('No filters yet — showing all subscriptions.', 'admin-filters-for-memberpress');
        }

        return __('No filters yet — showing all members.', 'admin-filters-for-memberpress');
    }

    /**
     * Value-control family for a field type.
     *
     * @param string $type Field type.
     * @return string `choice`|`text`|`date`|`number`
     */
    private static function field_kind($type)
    {
        switch ($type) {
            case 'date':
            case 'date_range':
                return 'date';
            case 'number':
                return 'number';
            case 'select':
            case 'country':
            case 'checkbox':
                return 'choice';
            default:
                return 'text';
        }
    }

    /**
     * Which bound of a pair a field is, normalised to from/to.
     *
     * @param array<string, mixed> $field Field definition.
     * @return string `from`|`to`|`''`
     */
    private static function range_slot(array $field)
    {
        $part = isset($field['range_part']) ? (string) $field['range_part'] : '';
        if ('' === $part && isset($field['date_range_part'])) {
            $part = (string) $field['date_range_part'];
        }

        if ('from' === $part || 'min' === $part) {
            return 'from';
        }
        if ('to' === $part || 'max' === $part) {
            return 'to';
        }

        return '';
    }

    /**
     * Row label for a catalog entry.
     *
     * A pair reads as one row, so its label is the part the two halves share:
     * "Registered (from)" + "Registered (to)" is "Registered".
     *
     * @param array<int, array<string, mixed>> $fields Fields sharing one base param.
     * @param string                           $base   Base param, as a last-resort label.
     * @return string
     */
    private static function entry_label(array $fields, $base)
    {
        $labels = [];
        foreach ($fields as $field) {
            $label = isset($field['label']) ? trim((string) $field['label']) : '';
            if ('' !== $label) {
                $labels[] = $label;
            }
        }

        if (empty($labels)) {
            return $base;
        }

        $shared = array_shift($labels);
        foreach ($labels as $label) {
            $shared = self::shared_label_prefix($shared, $label);
        }

        return '' !== $shared ? $shared : $base;
    }

    /**
     * Longest leading run of characters two labels share, trimmed of dangling punctuation.
     *
     * Splits on characters rather than bytes so a multibyte label is never cut mid-sequence.
     *
     * @param string $a First label.
     * @param string $b Second label.
     * @return string
     */
    private static function shared_label_prefix($a, $b)
    {
        $a_chars = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $b_chars = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($a_chars) || ! is_array($b_chars)) {
            return $a;
        }

        $shared = '';
        $len    = min(count($a_chars), count($b_chars));
        for ($i = 0; $i < $len; $i++) {
            if ($a_chars[ $i ] !== $b_chars[ $i ]) {
                break;
            }
            $shared .= $a_chars[ $i ];
        }

        // Never end mid-word: "Total spent (min)" and "Total spent (max)" diverge inside a word
        // and share "Total spent (m", so the fragment after the last boundary has to go.
        if ($i < count($a_chars) && $i < count($b_chars)) {
            $cut = preg_replace('/[\p{L}\p{N}]+$/u', '', $shared);
            if (is_string($cut) && '' !== trim($cut)) {
                $shared = $cut;
            }
        }

        $shared = trim($shared);
        $shared = rtrim($shared, " \t([{-–—/,:;");
        $shared = trim($shared);

        return '' !== $shared ? $shared : $a;
    }

    /**
     * Operators offered in one row, as `{v, l}` pairs for the `<select>`.
     *
     * Narrows the engine's list to what this row can actually express, so the selector never
     * offers a comparison that would be silently ignored.
     *
     * @param array<string, mixed> $field Field definition (first part of the pair).
     * @param bool                 $pair  Whether the entry drives two bound params.
     * @param string               $kind  Value-control family.
     * @return array<int, array<string, string>>
     */
    private static function operator_options(array $field, $pair, $kind)
    {
        $tokens = Meprmf_Util::get_operators_for_field($field);

        // MemberPress-column and native params are read as plain values: the SQL builders never
        // look at the operator for them, so only `is` would be truthful. Their date and number
        // pairs are the exception — those do go through the operator-aware range resolver.
        if (! empty($field['source']) && 'date' !== $kind && 'number' !== $kind) {
            $tokens = array_intersect($tokens, [ 'is' ]);
        }

        // The numeric pair is two independent bounds (`>= min`, `<= max`); it has no param that
        // could carry an equality, so `is` / `is not` are not expressible.
        if ('number' === $kind && $pair) {
            $tokens = array_intersect($tokens, [ 'at_least', 'at_most', 'between' ]);
        }

        // One `<select>` cannot express a list of values.
        if ('choice' === $kind) {
            $tokens = array_diff($tokens, [ 'is_one_of' ]);
        }

        $labels = Meprmf_Util::get_operator_labels();
        $out    = [];
        foreach ($tokens as $token) {
            $out[] = [
                'v' => (string) $token,
                'l' => isset($labels[ $token ]) ? (string) $labels[ $token ] : (string) $token,
            ];
        }

        return $out;
    }

    /**
     * Options for a choice-kind entry, as `{v, l}` pairs.
     *
     * @param array<string, mixed> $field Field definition.
     * @return array<int, array<string, string>>
     */
    private static function choice_options(array $field)
    {
        $type = isset($field['type']) ? (string) $field['type'] : '';
        $out  = [];

        // Both guards matter: the chip row resolves country codes through this catalog, so a
        // MemberPress without the country list has to degrade to raw codes rather than fatal.
        if ('country' === $type && class_exists('MeprUtils') && method_exists('MeprUtils', 'countries')) {
            foreach (MeprUtils::countries(true) as $code => $name) {
                $out[] = [
                    'v' => (string) $code,
                    'l' => (string) $name,
                ];
            }

            return $out;
        }

        if ('checkbox' === $type) {
            return [
                [
                    'v' => '1',
                    'l' => __('Checked', 'admin-filters-for-memberpress'),
                ],
            ];
        }

        if (! empty($field['options']) && is_array($field['options'])) {
            foreach ($field['options'] as $value => $label) {
                $out[] = [
                    'v' => (string) $value,
                    'l' => (string) $label,
                ];
            }
        }

        return $out;
    }
}
