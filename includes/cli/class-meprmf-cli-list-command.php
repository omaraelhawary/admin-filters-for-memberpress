<?php
/**
 * `wp meprmf list`: run the admin list filters outside the browser.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Print one MemberPress admin list, filtered the way the Filters card filters it.
 *
 * Deliberately not a `WP_CLI_Command` subclass. This file is required on every request from
 * includes/meprmf-load.php, and extending a class that only exists under WP-CLI would fatal
 * the whole site. WP-CLI registers an invokable class as a leaf command, so `__invoke` and
 * its helpers are the only place `WP_CLI` is named.
 */
class Meprmf_Cli_List_Command
{

    /**
     * Flags this command reads itself, so everything else is a filter param.
     *
     * @var array<int, string>
     */
    const OWN_FLAGS = [ 'screen', 'preset', 'limit', 'format' ];

    /**
     * Print a filtered MemberPress admin list.
     *
     * ## OPTIONS
     *
     * --screen=<screen>
     * : Which MemberPress admin list to query.
     * ---
     * options:
     *   - members
     *   - transactions
     *   - subscriptions
     *   - lifetimes
     * ---
     *
     * [--preset=<preset>]
     * : Saved view to apply, by id or by name. Names are matched without regard to case.
     *
     * [--limit=<limit>]
     * : Stop after this many rows. Every matching row is printed when this is omitted.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: csv
     * options:
     *   - csv
     *   - table
     *   - json
     * ---
     *
     * [--<field>=<value>]
     * : Any filter parameter the Filters card writes into the URL, such as --mpf_country=DE
     * or --mpm_product=42, plus the native MemberPress toolbar params for the screen. A flag
     * the screen does not know is an error rather than a silent no-op.
     *
     * ## EXAMPLES
     *
     *     # Members in Germany, as CSV.
     *     $ wp meprmf list --screen=members --mpf_country=DE --user=1
     *
     *     # A saved view, by name, as a table.
     *     $ wp meprmf list --screen=members --preset="Churn risk" --format=table --user=1
     *
     *     # Complete transactions for members in Germany, first 20 rows.
     *     $ wp meprmf list --screen=transactions --mpmt_txn_status=complete --mpf_country=DE --limit=20 --user=1
     *
     * @param array<int, string> $args       Positional args (none).
     * @param array<string, mixed> $assoc_args Flags.
     * @return void
     */
    public function __invoke($args, $assoc_args)
    {
        foreach ([ 'MeprUser', 'MeprTransaction', 'MeprSubscription', 'MeprUtils' ] as $class) {
            if (! class_exists($class)) {
                WP_CLI::error('MemberPress is not active, so there is no list to filter.');
            }
        }

        if (! Meprmf_Capabilities::current_user_can_filter()) {
            WP_CLI::error('This command reads a MemberPress admin list. Run it as an administrator with --user=<id>.');
        }

        $ctx = self::context_for_screen_flag((string) ($assoc_args['screen'] ?? ''));
        if (null === $ctx) {
            WP_CLI::error('Unknown --screen. Choose members, transactions, subscriptions or lifetimes.');
        }

        if (! $ctx->supports_meta_filters_list()) {
            WP_CLI::error('Filters are turned off for this list under MemberPress -> Admin Filters, so there is nothing to run.');
        }

        $storage_id    = $ctx->get_storage_id();
        $preset_params = [];
        $preset_ref    = isset($assoc_args['preset']) ? (string) $assoc_args['preset'] : '';
        if ('' !== $preset_ref) {
            $preset = Meprmf_Presets::find_preset_for_screen($storage_id, $preset_ref);
            if (null === $preset) {
                WP_CLI::error(sprintf('No saved view on this list matches "%s".', $preset_ref));
            }
            $preset_params = (array) $preset['params'];
        }

        $collected = self::collect_filter_params($ctx, self::filter_flags($assoc_args), $preset_params);
        if (! empty($collected['unknown'])) {
            WP_CLI::error(
                sprintf('This list has no filter called --%s.', implode(', --', $collected['unknown']))
            );
        }

        $params = $collected['params'];
        $rows   = self::fetch_rows($ctx, $params, self::limit_flag($assoc_args));

        // Refuse to print an unfiltered list when the caller asked for one of this plugin's
        // own filters and none of them reached the query: that means the filter was dropped,
        // not that it matched everything. A native-only filter is MemberPress's to apply, and
        // a call with no filters at all legitimately returns the whole list.
        $asked_for_predicates = Meprmf_Presets::request_asks_for_filters(
            $params,
            Meprmf_Presets::get_known_params_for_storage_id($storage_id)
        );
        if ($asked_for_predicates && ! Meprmf_Bulk_Match_Set::has_active_predicates()) {
            WP_CLI::error('The filters you passed produced no query conditions, so the list would come back unfiltered.');
        }

        if (empty($rows)) {
            WP_CLI::warning('No rows matched.');
            return;
        }

        $format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'csv';
        WP_CLI\Utils\format_items($format, $rows, array_keys((array) $rows[0]));
    }

