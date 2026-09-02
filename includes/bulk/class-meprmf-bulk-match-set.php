<?php
/**
 * The rows a bulk action would act on, read through MemberPress's own list-table query.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Unpaginated fetch of the currently filtered list, mapped to member ids.
 */
class Meprmf_Bulk_Match_Set
{

    /**
     * Fetch the full filtered set for one screen.
     *
     * Calls the same model method the screen's list table calls, with the argument shape
     * MemberPress's own CSV export uses (`$paged` and `$perpage` empty, so MeprDb::list_table
     * builds no LIMIT and no OFFSET). `mepr_list_table_args` runs on that call, so this
     * plugin's predicates land on it without a second SQL builder.
     *
     * @param Meprmf_Screen_Context $ctx    Screen context.
     * @param array<string, mixed>  $params Request params for MemberPress's own filters (typically $_GET).
     * @param string                $search Native search term, or empty.
     * @return array{rows: int, user_ids: array<int, int>}|null Null when the screen has no known model method.
     */
    public static function fetch(Meprmf_Screen_Context $ctx, array $params, $search = '')
    {
        $search = (string) $search;

        switch ($ctx->get_page()) {
            case Meprmf_Screen::PAGE_MEMBERS:
                $all      = MeprUser::list_table('user_login', 'ASC', '', $search, 'any', '', $params, false);
                $id_field = 'ID';
                break;
            case Meprmf_Screen::PAGE_TRANSACTIONS:
                $all      = MeprTransaction::list_table('created_at', 'ASC', '', $search, 'any', '', $params);
                $id_field = 'user_id';
                break;
            case Meprmf_Screen::PAGE_SUBSCRIPTIONS:
                $all      = MeprSubscription::subscr_table('created_at', 'ASC', '', $search, 'any', '', false, $params);
                $id_field = 'user_id';
                break;
            case Meprmf_Screen::PAGE_LIFETIMES:
                $all      = MeprSubscription::lifetime_subscr_table('created_at', 'ASC', '', $search, 'any', '', false, $params);
                $id_field = 'user_id';
                break;
            default:
                return null;
        }

        $results = ( is_array($all) && isset($all['results']) && is_array($all['results']) ) ? $all['results'] : [];

        $user_ids = [];
        foreach ($results as $row) {
            if (! is_object($row) || ! isset($row->{$id_field})) {
                continue;
            }
            $user_id = (int) $row->{$id_field};
            if ($user_id > 0) {
                $user_ids[ $user_id ] = $user_id;
            }
        }

        return [
            // The list-row count, which is the number the list and the CSV export show. Two
            // subscription rows for one member count as two rows and one unique member.
            'rows'     => count($results),
            'user_ids' => array_values($user_ids),
        ];
    }

    /**
     * Whether this plugin contributed any WHERE fragment to the last list-table query.
     *
     * Only meaningful straight after {@see fetch()}: Meprmf_Plugin::filter_list_table_args()
     * resets both builders at the top of every `mepr_list_table_args` pass, and it needs a real
     * model frame in the backtrace to run at all, so asking before the fetch always reads as
     * "no predicates".
     *
     * @return bool
     */
    public static function has_active_predicates()
    {
        $fragments = array_merge(
            (array) Meprmf_Predicate_Builder::get_last_fragments(),
            (array) Meprmf_Mepr_Predicate_Builder::get_last_fragments()
        );

        return ! empty($fragments);
    }
}
