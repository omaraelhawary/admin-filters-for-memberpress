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
        $passes         = Meprmf_Plugin::get_filter_pass_count();

        if (0 === $passes && null === $meta_fragments && null === $mepr_fragments) {
            return;
        }

        $meta_ns = (array) Meprmf_Predicate_Builder::get_last_fragment_ns();

        // [ line, per-fragment nanoseconds or null ]. The Mepr builder records no per-fragment
        // time, so its lines show none.
        $all = [];
        if (is_array($meta_fragments)) {
            foreach ($meta_fragments as $i => $sql) {
                $all[] = [ '[meta] ' . $sql, isset($meta_ns[ $i ]) ? (int) $meta_ns[ $i ] : null ];
            }
        }
        if (is_array($mepr_fragments)) {
            foreach ($mepr_fragments as $sql) {
                $all[] = [ '[mepr] ' . $sql, null ];
            }
        }

        $overhead_ms = self::ns_to_ms(Meprmf_Plugin::get_last_filter_overhead_ns());

        echo "\n<!-- Admin Filters for MemberPress debug: predicates=" . (int) count($all)
            . ' overhead_ms=' . esc_html($overhead_ms) . ' passes=' . (int) $passes . " -->\n";
        if ($passes > 0 || ! empty($all)) {
            echo '<div class="notice notice-info meprmf-debug" style="margin:12px;">';
            echo '<p><strong>' . esc_html__('Admin Filters for MemberPress — debug (WP_DEBUG)', 'admin-filters-for-memberpress') . '</strong></p>';
            echo '<p class="description">' . esc_html__('SQL predicate fragments applied to this MemberPress list table.', 'admin-filters-for-memberpress') . '</p>';
            if ($passes > 0) {
                // The fragment list below is the last pass; the total covers every pass this request,
                // so the pass count is printed with it.
                echo '<p class="description">filter overhead (plugin): ' . esc_html($overhead_ms) . ' ms'
                    . ' (' . esc_html((string) $passes) . ' '
                    . esc_html(1 === (int) $passes ? 'pass' : 'passes') . ')</p>';
            }
            if (! empty($all)) {
                echo '<pre style="white-space:pre-wrap;max-height:240px;overflow:auto;">';
                foreach ($all as $i => $row) {
                    $line = trim((string) $row[0]);
                    if ('' === $line) {
                        $line = esc_html__('(empty — $wpdb->prepare failed; check date filter SQL)', 'admin-filters-for-memberpress');
                    }
                    $suffix = null === $row[1] ? '' : ' [' . self::ns_to_ms($row[1]) . ' ms]';
                    echo esc_html((string) ( $i + 1 )) . '. ' . esc_html($line . $suffix) . "\n";
                }
                echo '</pre>';
            }
            echo '</div>';
        }
    }

    /**
     * Nanoseconds as a millisecond figure with three decimals.
     *
     * @param int $ns Nanoseconds.
     * @return string
     */
    private static function ns_to_ms($ns)
    {
        return number_format((int) $ns / 1000000, 3, '.', '');
    }
}
