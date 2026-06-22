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
     * Screen context for the MemberPress list-table call currently inside MeprDb::list_table().
     *
     * Uses the immediate caller of MeprDb::list_table() so predicates are not applied to
     * unrelated list_table() queries (e.g. upgrade queries) or the wrong model on the same request.
     *
     * @return Meprmf_Screen_Context|null
     */
    public static function detect_list_table_context()
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Identifies the MemberPress list_table() caller; not debug logging.
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        foreach ($trace as $frame) {
            if (empty($frame['class']) || empty($frame['function'])) {
                continue;
            }
            $ctx = self::context_for_list_table_caller((string) $frame['class'], (string) $frame['function']);
            if (null !== $ctx) {
                return $ctx;
            }
        }

        return null;
    }

    /**
     * Map a MemberPress list_table() caller to a screen context.
     *
     * @param string $class    Class name from debug_backtrace.
     * @param string $function Method name from debug_backtrace.
     * @return Meprmf_Screen_Context|null
     */
    public static function context_for_list_table_caller($class, $function)
    {
        $key = $class . '::' . $function;

        $ctx = null;

        switch ($key) {
            case 'MeprUser::list_table':
                $ctx = new Meprmf_Screen_Context(self::PAGE_MEMBERS, 'u.ID');
                break;
            case 'MeprTransaction::list_table':
                $ctx = new Meprmf_Screen_Context(self::PAGE_TRANSACTIONS, 'tr.user_id');
                break;
            case 'MeprSubscription::subscr_table':
                $ctx = new Meprmf_Screen_Context(self::PAGE_SUBSCRIPTIONS, 'sub.user_id');
                break;
            case 'MeprSubscription::lifetime_subscr_table':
                $ctx = new Meprmf_Screen_Context(self::PAGE_LIFETIMES, 'txn.user_id');
                break;
        }

        if (null !== $ctx) {
            return $ctx;
        }

        /**
         * Map a custom MemberPress list_table() caller to screen context.
         *
         * @since 1.9.0
         * @param Meprmf_Screen_Context|null $ctx     Null when the built-in map has no match.
         * @param string                     $class   Class from debug_backtrace.
         * @param string                     $function Method from debug_backtrace.
         */
        return apply_filters('meprmf_list_table_caller_context', null, $class, $function);
    }

    /**
     * Screen context for a stable storage id (localStorage / presets bucket).
     *
     * @param string $storage_id Value from {@see Meprmf_Screen_Context::get_storage_id()}.
     * @return Meprmf_Screen_Context|null
     */
    public static function context_for_storage_id($storage_id)
    {
        $storage_id = strtolower(trim((string) $storage_id));
        foreach (self::supported_page_contexts() as $ctx) {
            if ($ctx->get_storage_id() === $storage_id) {
                return $ctx;
            }
        }

        return null;
    }

    /**
     * All supported list-table screen contexts.
     *
     * @return array<int, Meprmf_Screen_Context>
     */
    public static function supported_page_contexts()
    {
        return [
            new Meprmf_Screen_Context(self::PAGE_MEMBERS, 'u.ID'),
            new Meprmf_Screen_Context(self::PAGE_SUBSCRIPTIONS, 'sub.user_id'),
            new Meprmf_Screen_Context(self::PAGE_LIFETIMES, 'txn.user_id'),
            new Meprmf_Screen_Context(self::PAGE_TRANSACTIONS, 'tr.user_id'),
        ];
    }

    /**
     * Whether predicates may run for this admin list (list-table caller, page slug, and WP_Screen).
     *
     * @param Meprmf_Screen_Context $ctx Context from {@see detect()} (admin page slug).
     * @return bool
     */
    public static function should_apply_list_table_predicates(Meprmf_Screen_Context $ctx)
    {
        $list_ctx = self::detect_list_table_context();
        if (null === $list_ctx || $list_ctx->get_page() !== $ctx->get_page()) {
            return false;
        }

        return self::current_wp_screen_matches_context($ctx);
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
            // Caller + admin page slug already matched; custom admin bootstraps may omit WP_Screen.
            return true;
        }
        $screen = get_current_screen();
        if (! $screen || empty($screen->id)) {
            // Caller + admin page slug already matched; screen id may be unset early in admin.
            return true;
        }

        return $screen->id === $ctx->get_wp_screen_id();
    }
}
