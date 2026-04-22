<?php
/**
 * Uninstall handler for Admin Filters for MemberPress (folder: admin-filters-for-memberpress; text domain: memberpress-members-meta-filters).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('meprmf_additional_filters');
