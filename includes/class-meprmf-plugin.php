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
     * Whether floating panel UI is enabled for this screen (Members hook preserved).
     *
     * @param Meprmf_Screen_Context $ctx Context.
     * @return bool
     */
    public static function use_floating_filter_panel(Meprmf_Screen_Context $ctx)
    {
        $default = true;
        if ($ctx->is_members()) {
            $default = (bool) apply_filters('meprmf_use_floating_members_panel', $default);
        }
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

        $ctx = Meprmf_Screen::detect();
        if (null === $ctx || ! $ctx->supports_meta_filters_list()) {
            return $args;
        }
        if (! Meprmf_Screen::current_wp_screen_matches_context($ctx)) {
            return $args;
        }
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return $args;
        }

        $valid = Meprmf_Filter_Registry::get_normalized_fields_for_context($ctx);
        if (empty($valid)) {
            return $args;
        }

        return Meprmf_Predicate_Builder::append_usermeta_exists($args, $ctx, $valid);
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
            $known = [];
            foreach (Meprmf_Filter_Registry::get_normalized_fields_for_context($ctx) as $field) {
                $p = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
                if ('' !== $p) {
                    $known[] = $p;
                }
            }
            sort($known, SORT_STRING);
            wp_localize_script(
                'meprmf-members-floating-panel',
                'meprmfMembersFloating',
                [
                    'knownParams'          => $known,
                    'knownParamsSignature' => md5(implode('|', $known)),
                    'storageId'            => $ctx->get_storage_id(),
                ]
            );
        }
    }
}