    /**
     * Run the list-table query with this plugin's predicates applied.
     *
     * `Meprmf_Screen::detect()` answers from the admin request, which does not exist here, so
     * the screen is named for the duration of the call and the filter params stand in for
     * `$_GET`. Both are unwound in `finally` so a failure cannot leave the override behind.
     *
     * @param Meprmf_Screen_Context $ctx    Screen context.
     * @param array<string, string> $params Filter params.
     * @param int                   $limit  Row cap, or 0 for all rows.
     * @return array<int, object>
     */
    private static function fetch_rows(Meprmf_Screen_Context $ctx, array $params, $limit)
    {
        Meprmf_Screen::set_cli_context($ctx);
        Meprmf_Util::push_request_overrides($params);

        try {
            $match = Meprmf_Bulk_Match_Set::fetch($ctx, $params, '', $limit);
        } finally {
            Meprmf_Util::pop_request_overrides();
            Meprmf_Screen::set_cli_context(null);
        }

        return null === $match ? [] : $match['results'];
    }

    /**
     * Merge a saved view's params with the flags, keeping only params this list knows.
     *
     * Explicit flags win over the view they were passed alongside, which is how the same
     * combination behaves in the browser: a saved view sets the URL and then you edit a row.
     *
     * @param Meprmf_Screen_Context $ctx           Screen context.
     * @param array<string, mixed>  $flags         Flags with this command's own removed.
     * @param array<string, mixed>  $preset_params Params from a saved view, or empty.
     * @return array{params: array<string, string>, unknown: array<int, string>}
     */
    public static function collect_filter_params(Meprmf_Screen_Context $ctx, array $flags, array $preset_params = [])
    {
        $known = [];
        foreach (Meprmf_Presets::request_filter_params($ctx) as $param) {
            $known[ $param ] = true;
        }

        $params  = [];
        $unknown = [];

        foreach ($preset_params as $key => $value) {
            $key = Meprmf_Util::sanitize_param((string) $key);
            if ('' !== $key && isset($known[ $key ]) && is_scalar($value)) {
                $params[ $key ] = sanitize_text_field((string) $value);
            }
        }

        foreach ($flags as $key => $value) {
            $clean = Meprmf_Util::sanitize_param((string) $key);
            if ('' === $clean || ! isset($known[ $clean ])) {
                $unknown[] = (string) $key;
                continue;
            }
            // A bare `--mpf_country` arrives as boolean true, and casting that to a string
            // would filter on the value "1". Report it with the typos instead.
            if (! is_string($value) && ! is_numeric($value)) {
                $unknown[] = (string) $key;
                continue;
            }
            $params[ $clean ] = sanitize_text_field((string) $value);
        }

        return [
            'params'  => $params,
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * Screen context for the `--screen` value, or null when it names no list.
     *
     * @param string $screen Flag value.
     * @return Meprmf_Screen_Context|null
     */
    private static function context_for_screen_flag($screen)
    {
        $pages = [
            'members'       => Meprmf_Screen::PAGE_MEMBERS,
            'transactions'  => Meprmf_Screen::PAGE_TRANSACTIONS,
            'subscriptions' => Meprmf_Screen::PAGE_SUBSCRIPTIONS,
            'lifetimes'     => Meprmf_Screen::PAGE_LIFETIMES,
        ];

        $page = $pages[ strtolower(trim($screen)) ] ?? '';
        if ('' === $page) {
            return null;
        }

        foreach (Meprmf_Screen::supported_page_contexts() as $ctx) {
            if ($ctx->get_page() === $page) {
                return $ctx;
            }
        }

        return null;
    }

    /**
     * Flags left once this command's own are removed.
     *
     * @param array<string, mixed> $assoc_args Flags.
     * @return array<string, mixed>
     */
    private static function filter_flags(array $assoc_args)
    {
        foreach (self::OWN_FLAGS as $flag) {
            unset($assoc_args[ $flag ]);
        }

        return $assoc_args;
    }

    /**
     * `--limit` as a positive row cap, or 0 for every row.
     *
     * @param array<string, mixed> $assoc_args Flags.
     * @return int
     */
    private static function limit_flag(array $assoc_args)
    {
        if (! isset($assoc_args['limit'])) {
            return 0;
        }

        $limit = (int) $assoc_args['limit'];
        if ($limit < 1) {
            WP_CLI::error('--limit must be 1 or more. Omit it to print every matching row.');
        }

        return $limit;
    }
}
