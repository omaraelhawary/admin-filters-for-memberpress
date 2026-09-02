<?php
/**
 * Benchmark the plugin's usermeta filter pass on a seeded wp_usermeta fixture.
 *
 * Run against a real WordPress install with MemberPress and this plugin active:
 *
 *     wp eval-file bin/benchmark-usermeta-filters.php
 *
 * Fixture seed (exact):
 *   6,000 wp_usermeta rows, 2,000 per meta_key, across 3 distinct keys
 *     meprmf_bench_country  values cycle US, DE, FR, GB
 *     meprmf_bench_plan     values cycle gold, silver, bronze
 *     meprmf_bench_city     values cycle Cairo, Berlin, Lisbon, Osaka, Lima
 *   user_id 900001 through 902000 (synthetic; the EXISTS never joins wp_users here)
 *   3 active filters: mpf_bench_country = US (exact), mpf_bench_plan = gold (exact),
 *   mpf_bench_city = Cairo (LIKE '%Cairo%')
 *
 * The three fields REPLACE whatever the site has configured, so the fragment count is
 * always 3 and the figure is comparable between runs. The script exits non-zero if the
 * pass builds a different number of fragments, because a timing with no fragments
 * measures nothing.
 *
 * Reported figure is `Meprmf_Plugin::get_last_filter_overhead_ns()`, the same number the
 * WP_DEBUG panel labels "filter overhead (plugin)": predicate construction for one pass,
 * not MemberPress's own list-table query.
 *
 * Cleanup deletes every row whose meta_key starts with `meprmf_bench_`, from a shutdown
 * handler so it also runs if the pass throws.
 *
 * @package MemberPress_Members_Meta_Filters
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Runs the filter from a caller name this plugin maps to the Members list.
 *
 * `Meprmf_Screen::detect_list_table_context()` reads the backtrace for a known
 * MeprDb::list_table() caller, so calling apply_filters() from file scope would be
 * rejected. The `meprmf_list_table_caller_context` filter below maps this class.
 */
class Meprmf_Benchmark_Runner
{

    /**
     * @param array<int, string> $args WHERE fragments.
     * @return array<int, string>
     */
    public static function list_table(array $args)
    {
        return apply_filters('mepr_list_table_args', $args);
    }
}

/**
 * Print one line to stdout.
 *
 * @param string $message Message.
 * @return void
 */
function meprmf_bench_line($message)
{
    if (class_exists('WP_CLI')) {
        WP_CLI::line($message);
        return;
    }
    echo $message . "\n";
}

/**
 * Print an error and stop with a non-zero status.
 *
 * @param string $message Message.
 * @return void
 */
function meprmf_bench_fail($message)
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }
    fwrite(STDERR, 'Error: ' . $message . "\n");
    exit(1);
}

global $wpdb;

if (! class_exists('Meprmf_Plugin') || ! has_filter('mepr_list_table_args', 'meprmf_filter_members_list_args')) {
    meprmf_bench_fail('Admin Filters for MemberPress is not loaded (is MemberPress active?).');
}

// Both admin gates: Meprmf_Screen::detect() needs is_admin(), and
// Meprmf_Screen::current_wp_screen_matches_context() compares the screen id. wp-cli sets
// neither. is_admin() reads $GLOBALS['current_screen']->in_admin() before the WP_ADMIN
// constant, so the screen stub carries the gate whether or not the define below took (WP may
// already have defined WP_ADMIN false by the time this file runs).
if (! defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}

$GLOBALS['current_screen'] = new class () {
    public $id = 'memberpress_page_memberpress-members';

    /**
     * @return bool
     */
    public function in_admin()
    {
        return true;
    }
};

// Meprmf_Capabilities does a real current_user_can() check, so the run needs a real user.
$admins = get_users(
    [
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]
);
if (empty($admins)) {
    meprmf_bench_fail('No administrator user found to run the benchmark as.');
}
wp_set_current_user((int) $admins[0]);
if (! Meprmf_Capabilities::current_user_can_filter()) {
    meprmf_bench_fail('User ' . (int) $admins[0] . ' cannot use admin filters; check the MemberPress admin capability.');
}

$bench_keys = [
    'meprmf_bench_country' => [ 'US', 'DE', 'FR', 'GB' ],
    'meprmf_bench_plan'    => [ 'gold', 'silver', 'bronze' ],
    'meprmf_bench_city'    => [ 'Cairo', 'Berlin', 'Lisbon', 'Osaka', 'Lima' ],
];

$rows_per_key   = 2000;
$first_user_id  = 900001;
$cleanup_prefix = 'meprmf_bench_';

