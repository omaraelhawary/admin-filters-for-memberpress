<?php
/**
 * Recurring / Non-Recurring subscription tab filter translation.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cross-screen param translation between Subscriptions and Lifetimes lists.
 */
class Meprmf_Subscription_Tabs
{

    /**
     * Whether two contexts are the recurring / lifetime subscription tab pair.
     *
     * @param Meprmf_Screen_Context $a First context.
     * @param Meprmf_Screen_Context $b Second context.
     * @return bool
     */
    public static function are_peers(Meprmf_Screen_Context $a, Meprmf_Screen_Context $b)
    {
        return ( $a->is_subscriptions_recurring() && $b->is_lifetimes() )
            || ( $a->is_lifetimes() && $b->is_subscriptions_recurring() );
    }

    /**
     * Peer admin page slug for a subscription tab screen, or empty.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return string
     */
    public static function peer_page(Meprmf_Screen_Context $ctx)
    {
        if ($ctx->is_subscriptions_recurring()) {
            return Meprmf_Screen::PAGE_LIFETIMES;
        }
        if ($ctx->is_lifetimes()) {
            return Meprmf_Screen::PAGE_SUBSCRIPTIONS;
        }

        return '';
    }

    /**
     * Peer screen context for a subscription tab screen, or null.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return Meprmf_Screen_Context|null
     */
    public static function peer_context(Meprmf_Screen_Context $ctx)
    {
        if ($ctx->is_subscriptions_recurring()) {
            return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_LIFETIMES, 'txn.user_id');
        }
        if ($ctx->is_lifetimes()) {
            return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');
        }

