<?php
/**
 * Builds WHERE fragments (EXISTS on wp_usermeta) for MemberPress list tables.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Predicate builder for user-meta filters.
 */
class Meprmf_Predicate_Builder
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
     * Append EXISTS subqueries for active user-meta filters.
     *
     * Each active meta filter adds a correlated EXISTS on wp_usermeta. Sites with many
     * custom fields and several active filters may see higher query cost; date-range
     * groups are merged into a single EXISTS per field.
     *
     * @param array<int, string>           $args  Existing WHERE fragments.
     * @param Meprmf_Screen_Context        $ctx   Screen context.
     * @param array<int, array<string, mixed>> $valid Normalized field definitions.
     * @return array<int, string>
     */
    public static function append_usermeta_exists(array $args, Meprmf_Screen_Context $ctx, array $valid)
    {
        self::$last_fragments = [];
        global $wpdb;

        $uid = $ctx->get_user_id_column_sql();

        $range_groups = [];
        $skip_params  = [];
        foreach ($valid as $field) {
            if (empty($field['date_range_of']) || empty($field['date_range_part'])) {
                continue;
            }
            $group_key = (string) $field['date_range_of'];
            if (! isset($range_groups[ $group_key ])) {
                // The operator lives on the group base, so both parts resolve to one set of bounds.
                $bounds                     = Meprmf_Util::resolve_range_bounds($group_key, $field);
                $range_groups[ $group_key ] = [
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Filter field config array key, not a DB query.
                    'meta_key' => (string) $field['meta_key'],
                    'from'     => $bounds['from'],
                    'to'       => $bounds['to'],
                    'negate'   => $bounds['negate'],
                ];
            }
            $skip_params[ (string) $field['param'] ] = true;
        }

        // Table alias and outer user_id column expression are fixed SQL fragments (not user input); values use %s placeholders.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ($range_groups as $base => $group) {
            $args = self::append_usermeta_date_range_exists(
                $args,
                $wpdb,
                $uid,
                'mpf_um_' . $base,
                $group['meta_key'],
                $group['from'],
                $group['to'],
                $group['negate']
            );
        }

        foreach ($valid as $field) {
            $param = (string) $field['param'];
            if (isset($skip_params[ $param ])) {
                continue;
            }

            $meta  = (string) $field['meta_key'];
            $alias = 'mpf_um_' . $param;
            $ftype = (string) $field['type'];

            $operator = Meprmf_Util::field_supports_operators($field)
                ? Meprmf_Util::get_field_operator($param, $field)
                : Meprmf_Util::OPERATOR_DEFAULT;

            // Dates are handled before the generic operator dispatch, so `after` on a date
            // is a date comparison and never a string compare.
            if ('date' === $ftype || 'date_range' === $ftype) {
                if (in_array($operator, Meprmf_Util::VALUELESS_OPERATORS, true)) {
                    $args = self::append_operator_exists($args, $wpdb, $uid, $alias, $meta, $param, $operator, $field);
                    continue;
                }

                if ('date_range' === $ftype || Meprmf_Util::is_range_operator($operator)) {
                    $bounds = Meprmf_Util::resolve_range_bounds($param, $field);
                    if (null === $bounds['from'] && null === $bounds['to']) {
                        continue;
                    }

                    $args = self::append_usermeta_date_range_exists(
                        $args,
                        $wpdb,
                        $uid,
                        $alias,
                        $meta,
                        $bounds['from'],
                        $bounds['to'],
                        $bounds['negate']
                    );
                    continue;
                }

                $ymd = Meprmf_Util::parse_date_param(Meprmf_Util::get_request_value($param));
                if (null === $ymd) {
                    continue;
                }

                $args = self::append_usermeta_date_exact_exists($args, $wpdb, $uid, $alias, $meta, $ymd);
                continue;
            }

            if (Meprmf_Util::OPERATOR_DEFAULT !== $operator) {
                $args = self::append_operator_exists($args, $wpdb, $uid, $alias, $meta, $param, $operator, $field);
                continue;
            }

            $raw   = Meprmf_Util::get_request_value($param);
            if ('' === $raw) {
                continue;
            }

            $match = Meprmf_Util::get_field_match_mode($field);

            if ('checkbox' === $ftype) {
                if ('1' !== $raw) {
                    continue;
                }
                $sql      = $wpdb->prepare(
                    "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = {$uid} AND {$alias}.meta_key = %s AND {$alias}.meta_value IN ('on', '1', 'true') )",
                    $meta
                );
                $args[]   = $sql;
                self::$last_fragments[] = $sql;
                continue;
            }

            if ('exact' === $match || 'country' === $ftype) {
                $sql    = $wpdb->prepare(
                    "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = {$uid} AND {$alias}.meta_key = %s AND {$alias}.meta_value = %s )",
                    $meta,
                    $raw
                );
                $args[] = $sql;
                self::$last_fragments[] = $sql;
                continue;
            }

            if ('contains' === $match) {
                $serialized_needle = 's:' . strlen($raw) . ':"' . $wpdb->esc_like($raw) . '";';
                $sql               = $wpdb->prepare(
                    "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = {$uid} AND {$alias}.meta_key = %s AND ( {$alias}.meta_value = %s OR {$alias}.meta_value LIKE %s ) )",
                    $meta,
                    $raw,
                    '%' . $serialized_needle . '%'
                );
                $args[]            = $sql;
                self::$last_fragments[] = $sql;
                continue;
            }

            $like = '%' . $wpdb->esc_like($raw) . '%';
            $sql  = $wpdb->prepare(
                "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = {$uid} AND {$alias}.meta_key = %s AND {$alias}.meta_value LIKE %s )",
                $meta,
                $like
            );
            $args[] = $sql;
            self::$last_fragments[] = $sql;
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $args;
    }

    /**
     * Record one finished fragment.
     *
     * @param array<int, string> $args WHERE fragments.
     * @param mixed              $sql  Prepared fragment.
     * @return array<int, string>
     */
    private static function push_fragment(array $args, $sql)
    {
        if (! is_string($sql) || '' === $sql) {
            return $args;
        }

        $args[]                 = $sql;
        self::$last_fragments[] = $sql;

        return $args;
    }

    /**
     * Append the fragment for an explicitly chosen comparison operator.
     *
     * Negation uses NOT EXISTS rather than `!=` so that users with no row for the meta
     * key are included — "is not Germany" must return the people with no country set.
     *
     * Returns $args unchanged when a value-taking operator has no value, matching how an
     * empty filter field has always been treated: no constraint at all.
     *
     * @param array<int, string>   $args     WHERE fragments.
     * @param wpdb                 $wpdb     Database.
     * @param string               $uid      User id column SQL.
     * @param string               $alias    Usermeta table alias.
     * @param string               $meta_key Meta key.
     * @param string               $param    Base GET param.
     * @param string               $operator One of Meprmf_Util::get_operators().
     * @param array<string, mixed> $field    Field definition.
     * @return array<int, string>
     */
    private static function append_operator_exists(array $args, $wpdb, $uid, $alias, $meta_key, $param, $operator, array $field)
    {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias, uid and the EXISTS keyword are fixed SQL fragments; values use placeholders.
        // The meta_key placeholder stays in each prepare() literal below so the placeholder
        // count is visible to WordPress.DB.PreparedSQLPlaceholders, which cannot see into $subject.
        $subject = "SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = {$uid} AND {$alias}.meta_key =";

        if (in_array($operator, Meprmf_Util::VALUELESS_OPERATORS, true)) {
            // "Empty" deliberately covers both no usermeta row at all and a row holding
            // an empty string, which is what an admin means by "has no value set".
            $keyword = ('is_empty' === $operator) ? 'NOT EXISTS' : 'EXISTS';
            $sql     = $wpdb->prepare("{$keyword} ( {$subject} %s AND {$alias}.meta_value <> '' )", $meta_key);

            return self::push_fragment($args, $sql);
        }

        if ('is_one_of' === $operator) {
            $values = Meprmf_Util::get_request_values($param);
            if (empty($values)) {
                return $args;
            }

            $placeholders = implode(', ', array_fill(0, count($values), '%s'));
            $prepare_args = array_merge([ $meta_key ], $values);
            $sql          = $wpdb->prepare(
                "EXISTS ( {$subject} %s AND {$alias}.meta_value IN ({$placeholders}) )",
                ...$prepare_args
            );

            return self::push_fragment($args, $sql);
        }

        $raw = Meprmf_Util::get_request_value($param);
        if ('' === $raw) {
            return $args;
        }

        if ('is' === $operator || 'is_not' === $operator) {
            $keyword = ('is_not' === $operator) ? 'NOT EXISTS' : 'EXISTS';
            $sql     = $wpdb->prepare("{$keyword} ( {$subject} %s AND {$alias}.meta_value = %s )", $meta_key, $raw);

            return self::push_fragment($args, $sql);
        }

        // contains / not_contains mirror the field's own match mode, so a field that
        // stores serialized multi-values keeps matching the way it does without an operator.
        $keyword = ('not_contains' === $operator) ? 'NOT EXISTS' : 'EXISTS';

        if ('contains' === Meprmf_Util::get_field_match_mode($field)) {
            $serialized_needle = 's:' . strlen($raw) . ':"' . $wpdb->esc_like($raw) . '";';
            $sql               = $wpdb->prepare(
                "{$keyword} ( {$subject} %s AND ( {$alias}.meta_value = %s OR {$alias}.meta_value LIKE %s ) )",
                $meta_key,
                $raw,
                '%' . $serialized_needle . '%'
            );

            return self::push_fragment($args, $sql);
        }

        $sql = $wpdb->prepare(
            "{$keyword} ( {$subject} %s AND {$alias}.meta_value LIKE %s )",
            $meta_key,
            '%' . $wpdb->esc_like($raw) . '%'
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return self::push_fragment($args, $sql);
    }

    /**
     * @param array<int, string> $args       WHERE fragments.
     * @param wpdb               $wpdb       Database.
     * @param string             $uid        User id column SQL.
     * @param string             $alias      Usermeta table alias.
     * @param string             $meta_key   Meta key.
     * @param string             $ymd        Y-m-d filter value.
     * @return array<int, string>
     */
    private static function append_usermeta_date_exact_exists(array $args, $wpdb, $uid, $alias, $meta_key, $ymd)
    {
        $match_values = Meprmf_Util::usermeta_date_exact_match_values($ymd);
        $placeholders = implode(', ', array_fill(0, count($match_values), '%s'));
        $prepare_args = array_merge([ $meta_key ], $match_values);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias and uid are fixed SQL fragments; values use placeholders.
        $sql = $wpdb->prepare(
            "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = {$uid} AND {$alias}.meta_key = %s AND {$alias}.meta_value IN ({$placeholders}) )",
            ...$prepare_args
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (! is_string($sql) || '' === $sql) {
            return $args;
        }
        $args[]                 = $sql;
        self::$last_fragments[] = $sql;

        return $args;
    }

    /**
     * @param array<int, string> $args     WHERE fragments.
     * @param wpdb               $wpdb     Database.
     * @param string             $uid      User id column SQL.
     * @param string             $alias    Usermeta table alias.
     * @param string             $meta_key Meta key.
     * @param string|null        $from     Y-m-d lower bound.
     * @param string|null        $to       Y-m-d upper bound.
     * @param bool               $negate   When true, invert the whole predicate (`not_in_last`).
     * @return array<int, string>
     */
    private static function append_usermeta_date_range_exists(array $args, $wpdb, $uid, $alias, $meta_key, $from, $to, $negate = false)
    {
        if (null === $from && null === $to) {
            return $args;
        }

        $parsed  = Meprmf_Util::usermeta_date_value_sql_expr("{$alias}.meta_value");
        $clauses = [
            "{$alias}.meta_key = " . Meprmf_Util::wpdb_quote_scalar($wpdb, $meta_key),
            "{$parsed} IS NOT NULL",
        ];

        if (null !== $from) {
            $clauses[] = "{$parsed} >= STR_TO_DATE(" . Meprmf_Util::wpdb_quote_scalar($wpdb, $from) . ", '%Y-%m-%d')";
        }
        if (null !== $to) {
            $clauses[] = "{$parsed} <= STR_TO_DATE(" . Meprmf_Util::wpdb_quote_scalar($wpdb, $to) . ", '%Y-%m-%d')";
        }

        // NOT EXISTS also keeps users with no meta row at all, which is what "not in the
        // last N days" has to mean: never logged in / no date recorded is not "recent".
        $keyword = $negate ? 'NOT EXISTS' : 'EXISTS';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias and uid are fixed SQL fragments; meta values are quoted.
        $sql = "{$keyword} ( SELECT 1 FROM {$wpdb->usermeta} AS {$alias} WHERE {$alias}.user_id = {$uid} AND " . implode(' AND ', $clauses) . ' )';
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $args[]                 = $sql;
        self::$last_fragments[] = $sql;

        return $args;
    }
}