register_shutdown_function(
    static function () use ($wpdb, $cleanup_prefix) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark fixture teardown.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb.
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like($cleanup_prefix) . '%'
            )
        );
        meprmf_bench_line('Cleanup: removed ' . (int) $deleted . " rows with meta_key LIKE '{$cleanup_prefix}%'.");
    }
);

$seeded = 0;
foreach ($bench_keys as $meta_key => $values) {
    for ($offset = 0; $offset < $rows_per_key; $offset += 500) {
        $chunk        = min(500, $rows_per_key - $offset);
        $placeholders = implode(', ', array_fill(0, $chunk, '(%d, %s, %s)'));
        $params       = [];
        for ($i = 0; $i < $chunk; $i++) {
            $n        = $offset + $i;
            $params[] = $first_user_id + $n;
            $params[] = $meta_key;
            $params[] = $values[ $n % count($values) ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Benchmark fixture seed.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb; values are placeholders.
                "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES {$placeholders}",
                ...$params
            )
        );
        if (false === $inserted) {
            meprmf_bench_fail('Seeding failed on ' . $meta_key . ': ' . $wpdb->last_error);
        }
        $seeded += $chunk;
    }
}
meprmf_bench_line('Seeded ' . $seeded . ' wp_usermeta rows across ' . count($bench_keys) . ' meta keys.');

// Registering the fields is what makes them filterable: the registry only normalizes what
// the providers or this hook report, so seeding rows alone would build zero fragments.
add_filter(
    'meprmf_members_meta_filters_fields',
    static function () {
        return [
            [
                'param'    => 'mpf_bench_country',
                'meta_key' => 'meprmf_bench_country',
                'label'    => 'Bench country',
                'type'     => 'text',
                'match'    => 'exact',
            ],
            [
                'param'    => 'mpf_bench_plan',
                'meta_key' => 'meprmf_bench_plan',
                'label'    => 'Bench plan',
                'type'     => 'text',
                'match'    => 'exact',
            ],
            [
                'param'    => 'mpf_bench_city',
                'meta_key' => 'meprmf_bench_city',
                'label'    => 'Bench city',
                'type'     => 'text',
                'match'    => 'like',
            ],
        ];
    },
    99
);
Meprmf_Members_Provider::clear_filter_fields_cache();

add_filter(
    'meprmf_list_table_caller_context',
    static function ($ctx, $class, $function) {
        if ('Meprmf_Benchmark_Runner' === $class && 'list_table' === $function) {
            return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
        }
        return $ctx;
    },
    10,
    3
);

$filters = [
    'mpf_bench_country' => 'US',
    'mpf_bench_plan'    => 'gold',
    'mpf_bench_city'    => 'Cairo',
];

$_GET['page'] = Meprmf_Screen::PAGE_MEMBERS;
foreach ($filters as $param => $value) {
    $_GET[ $param ] = $value;
}

$ns_before = Meprmf_Plugin::get_last_filter_overhead_ns();
$args      = Meprmf_Benchmark_Runner::list_table([ '1=1' ]);
$ns_after  = Meprmf_Plugin::get_last_filter_overhead_ns();

$fragments = (array) Meprmf_Predicate_Builder::get_last_fragments();
$fragment_ns = (array) Meprmf_Predicate_Builder::get_last_fragment_ns();

if (count($fragments) !== count($filters)) {
    meprmf_bench_fail(
        'Built ' . count($fragments) . ' usermeta fragments for ' . count($filters)
        . ' filters; the fixture did not exercise the builder, so the timing means nothing.'
    );
}

// get_last_filter_overhead_ns() accumulates across passes, so report the delta.
$overhead_ms = ( $ns_after - $ns_before ) / 1000000;

meprmf_bench_line('');
meprmf_bench_line('Filters applied:      ' . count($filters));
meprmf_bench_line('Usermeta fragments:   ' . count($fragments));
meprmf_bench_line('Total WHERE args:     ' . count($args));
meprmf_bench_line('Passes this run:      ' . Meprmf_Plugin::get_filter_pass_count());
meprmf_bench_line('');
foreach ($fragments as $i => $sql) {
    $ms = isset($fragment_ns[ $i ]) ? number_format($fragment_ns[ $i ] / 1000000, 3) : '?';
    meprmf_bench_line('  fragment ' . ( $i + 1 ) . ': ' . $ms . ' ms  ' . $sql);
}
meprmf_bench_line('');
meprmf_bench_line('filter overhead (plugin): ' . number_format($overhead_ms, 3) . ' ms');
meprmf_bench_line('');
meprmf_bench_line('This is the >200ms gate figure. It covers registry normalization, both');
meprmf_bench_line('predicate builders and apply_match_mode(). MemberPress runs the resulting');
meprmf_bench_line('SQL after the filter returns, so its query time is not in this number.');
