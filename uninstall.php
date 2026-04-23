<?php
/**
 * Uninstall handler for Admin Filters for MemberPress (folder: admin-filters-for-memberpress; text domain: admin-filters-for-memberpress).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Legacy option from older releases; harmless if already absent.
delete_option('meprmf_additional_filters');
