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
delete_option('meprmf_filter_presets');

// Per-admin date-range UI preference (Settings → customize in the floating panel).
delete_metadata('user', 0, 'meprmf_date_custom_fields_use_range', '', true);
