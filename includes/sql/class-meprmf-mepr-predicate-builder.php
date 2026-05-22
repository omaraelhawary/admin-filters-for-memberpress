<?php
/**
 * Builds WHERE fragments for MemberPress table filters (Members user EXISTS; row-scoped on other lists).
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

    /** @var array<string, string> Memoized txn_complete_types_sql per table alias. */
    private static $txn_types_cache = [];

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
        self::$last_fragments  = null;
        self::$txn_types_cache = [];
    }

    /**
     * Append WHERE fragments for active core MemberPress table filters.
     *
     * @param array<int, string>                 $args  Existing WHERE fragments.
     * @param Meprmf_Screen_Context              $ctx   Screen context.
     * @param array<int, array<string, mixed>>   $valid Normalized core field definitions.
     * @return array<int, string>
     */
    public static function append_mepr_exists(array $args, Meprmf_Screen_Context $ctx, array $valid)
    {
        self::$last_fragments = [];

        if (empty($valid) || ! $ctx->supports_core_filters()) {
            return $args;
        }

        if (! class_exists('MeprDb') || ! class_exists('MeprTransaction') || ! class_exists('MeprUtils')) {
            return $args;
        }

        if ($ctx->is_members()) {
            $args = self::append_members_user_exists($args, $ctx, $valid);
        } else {
            $row_alias = $ctx->get_primary_row_alias();
            if ('' !== $row_alias) {
                $args = self::append_row_predicates($args, $ctx, $valid, $row_alias);
            }
        }

        $values = self::collect_request_values($valid);

        /**
         * Filter WHERE fragments after core MemberPress table predicates are built.
         *
         * @param array<int, string>               $args   WHERE fragments.
         * @param Meprmf_Screen_Context            $ctx    Screen context.
         * @param array<string, string>            $values Active core filter request values.
         * @param array<int, array<string, mixed>> $valid  Normalized core field definitions.
         */
        return apply_filters('meprmf_mepr_predicate_fragments', $args, $ctx, $values, $valid);
    }

    /**
     * Members list: EXISTS subqueries correlated on the user id column.
     *
     * @param array<int, string>               $args  Existing WHERE fragments.
     * @param Meprmf_Screen_Context            $ctx   Screen context.
     * @param array<int, array<string, mixed>> $valid Normalized core field definitions.
     * @return array<int, string>
     */
    private static function append_members_user_exists(array $args, Meprmf_Screen_Context $ctx, array $valid)
    {
        global $wpdb;
        $mepr_db = MeprDb::fetch();
        $uid     = $ctx->get_user_id_column_sql();
        $values  = self::collect_request_values($valid);

        $product_id  = self::int_param($values, self::param_for_predicate($valid, 'product'));
        $access      = self::string_param($values, self::param_for_predicate($valid, 'access'));
        $sub_status  = self::string_param($values, self::param_for_predicate($valid, 'sub_status'));
        $exp_from    = self::date_param($values, self::param_for_predicate($valid, 'exp_from'));
        $exp_to      = self::date_param($values, self::param_for_predicate($valid, 'exp_to'));
        $member_from   = self::date_param($values, self::param_for_predicate($valid, 'member_from'));
        $member_to     = self::date_param($values, self::param_for_predicate($valid, 'member_to'));
        $member_status = self::string_param($values, self::param_for_predicate($valid, 'member_status'));

        if ('' !== $member_status && self::is_allowed_member_status($member_status)) {
            $sql = self::build_member_status_clause($member_status, $product_id);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        if (null !== $member_from) {
            $sql                    = $wpdb->prepare('m.created_at >= %s', $member_from . ' 00:00:00');
            $args[]                 = $sql;
            self::$last_fragments[] = $sql;
        }

        if (null !== $member_to) {
            $sql                    = $wpdb->prepare('m.created_at <= %s', $member_to . ' 23:59:59');
            $args[]                 = $sql;
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

        return $args;
    }

    /**
     * Transactions / Subscriptions / Lifetimes: constrain the primary list row.
     *
     * @param array<int, string>               $args      Existing WHERE fragments.
     * @param Meprmf_Screen_Context            $ctx       Screen context.
     * @param array<int, array<string, mixed>> $valid     Normalized core field definitions.
     * @param string                           $row_alias Primary table alias (tr, sub, txn).
     * @return array<int, string>
     */
    private static function append_row_predicates(array $args, Meprmf_Screen_Context $ctx, array $valid, $row_alias)
    {
        global $wpdb;
        $mepr_db = MeprDb::fetch();
        $values  = self::collect_request_values($valid);

        $product_id  = self::int_param($values, self::param_for_predicate($valid, 'product'));
        $access        = self::string_param($values, self::param_for_predicate($valid, 'access'));
        $sub_status    = self::string_param($values, self::param_for_predicate($valid, 'sub_status'));
        $exp_from      = self::date_param($values, self::param_for_predicate($valid, 'exp_from'));
        $exp_to        = self::date_param($values, self::param_for_predicate($valid, 'exp_to'));
        $member_from   = self::date_param($values, self::param_for_predicate($valid, 'member_from'));
        $member_to     = self::date_param($values, self::param_for_predicate($valid, 'member_to'));
        $created_from  = self::date_param($values, self::param_for_predicate($valid, 'created_from'));
        $created_to    = self::date_param($values, self::param_for_predicate($valid, 'created_to'));
        $txn_status    = self::string_param($values, self::param_for_predicate($valid, 'txn_status'));
        $gateway       = self::string_param($values, self::param_for_predicate($valid, 'gateway'));
        $uid           = $ctx->get_user_id_column_sql();
        $expires_alias = $ctx->is_subscriptions_recurring() ? 'expiring_txn' : $row_alias;

        if ('' !== $gateway && self::is_allowed_gateway($gateway)) {
            $sql = $wpdb->prepare("{$row_alias}.gateway = %s", $gateway);
            $args[]                 = $sql;
            self::$last_fragments[] = $sql;
        }

        if ('' !== $txn_status && self::is_allowed_txn_status($txn_status)) {
            $sql                    = $wpdb->prepare("{$row_alias}.status = %s", $txn_status);
            $args[]                 = $sql;
            self::$last_fragments[] = $sql;
        }

        if (null !== $created_from || null !== $created_to) {
            $sql = self::build_row_created_range($row_alias, $created_from, $created_to);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        if (null !== $member_from || null !== $member_to) {
            $sql = self::build_member_since_exists($mepr_db->members, $uid, $member_from, $member_to);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        if (null !== $exp_from || null !== $exp_to) {
            $sql = self::build_row_expires_range($expires_alias, $product_id, $exp_from, $exp_to, $row_alias);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        if ('active' === $access) {
            $sql = self::build_row_active_access($expires_alias, $product_id, $row_alias);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        } elseif (in_array($access, [ 'inactive', 'expired' ], true)) {
            $sql = self::build_row_inactive_access($expires_alias, $product_id, $row_alias);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        } elseif ($product_id > 0) {
            $sql = self::build_row_product_clause($row_alias, $product_id);
            if ('' !== $sql) {
                $args[]                 = $sql;
                self::$last_fragments[] = $sql;
            }
        }

        if ('' !== $sub_status && self::is_allowed_sub_status($sub_status)) {
            if ($ctx->is_subscriptions_recurring()) {
                $status_sql = $wpdb->prepare("{$row_alias}.status = %s", $sub_status);
                if ($product_id > 0) {
                    $status_sql .= ' AND ' . self::build_row_product_clause($row_alias, $product_id);
                }
                $args[]                 = $status_sql;
                self::$last_fragments[] = $status_sql;
            } else {
                $sql = self::build_row_subscription_status_exists(
                    $mepr_db->subscriptions,
                    $row_alias,
                    $sub_status,
                    $product_id
                );
                if ('' !== $sql) {
                    $args[]                 = $sql;
                    self::$last_fragments[] = $sql;
                }
            }
        }

        return $args;
    }

    /**
     * @param array<int, array<string, mixed>> $valid    Field definitions.
     * @param string                           $predicate Predicate key from field row.
     * @return string Param name or empty.
     */
    private static function param_for_predicate(array $valid, $predicate)
    {
        foreach ($valid as $field) {
            if (! empty($field['predicate']) && $predicate === $field['predicate'] && ! empty($field['param'])) {
                return (string) $field['param'];
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $values Values map.
     * @param string                $key    Param key.
     * @return string
     */
    private static function string_param(array $values, $key)
    {
        if ('' === $key || ! isset($values[ $key ])) {
            return '';
        }

        return (string) $values[ $key ];
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
     * @param string $status Member status slug.
     * @return bool
     */
    private static function is_allowed_member_status($status)
    {
        return in_array($status, [ 'active', 'inactive', 'expired', 'none' ], true);
    }

    /**
     * @param string $status Transaction status.
     * @return bool
     */
    private static function is_allowed_txn_status($status)
    {
        return in_array(
            $status,
            [
                MeprTransaction::$pending_str,
                MeprTransaction::$complete_str,
                MeprTransaction::$refunded_str,
                MeprTransaction::$failed_str,
            ],
            true
        );
    }

    /**
     * @param string $gateway Gateway id.
     * @return bool
     */
    private static function is_allowed_gateway($gateway)
    {
        if (! class_exists('MeprOptions')) {
            return false;
        }

        $methods = MeprOptions::fetch()->payment_methods();
        if (! is_array($methods)) {
            return false;
        }

        return isset($methods[ $gateway ]);
    }

    /**
     * MemberPress Members list aggregate status (mepr_members AS m).
     *
     * @param string $status     active|inactive|expired|none.
     * @param int    $product_id Membership product id or 0.
     * @return string
     */
    private static function build_member_status_clause($status, $product_id)
    {
        global $wpdb;

        if ($product_id > 0) {
            if ('active' === $status) {
                return $wpdb->prepare("m.memberships RLIKE '(^|,)%d(,|$)'", $product_id);
            }
            if (in_array($status, [ 'inactive', 'expired' ], true)) {
                return $wpdb->prepare("m.inactive_memberships RLIKE '(^|,)%d(,|$)'", $product_id);
            }

            return '';
        }

        switch ($status) {
            case 'active':
                return '(m.active_txn_count > 0 OR m.trial_txn_count > 0)';
            case 'inactive':
                return '(m.active_txn_count <= 0 AND m.expired_txn_count > 0 AND m.trial_txn_count <= 0)';
            case 'expired':
                return "m.inactive_memberships <> ''";
            case 'none':
                return '(m.active_txn_count <= 0 AND m.expired_txn_count <= 0 AND m.trial_txn_count <= 0)';
        }

        return '';
    }

    /**
     * @param string      $alias Row alias (tr or txn).
     * @param string|null $from  Y-m-d from.
     * @param string|null $to    Y-m-d to.
     * @return string
     */
    private static function build_row_created_range($alias, $from, $to)
    {
        global $wpdb;

        $bits = [];

        if (null !== $from) {
            $bits[] = $wpdb->prepare("{$alias}.created_at >= %s", $from . ' 00:00:00');
        }
        if (null !== $to) {
            $bits[] = $wpdb->prepare("{$alias}.created_at <= %s", $to . ' 23:59:59');
        }

        if (empty($bits)) {
            return '';
        }

        return '(' . implode(' AND ', $bits) . ')';
    }

    /**
     * SQL clause matching MeprTransaction::get_all_complete_by_user_id txn types/statuses.
     *
     * @param string $alias Table alias.
     * @return string
     */
    private static function txn_complete_types_sql($alias)
    {
        if (isset(self::$txn_types_cache[ $alias ])) {
            return self::$txn_types_cache[ $alias ];
        }

        global $wpdb;

        self::$txn_types_cache[ $alias ] = $wpdb->prepare(
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

        return self::$txn_types_cache[ $alias ];
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

    /**
     * @param string      $members_table mepr_members table name.
     * @param string      $uid           User id SQL expression.
     * @param string|null $from          Y-m-d from.
     * @param string|null $to            Y-m-d to.
     * @return string
     */
    private static function build_member_since_exists($members_table, $uid, $from, $to)
    {
        global $wpdb;

        $alias     = 'mpmf_mbr_rng';
        $date_bits = [];

        if (null !== $from) {
            $date_bits[] = $wpdb->prepare("{$alias}.created_at >= %s", $from . ' 00:00:00');
        }
        if (null !== $to) {
            $date_bits[] = $wpdb->prepare("{$alias}.created_at <= %s", $to . ' 23:59:59');
        }

        if (empty($date_bits)) {
            return '';
        }

        $date_sql = implode(' AND ', $date_bits);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return "EXISTS (
            SELECT 1 FROM {$members_table} AS {$alias}
            WHERE {$alias}.user_id = {$uid}
              AND {$date_sql}
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param string $alias      Row or expiring_txn alias for expires_at.
     * @param int    $product_id Product id or 0.
     * @param string|null $from  Y-m-d from.
     * @param string|null $to    Y-m-d to.
     * @param string $row_alias  Primary row alias for optional product_id filter.
     * @return string
     */
    private static function build_row_expires_range($alias, $product_id, $from, $to, $row_alias)
    {
        global $wpdb;

        $lifetime  = MeprUtils::db_lifetime();
        $product   = $product_id > 0 ? $wpdb->prepare("{$row_alias}.product_id = %d", $product_id) : '';
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

        $date_sql         = implode(' AND ', $date_bits);
        $exclude_lifetime = $wpdb->prepare(
            "{$alias}.expires_at <> %s AND {$alias}.expires_at IS NOT NULL",
            $lifetime
        );
        $product_clause   = '' !== $product ? ' AND ' . $product : '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return "({$exclude_lifetime} AND {$date_sql}{$product_clause})";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param string $expires_alias Alias holding expires_at (tr, txn, or expiring_txn).
     * @param int    $product_id    Product id or 0.
     * @param string $row_alias     Primary row alias for product filter.
     * @return string
     */
    private static function build_row_active_access($expires_alias, $product_id, $row_alias)
    {
        $types   = self::txn_complete_types_sql($expires_alias);
        $expires = self::txn_active_expires_sql($expires_alias);
        $product = self::build_row_product_clause($row_alias, $product_id);
        $bits    = [ $types, $expires ];

        if ('' !== $product) {
            $bits[] = $product;
        }

        return '(' . implode(' AND ', $bits) . ')';
    }

    /**
     * @param string $expires_alias Alias holding expires_at.
     * @param int    $product_id    Product id or 0.
     * @param string $row_alias     Primary row alias for product filter.
     * @return string
     */
    private static function build_row_inactive_access($expires_alias, $product_id, $row_alias)
    {
        global $wpdb;

        $types    = self::txn_complete_types_sql($expires_alias);
        $lifetime = MeprUtils::db_lifetime();
        $now      = MeprUtils::db_now();
        $product  = self::build_row_product_clause($row_alias, $product_id);

        $expired_dates = $wpdb->prepare(
            "{$expires_alias}.expires_at <> %s AND {$expires_alias}.expires_at IS NOT NULL AND {$expires_alias}.expires_at <= %s",
            $lifetime,
            $now
        );

        $bits = [ $types, $expired_dates ];
        if ('' !== $product) {
            $bits[] = $product;
        }

        return '(' . implode(' AND ', $bits) . ')';
    }

    /**
     * @param string $alias      Primary row alias.
     * @param int    $product_id Product id.
     * @return string
     */
    private static function build_row_product_clause($alias, $product_id)
    {
        if ($product_id <= 0) {
            return '';
        }

        global $wpdb;

        return $wpdb->prepare("{$alias}.product_id = %d", $product_id);
    }

    /**
     * Subscription status on the row's linked subscription (Transactions / Lifetimes).
     *
     * @param string $sub_table  Subscriptions table name.
     * @param string $txn_alias  Transaction row alias (tr or txn).
     * @param string $status     Subscription status.
     * @param int    $product_id Product id or 0.
     * @return string
     */
    private static function build_row_subscription_status_exists($sub_table, $txn_alias, $status, $product_id)
    {
        global $wpdb;

        $alias         = 'mpmf_sub_row';
        $status_clause = $wpdb->prepare("{$alias}.status = %s", $status);
        $product       = $product_id > 0 ? $wpdb->prepare("AND {$alias}.product_id = %d", $product_id) : '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return "EXISTS (
            SELECT 1 FROM {$sub_table} AS {$alias}
            WHERE {$alias}.id = {$txn_alias}.subscription_id
              AND {$txn_alias}.subscription_id > 0
              AND {$status_clause}
              {$product}
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
