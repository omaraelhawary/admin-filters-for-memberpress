<?php
/**
 * Loads plugin classes (order matters).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-meprmf-util.php';
require_once __DIR__ . '/class-meprmf-capabilities.php';
require_once __DIR__ . '/screen/class-meprmf-screen-context.php';
require_once __DIR__ . '/screen/class-meprmf-screen.php';
require_once __DIR__ . '/screen/class-meprmf-subscription-tabs.php';
require_once __DIR__ . '/class-meprmf-native-params.php';
require_once __DIR__ . '/filters/providers/class-meprmf-members-provider.php';
require_once __DIR__ . '/filters/providers/class-meprmf-members-core-provider.php';
require_once __DIR__ . '/filters/providers/class-meprmf-addon-provider.php';
require_once __DIR__ . '/filters/providers/class-meprmf-members-activity-provider.php';
require_once __DIR__ . '/filters/class-meprmf-filter-registry.php';
require_once __DIR__ . '/sql/class-meprmf-corporate-predicates.php';
require_once __DIR__ . '/sql/class-meprmf-predicate-builder.php';
require_once __DIR__ . '/sql/class-meprmf-mepr-predicate-builder.php';
require_once __DIR__ . '/ui/class-meprmf-active-filters.php';
require_once __DIR__ . '/ui/class-meprmf-toolbar-renderer.php';
require_once __DIR__ . '/ui/class-meprmf-columns.php';
require_once __DIR__ . '/ui/class-meprmf-debug-panel.php';
require_once __DIR__ . '/class-meprmf-settings.php';
require_once __DIR__ . '/admin/class-meprmf-settings-page.php';
require_once __DIR__ . '/class-meprmf-presets.php';
require_once __DIR__ . '/bulk/class-meprmf-bulk-set-meta.php';
require_once __DIR__ . '/bulk/class-meprmf-bulk-runner.php';
require_once __DIR__ . '/bulk/class-meprmf-bulk-match-set.php';
require_once __DIR__ . '/bulk/class-meprmf-bulk-snapshot.php';
require_once __DIR__ . '/bulk/class-meprmf-bulk.php';
require_once __DIR__ . '/cli/class-meprmf-cli-list-command.php';
require_once __DIR__ . '/class-meprmf-plugin.php';
