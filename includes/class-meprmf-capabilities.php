<?php
/**
 * Capability checks aligned with MemberPress admin menus.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Capability helpers.
 */
class Meprmf_Capabilities
{

    /**
     * Whether the current user may use admin filters.
     *
     * @return bool
     */
    public static function current_user_can_filter()
    {
        return current_user_can(MeprUtils::get_mepr_admin_capability());
    }
}
