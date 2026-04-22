<?php
/**
 * Resolves filter definitions per screen (Members in Phase 0).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Filter registry.
 */
class Meprmf_Filter_Registry
{

    /**
     * Normalized field definitions for the Members screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_fields_for_members()
    {
        return Meprmf_Util::normalize_filter_fields(Meprmf_Members_Provider::get_filter_fields());
    }

    /**
     * Normalized fields for a detected screen context.
     *
     * @param Meprmf_Screen_Context $ctx Context.
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_fields_for_context(Meprmf_Screen_Context $ctx)
    {
        if ($ctx->is_members()) {
            return self::get_normalized_fields_for_members();
        }
        return [];
    }
}
