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

// Site-wide options from the Settings screen (MemberPress -> Admin Filters).
delete_option('meprmf_settings');

// Per-admin date-range UI preference (Settings → customize in the floating panel).
delete_metadata('user', 0, 'meprmf_date_custom_fields_use_range', '', true);

/*
 * Per-admin default view and pinned menu entries, one meta key per list screen. The ids are
 * the storage ids of Meprmf_Screen::supported_page_contexts() -- listed literally so this file
 * stays loadable with nothing but WordPress, the way the rest of it is.
 */
foreach ([ 'memberpress_members', 'memberpress_subscriptions', 'memberpress_lifetimes', 'memberpress_trans' ] as $meprmf_screen_id) {
    delete_metadata('user', 0, 'meprmf_default_view_' . $meprmf_screen_id, '', true);
    delete_metadata('user', 0, 'meprmf_pinned_view_' . $meprmf_screen_id, '', true);
}
unset($meprmf_screen_id);
