<?php
/**
 * Plugin bootstrap: hooks and list-table integration.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class.
 */
class Meprmf_Plugin
{

    /**
     * Floating panel bodies deferred to admin_footer (MemberPress wraps hooks in a `<p>`;
     * block-level panel markup must not be printed there or tablenav / table layout breaks).
     *
     * @var array<int, array{0: array<int, array<string, mixed>>, 1: Meprmf_Screen_Context}>
     */
    private static $deferred_floating_panels = [];

    /**
     * Nanoseconds spent in apply_list_table_predicates() across this request.
     *
     * Accumulated, not overwritten: `mepr_list_table_args` can fire more than once per admin
     * request, so the pass count is reported alongside the total.
     *
     * @var int
     */
    private static $filter_overhead_ns = 0;

    /**
     * How many times apply_list_table_predicates() ran this request.
     *
     * @var int
     */
    private static $filter_passes = 0;

    /**
     * Total nanoseconds spent building this plugin's predicates on this request.
     *
     * Covers registry normalization, both predicate builders and apply_match_mode(). It does
     * not cover MemberPress's own list-table query, which runs after the filter returns.
     *
     * @return int
     */
    public static function get_last_filter_overhead_ns()
    {
        return self::$filter_overhead_ns;
    }

    /**
     * How many predicate passes ran this request (see {@see $filter_overhead_ns}).
     *
     * @return int
     */
    public static function get_filter_pass_count()
    {
        return self::$filter_passes;
    }

    /**
     * Admin page slugs that load floating / inline filter assets.
     *
     * @return array<int, string>
     */
    public static function get_meta_filters_admin_page_slugs()
    {
        return [
            Meprmf_Screen::PAGE_MEMBERS,
            Meprmf_Screen::PAGE_SUBSCRIPTIONS,
            Meprmf_Screen::PAGE_LIFETIMES,
            Meprmf_Screen::PAGE_TRANSACTIONS,
        ];
    }

    /**
     * Boot hooks after MemberPress is available.
     *
     * @return void
     */
    public static function init()
    {
        // String callbacks preserve remove_action/remove_filter compatibility.
        add_action('mepr_table_controls_search', 'meprmf_render_meta_filters', 20, 2);
        add_filter('mepr_list_table_args', 'meprmf_filter_members_list_args', 10, 1);
        add_action('admin_enqueue_scripts', 'meprmf_admin_enqueue_scripts');
        add_action('admin_footer', [ __CLASS__, 'print_deferred_floating_filter_panels' ], 5);
        Meprmf_Debug_Panel::init();
        Meprmf_Columns::init();
    }

    /**
     * Queue the floating panel DOM to print in admin_footer (see class doc for $deferred_floating_panels).
     *
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @param Meprmf_Screen_Context             $ctx   Screen context.
     * @return void
     */
    public static function queue_deferred_floating_filter_panel(array $valid, Meprmf_Screen_Context $ctx)
    {
        self::$deferred_floating_panels[] = [ $valid, $ctx ];
    }

    /**
     * Print queued panel markup and clear the queue.
     *
     * @return void
     */
    public static function print_deferred_floating_filter_panels()
    {
        if (empty(self::$deferred_floating_panels)) {
            return;
        }
        echo '<div id="meprmf-floating-panels-pool" class="meprmf-floating-panels-pool" hidden>';
        foreach (self::$deferred_floating_panels as $job) {
            Meprmf_Toolbar_Renderer::echo_floating_filter_panel_surface($job[0], $job[1]);
        }
        echo '</div>';
        self::$deferred_floating_panels = [];
    }

