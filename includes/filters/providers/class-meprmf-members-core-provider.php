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
     * Gateway id => label for payment method filters.
     *
     * @return array<string, string>
     */
    private static function fetch_gateway_options()
    {
        $gateways = [];

        if (! class_exists('MeprOptions')) {
            return $gateways;
        }

        $opts    = MeprOptions::fetch();
        $methods = $opts->payment_methods();
        if (! is_array($methods) || empty($methods)) {
            return $gateways;
        }

        foreach ($methods as $id => $method) {
            $id = is_scalar($id) ? (string) $id : '';
            if ('' === $id) {
                continue;
            }
            $label = '';
            if (is_object($method)) {
                $label = trim(
                    ( isset($method->label) ? (string) $method->label : '' ) .
                    ( isset($method->name) ? ' (' . (string) $method->name . ')' : '' )
                );
            }
            $gateways[ $id ] = '' !== $label ? $label : $id;
        }

        return $gateways;
    }

    /**
     * Transaction status options (Transactions / Lifetimes lists).
     *
     * @return array<string, string>
     */
    private static function fetch_txn_status_options()
    {
        if (! class_exists('MeprTransaction')) {
            return [];
        }

        return [
            MeprTransaction::$pending_str   => __('Pending', 'admin-filters-for-memberpress'),
            MeprTransaction::$complete_str  => __('Complete', 'admin-filters-for-memberpress'),
            MeprTransaction::$confirmed_str  => __('Confirmed', 'admin-filters-for-memberpress'),
            MeprTransaction::$refunded_str   => __('Refunded', 'admin-filters-for-memberpress'),
            MeprTransaction::$failed_str     => __('Failed', 'admin-filters-for-memberpress'),
        ];
    }

    /**
     * Extra core filters for one list screen (params already use that screen's prefix).
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<int, array<string, mixed>>
     */
    private static function build_screen_specific_core_fields(Meprmf_Screen_Context $ctx)
    {
        $prefix = $ctx->get_core_filter_param_prefix();
        if ('' === $prefix) {
            return [];
        }

        $fields = [];

        if ($ctx->is_members()) {
            $fields[] = [
                'param'     => $prefix . 'member_status',
                'label'     => __('Member status', 'admin-filters-for-memberpress'),
                'type'      => 'select',
                'source'    => 'mepr_member',
                'predicate' => 'member_status',
                'options'   => [
                    'active'   => __('Active members', 'admin-filters-for-memberpress'),
                    'inactive' => __('Inactive members', 'admin-filters-for-memberpress'),
                    'expired'  => __('Expired members', 'admin-filters-for-memberpress'),
                    'none'     => __('Non-members', 'admin-filters-for-memberpress'),
                ],
            ];

            if (class_exists('MPCA_Corporate_Account')) {
                $fields[] = [
                    'param'     => $prefix . 'corp_type',
                    'label'     => __('Corporate type', 'admin-filters-for-memberpress'),
                    'type'      => 'select',
                    'source'    => 'mepr_member',
                    'predicate' => 'corp_type',
                    'options'   => [
                        'owner'       => __('Corp account owner', 'admin-filters-for-memberpress'),
                        'sub_account' => __('Sub account', 'admin-filters-for-memberpress'),
                        'none'        => __('Not corporate', 'admin-filters-for-memberpress'),
                    ],
                ];
            }

            return $fields;
        }

        $gateways = self::fetch_gateway_options();
        if (! empty($gateways)) {
            $fields[] = [
                'param'     => $prefix . 'gateway',
                'label'     => __('Gateway', 'admin-filters-for-memberpress'),
                'type'      => 'select',
                'source'    => $ctx->is_subscriptions_recurring() ? 'mepr_subscription' : 'mepr_transaction',
                'predicate' => 'gateway',
                'options'   => $gateways,
            ];
        }

        if ($ctx->is_transactions() || $ctx->is_lifetimes()) {
            $txn_statuses = self::fetch_txn_status_options();
            if (! empty($txn_statuses)) {
                $fields[] = [
                    'param'     => $prefix . 'txn_status',
                    'label'     => __('Transaction status', 'admin-filters-for-memberpress'),
                    'type'      => 'select',
                    'source'    => 'mepr_transaction',
                    'predicate' => 'txn_status',
                    'options'   => $txn_statuses,
                ];
            }

            $fields[] = [
                'param'     => $prefix . 'created_from',
                'label'     => __('Created from', 'admin-filters-for-memberpress'),
                'type'      => 'date',
                'source'    => 'mepr_transaction',
                'predicate' => 'created_from',
            ];
            $fields[] = [
                'param'     => $prefix . 'created_to',
                'label'     => __('Created to', 'admin-filters-for-memberpress'),
                'type'      => 'date',
                'source'    => 'mepr_transaction',
                'predicate' => 'created_to',
            ];
        }

        if ($ctx->is_lifetimes()) {
            $coupons = self::fetch_coupon_options();
            if (! empty($coupons)) {
                $fields[] = [
                    'param'     => $prefix . 'coupon',
                    'label'     => __('Coupon', 'admin-filters-for-memberpress'),
                    'type'      => 'select',
                    'source'    => 'mepr_transaction',
                    'predicate' => 'coupon',
                    'options'   => $coupons,
                ];
            }
        }

        return $fields;
    }

    /**
     * Coupon id => title for lifetime transaction filters.
     *
     * @return array<int, string>
     */
    private static function fetch_coupon_options()
    {
        $options = [];

        if (! class_exists('MeprCptModel')) {
            return $options;
        }

        $coupons = MeprCptModel::all(
            'MeprCoupon',
            false,
            [
                'orderby' => 'title',
                'order'   => 'ASC',
            ]
        );

        if (! is_array($coupons)) {
            return $options;
        }

        foreach ($coupons as $coupon) {
            if (empty($coupon->ID) || ! isset($coupon->post_title)) {
                continue;
            }
            $options[ (int) $coupon->ID ] = (string) $coupon->post_title;
        }

        return $options;
    }

    /**
     * Clarify Access labels on row-scoped lists (Transactions, Subscriptions, Lifetimes).
     *
     * @param array<int, array<string, mixed>> $fields Field rows.
     * @param Meprmf_Screen_Context              $ctx    Screen context.
     * @return array<int, array<string, mixed>>
     */
    private static function apply_access_field_labels(array $fields, Meprmf_Screen_Context $ctx)
    {
        if ($ctx->is_members()) {
            return $fields;
        }

        foreach ($fields as $i => $field) {
            if (empty($field['predicate']) || 'access' !== $field['predicate']) {
                continue;
            }
            $fields[ $i ]['options'] = [
                'active'   => __('Active access (this row)', 'admin-filters-for-memberpress'),
                'inactive' => __('Inactive / expired (this row)', 'admin-filters-for-memberpress'),
            ];
        }

        return $fields;
    }

    /**
     * Replace leading mpm_ param keys for non-Members list screens.
     *
     * @param array<int, array<string, mixed>> $fields     Field rows.
     * @param string                             $new_prefix e.g. mpmt_ (includes trailing underscore).
     * @return array<int, array<string, mixed>>
     */
    public static function remap_core_field_params(array $fields, $new_prefix)
    {
        $new_prefix = (string) $new_prefix;
        $out        = [];
        $old_len    = strlen('mpm_');
        foreach ($fields as $field) {
            if (! empty($field['param']) && is_string($field['param']) && 0 === strpos($field['param'], 'mpm_')) {
                $field['param'] = $new_prefix . substr($field['param'], $old_len);
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * Product id => title map for membership filter options.
     *
     * @return array<int, string>
     */
    private static function fetch_product_options()
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

        return $products;
    }

    /**
     * Filter field definitions for MemberPress table filters on the Members list.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_core_filter_fields()
    {
        return self::get_core_filter_fields_for_context(
            new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID')
        );
    }

    /**
     * Core MemberPress table filter fields for a list screen (mpm_* on Members; prefixed on other lists).
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<int, array<string, mixed>>
     */
    public static function get_core_filter_fields_for_context(Meprmf_Screen_Context $ctx)
    {
        if (! $ctx->supports_core_filters()) {
            return [];
        }

        $products = self::fetch_product_options();
        $base     = self::apply_access_field_labels(self::build_core_filter_fields($products), $ctx);
        $extra    = self::build_screen_specific_core_fields($ctx);

        if ($ctx->is_members()) {
            /**
             * Filter core MemberPress table filter fields on the Members list.
             *
             * @param array<int, array<string, mixed>> $fields Field definitions.
             */
            return apply_filters('meprmf_members_core_filters_fields', array_merge($base, $extra));
        }

        $prefix = $ctx->get_core_filter_param_prefix();
        if ('' === $prefix || 'mpm_' === $prefix) {
            return [];
        }

        $remapped = array_merge(self::remap_core_field_params($base, $prefix), $extra);
        $remapped = self::apply_access_field_labels($remapped, $ctx);

        if ($ctx->is_transactions()) {
            /**
             * Filter core MemberPress table filter fields on the Transactions list.
             *
             * @param array<int, array<string, mixed>> $fields Field rows; params use mpmt_* prefix.
             */
            return apply_filters('meprmf_transactions_core_filters_fields', $remapped);
        }

        if ($ctx->is_subscriptions_recurring()) {
            /**
             * Filter core MemberPress table filter fields on the Subscriptions list.
             *
             * @param array<int, array<string, mixed>> $fields Field rows; params use mpms_* prefix.
             */
            return apply_filters('meprmf_subscriptions_core_filters_fields', $remapped);
        }

        if ($ctx->is_lifetimes()) {
            /**
             * Filter core MemberPress table filter fields on the Lifetimes list.
             *
             * @param array<int, array<string, mixed>> $fields Field rows; params use mpml_* prefix.
             */
            return apply_filters('meprmf_lifetimes_core_filters_fields', $remapped);
        }

        return [];
    }
}
