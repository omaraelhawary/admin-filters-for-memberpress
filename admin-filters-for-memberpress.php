<?php
/**
 * Plugin Name: Admin Filters for MemberPress
 * Plugin URI: https://wordpress.org/plugins/admin-filters-for-memberpress/
 * Description: Adds address and MemberPress custom-field filters to the MemberPress Members admin list. Codebase refactored for Transactions and Subscriptions in upcoming releases. Uses MemberPress hooks only.
 * Version: 1.6.6
 * Requires at least: 5.6
 * Requires PHP: 8.1
 * Author: Omar ElHawray
 * Author URI: https://omarelhawary.com
 * GitHub URI: https://github.com/omaraelhawary/admin-filters-for-memberpress
 * License: GPLv2 or later
 * Text Domain: admin-filters-for-memberpress
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

/** @var string Plugin version for asset cache-busting. */
if (! defined('MEPRMF_VERSION')) {
    define('MEPRMF_VERSION', '1.6.6');
}

require_once __DIR__ . '/includes/meprmf-load.php';
require_once __DIR__ . '/compat/legacy-functions.php';

/**
 * Bootstrap after plugins load.
 */
add_action(
    'plugins_loaded',
    static function () {
        // Translations load automatically for WordPress.org plugins (WP 4.6+); no load_plugin_textdomain() needed.

        if (! class_exists('MeprUtils') || ! class_exists('MeprOptions')) {
            return;
        }

        Meprmf_Plugin::init();
    },
    20
);