    /**
     * Whether the filter card UI is enabled for this screen (both legacy hook names preserved).
     *
     * Returning false now removes the filter UI entirely rather than falling back to the old
     * inline toolbar: since 2.1.0 the query-builder card is the only filter UI. MemberPress's
     * own search and "Filter by … Go" rows, and this plugin's chips, are unaffected.
     *
     * @param Meprmf_Screen_Context $ctx Context.
     * @return bool
     */
    public static function use_floating_filter_panel(Meprmf_Screen_Context $ctx)
    {
        $default = Meprmf_Settings::is_floating_panel_enabled();
        if ($ctx->is_members()) {
            /**
             * Whether the filter card is rendered on the Members list.
             *
             * @since 1.6.5
             * @param bool $enabled Default true; return false to render no filter UI.
             */
            $default = (bool) apply_filters('meprmf_use_floating_members_panel', $default);
        }

        /**
         * Whether the filter card is rendered for this list screen.
         *
         * @since 1.7.0
         * @param bool                  $enabled Default true; return false to render no filter UI.
         * @param Meprmf_Screen_Context $ctx     Screen context.
         */
        return (bool) apply_filters('meprmf_use_floating_meta_filters_panel', $default, $ctx);
    }

    /**
     * Applies EXISTS subqueries on wp_usermeta for active filters on supported list screens.
     *
     * @param array<int, string> $args WHERE fragments for MeprDb::list_table.
     * @return array<int, string>
     */
    public static function filter_list_table_args($args)
    {
        Meprmf_Predicate_Builder::reset_last_fragments();
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();

        $ctx = Meprmf_Screen::detect();
        if (null === $ctx || ! $ctx->supports_meta_filters_list()) {
            return $args;
        }
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return $args;
        }

        $list_ctx = Meprmf_Screen::detect_list_table_context();
        if (null !== $list_ctx && Meprmf_Subscription_Tabs::is_cross_tab_list_table_request($ctx, $list_ctx)) {
            if (! Meprmf_Screen::current_wp_screen_matches_context($ctx)) {
                return $args;
            }

            return self::apply_list_table_predicates($args, $list_ctx, $ctx);
        }

        if (! Meprmf_Screen::should_apply_list_table_predicates($ctx)) {
            return $args;
        }

