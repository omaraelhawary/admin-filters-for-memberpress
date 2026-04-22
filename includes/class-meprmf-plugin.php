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
     * Boot hooks after MemberPress is available.
     *
     * @return void
     */
    public static function init()
    {
        // String callbacks preserve remove_action/remove_filter compatibility.
        add_action('mepr_table_controls_search', 'meprmf_render_meta_filters', 20, 2);
        add_filter('mepr_list_table_args', 'meprmf_filter_members_list_args', 10, 1);
        add_action('admin_menu', 'meprmf_register_admin_menu', 30);
        add_action('admin_init', 'meprmf_register_settings');
        add_action('admin_enqueue_scripts', 'meprmf_admin_enqueue_scripts');
        Meprmf_Debug_Panel::init();
    }

    /**
     * Applies EXISTS subqueries on wp_usermeta for active filters (Members screen only in Phase 0).
     *
     * @param array<int, string> $args WHERE fragments for MeprDb::list_table.
     * @return array<int, string>
     */
    public static function filter_list_table_args($args)
    {
        Meprmf_Predicate_Builder::reset_last_fragments();

        $ctx = Meprmf_Screen::detect();
        if (null === $ctx || ! $ctx->is_members()) {
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
     * Register submenu under MemberPress.
     *
     * @return void
     */
    public static function register_admin_menu()
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return;
        }

        add_submenu_page(
            'memberpress',
            __('Member list filters', 'memberpress-members-meta-filters'),
            __('Member list filters', 'memberpress-members-meta-filters'),
            MeprUtils::get_mepr_admin_capability(),
            'meprmf-settings',
            'meprmf_render_settings_page'
        );
    }

    /**
     * Load admin styles/scripts on relevant screens.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public static function admin_enqueue_scripts($hook_suffix)
    {
        if ('memberpress_page_meprmf-settings' === $hook_suffix) {
            wp_enqueue_style(
                'meprmf-admin-settings',
                meprmf_plugin_url('assets/meprmf-admin-settings.css'),
                [],
                MEPRMF_VERSION
            );
            wp_enqueue_script(
                'meprmf-admin-settings',
                meprmf_plugin_url('assets/meprmf-admin-settings.js'),
                [],
                MEPRMF_VERSION,
                true
            );
            return;
        }

        if (
            Meprmf_Capabilities::current_user_can_filter()
            && isset($_GET['page']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'memberpress-members' === sanitize_text_field(wp_unslash($_GET['page']))
        ) {
            wp_enqueue_style(
                'meprmf-members-toolbar',
                meprmf_plugin_url('assets/meprmf-members-toolbar.css'),
                [],
                MEPRMF_VERSION
            );
        }
    }
}
