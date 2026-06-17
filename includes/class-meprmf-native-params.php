<?php
/**
 * MemberPress native toolbar GET params (presets + knownParams).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Whitelist of native MemberPress toolbar query keys per list screen.
 */
class Meprmf_Native_Params
{

    /**
     * Native toolbar param keys for a list screen.
     *
     * @param Meprmf_Screen_Context $ctx Screen context.
     * @return array<int, string>
     */
    public static function for_context(Meprmf_Screen_Context $ctx)
    {
        if (! $ctx->supports_meta_filters_list()) {
            return [];
        }

        $keys = [];

        if ($ctx->is_members()) {
            $keys = [ 'status', 'membership' ];
        } elseif ($ctx->is_subscriptions_recurring() || $ctx->is_lifetimes()) {
            $keys = [ 'status', 'membership', 'gateway' ];
        } elseif ($ctx->is_transactions()) {
            $keys = [
                'status',
                'membership',
                'gateway',
                'date_range_filter',
                'date_start',
                'date_end',
                'date_field',
            ];
            if (self::is_gifting_active()) {
                $keys[] = 'type';
            }
        }

        /**
         * Native MemberPress toolbar GET keys included in presets and knownParams.
         *
         * @since 2.0.0
         * @param array<int, string>   $keys Toolbar param keys.
         * @param Meprmf_Screen_Context $ctx  Screen context.
         */
        return apply_filters('meprmf_native_toolbar_params', $keys, $ctx);
    }

    /**
     * @return bool
     */
    private static function is_gifting_active()
    {
        return class_exists('memberpress\\gifting\\controllers\\App');
    }
}