        return self::apply_list_table_predicates($args, $ctx);
    }

    /**
     * Applies EXISTS subqueries on wp_usermeta for active filters on supported list screens.
     *
     * @param array<int, string>             $args       WHERE fragments for MeprDb::list_table.
     * @param Meprmf_Screen_Context          $predicate_ctx Context whose SQL shape the predicates target.
     * @param Meprmf_Screen_Context|null     $request_ctx   Admin page context supplying $_GET (defaults to predicate_ctx).
     * @return array<int, string>
     */
    private static function apply_list_table_predicates(array $args, Meprmf_Screen_Context $predicate_ctx, Meprmf_Screen_Context $request_ctx = null)
    {
        // Outer clock: the whole pass, so registry normalization and apply_match_mode() are inside
        // it. The inner try/finally keeps its own scope, because apply_match_mode() must read the
        // request with the cross-tab overrides already popped.
        $started = hrtime(true);

        try {
            if (null === $request_ctx) {
                $request_ctx = $predicate_ctx;
            }

            $overrides = [];
            if ($predicate_ctx->get_page() !== $request_ctx->get_page()) {
                $overrides = Meprmf_Subscription_Tabs::translate_request_params($request_ctx, $predicate_ctx);
            }

            if (! empty($overrides)) {
                Meprmf_Util::push_request_overrides($overrides);
            }

            try {
                $meta_valid = Meprmf_Filter_Registry::get_normalized_meta_fields_for_context($predicate_ctx);
                if (! empty($meta_valid)) {
                    $args = Meprmf_Predicate_Builder::append_usermeta_exists($args, $predicate_ctx, $meta_valid);
                }

                $core_valid = Meprmf_Filter_Registry::get_normalized_mepr_predicate_fields_for_context($predicate_ctx);
                if (! empty($core_valid)) {
                    $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists($args, $predicate_ctx, $core_valid);
                }
            } finally {
                if (! empty($overrides)) {
                    Meprmf_Util::pop_request_overrides();
                }
            }

            return self::apply_match_mode($args, $predicate_ctx);
        } finally {
            self::$filter_overhead_ns += (int) ( hrtime(true) - $started );
            ++self::$filter_passes;
        }
    }

    /**
     * Join this plugin's own fragments with OR when match=any.
     *
     * Only fragments this request's builders reported are touched: MemberPress core and
     * third-party fragments must keep their AND, or the list's own scoping breaks.
     *
     * @param array<int, string>    $args WHERE fragments.
     * @param Meprmf_Screen_Context $ctx  Screen context.
     * @return array<int, string>
     */
    public static function apply_match_mode(array $args, Meprmf_Screen_Context $ctx)
    {
        if ('any' !== Meprmf_Util::get_match_mode($ctx)) {
            return $args;
        }

        $pool = array_merge(
            (array) Meprmf_Predicate_Builder::get_last_fragments(),
            (array) Meprmf_Mepr_Predicate_Builder::get_last_fragments()
        );

        $kept     = [];
        $branches = [];
        foreach ($args as $fragment) {
            $found = array_search($fragment, $pool, true);
            if (false === $found) {
                $kept[] = $fragment;
                continue;
            }
            // Drop one occurrence only: two filters can produce the same fragment.
            unset($pool[ $found ]);
            $branches[] = '( ' . $fragment . ' )';
        }

        if (count($branches) < 2) {
            return $args;
        }

        $kept[] = '( ' . implode(' OR ', $branches) . ' )';

        return $kept;
    }

    /**
     * `.min` before extension when SCRIPT_DEBUG is off (matches WordPress core).
     *
     * @return string `''` or `'.min'`.
     */
    private static function admin_asset_suffix()
    {
        return (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
    }

    /**
     * Relative-window units for the "is in the last N …" control, as `{v, l}` pairs.
     *
     * @return array<int, array<string, string>>
     */
    private static function relative_unit_choices()
    {
        $out = [];
        foreach (Meprmf_Util::get_relative_unit_labels() as $unit => $label) {
            $out[] = [
                'v' => $unit,
                'l' => $label,
            ];
        }

        return $out;
    }

    /**
     * Load admin styles/scripts on relevant screens.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public static function admin_enqueue_scripts($hook_suffix)
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin screen slug for conditional assets; no form submission.
        if (empty($_GET['page'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = sanitize_text_field(wp_unslash($_GET['page']));
        if (! in_array($page, self::get_meta_filters_admin_page_slugs(), true)) {
            return;
        }

        $ctx = Meprmf_Screen::detect();
        if (null === $ctx || ! $ctx->supports_meta_filters_list()) {
            return;
        }

        $suffix = self::admin_asset_suffix();

        wp_enqueue_style(
            'meprmf-members-toolbar',
            meprmf_plugin_url("assets/meprmf-members-toolbar{$suffix}.css"),
            [],
            MEPRMF_VERSION
        );

        if (self::use_floating_filter_panel($ctx)) {
            wp_enqueue_script(
                'meprmf-members-floating-panel',
                meprmf_plugin_url("assets/meprmf-members-floating-panel{$suffix}.js"),
                [],
                MEPRMF_VERSION,
                true
            );
            // The match mode is a known param too, so Apply does not drop it from the URL. So is
            // the default-view suppressor, or a stale one would outlive the Apply that set it.
            $known = [ Meprmf_Util::MATCH_MODE_PARAM, Meprmf_Presets::SUPPRESS_PARAM ];
            foreach (Meprmf_Filter_Registry::get_normalized_fields_for_context($ctx) as $field) {
                foreach (Meprmf_Util::collect_field_request_params($field) as $p) {
                    if ('' !== $p) {
                        $known[] = $p;
                    }
                }
            }
            foreach (Meprmf_Native_Params::for_context($ctx) as $p) {
                $p = Meprmf_Util::sanitize_param((string) $p);
                if ('' !== $p) {
                    $known[] = $p;
                }
            }
            $known = array_values(array_unique($known));
            sort($known, SORT_STRING);
            $native = Meprmf_Native_Params::for_context($ctx);
            $tab_link = Meprmf_Subscription_Tabs::tab_link_config($ctx);
            wp_localize_script(
                'meprmf-members-floating-panel',
                'meprmfMembersFloating',
                [
                    'knownParams'          => $known,
                    'nativeParams'         => $native,
                    'storageId'            => $ctx->get_storage_id(),
                    'matchParam'           => Meprmf_Util::MATCH_MODE_PARAM,
                    'matchMode'            => Meprmf_Util::get_match_mode($ctx),
                    'subscriptionTab'      => $tab_link,
                    'catalog'              => Meprmf_Toolbar_Renderer::build_field_catalog(
                        Meprmf_Filter_Registry::get_normalized_fields_for_context($ctx)
                    ),
                    'groupLabels'          => Meprmf_Util::get_group_labels(),
                    'relativeUnits'        => self::relative_unit_choices(),
                    'presets'              => Meprmf_Presets::get_presets_for_screen($ctx->get_storage_id()),
                    'defaultView'          => Meprmf_Presets::get_default_view_id($ctx->get_storage_id()),
                    'pinnedViews'          => Meprmf_Presets::get_pinned_view_ids($ctx->get_storage_id()),
                    'suppressParam'        => Meprmf_Presets::SUPPRESS_PARAM,
                    'suppressValue'        => Meprmf_Presets::SUPPRESS_VALUE,
                    'presetsNonce'         => wp_create_nonce('meprmf_filter_presets'),
                    'ajaxUrl'              => admin_url('admin-ajax.php'),
                    'i18n'                 => [
                        'savePrompt'         => __('Preset name', 'admin-filters-for-memberpress'),
                        'saveError'          => __('Could not save the preset. Please try again.', 'admin-filters-for-memberpress'),
                        'noActiveFilters'    => __('Apply at least one filter before saving a preset.', 'admin-filters-for-memberpress'),
                        /* translators: %s: saved view name. */
                        'deleteViewConfirm'  => __('Delete the saved view “%s”? This removes it for everyone.', 'admin-filters-for-memberpress'),
                        /* translators: %s: saved view name. */
                        'deleteViewConfirmPrivate' => __('Delete your private view “%s”?', 'admin-filters-for-memberpress'),
                        'deleteViewError'    => __('Could not delete the saved view. Please try again.', 'admin-filters-for-memberpress'),
                        'savePrivatePrompt'  => __('Keep this view private to you? Cancel shares it with the other administrators.', 'admin-filters-for-memberpress'),
                        'savedViewsPlaceholder' => __('Saved views…', 'admin-filters-for-memberpress'),
                        'setDefaultView'     => __('Set as default', 'admin-filters-for-memberpress'),
                        'clearDefaultView'   => __('Clear default', 'admin-filters-for-memberpress'),
                        'defaultViewError'   => __('Could not change the default view. Please try again.', 'admin-filters-for-memberpress'),
                        'pinView'            => __('Pin to menu', 'admin-filters-for-memberpress'),
                        'unpinView'          => __('Unpin from menu', 'admin-filters-for-memberpress'),
                        'pinViewError'       => __('Could not change the pinned views. Please try again.', 'admin-filters-for-memberpress'),
                        'anyValue'           => __('Any value', 'admin-filters-for-memberpress'),
                        'valuePlaceholder'   => __('Type a value…', 'admin-filters-for-memberpress'),
                        'noValueNeeded'      => __('no value needed', 'admin-filters-for-memberpress'),
                        'andJoiner'          => __('and', 'admin-filters-for-memberpress'),
                        /* translators: %s: filter field label. */
                        'removeFilter'       => __('Remove %s filter', 'admin-filters-for-memberpress'),
                        /* translators: %s: filter field label. */
                        'operatorFor'        => __('Comparison for %s', 'admin-filters-for-memberpress'),
                        /* translators: %s: filter field label. */
                        'valueFor'           => __('Value for %s', 'admin-filters-for-memberpress'),
                        /* translators: %s: filter field label. */
                        'valueFromFor'       => __('%s from', 'admin-filters-for-memberpress'),
                        /* translators: %s: filter field label. */
                        'valueToFor'         => __('%s to', 'admin-filters-for-memberpress'),
                        /* translators: %s: filter field label. */
                        'windowAmountFor'    => __('%s window length', 'admin-filters-for-memberpress'),
                        /* translators: %s: filter field label. */
                        'windowUnitFor'      => __('%s window unit', 'admin-filters-for-memberpress'),
                        'noFilterMatches'    => __('No filters match.', 'admin-filters-for-memberpress'),
                        'opIs'               => __('is', 'admin-filters-for-memberpress'),
                        'kindChoice'         => __('choice', 'admin-filters-for-memberpress'),
                        'kindText'           => __('text', 'admin-filters-for-memberpress'),
                        'kindDate'           => __('date', 'admin-filters-for-memberpress'),
                        'kindNumber'         => __('number', 'admin-filters-for-memberpress'),
                    ],
                ]
            );

            self::enqueue_bulk_actions_script($suffix);
        }
    }

    /**
     * Bulk-action modal script, for admins allowed to write to the filtered set.
     *
     * Gated on the same capability as the AJAX handler and the toolbar button, so an admin who
     * may filter but not write in bulk never loads it.
     *
     * @since 2.3.0
     * @param string $suffix Asset suffix from {@see admin_asset_suffix()}.
     * @return void
     */
    private static function enqueue_bulk_actions_script($suffix)
    {
        if (! Meprmf_Capabilities::current_user_can_bulk_actions()) {
            return;
        }

        wp_enqueue_script(
            'meprmf-bulk-actions',
            meprmf_plugin_url("assets/meprmf-bulk-actions{$suffix}.js"),
            [ 'meprmf-members-floating-panel' ],
            MEPRMF_VERSION,
            true
        );

        wp_localize_script(
            'meprmf-bulk-actions',
            'meprmfBulkActions',
            [
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce(Meprmf_Bulk::NONCE_ACTION),
                'batchSize' => Meprmf_Bulk_Runner::batch_size(),
                'i18n'      => [
                    'title'        => __('Bulk actions on the filtered set', 'admin-filters-for-memberpress'),
                    'action'       => __('Set user meta on every member in the filtered list.', 'admin-filters-for-memberpress'),
                    'metaKey'      => __('Meta key', 'admin-filters-for-memberpress'),
                    'metaValue'    => __('Meta value', 'admin-filters-for-memberpress'),
                    'preview'      => __('Preview', 'admin-filters-for-memberpress'),
                    'run'          => __('Run', 'admin-filters-for-memberpress'),
                    'cancel'       => __('Cancel', 'admin-filters-for-memberpress'),
                    'close'        => __('Close', 'admin-filters-for-memberpress'),
                    'previewFirst' => __('Preview the set before running.', 'admin-filters-for-memberpress'),
                    'working'      => __('Working…', 'admin-filters-for-memberpress'),
                    /* translators: 1: matching list rows, 2: unique members. */
                    'matchCount'   => __('%1$d matching rows / %2$d unique members', 'admin-filters-for-memberpress'),
                    /* translators: 1: meta key, 2: meta value. */
                    'summary'      => __('Will set %1$s to %2$s on every one of those members.', 'admin-filters-for-memberpress'),
                    /* translators: %d: number of member ids listed. */
                    'previewIds'   => __('Dry run, nothing written. First %d member ids:', 'admin-filters-for-memberpress'),
                    /* translators: %d: number of members. */
                    'running'      => __('Running on %d members…', 'admin-filters-for-memberpress'),
                    /* translators: 1: current batch, 2: total batches, 3: members written so far, 4: total members. */
                    'batchProgress' => __('Batch %1$d of %2$d: %3$d of %4$d members written.', 'admin-filters-for-memberpress'),
                    /* translators: 1: members written, 2: members processed. */
                    'done'         => __('Done: %1$d of %2$d members written.', 'admin-filters-for-memberpress'),
                    /* translators: 1: members written, 2: member id the run stopped on. */
                    'stopped'      => __('Stopped: %1$d members written, then the write for member %2$d failed.', 'admin-filters-for-memberpress'),
                    'error'        => __('Could not run the bulk action. Please try again.', 'admin-filters-for-memberpress'),
                ],
            ]
        );
    }
}
