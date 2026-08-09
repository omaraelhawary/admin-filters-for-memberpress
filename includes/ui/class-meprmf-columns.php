<?php
/**
 * Shows the fields you filtered on as columns in the MemberPress list tables.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adds usermeta-backed columns to the MemberPress list tables.
 *
 * You can filter Members by country or by any Settings -> Fields custom field and the
 * table still will not show you the value you filtered on. This registers a column for
 * each active meta filter through MemberPress's own column filters, and renders the
 * cell through the `default:` hook each of its row views provides.
 */
class Meprmf_Columns
{

    /** Prefix for column keys this plugin registers. */
    const COL_PREFIX = 'meprmf_col_';

    /**
     * Resolved columns for this request.
     *
     * The cell hook fires once per row per column, so without this the field registry
     * would be rebuilt from MeprOptions on every cell.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static $resolved = null;

    /**
     * Register column and cell hooks for every supported screen.
     *
     * @return void
     */
    public static function init()
    {
        add_filter('mepr_admin_members_cols', [ __CLASS__, 'add_columns' ]);
        add_filter('mepr_admin_transactions_cols', [ __CLASS__, 'add_columns' ]);
        add_filter('mepr_admin_subscriptions_cols', [ __CLASS__, 'add_columns' ]);

        // Each row view names its own hook and orders the args differently.
        add_action('mepr_members_list_table_row', [ __CLASS__, 'render_members_cell' ], 10, 4);
        add_action('mepr_admin_transactions_cell', [ __CLASS__, 'render_transactions_cell' ], 10, 3);
        add_action('mepr_admin_subscriptions_cell', [ __CLASS__, 'render_subscriptions_cell' ], 10, 4);
    }

    /**
     * Meta fields that should appear as columns on the current screen.
     *
     * Defaults to "whatever is currently being filtered on", which is the case where a
     * missing column is actually confusing. Only usermeta-backed fields qualify: the core
     * MemberPress filters (access, status, membership) already have their own columns.
     *
     * @return array<string, array<string, mixed>> Column key => field definition.
     */
    public static function columns_for_current_screen()
    {
        if (null !== self::$resolved) {
            return self::$resolved;
        }

        self::$resolved = self::resolve_columns();

        return self::$resolved;
    }

    /**
     * Reset the per-request column cache (tests).
     *
     * @return void
     */
    public static function reset_cache()
    {
        self::$resolved = null;
    }

