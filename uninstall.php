<?php
/**
 * Uninstall handler for MemberPress Members Meta Filters.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('meprmf_additional_filters');
