<?php
/**
 * Admin footer debug output when WP_DEBUG is on.
 *
 * Renders predicate SQL for administrators only. Keep WP_DEBUG off in production.
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

        echo "\n<!-- Admin Filters for MemberPress debug: predicates=" . (int) count($all) . " -->\n";
        if (! empty($all)) {
            echo '<div class="notice notice-info meprmf-debug" style="margin:12px;">';
            echo '<p><strong>' . esc_html__('Admin Filters for MemberPress — debug (WP_DEBUG)', 'admin-filters-for-memberpress') . '</strong></p>';
            echo '<p class="description">' . esc_html__('SQL predicate fragments applied to this MemberPress list table.', 'admin-filters-for-memberpress') . '</p>';
            echo '<pre style="white-space:pre-wrap;max-height:240px;overflow:auto;">';
            foreach ($all as $i => $sql) {
                $line = trim((string) $sql);
                if ('' === $line) {
                    $line = esc_html__('(empty — $wpdb->prepare failed; check date filter SQL)', 'admin-filters-for-memberpress');
                }
                echo esc_html((string) ( $i + 1 )) . '. ' . esc_html($line) . "\n";
            }
            echo '</pre></div>';
        }
    }
}
