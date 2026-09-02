<?php
/**
 * Timing instrumentation for the list-table predicate pass.
 *
 * Smoke test only: it proves the clock runs and the per-fragment array stays in lockstep
 * with the fragment array. The >200ms gate needs real MySQL and lives in
 * bin/benchmark-usermeta-filters.php.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Debug_Panel;
use Meprmf_Members_Provider;
use Meprmf_Mepr_Predicate_Builder;
use Meprmf_Plugin;
use Meprmf_Predicate_Builder;
use Meprmf_Screen;
use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Plugin
 */
class PluginTimingTest extends TestCase
{

    /** @var array<string, mixed> */
    private $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrap_stubs();

        $this->original_get = $_GET;
        $_GET               = [];

        $GLOBALS['meprmf_test_user_caps'][ \MeprUtils::get_mepr_admin_capability() ] = true;
        Meprmf_Members_Provider::clear_filter_fields_cache();
        Meprmf_Predicate_Builder::reset_last_fragments();
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();
    }

    protected function tearDown(): void
    {
        $_GET                           = $this->original_get;
        $GLOBALS['meprmf_test_filters'] = [];
        $GLOBALS['meprmf_test_user_caps'] = [];
        unset($GLOBALS['meprmf_test_current_screen']);
        Meprmf_Members_Provider::clear_filter_fields_cache();
        Meprmf_Predicate_Builder::reset_last_fragments();
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();
        parent::tearDown();
    }

    private function bootstrap_stubs(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/meprmf-load.php';

        if (! class_exists('MeprOptions', false)) {
            eval(
                'class MeprOptions {
                    public $custom_fields = [];
                    public $show_address_fields = false;
                    public $show_address_on_account = false;
                    public $address_fields = [];
                    public static function fetch() { return new self(); }
                    public function payment_methods() { return []; }
                }'
            );
        }

        if (! defined('WP_DEBUG')) {
            define('WP_DEBUG', true);
        }

        global $wpdb;
        $wpdb = new class() {
            public $prefix   = 'wp_';
            public $usermeta = 'wp_usermeta';

            /**
             * @param string $text Text.
             * @return string
             */
            public function esc_like($text)
            {
                return addcslashes((string) $text, '_%\\');
            }

            /**
             * @param string $query   Query.
             * @param mixed  ...$args Args.
             * @return string
             */
            public function prepare($query, ...$args)
            {
                if (empty($args)) {
                    return $query;
                }
                foreach ($args as $arg) {
                    $replacement = is_numeric($arg)
                        ? (string) $arg
                        : "'" . str_replace("'", "''", (string) $arg) . "'";
                    $query       = preg_replace('/%[sdf]/', $replacement, $query, 1);
                }
                return preg_replace('/%%/', '%', $query);
            }
        };
    }

    /**
     * Three usermeta filter fields registered as if a site had them configured.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bench_fields()
    {
        return [
            [
                'param'    => 'mpf_timing_a',
                'meta_key' => 'meprmf_timing_a',
                'label'    => 'Timing A',
                'type'     => 'text',
                'match'    => 'like',
            ],
            [
                'param'    => 'mpf_timing_b',
                'meta_key' => 'meprmf_timing_b',
                'label'    => 'Timing B',
                'type'     => 'text',
                'match'    => 'exact',
            ],
            [
                'param'    => 'mpf_timing_c',
                'meta_key' => 'meprmf_timing_c',
                'label'    => 'Timing C',
                'type'     => 'text',
                'match'    => 'like',
            ],
        ];
    }

    /**
     * Run one Members pass through the public entry point.
     *
     * `filter_list_table_args()` only reaches the predicate builders when
     * `Meprmf_Screen::detect_list_table_context()` recognises the caller, so the pass runs
     * from a wrapper class mapped through `meprmf_list_table_caller_context`, the same way
     * ListTableScopingTest gets past that gate.
     *
     * @param array<string, string>|null   $get_overrides Optional $_GET values; omit filter params for empty values.
     * @param array<int, array<string, mixed>>|null $fields Field rows for meprmf_members_meta_filters_fields; [] for none.
     * @return array<int, string>
     */
    private function run_members_pass($get_overrides = null, $fields = null)
    {
        if (! class_exists('Meprmf_Test_Timing_Members', false)) {
            eval(
                'class Meprmf_Test_Timing_Members {
                    public static function list_table($args) {
                        return \\Meprmf_Plugin::filter_list_table_args($args);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Timing_Members' === $class && 'list_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
                }
                return $ctx;
            },
            10,
            3
        );

        \add_filter(
            'meprmf_members_meta_filters_fields',
            function () use ($fields) {
                return null === $fields ? $this->bench_fields() : $fields;
            }
        );

        if (null === $get_overrides) {
            $_GET['page']         = Meprmf_Screen::PAGE_MEMBERS;
            $_GET['mpf_timing_a'] = 'alpha';
            $_GET['mpf_timing_b'] = 'beta';
            $_GET['mpf_timing_c'] = 'gamma';
        } else {
            $_GET = $get_overrides;
        }

        return \Meprmf_Test_Timing_Members::list_table([ '1=1' ]);
    }

    public function test_pass_records_non_negative_overhead_and_counts_the_pass()
    {
        $ns_before    = Meprmf_Plugin::get_last_filter_overhead_ns();
        $passes_before = Meprmf_Plugin::get_filter_pass_count();

        $out = $this->run_members_pass();

        $this->assertGreaterThan(1, count($out), 'the pass should add predicates to $args');
        $this->assertGreaterThanOrEqual(0, Meprmf_Plugin::get_last_filter_overhead_ns());
        $this->assertGreaterThanOrEqual($ns_before, Meprmf_Plugin::get_last_filter_overhead_ns());
        $this->assertSame($passes_before + 1, Meprmf_Plugin::get_filter_pass_count());
    }

    public function test_per_fragment_timings_line_up_with_the_fragments()
    {
        $this->run_members_pass();

        $fragments = Meprmf_Predicate_Builder::get_last_fragments();
        $timings   = Meprmf_Predicate_Builder::get_last_fragment_ns();

        $this->assertIsArray($fragments);
        $this->assertIsArray($timings);
        $this->assertCount(3, $fragments);
        $this->assertSame(array_keys($fragments), array_keys($timings));
        foreach ($timings as $ns) {
            $this->assertGreaterThanOrEqual(0, $ns);
        }
    }

    public function test_debug_panel_labels_the_total_as_plugin_overhead()
    {
        $this->run_members_pass();

        ob_start();
        Meprmf_Debug_Panel::maybe_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('filter overhead (plugin): ', $html);
        $this->assertStringContainsString(' ms', $html);
        $this->assertMatchesRegularExpression('/\[[0-9]+\.[0-9]{3} ms\]/', $html);
    }

    public function test_debug_panel_shows_overhead_when_pass_produces_zero_fragments()
    {
        $this->run_members_pass([ 'page' => Meprmf_Screen::PAGE_MEMBERS ]);

        $this->assertSame([], Meprmf_Predicate_Builder::get_last_fragments());
        $this->assertGreaterThan(0, Meprmf_Plugin::get_filter_pass_count());

        ob_start();
        Meprmf_Debug_Panel::maybe_render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('filter overhead (plugin): ', $html);
        $this->assertMatchesRegularExpression('/\([0-9]+ passes?\)/', $html);
        $this->assertStringNotContainsString('<pre', $html);
    }
}
