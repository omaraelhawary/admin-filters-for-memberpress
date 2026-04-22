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
require_once __DIR__ . '/filters/providers/class-meprmf-members-provider.php';
require_once __DIR__ . '/filters/class-meprmf-filter-registry.php';
require_once __DIR__ . '/sql/class-meprmf-predicate-builder.php';
require_once __DIR__ . '/cache/class-meprmf-definitions-cache.php';
require_once __DIR__ . '/ui/class-meprmf-toolbar-renderer.php';
require_once __DIR__ . '/ui/class-meprmf-debug-panel.php';
require_once __DIR__ . '/class-meprmf-plugin.php';
