<?php
/**
 * Members list aggregate / activity filter field definitions.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Filters on mepr_members aggregates and WordPress user registration / login data.
 */
class Meprmf_Members_Activity_Provider
{

    /**
     * Activity filter fields for the Members list.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<int, array<string, mixed>>
     */
    public static function get_activity_fields_for_context(Meprmf_Screen_Context $ctx)
    {
        if (! $ctx->is_members()) {
            return [];
        }

        $fields = [
            [
                'param'     => 'mpm_registered_from',
                'label'     => __('Registered (from)', 'admin-filters-for-memberpress'),
                'type'      => 'date',
                'source'    => 'mepr_member',
                'predicate' => 'registered_from',
                'group'     => Meprmf_Util::GROUP_DATES,
                'range_of'   => 'mpm_registered',
                'range_part' => 'from',
            ],
            [
                'param'     => 'mpm_registered_to',
                'label'     => __('Registered (to)', 'admin-filters-for-memberpress'),
                'type'      => 'date',
                'source'    => 'mepr_member',
                'predicate' => 'registered_to',
                'group'     => Meprmf_Util::GROUP_DATES,
                'range_of'   => 'mpm_registered',
                'range_part' => 'to',
            ],
            [
                'param'     => 'mpm_last_login_from',
                'label'     => __('Last login (from)', 'admin-filters-for-memberpress'),
                'type'      => 'date',
                'source'    => 'mepr_member',
                'predicate' => 'last_login_from',
                'group'     => Meprmf_Util::GROUP_DATES,
                'range_of'   => 'mpm_last_login',
                'range_part' => 'from',
            ],
            [
                'param'     => 'mpm_last_login_to',
                'label'     => __('Last login (to)', 'admin-filters-for-memberpress'),
                'type'      => 'date',
                'source'    => 'mepr_member',
                'predicate' => 'last_login_to',
                'group'     => Meprmf_Util::GROUP_DATES,
                'range_of'   => 'mpm_last_login',
                'range_part' => 'to',
            ],
            [
                'param'     => 'mpm_spent_min',
                'label'     => __('Total spent (min)', 'admin-filters-for-memberpress'),
                'type'      => 'number',
                'source'    => 'mepr_member',
                'predicate' => 'spent_min',
                'group'     => Meprmf_Util::GROUP_ACTIVITY,
                'range_of'   => 'mpm_spent',
                'range_part' => 'min',
                'unit'       => self::currency_symbol(),
            ],
            [
                'param'     => 'mpm_spent_max',
                'label'     => __('Total spent (max)', 'admin-filters-for-memberpress'),
                'type'      => 'number',
                'source'    => 'mepr_member',
                'predicate' => 'spent_max',
                'group'     => Meprmf_Util::GROUP_ACTIVITY,
                'range_of'   => 'mpm_spent',
                'range_part' => 'max',
                'unit'       => self::currency_symbol(),
            ],
            [
                'param'     => 'mpm_trial',
                'label'     => __('On trial', 'admin-filters-for-memberpress'),
                'type'      => 'checkbox',
                'source'    => 'mepr_member',
                'predicate' => 'trial',
                'group'     => Meprmf_Util::GROUP_MEMBERSHIP,
            ],
        ];

        /**
         * Members list activity / aggregate filter fields.
         *
         * @since 2.0.0
         * @param array<int, array<string, mixed>> $fields Field definitions.
         * @param Meprmf_Screen_Context              $ctx    Screen context.
         */
        return apply_filters('meprmf_members_activity_filters_fields', $fields, $ctx);
    }

    /**
     * MemberPress currency symbol for the money inputs on this list.
     *
     * @return string Empty when MemberPress options are unavailable.
     */
    private static function currency_symbol()
    {
        return Meprmf_Util::currency_symbol();
    }
}
