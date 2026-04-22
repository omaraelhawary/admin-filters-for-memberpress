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

        foreach ($valid as $field) {
            $param = (string) $field['param'];
            $raw   = Meprmf_Util::get_request_value($param);
            if ('' === $raw) {
                continue;
            }

            $meta  = (string) $field['meta_key'];
            $alias = 'mpf_um_' . $param;
            $ftype = (string) $field['type'];
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

        return $args;
    }
}