        return null;
    }

    /**
     * Whether the active admin page and list_table() caller are the subscription tab pair.
     *
     * @param Meprmf_Screen_Context      $page_ctx  Context from {@see Meprmf_Screen::detect()}.
     * @param Meprmf_Screen_Context|null $list_ctx  Context from {@see Meprmf_Screen::detect_list_table_context()}.
     * @return bool
     */
    public static function is_cross_tab_list_table_request(Meprmf_Screen_Context $page_ctx, $list_ctx)
    {
        return $list_ctx instanceof Meprmf_Screen_Context
            && self::are_peers($page_ctx, $list_ctx);
    }

    /**
     * Build GET overrides for predicate builders on the target tab from the source request.
     *
     * @param Meprmf_Screen_Context $from Source screen (admin ?page=).
     * @param Meprmf_Screen_Context $to   Target screen (list_table() caller).
     * @return array<string, string|array<int, string>>
     */
    public static function translate_request_params(Meprmf_Screen_Context $from, Meprmf_Screen_Context $to)
    {
        if (! self::are_peers($from, $to)) {
            return [];
        }

        $overrides = [];

        foreach (self::pass_through_params() as $param) {
            self::copy_request_param($overrides, $param, $param);
        }

        foreach (self::collect_prefix_params('mpfs_') as $param => $value) {
            $overrides[ $param ] = $value;
        }

        $to_by_predicate = self::predicate_field_map(
            Meprmf_Filter_Registry::get_normalized_mepr_predicate_fields_for_context($to)
        );

        foreach (Meprmf_Filter_Registry::get_normalized_mepr_predicate_fields_for_context($from) as $from_field) {
            $predicate = isset($from_field['predicate']) ? (string) $from_field['predicate'] : '';
            if ('' === $predicate || ! isset($to_by_predicate[ $predicate ])) {
                continue;
            }

            self::copy_field_request_params($overrides, $from_field, $to_by_predicate[ $predicate ]);
        }

        return $overrides;
    }

    /**
     * Core filter params on the source screen that do not map to the peer tab.
     *
     * @param Meprmf_Screen_Context $from Source screen.
     * @param Meprmf_Screen_Context $to   Peer screen.
     * @return array<int, string>
     */
    public static function untranslatable_core_params(Meprmf_Screen_Context $from, Meprmf_Screen_Context $to)
    {
        if (! self::are_peers($from, $to)) {
            return [];
        }

        $to_predicates = array_keys(
            self::predicate_field_map(
                Meprmf_Filter_Registry::get_normalized_mepr_predicate_fields_for_context($to)
            )
        );

        $drop = [];
        foreach (Meprmf_Filter_Registry::get_normalized_mepr_predicate_fields_for_context($from) as $field) {
            $predicate = isset($field['predicate']) ? (string) $field['predicate'] : '';
            if ('' === $predicate || in_array($predicate, $to_predicates, true)) {
                continue;
            }

            foreach (Meprmf_Util::collect_field_request_params($field) as $param) {
                if ('' !== $param && self::request_param_is_active($param)) {
                    $drop[] = $param;
                }
            }
        }

        sort($drop, SORT_STRING);

        return array_values(array_unique($drop));
    }

    /**
     * Config for rewriting subscription tab links in the floating panel script.
     *
     * @param Meprmf_Screen_Context $ctx Current screen.
     * @return array<string, mixed>|null
     */
    public static function tab_link_config(Meprmf_Screen_Context $ctx)
    {
        $peer = self::peer_context($ctx);
        if (null === $peer) {
            return null;
        }

        return [
            'peerPage'       => $peer->get_page(),
            'coreFromPrefix' => $ctx->get_core_filter_param_prefix(),
            'coreToPrefix'   => $peer->get_core_filter_param_prefix(),
            'dropParams'     => self::untranslatable_core_params($ctx, $peer),
        ];
    }

    /**
     * GET params copied verbatim between subscription tabs.
     *
     * @return array<int, string>
     */
    private static function pass_through_params()
    {
        return [
            Meprmf_Util::MATCH_MODE_PARAM,
            Meprmf_Presets::SUPPRESS_PARAM,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields Field rows.
     * @return array<string, array<string, mixed>>
     */
    private static function predicate_field_map(array $fields)
    {
        $map = [];
        foreach ($fields as $field) {
            if (empty($field['predicate'])) {
                continue;
            }
            $map[ (string) $field['predicate'] ] = $field;
        }

        return $map;
    }

    /**
     * @param array<string, string|array<int, string>> $overrides Outgoing overrides.
     * @param array<string, mixed>                      $from_field Source field row.
     * @param array<string, mixed>                      $to_field   Target field row with the same predicate.
     * @return void
     */
    private static function copy_field_request_params(array &$overrides, array $from_field, array $to_field)
    {
        $from_param = isset($from_field['param']) ? (string) $from_field['param'] : '';
        $to_param   = isset($to_field['param']) ? (string) $to_field['param'] : '';
        if ('' !== $from_param && '' !== $to_param) {
            self::copy_request_param($overrides, $from_param, $to_param);
        }

        $from_base = Meprmf_Util::range_base_param($from_field);
        $to_base   = Meprmf_Util::range_base_param($to_field);

        $from_op = Meprmf_Util::operator_param_name($from_base);
        $to_op   = Meprmf_Util::operator_param_name($to_base);
        if ('' !== $from_op && '' !== $to_op) {
            self::copy_request_param($overrides, $from_op, $to_op);
        }

        $from_rel = Meprmf_Util::relative_param_names($from_base);
        $to_rel   = Meprmf_Util::relative_param_names($to_base);
        if ('' !== $from_rel['n'] && '' !== $to_rel['n']) {
            self::copy_request_param($overrides, $from_rel['n'], $to_rel['n']);
        }
        if ('' !== $from_rel['unit'] && '' !== $to_rel['unit']) {
            self::copy_request_param($overrides, $from_rel['unit'], $to_rel['unit']);
        }

        $from_range = Meprmf_Util::date_range_param_names($from_base);
        $to_range   = Meprmf_Util::date_range_param_names($to_base);
        if ('' !== $from_range['from'] && '' !== $to_range['from']) {
            self::copy_request_param($overrides, $from_range['from'], $to_range['from']);
        }
        if ('' !== $from_range['to'] && '' !== $to_range['to']) {
            self::copy_request_param($overrides, $from_range['to'], $to_range['to']);
        }
    }

    /**
     * @param array<string, string|array<int, string>> $overrides Outgoing overrides.
     * @param string                                   $from_param Source GET key.
     * @param string                                   $to_param   Target GET key.
     * @return void
     */
    private static function copy_request_param(array &$overrides, $from_param, $to_param)
    {
        $values = Meprmf_Util::get_request_values($from_param);
        if (! empty($values)) {
            $overrides[ $to_param ] = 1 === count($values) ? $values[0] : $values;
            return;
        }

        $scalar = Meprmf_Util::get_request_value($from_param);
        if ('' !== $scalar) {
            $overrides[ $to_param ] = $scalar;
        }
    }

    /**
     * @param string $prefix Param prefix including trailing underscore.
     * @return array<string, string|array<int, string>>
     */
    private static function collect_prefix_params($prefix)
    {
        $out = [];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter query args on admin list screens.
        if (empty($_GET) || ! is_array($_GET)) {
            return $out;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        foreach (array_keys($_GET) as $raw_key) {
            $param = Meprmf_Util::sanitize_param((string) $raw_key);
            if ('' === $param || 0 !== strpos($param, $prefix)) {
                continue;
            }

            $values = Meprmf_Util::get_request_values($param);
            if (! empty($values)) {
                $out[ $param ] = $values;
                continue;
            }

            $scalar = Meprmf_Util::get_request_value($param);
            if ('' !== $scalar) {
                $out[ $param ] = $scalar;
            }
        }

        return $out;
    }

    /**
     * @param string $param GET param.
     * @return bool
     */
    private static function request_param_is_active($param)
    {
        return '' !== Meprmf_Util::get_request_value($param) || ! empty(Meprmf_Util::get_request_values($param));
    }
}
