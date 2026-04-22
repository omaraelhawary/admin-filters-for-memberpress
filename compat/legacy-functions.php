<?php
/**
 * Procedural API shims (backward compatibility for extensions and remove_action).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * URL to a file under this plugin directory.
 *
 * @param string $relative Path relative to the main plugin file (e.g. `assets/foo.css`).
 * @return string
 */
function meprmf_plugin_url($relative)
{
    return plugins_url($relative, MEPRMF_PLUGIN_FILE);
}

/**
 * Only the Members screen uses alias `u` for wp_users in MeprUser::list_table().
 *
 * @return bool
 */
function meprmf_is_members_admin_list_request()
{
    return Meprmf_Screen::is_members_admin_list_request();
}

/**
 * Capability aligned with MemberPress admin menus.
 *
 * @return bool
 */
function meprmf_current_user_can_filter()
{
    return Meprmf_Capabilities::current_user_can_filter();
}

/**
 * Register submenu under MemberPress.
 *
 * @return void
 */
function meprmf_register_admin_menu()
{
    Meprmf_Plugin::register_admin_menu();
}

/**
 * Register option and sanitization.
 *
 * @return void
 */
function meprmf_register_settings()
{
    Meprmf_Settings_Page::register();
}

/**
 * Load admin styles/scripts on relevant screens.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function meprmf_admin_enqueue_scripts($hook_suffix)
{
    Meprmf_Plugin::admin_enqueue_scripts($hook_suffix);
}

/**
 * Sanitize saved additional filter rows.
 *
 * @param mixed $value Raw option value.
 * @return array<int, array<string, mixed>>
 */
function meprmf_sanitize_additional_filters_option($value)
{
    return Meprmf_Settings_Page::sanitize_additional_filters_option($value);
}

/**
 * Render settings page (MemberPress → Member list filters).
 *
 * @return void
 */
function meprmf_render_settings_page()
{
    Meprmf_Settings_Page::render();
}

/**
 * Build filter field rows from saved additional filters option.
 *
 * @return array<int, array<string, mixed>>
 */
function meprmf_get_additional_filter_fields()
{
    return Meprmf_Members_Provider::get_additional_filter_fields();
}

/**
 * Map a MemberPress custom field definition to a filter field row, or null to skip.
 *
 * @param object $cf Custom field object from MeprOptions.
 * @return array<string, mixed>|null
 */
function meprmf_map_mepr_custom_field_to_filter($cf)
{
    return Meprmf_Members_Provider::map_mepr_custom_field_to_filter($cf);
}

/**
 * Built-in filters for the six MemberPress address fields.
 *
 * @param object $opts MeprOptions instance.
 * @return array<int, array<string, mixed>>
 */
function meprmf_get_address_filter_fields($opts)
{
    return Meprmf_Members_Provider::get_address_filter_fields($opts);
}

/**
 * Field definitions: filterable.
 *
 * @return array<int, array<string, mixed>>
 */
function meprmf_get_filter_fields()
{
    return Meprmf_Members_Provider::get_filter_fields();
}

/**
 * Validate, sanitize, and dedupe filter field definitions.
 *
 * @param array<int, array<string, mixed>> $fields Raw field definitions.
 * @return array<int, array<string, mixed>>
 */
function meprmf_normalize_filter_fields(array $fields)
{
    return Meprmf_Util::normalize_filter_fields($fields);
}

/**
 * Resolve SQL match mode for a field.
 *
 * @param array<string, mixed> $field Field definition.
 * @return string 'exact'|'like'|'contains'
 */
function meprmf_get_field_match_mode(array $field)
{
    return Meprmf_Util::get_field_match_mode($field);
}

/**
 * Sanitize a HTML id / $_GET key to [a-z0-9_]. Null-safe.
 *
 * @param mixed $param Raw param.
 * @return string
 */
function meprmf_sanitize_param($param)
{
    return Meprmf_Util::sanitize_param($param);
}

/**
 * Read a scalar value from $_GET for the given param.
 *
 * @param string $param Param name.
 * @return string
 */
function meprmf_get_request_value($param)
{
    return Meprmf_Util::get_request_value($param);
}

/**
 * Output one filter control (select or search input).
 *
 * @param array<string, mixed> $field Field definition.
 * @param bool                 $compact   When true, show visible label and wrap in a grid cell.
 * @param bool                 $omit_name When true, omit `name` (floating panel).
 * @return void
 */
function meprmf_render_single_filter_control(array $field, $compact, $omit_name = false)
{
    Meprmf_Toolbar_Renderer::render_single_filter_control($field, $compact, $omit_name);
}

/**
 * Renders extra filter controls.
 *
 * @param string $search_term Unused.
 * @param int    $perpage     Unused.
 * @return void
 */
function meprmf_render_meta_filters($search_term, $perpage)
{
    Meprmf_Toolbar_Renderer::render($search_term, $perpage);
}

/**
 * Applies EXISTS subqueries on wp_usermeta for active filters.
 *
 * @param array<int, string> $args WHERE fragments for MeprDb::list_table.
 * @return array<int, string>
 */
function meprmf_filter_members_list_args($args)
{
    return Meprmf_Plugin::filter_list_table_args($args);
}
