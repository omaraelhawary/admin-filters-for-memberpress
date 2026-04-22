<?php
/**
 * Filter field definitions for the Members admin list (user meta / address / custom fields).
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Members list filter definitions.
 */
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Field definitions use usermeta key name as schema; arrays are not WP_Query meta_key clauses.
class Meprmf_Members_Provider
{

    /** @var array<int, array<string, mixed>>|null */
    private static $cached_filter_fields = null;

    /**
     * Clear in-request cache (e.g. after tests or option updates).
     *
     * @return void
     */
    public static function clear_filter_fields_cache()
    {
        self::$cached_filter_fields = null;
    }

    /**
     * Map a MemberPress custom field definition to a filter field row, or null to skip.
     *
     * @param object $cf Custom field object from MeprOptions.
     * @return array<string, mixed>|null
     */
    public static function map_mepr_custom_field_to_filter($cf)
    {
        if (empty($cf->field_key) || empty($cf->field_name)) {
            return null;
        }

        $prefix = 'mpf_';
        $param  = $prefix . sanitize_key(str_replace('-', '_', $cf->field_key));
        if (strlen($param) < strlen($prefix) + 2) {
            return null;
        }

        $field_key = (string) $cf->field_key;
        $label     = (string) $cf->field_name;
        $ftype     = isset($cf->field_type) ? (string) $cf->field_type : 'text';

        $option_rows_to_map = static function ($cf_obj) {
            $options = [];
            if (empty($cf_obj->options) || ! is_array($cf_obj->options)) {
                return $options;
            }
            foreach ($cf_obj->options as $option) {
                if (empty($option->option_value)) {
                    continue;
                }
                $options[ (string) $option->option_value ] = (string) $option->option_name;
            }
            return $options;
        };

        if (in_array($ftype, [ 'dropdown', 'radios' ], true)) {
            $options = $option_rows_to_map($cf);
            if (empty($options)) {
                return null;
            }
            return [
                'param'    => $param,
                'meta_key' => $field_key,
                'label'    => $label,
                'type'     => 'select',
                'match'    => 'exact',
                'options'  => $options,
            ];
        }

        if (in_array($ftype, [ 'multiselect', 'checkboxes' ], true)) {
            $options = $option_rows_to_map($cf);
            if (empty($options)) {
                return null;
            }
            return [
                'param'    => $param,
                'meta_key' => $field_key,
                'label'    => $label,
                'type'     => 'select',
                'match'    => 'contains',
                'options'  => $options,
            ];
        }

        if ('checkbox' === $ftype) {
            return [
                'param'    => $param,
                'meta_key' => $field_key,
                'label'    => $label,
                'type'     => 'checkbox',
                'match'    => 'exact',
            ];
        }

        if (in_array($ftype, [ 'text', 'email', 'url', 'tel', 'date', 'textarea', 'file' ], true)) {
            return [
                'param'    => $param,
                'meta_key' => $field_key,
                'label'    => $label,
                'type'     => 'text',
                'match'    => 'like',
            ];
        }

        return null;
    }

    /**
     * Built-in filters for the six MemberPress address fields.
     *
     * @param object $opts MeprOptions instance.
     * @return array<int, array<string, mixed>>
     */
    public static function get_address_filter_fields($opts)
    {
        // MemberPress separates "Show fields below…" (checkout / signup) from "Show on Account".
        // Admins often enable only account-side capture; still expose address filters for the Members list.
        $address_capture_enabled = ! empty($opts->show_address_fields) || ! empty($opts->show_address_on_account);

        $enabled = (bool) apply_filters(
            'meprmf_include_address_filters',
            $address_capture_enabled,
            $opts
        );

        if (! $enabled) {
            return [];
        }

        $country_label = __('Country', 'admin-filters-for-memberpress');
        $state_label   = __('State / Province', 'admin-filters-for-memberpress');
        $city_label    = __('City', 'admin-filters-for-memberpress');
        $zip_label     = __('Zip / Postal code', 'admin-filters-for-memberpress');
        $addr1_label   = __('Address line 1', 'admin-filters-for-memberpress');
        $addr2_label   = __('Address line 2', 'admin-filters-for-memberpress');

        if (! empty($opts->address_fields) && is_array($opts->address_fields)) {
            foreach ($opts->address_fields as $af) {
                if (empty($af->field_key) || empty($af->field_name)) {
                    continue;
                }
                switch ($af->field_key) {
                    case 'mepr-address-one':
                        $addr1_label = (string) $af->field_name;
                        break;
                    case 'mepr-address-two':
                        $addr2_label = (string) $af->field_name;
                        break;
                    case 'mepr-address-city':
                        $city_label = (string) $af->field_name;
                        break;
                    case 'mepr-address-country':
                        $country_label = (string) $af->field_name;
                        break;
                    case 'mepr-address-state':
                        $state_label = (string) $af->field_name;
                        break;
                    case 'mepr-address-zip':
                        $zip_label = (string) $af->field_name;
                        break;
                }
            }
        }

        return [
            [
                'param'    => 'mpf_country',
                'meta_key' => 'mepr-address-country',
                'label'    => $country_label,
                'type'     => 'country',
                'match'    => 'exact',
            ],
            [
                'param'    => 'mpf_state',
                'meta_key' => 'mepr-address-state',
                'label'    => $state_label,
                'type'     => 'text',
                'match'    => 'like',
            ],
            [
                'param'    => 'mpf_city',
                'meta_key' => 'mepr-address-city',
                'label'    => $city_label,
                'type'     => 'text',
                'match'    => 'like',
            ],
            [
                'param'    => 'mpf_zip',
                'meta_key' => 'mepr-address-zip',
                'label'    => $zip_label,
                'type'     => 'text',
                'match'    => 'like',
            ],
            [
                'param'    => 'mpf_address_one',
                'meta_key' => 'mepr-address-one',
                'label'    => $addr1_label,
                'type'     => 'text',
                'match'    => 'like',
            ],
            [
                'param'    => 'mpf_address_two',
                'meta_key' => 'mepr-address-two',
                'label'    => $addr2_label,
                'type'     => 'text',
                'match'    => 'like',
            ],
        ];
    }

    /**
     * All filter field definitions for Members (cached per request).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_filter_fields()
    {
        if (null !== self::$cached_filter_fields) {
            return self::$cached_filter_fields;
        }

        $opts = MeprOptions::fetch();

        $fields = self::get_address_filter_fields($opts);

        if (! empty($opts->custom_fields) && is_array($opts->custom_fields)) {
            foreach ($opts->custom_fields as $cf) {
                $mapped = self::map_mepr_custom_field_to_filter($cf);
                if (null !== $mapped) {
                    $fields[] = $mapped;
                }
            }
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- MemberPress extension filter (upstream hook name).
        $fields = apply_filters('mepr_members_meta_filters_fields', $fields);

        self::$cached_filter_fields = $fields;
        return self::$cached_filter_fields;
    }
}
// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key
