<?php
/**
 * Admin footer debug output when WP_DEBUG is on.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Debug panel for list-table predicate fragments.
 */
class Meprmf_Debug_Panel
{

    /**
     * Register admin_footer hook.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_footer', [ __CLASS__, 'maybe_render' ], 999);
    }

    /**
     * Print debug block on Members list when WP_DEBUG and capability.
     *
     * @return void
     */
    public static function maybe_render()
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }
        if (! Meprmf_Screen::is_meta_filters_admin_list_request()) {
            return;
        }
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return;
        }

        $meta_fragments = Meprmf_Predicate_Builder::get_last_fragments();
        $mepr_fragments = Meprmf_Mepr_Predicate_Builder::get_last_fragments();

        if (null === $meta_fragments && null === $mepr_fragments) {
            return;
        }

        $all = [];
        if (is_array($meta_fragments)) {
            foreach ($meta_fragments as $sql) {
                $all[] = '[meta] ' . $sql;
            }
        }
        if (is_array($mepr_fragments)) {
            foreach ($mepr_fragments as $sql) {
                $all[] = '[mepr] ' . $sql;
            }
        }

        echo "\n<!-- Meprmf Debug: predicates=" . (int) count($all) . " -->\n";
        if (! empty($all)) {
            echo '<div class="notice notice-info meprmf-debug" style="margin:12px;"><p><strong>Meprmf (WP_DEBUG)</strong></p><pre style="white-space:pre-wrap;max-height:240px;overflow:auto;">';
            foreach ($all as $i => $sql) {
                echo esc_html((string) ( $i + 1 )) . '. ' . esc_html($sql) . "\n";
            }
            echo '</pre></div>';
        }
    }
}
