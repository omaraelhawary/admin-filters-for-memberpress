<?php
/**
 * MemberPress table filter field definitions for the Members admin list.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Core filters: memberships, access, subscriptions, dates (mepr_* tables).
 */
class Meprmf_Members_Core_Provider
{

    /**
     * Build field rows from a product id => title map.
     *
     * @param array<int|string, string> $products Product options.
     * @return array<int, array<string, mixed>>
     */
    public static function build_core_filter_fields(array $products)
    {
        $fields = [
            [
                'param'    => 'mpm_product',
                'label'    => __('Membership', 'admin-filters-for-memberpress'),
                'type'     => 'select',
                'source'   => 'mepr_transaction',
                'predicate' => 'product',
                'options'  => $products,
            ],
            [
                'param'    => 'mpm_access',
                'label'    => __('Access', 'admin-filters-for-memberpress'),
                'type'     => 'select',
                'source'   => 'mepr_transaction',
                'predicate' => 'access',
                'options'  => [
                    'active'   => __('Active', 'admin-filters-for-memberpress'),
                    'inactive' => __('Inactive', 'admin-filters-for-memberpress'),
                ],
            ],
            [
                'param'    => 'mpm_sub_status',
                'label'    => __('Subscription status', 'admin-filters-for-memberpress'),
                'type'     => 'select',
                'source'   => 'mepr_subscription',
                'predicate' => 'sub_status',
                'options'  => [
                    MeprSubscription::$active_str     => __('Active subscription', 'admin-filters-for-memberpress'),
                    MeprSubscription::$pending_str    => __('Pending subscription', 'admin-filters-for-memberpress'),
                    MeprSubscription::$cancelled_str  => __('Cancelled subscription', 'admin-filters-for-memberpress'),
                    MeprSubscription::$suspended_str   => __('Paused subscription', 'admin-filters-for-memberpress'),
                ],
            ],
            [
                'param'    => 'mpm_exp_from',
                'label'    => __('Expires from', 'admin-filters-for-memberpress'),
                'type'     => 'date',
                'source'   => 'mepr_transaction',
                'predicate' => 'exp_from',
            ],
            [
                'param'    => 'mpm_exp_to',
                'label'    => __('Expires to', 'admin-filters-for-memberpress'),
                'type'     => 'date',
                'source'   => 'mepr_transaction',
                'predicate' => 'exp_to',
            ],
            [
                'param'    => 'mpm_member_from',
                'label'    => __('Member since (from)', 'admin-filters-for-memberpress'),
                'type'     => 'date',
                'source'   => 'mepr_member',
                'predicate' => 'member_from',
            ],
            [
                'param'    => 'mpm_member_to',
                'label'    => __('Member since (to)', 'admin-filters-for-memberpress'),
                'type'     => 'date',
                'source'   => 'mepr_member',
                'predicate' => 'member_to',
            ],
        ];

        return $fields;
    }

    /**
     * Filter field definitions for MemberPress table filters on the Members list.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_core_filter_fields()
    {
        $products = [];

        if (class_exists('MeprCptModel')) {
            $prds = MeprCptModel::all(
                'MeprProduct',
                false,
                [
                    'orderby' => 'title',
                    'order'   => 'ASC',
                ]
            );
            if (is_array($prds)) {
                foreach ($prds as $p) {
                    if (! empty($p->ID) && isset($p->post_title)) {
                        $products[ (int) $p->ID ] = (string) $p->post_title;
                    }
                }
            }
        }

        $fields = self::build_core_filter_fields($products);

        /**
         * Filter core MemberPress table filter fields on the Members list.
         *
         * @param array<int, array<string, mixed>> $fields Field definitions.
         */
        return apply_filters('meprmf_members_core_filters_fields', $fields);
    }
}
