<?php
/**
 * Builds WHERE fragments (EXISTS on mepr_* tables) for Members list filters.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Predicate builder for MemberPress subscription/transaction/member filters.
 */
class Meprmf_Mepr_Predicate_Builder
{

    /** @var array<int, string>|null Last fragments added (for debug). */
    private static $last_fragments = null;

    /**
     * @return array<int, string>|null
     */
    public static function get_last_fragments()
    {
        return self::$last_fragments;
    }

    /**
     * Reset last debug fragments.
     *
     * @return void
     */
    public static function reset_last_fragments()
    {
        self::$last_fragments = null;
    }

    /**
     * Append EXISTS / WHERE fragments for active core MemberPress filters (Members list only).
     *
     * @param array<int, string>                 $args  Existing WHERE fragments.
     * @param Meprmf_Screen_Context              $ctx   Screen context.
     * @param array<int, array<string, mixed>>   $valid Normalized core field definitions.
     * @return array<int, string>
     */
    public static function append_mepr_exists(array $args, Meprmf_Screen_Context $ctx, array $valid)
    {
        self::$last_fragments = [];

        if (! $ctx->is_members() || empty($valid)) {
            return $args;
        }

        if (! class_exists('MeprDb') || ! class_exists('MeprTransaction') || ! class_exists('MeprUtils')) {
            return $args;
        }

        global $wpdb;
        $mepr_db = MeprDb::fetch();
        $uid     = $ctx->get_user_id_column_sql();

        $values = self::collect_request_values($valid);

        $product_id = self::int_param($values, 'mpm_product');
        $access     = isset($values['mpm_access']) ? (string) $values['mpm_access'] : '';
        $sub_status = isset($values['mpm_sub_status']) ? (string) $values['mpm_sub_status'] : '';
        $exp_from   = self::date_param($values, 'mpm_exp_from');
        $exp_to     = self::date_param($values, 'mpm_exp_to');
        $member_from = self::date_param($values, 'mpm_member_from');
        $member_to   = self::date_param($values, 'mpm_member_to');

        if (null !== $member_from) {
            $sql      = $wpdb->prepare('m.created_at >= %s', $member_from . ' 00:00:00');
            $args[]   = $sql;
            self::$last_fragments[] = $sql;
        }

        if (null !== $member_to) {
            $sql      = $wpdb->prepare('m.created_at <= %s', $member_to . ' 23:59:59');
            $args[]   = $sql;
            self::$last_fragments[] = $sql;
        }

        if (null !== $exp_from || null !== $exp_to) {
            $sql = self::build_expires_range_exists($mepr_db->transactions, $uid, $product_id, $exp_from, $exp_to);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        if ('active' === $access) {
            $sql = self::build_active_access_exists($mepr_db->transactions, $uid, $product_id);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        } elseif (in_array($access, [ 'inactive', 'expired' ], true)) {
            // `expired` kept for bookmarked URLs from earlier releases.
            $sql = self::build_inactive_access_exists($mepr_db->transactions, $uid, $product_id);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        } elseif ($product_id > 0) {
            $sql = self::build_product_exists($mepr_db->transactions, $uid, $product_id);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        if ('' !== $sub_status && self::is_allowed_sub_status($sub_status)) {
            $sql = self::build_subscription_status_exists($mepr_db->subscriptions, $uid, $sub_status, $product_id);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        /**
         * Filter WHERE fragments after core MemberPress table predicates are built.
         *
         * @param array<int, string>               $args   WHERE fragments.
         * @param Meprmf_Screen_Context            $ctx    Screen context.
         * @param array<string, string>            $values Active mpm_* request values.
         * @param array<int, array<string, mixed>> $valid  Normalized core field definitions.
         */
        $args = apply_filters('meprmf_mepr_predicate_fragments', $args, $ctx, $values, $valid);

        return $args;
    }

    /**
     * @param array<int, array<string, mixed>> $valid Field definitions.
     * @return array<string, string>
     */
    private static function collect_request_values(array $valid)
    {
        $values = [];
        foreach ($valid as $field) {
            $param = isset($field['param']) ? (string) $field['param'] : '';
            if ('' === $param) {
                continue;
            }
            $raw = Meprmf_Util::get_request_value($param);
            if ('' !== $raw) {
                $values[ $param ] = $raw;
            }
        }
        return $values;
    }

    /**
     * @param array<string, string> $values Values map.
     * @param string                $key    Param key.
     * @return int
     */
    private static function int_param(array $values, $key)
    {
        if (! isset($values[ $key ]) || ! is_numeric($values[ $key ])) {
            return 0;
        }
        return max(0, (int) $values[ $key ]);
    }

    /**
     * @param array<string, string> $values Values map.
     * @param string                $key    Param key.
     * @return string|null Y-m-d or null.
     */
    private static function date_param(array $values, $key)
    {
        if (! isset($values[ $key ])) {
            return null;
        }
        $raw = (string) $values[ $key ];
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        return $raw;
    }

    /**
     * @param string $status Subscription status.
     * @return bool
     */
    private static function is_allowed_sub_status($status)
    {
        return in_array(
            $status,
            [
                MeprSubscription::$active_str,
                MeprSubscription::$pending_str,
                MeprSubscription::$cancelled_str,
                MeprSubscription::$suspended_str,
            ],
            true
        );
    }

    /**
     * SQL clause matching MeprTransaction::get_all_complete_by_user_id txn types/statuses.
     *
     * @param string $alias Table alias.
     * @return string
     */
    private static function txn_complete_types_sql($alias)
    {
        global $wpdb;

        return $wpdb->prepare(
            "( ( {$alias}.txn_type IN (%s,%s,%s,%s) AND {$alias}.status = %s )
               OR ( {$alias}.txn_type = %s AND {$alias}.status = %s ) )",
            MeprTransaction::$payment_str,
            MeprTransaction::$sub_account_str,
            MeprTransaction::$woo_txn_str,
            MeprTransaction::$fallback_str,
            MeprTransaction::$complete_str,
            MeprTransaction::$subscription_confirmation_str,
            MeprTransaction::$confirmed_str
        );
    }

    /**
     * Active access expiry clause (not lifetime-expired).
     *
     * @param string $alias Table alias.
     * @return string
     */
    private static function txn_active_expires_sql($alias)
    {
        global $wpdb;

        return $wpdb->prepare(
            "( {$alias}.expires_at > %s OR {$alias}.expires_at = %s OR {$alias}.expires_at IS NULL )",
            MeprUtils::db_now(),
            MeprUtils::db_lifetime()
        );
    }

    /**
     * @param string $table      Transactions table name.
     * @param string $uid        User id SQL expression.
     * @param int    $product_id Product id or 0.
     * @return string
     */
    private static function build_active_access_exists($table, $uid, $product_id)
    {
        global $wpdb;

        $alias    = 'mpmf_t_active';
        $types    = self::txn_complete_types_sql($alias);
        $expires  = self::txn_active_expires_sql($alias);
        $product  = $product_id > 0 ? $wpdb->prepare("AND {$alias}.product_id = %d", $product_id) : '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return "EXISTS (
            SELECT 1 FROM {$table} AS {$alias}
            WHERE {$alias}.user_id = {$uid}
              {$product}
              AND {$types}
              AND {$expires}
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Inactive access: had qualifying transactions but none currently grant access.
     *
     * @param string $table      Transactions table name.
     * @param string $uid        User id SQL expression.
     * @param int    $product_id Product id or 0.
     * @return string
     */
    private static function build_inactive_access_exists($table, $uid, $product_id)
    {
        global $wpdb;

        $alias   = 'mpmf_t_exp';
        $types   = self::txn_complete_types_sql($alias);
        $product = $product_id > 0 ? $wpdb->prepare("AND {$alias}.product_id = %d", $product_id) : '';

        $lifetime = MeprUtils::db_lifetime();
        $now      = MeprUtils::db_now();

        $expired_dates = $wpdb->prepare(
            "{$alias}.expires_at <> %s AND {$alias}.expires_at IS NOT NULL AND {$alias}.expires_at <= %s",
            $lifetime,
            $now
        );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $had_access = "EXISTS (
            SELECT 1 FROM {$table} AS {$alias}
            WHERE {$alias}.user_id = {$uid}
              {$product}
              AND {$types}
              AND {$expired_dates}
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $active     = self::build_active_access_exists($table, $uid, $product_id);
        $not_active = 'NOT ' . $active;

        return '(' . $had_access . ' AND ' . $not_active . ')';
    }

    /**
     * Any complete transaction for a product (access not required).
     *
     * @param string $table      Transactions table name.
     * @param string $uid        User id SQL expression.
     * @param int    $product_id Product id.
     * @return string
     */
    private static function build_product_exists($table, $uid, $product_id)
    {
        if ($product_id <= 0) {
            return '';
        }

        global $wpdb;

        $alias  = 'mpmf_t_prd';
        $types  = self::txn_complete_types_sql($alias);

        $product_clause = $wpdb->prepare("AND {$alias}.product_id = %d", $product_id);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return "EXISTS (
            SELECT 1 FROM {$table} AS {$alias}
            WHERE {$alias}.user_id = {$uid}
              {$product_clause}
              AND {$types}
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param string      $table      Transactions table name.
     * @param string      $uid        User id SQL expression.
     * @param int         $product_id Product id or 0.
     * @param string|null $from       Y-m-d from.
     * @param string|null $to         Y-m-d to.
     * @return string
     */
    private static function build_expires_range_exists($table, $uid, $product_id, $from, $to)
    {
        global $wpdb;

        $alias     = 'mpmf_t_exp_rng';
        $types     = self::txn_complete_types_sql($alias);
        $lifetime  = MeprUtils::db_lifetime();
        $product   = $product_id > 0 ? $wpdb->prepare("AND {$alias}.product_id = %d", $product_id) : '';
        $date_bits = [];

        if (null !== $from) {
            $date_bits[] = $wpdb->prepare("{$alias}.expires_at >= %s", $from . ' 00:00:00');
        }
        if (null !== $to) {
            $date_bits[] = $wpdb->prepare("{$alias}.expires_at <= %s", $to . ' 23:59:59');
        }

        if (empty($date_bits)) {
            return '';
        }

        $date_sql          = implode(' AND ', $date_bits);
        $exclude_lifetime  = $wpdb->prepare(
            "{$alias}.expires_at <> %s AND {$alias}.expires_at IS NOT NULL",
            $lifetime
        );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return "EXISTS (
            SELECT 1 FROM {$table} AS {$alias}
            WHERE {$alias}.user_id = {$uid}
              {$product}
              AND {$types}
              AND {$exclude_lifetime}
              AND {$date_sql}
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param string $table      Subscriptions table name.
     * @param string $uid        User id SQL expression.
     * @param string $status     Subscription status.
     * @param int    $product_id Product id or 0.
     * @return string
     */
    private static function build_subscription_status_exists($table, $uid, $status, $product_id)
    {
        global $wpdb;

        $alias   = 'mpmf_sub';
        $product = $product_id > 0 ? $wpdb->prepare("AND {$alias}.product_id = %d", $product_id) : '';

        $status_clause = $wpdb->prepare("{$alias}.status = %s", $status);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return "EXISTS (
            SELECT 1 FROM {$table} AS {$alias}
            WHERE {$alias}.user_id = {$uid}
              AND {$status_clause}
              {$product}
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
