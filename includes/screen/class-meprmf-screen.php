<?php
/**
 * Detects which MemberPress admin list screen is active.
 *
 * Unknown screens return null so list-table hooks are left unchanged
 * (e.g. MeprSubscription::upgrade_query() must not get member filters).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Screen detection.
 */
class Meprmf_Screen
{

    public const PAGE_MEMBERS        = 'memberpress-members';

    public const PAGE_SUBSCRIPTIONS  = 'memberpress-subscriptions';

    public const PAGE_LIFETIMES      = 'memberpress-lifetimes';

    public const PAGE_TRANSACTIONS   = 'memberpress-trans';

    /**
     * Resolve context for the current admin request, or null if unsupported / unknown.
     *
     * @return Meprmf_Screen_Context|null
     */
    public static function detect()
    {
        if (! is_admin()) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['page'])) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = sanitize_text_field(wp_unslash($_GET['page']));

        switch ($page) {
            case self::PAGE_MEMBERS:
                return new Meprmf_Screen_Context(self::PAGE_MEMBERS, 'u.ID');
            case self::PAGE_SUBSCRIPTIONS:
                return new Meprmf_Screen_Context(self::PAGE_SUBSCRIPTIONS, 'sub.user_id');
            case self::PAGE_LIFETIMES:
                return new Meprmf_Screen_Context(self::PAGE_LIFETIMES, 'txn.user_id');
            case self::PAGE_TRANSACTIONS:
                return new Meprmf_Screen_Context(self::PAGE_TRANSACTIONS, 'tr.user_id');
            default:
                return null;
        }
    }

    /**
     * Whether the current admin request targets a screen where meta filters apply.
     *
     * @return bool
     */
    public static function is_meta_filters_admin_list_request()
    {
        $ctx = self::detect();
        return null !== $ctx && $ctx->supports_meta_filters_list();
    }

    /**
     * Whether the Members list is the active admin page.
     *
     * @return bool
     */
    public static function is_members_admin_list_request()
    {
        $ctx = self::detect();
        return null !== $ctx && $ctx->is_members();
    }

    /**
     * When WP_Screen is available and identified, ensure it matches the list table
     * for this context (avoids applying predicates to unrelated MeprDb::list_table calls).
     *
     * @param Meprmf_Screen_Context $ctx Context.
     * @return bool
     */
    public static function current_wp_screen_matches_context(Meprmf_Screen_Context $ctx)
    {
        if (! function_exists('get_current_screen')) {
            return true;
        }
        $screen = get_current_screen();
        if ($screen && ! empty($screen->id)) {
            return $screen->id === $ctx->get_wp_screen_id();
        }
        return true;
    }
}
