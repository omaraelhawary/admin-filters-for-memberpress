<?php
/**
 * Plugin Name: Admin Filters for MemberPress
 * Plugin URI: https://github.com/omarelhawray/admin-filters-for-memberpress
 * Description: Adds address, MemberPress custom fields, and extra user-meta filters to the MemberPress Members admin list. Codebase refactored for Transactions and Subscriptions in upcoming releases. Uses MemberPress hooks only.
 * Version: 1.6.5
 * Requires at least: 5.6
 * Requires PHP: 8.1
 * Requires Plugins: memberpress
 * Author: Omar ElHawray
 * Author URI: https://omarelhawary.com
 * GitHub URI: https://github.com/omarelhawray/admin-filters-for-memberpress
 * License: GPLv2 or later
 * Text Domain: memberpress-members-meta-filters
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/** @var string Absolute path to this plugin file. */
if (! defined('MEPRMF_PLUGIN_FILE')) {
    define('MEPRMF_PLUGIN_FILE', __FILE__);
}

/** @var string Option name for manually configured filters. */
if (! defined('MEPRMF_OPTION_ADDITIONAL')) {
    define('MEPRMF_OPTION_ADDITIONAL', 'meprmf_additional_filters');
}

/** @var string Plugin version for asset cache-busting. */
if (! defined('MEPRMF_VERSION')) {
    define('MEPRMF_VERSION', '1.6.5');
}

/** @var int Maximum number of additional filter rows shown/stored on the settings page. */
if (! defined('MEPRMF_MAX_ROWS')) {
    define('MEPRMF_MAX_ROWS', 25);
}

require_once __DIR__ . '/includes/meprmf-load.php';
require_once __DIR__ . '/compat/legacy-functions.php';

/**
 * Bootstrap after plugins load.
 */
add_action(
    'plugins_loaded',
    static function () {
        load_plugin_textdomain(
            'memberpress-members-meta-filters',
            false,
            dirname(plugin_basename(MEPRMF_PLUGIN_FILE)) . '/languages'
        );

        if (! class_exists('MeprUtils') || ! class_exists('MeprOptions')) {
            return;
        }

        Meprmf_Plugin::init();
    },
    20
);
