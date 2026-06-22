<?php
/**
 * Corporate account EXISTS fragments for the Members list.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SQL helpers aligned with MemberPress Corporate account type column logic.
 */
class Meprmf_Corporate_Predicates
{

    /**
     * EXISTS fragment: user is a corporate sub-account.
     *
     * @param string $uid_sql User id SQL expression (e.g. u.ID).
     * @return string
     */
    public static function sub_account_exists_sql($uid_sql)
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uid_sql is a fixed user-id column expression from screen context.
        $sql = $wpdb->prepare(
            "EXISTS (
                SELECT 1 FROM {$wpdb->usermeta} AS mpmf_ca_um
                WHERE mpmf_ca_um.user_id = {$uid_sql}
                  AND mpmf_ca_um.meta_key = %s
                  AND mpmf_ca_um.meta_value <> ''
                  AND mpmf_ca_um.meta_value IS NOT NULL
            )",
            'mpca_corporate_account_id'
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $sql;
    }

    /**
     * EXISTS fragment: user owns an active corporate account (matches Corp Type column).
     *
     * @param string $uid_sql User id SQL expression.
     * @return string
     */
    public static function owner_exists_sql($uid_sql)
    {
        if (! class_exists('MeprDb') || ! class_exists('MeprUtils') || ! class_exists('MPCA_Db')) {
            return '';
        }

        global $wpdb;

        $mepr_db = MeprDb::fetch();
        $mpca_db = MPCA_Db::fetch();
        $lifetime = $wpdb->prepare('%s', MeprUtils::db_lifetime());

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $subscription_owners = "EXISTS (
            SELECT 1 FROM {$mepr_db->transactions} AS mpmf_ca_tr
            WHERE mpmf_ca_tr.user_id = {$uid_sql}
              AND mpmf_ca_tr.subscription_id IN (
                SELECT ca.obj_id FROM {$mpca_db->corporate_accounts} AS ca
                WHERE ca.obj_type = 'subscriptions'
                  AND ca.user_id = {$uid_sql}
              )
              AND mpmf_ca_tr.status IN ('complete', 'confirmed')
              AND (
                mpmf_ca_tr.expires_at IS NULL
                OR mpmf_ca_tr.expires_at = {$lifetime}
                OR mpmf_ca_tr.expires_at >= NOW()
              )
        )";

        $transaction_owners = "EXISTS (
            SELECT 1 FROM {$mpca_db->corporate_accounts} AS mpmf_ca_row
            WHERE mpmf_ca_row.user_id = {$uid_sql}
              AND mpmf_ca_row.obj_type = 'transactions'
              AND mpmf_ca_row.obj_id IN (
                SELECT mpmf_ca_tx.id FROM {$mepr_db->transactions} AS mpmf_ca_tx
                WHERE mpmf_ca_tx.user_id = {$uid_sql}
                  AND mpmf_ca_tx.status IN ('complete', 'confirmed')
                  AND (
                    mpmf_ca_tx.expires_at IS NULL
                    OR mpmf_ca_tx.expires_at = {$lifetime}
                    OR mpmf_ca_tx.expires_at >= NOW()
                  )
              )
        )";
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return '(' . $subscription_owners . ' OR ' . $transaction_owners . ')';
    }

    /**
     * WHERE fragment for corporate type filter value.
     *
     * @param string                  $type    owner|sub_account|none.
     * @param string                  $uid_sql User id SQL expression.
     * @param Meprmf_Screen_Context|null $ctx  Screen context for filters.
     * @return string
     */
    public static function clause_for_type($type, $uid_sql, $ctx = null)
    {
        $type = (string) $type;
        if (! in_array($type, [ 'owner', 'sub_account', 'none' ], true)) {
            return '';
        }

        $sub   = self::sub_account_exists_sql($uid_sql);
        $owner = self::owner_exists_sql($uid_sql);

        if ('' === $owner && 'owner' !== $type) {
            // Corporate add-on tables unavailable.
            if ('sub_account' === $type) {
                $sql = $sub;
            } else {
                return '';
            }
        } elseif ('sub_account' === $type) {
            $sql = $sub;
        } elseif ('owner' === $type) {
            $sql = '(' . $owner . ' AND NOT ' . $sub . ')';
        } else {
            $sql = '(NOT ' . $sub . ' AND NOT ' . $owner . ')';
        }

        /**
         * Override corporate type predicate SQL on the Members list.
         *
         * @since 2.0.0
         * @param string                     $sql     SQL fragment or empty.
         * @param string                     $type    Filter value.
         * @param Meprmf_Screen_Context|null $ctx     Screen context.
         */
        return apply_filters('meprmf_corporate_type_predicate', $sql, $type, $ctx);
    }
}