    /**
     * Work out the columns without consulting the cache.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function resolve_columns()
    {
        $ctx = Meprmf_Screen::detect();
        if (null === $ctx || ! $ctx->supports_meta_filters_list()) {
            return [];
        }
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return [];
        }

        $fields = Meprmf_Filter_Registry::get_normalized_meta_fields_for_context($ctx);
        $out    = [];

        foreach ($fields as $field) {
            $param = Meprmf_Util::sanitize_param(isset($field['param']) ? $field['param'] : '');
            if ('' === $param || empty($field['meta_key'])) {
                continue;
            }
            if (! self::is_field_active($field, $param)) {
                continue;
            }

            $out[ self::COL_PREFIX . $param ] = $field;
        }

        /**
         * Columns added for the fields currently being filtered on.
         *
         * Return an empty array to switch the behaviour off, or add your own
         * `meprmf_col_*` entries keyed the same way to force a column on.
         *
         * @since 2.1.0
         * @param array<string, array<string, mixed>> $out Column key => field definition.
         * @param Meprmf_Screen_Context               $ctx Screen context.
         */
        return apply_filters('meprmf_meta_columns', $out, $ctx);
    }

    /**
     * Whether a field currently constrains the list.
     *
     * A date range is active when either bound is set, and "is empty" / "is not empty"
     * count even though their value box is blank.
     *
     * @param array<string, mixed> $field Field definition.
     * @param string               $param Base param.
     * @return bool
     */
    private static function is_field_active(array $field, $param)
    {
        foreach (Meprmf_Util::collect_field_request_params($field) as $p) {
            if (Meprmf_Util::is_operator_param($p)) {
                continue;
            }
            if ('' !== Meprmf_Util::get_request_value($p)) {
                return true;
            }
        }

        return Meprmf_Util::has_valueless_operator($param);
    }

    /**
     * Append this plugin's columns to a MemberPress column set.
     *
     * @param mixed $cols Existing columns.
     * @return mixed
     */
    public static function add_columns($cols)
    {
        if (! is_array($cols)) {
            return $cols;
        }

        foreach (self::columns_for_current_screen() as $key => $field) {
            if (isset($cols[ $key ])) {
                continue;
            }
            $cols[ $key ] = isset($field['label']) ? (string) $field['label'] : $key;
        }

        return $cols;
    }

    /**
     * Resolve and echo one cell.
     *
     * `get_user_meta()` caches every meta row for a user on first access, so N added
     * columns cost one query per row rather than one per cell. It is still one query per
     * row on the page: MemberPress's list query does not select these values and its row
     * views expose no hook that sees the whole page at once, so there is nowhere to batch.
     *
     * @param string $column_name Column key.
     * @param int    $user_id     Row's user id.
     * @param string $attributes  Pre-built td attributes from the row view.
     * @return void
     */
    private static function render_cell($column_name, $user_id, $attributes)
    {
        $columns = self::columns_for_current_screen();
        if (! isset($columns[ $column_name ])) {
            return;
        }

        $user_id = (int) $user_id;
        $value   = '';

        if ($user_id > 0) {
            $raw = get_user_meta($user_id, (string) $columns[ $column_name ]['meta_key'], true);
            if (is_array($raw)) {
                $raw = implode(', ', array_filter(array_map('strval', $raw), 'strlen'));
            }
            $value = is_scalar($raw) ? (string) $raw : '';
        }

        $field   = $columns[ $column_name ];
        $options = ( ! empty($field['options']) && is_array($field['options'])) ? $field['options'] : [];

        // Country fields carry no options of their own; show the name, not the code.
        if ('' !== $value && empty($options) && 'country' === ( isset($field['type']) ? $field['type'] : '' )
            && class_exists('MeprUtils') && method_exists('MeprUtils', 'countries')
        ) {
            $countries = MeprUtils::countries(true);
            if (is_array($countries)) {
                $options = $countries;
            }
        }

        if ('' !== $value && ! empty($options)) {
            foreach ($options as $opt_value => $opt_label) {
                if ((string) $opt_value === $value) {
                    $value = (string) $opt_label;
                    break;
                }
            }
        }

        // $attributes is assembled by MemberPress's own row view from a class name and an
        // optional inline display:none; it is markup by the time it reaches this hook.
        echo '<td ' . wp_kses_data($attributes) . '>' . esc_html($value) . '</td>';
    }

    /**
     * Members row view: do_action('mepr_members_list_table_row', $attributes, $rec, $column_name, $column_display_name).
     *
     * @param string $attributes  Td attributes.
     * @param object $rec         Row record.
     * @param string $column_name Column key.
     * @return void
     */
    public static function render_members_cell($attributes, $rec, $column_name)
    {
        self::render_cell((string) $column_name, isset($rec->ID) ? $rec->ID : 0, (string) $attributes);
    }

    /**
     * Transactions row view: do_action('mepr_admin_transactions_cell', $column_name, $rec, $attributes).
     *
     * @param string $column_name Column key.
     * @param object $rec         Row record.
     * @param string $attributes  Td attributes.
     * @return void
     */
    public static function render_transactions_cell($column_name, $rec, $attributes)
    {
        self::render_cell((string) $column_name, isset($rec->user_id) ? $rec->user_id : 0, (string) $attributes);
    }

    /**
     * Subscriptions row view: do_action('mepr_admin_subscriptions_cell', $column_name, $rec, $table, $attributes).
     *
     * @param string $column_name Column key.
     * @param object $rec         Row record.
     * @param mixed  $table       Table instance (unused).
     * @param string $attributes  Td attributes.
     * @return void
     */
    public static function render_subscriptions_cell($column_name, $rec, $table, $attributes)
    {
        self::render_cell((string) $column_name, isset($rec->user_id) ? $rec->user_id : 0, (string) $attributes);
    }
}
