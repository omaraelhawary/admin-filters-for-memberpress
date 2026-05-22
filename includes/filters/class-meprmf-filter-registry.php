<?php
/**
 * Resolves filter definitions per MemberPress admin list screen.
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
     * Normalized meta (usermeta) field definitions for the Members screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_meta_fields_for_members()
    {
        return Meprmf_Util::normalize_filter_fields(Meprmf_Members_Provider::get_filter_fields());
    }

    /**
     * Normalized field definitions for the Members screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_fields_for_members()
    {
        return array_merge(
            self::get_normalized_core_fields_for_members(),
            self::get_normalized_meta_fields_for_members()
        );
    }

    /**
     * Normalized core MemberPress table filter fields (Members list only).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_core_fields_for_members()
    {
        return self::get_normalized_core_fields_for_context(
            new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID')
        );
    }

    /**
     * Normalized core MemberPress table filter fields for a list screen.
     *
     * @param Meprmf_Screen_Context $ctx Context.
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_core_fields_for_context(Meprmf_Screen_Context $ctx)
    {
        if (! $ctx->supports_core_filters()) {
            return [];
        }

        return Meprmf_Util::normalize_core_filter_fields(
            Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx)
        );
    }

    /**
     * Normalized meta (usermeta) fields for a detected screen context.
     *
     * @param Meprmf_Screen_Context $ctx Context.
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_meta_fields_for_context(Meprmf_Screen_Context $ctx)
    {
        if (! $ctx->supports_meta_filters_list()) {
            return [];
        }
        return Meprmf_Util::normalize_filter_fields(Meprmf_Members_Provider::get_filter_fields_for_context($ctx));
    }

    /**
     * Normalized fields for a detected screen context (core + meta on Members).
     *
     * @param Meprmf_Screen_Context $ctx Context.
     * @return array<int, array<string, mixed>>
     */
    public static function get_normalized_fields_for_context(Meprmf_Screen_Context $ctx)
    {
        if (! $ctx->supports_meta_filters_list()) {
            return [];
        }

        $meta = self::get_normalized_meta_fields_for_context($ctx);

        return array_merge(self::get_normalized_core_fields_for_context($ctx), $meta);
    }
}
