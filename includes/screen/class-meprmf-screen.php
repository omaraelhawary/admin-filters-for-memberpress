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

    public const PAGE_MEMBERS = 'memberpress-members';

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

        if (self::PAGE_MEMBERS === $page) {
            return new Meprmf_Screen_Context(self::PAGE_MEMBERS, 'u.ID');
        }

        return null;
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
}
