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

    /**
     * Whether the current user may run a bulk write against the filtered set.
     *
     * Deliberately above {@see current_user_can_filter()}: MemberPress's own admin capability
     * defaults to `remove_users`, which is what reading a filtered list already needs, and a
     * bulk write is not a read. `manage_options` is the same bar saved views use for managing
     * another administrator's work.
     *
     * @since 2.3.0
     * @return bool
     */
    public static function current_user_can_bulk_actions()
    {
        /**
         * Capability required to run bulk actions on the filtered set.
         *
         * @since 2.3.0
         * @param string $capability Default `manage_options`.
         */
        $capability = (string) apply_filters('meprmf_bulk_actions_capability', 'manage_options');

        return current_user_can($capability);
    }
}
