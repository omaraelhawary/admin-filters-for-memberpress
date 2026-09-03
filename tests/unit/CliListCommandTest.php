<?php
/**
 * `wp meprmf list`: screen override, param collection, and saved-view lookup.
 *
 * The row count is not asserted against canned model rows on purpose. Stubbing the model to
 * return N rows and then asserting N proves nothing about the query. What decides whether the
 * CLI and the list agree is the WHERE fragments `mepr_list_table_args` receives, so that is
 * what these tests compare: one pass driven by an admin request, one driven by the CLI screen
 * override and the request-override stack, same values, byte-identical conditions.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Cli_List_Command;
use Meprmf_Members_Provider;
use Meprmf_Mepr_Predicate_Builder;
use Meprmf_Plugin;
use Meprmf_Predicate_Builder;
use Meprmf_Presets;
use Meprmf_Screen;
use Meprmf_Screen_Context;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Cli_List_Command
 * @covers Meprmf_Screen::set_cli_context
 * @covers Meprmf_Presets::find_preset_for_screen
 */
class CliListCommandTest extends TestCase
{

    private const SCREEN = 'memberpress_members';

    /** @var array<string, mixed> */
    private $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/meprmf-load.php';

        $this->bootstrap_wp_cli_stub();
        $this->original_get = $_GET;
        $_GET               = [];

        $GLOBALS['meprmf_test_options']         = [];
        $GLOBALS['meprmf_test_filters']         = [];
        $GLOBALS['meprmf_test_user_meta']       = [];
        $GLOBALS['meprmf_test_user_caps']       = [ \MeprUtils::get_mepr_admin_capability() => true ];
        $GLOBALS['meprmf_test_current_user_id'] = 0;
        $GLOBALS['meprmf_preset_id_counter']    = 0;
        $GLOBALS['meprmf_test_wp_cli_errors']   = [];

        $this->bootstrap_stubs();
        Meprmf_Screen::set_cli_context(null);
        Meprmf_Util::reset_request_overrides();
        Meprmf_Members_Provider::clear_filter_fields_cache();
        Meprmf_Predicate_Builder::reset_last_fragments();
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();
    }

    protected function tearDown(): void
    {
        $_GET = $this->original_get;

        $GLOBALS['meprmf_test_options']         = [];
        $GLOBALS['meprmf_test_filters']         = [];
        $GLOBALS['meprmf_test_user_meta']       = [];
        $GLOBALS['meprmf_test_user_caps']       = [];
        $GLOBALS['meprmf_test_current_user_id'] = 0;
        $GLOBALS['meprmf_preset_id_counter']    = 0;
        $GLOBALS['meprmf_test_wp_cli_errors']   = [];

        Meprmf_Screen::set_cli_context(null);
        Meprmf_Util::reset_request_overrides();
        Meprmf_Members_Provider::clear_filter_fields_cache();
        Meprmf_Predicate_Builder::reset_last_fragments();
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();
        parent::tearDown();
    }

    /**
     * @return void
     */
    private function bootstrap_wp_cli_stub(): void
    {
        if (! class_exists('WP_CLI', false)) {
            eval(
                'class WP_CLI {
                    public static function error($message) {
                        if (! isset($GLOBALS["meprmf_test_wp_cli_errors"])) {
                            $GLOBALS["meprmf_test_wp_cli_errors"] = [];
                        }
                        $GLOBALS["meprmf_test_wp_cli_errors"][] = (string) $message;
                        throw new \\RuntimeException("meprmf_test_wp_cli_error");
                    }
                }'
            );
        }
    }

    /**
     * MemberPress options and a $wpdb with just the escaping the builders call.
     *
     * The MeprOptions body is copied from MembersCoreProviderTest rather than trimmed to what
     * this file needs. These stubs are eval'd into the global scope behind a class_exists
     * guard, so the first class to run defines the one every later class sees, and this class
     * runs before the three that read gateways and account address fields off it.
     *
     * @return void
     */
    private function bootstrap_stubs(): void
    {
        if (! class_exists('MeprOptions', false)) {
            eval(
                'class MeprOptions {
                    public $custom_fields = [];
                    public $show_address_fields = false;
                    public $show_address_on_account = true;
                    public $address_fields = [];
                    public static function fetch() { return new self(); }
                    public function payment_methods() {
                        return [ "manual" => (object) [ "label" => "Manual", "name" => "Manual" ] ];
                    }
                }'
            );
        }

        if (! class_exists('MeprDb', false)) {
            eval(
                'class MeprDb {
                    public $transactions = "wp_mepr_transactions";
                    public $subscriptions = "wp_mepr_subscriptions";
                    public $members = "wp_mepr_members";
                    public static function fetch() {
                        return new self();
                    }
                }'
            );
        }

        if (! class_exists('MeprCptModel', false)) {
            eval(
                'class MeprCptModel {
                    public static function all($model, $unused, $args) {
                        unset($model, $unused, $args);
                        return [ (object) [ "ID" => 3, "post_title" => "Lifetime plan" ] ];
                    }
                }'
            );
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
     * @return Meprmf_Screen_Context
     */
    private function members_context()
    {
        return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
    }

    /**
     * @return Meprmf_Screen_Context
     */
    private function transactions_context()
    {
        return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
    }

    /**
     * @return Meprmf_Screen_Context
     */
    private function subscriptions_context()
    {
        return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');
    }

    /**
     * @return Meprmf_Screen_Context
     */
    private function lifetimes_context()
    {
        return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_LIFETIMES, 'txn.user_id');
    }

    /**
     * One usermeta text field, which is the field type that offers `is_one_of`.
     *
     * @return void
     */
    private function register_country_field(): void
    {
        \add_filter(
            'meprmf_members_meta_filters_fields',
            static function () {
                return [
                    [
                        'param'    => 'mpf_cli_country',
                        'meta_key' => 'meprmf_cli_country',
                        'label'    => 'Country',
                        'type'     => 'text',
                        'match'    => 'exact',
                    ],
                ];
            }
        );
    }

    /**
     * Run one Members predicate pass through the public entry point.
     *
     * `filter_list_table_args()` needs `Meprmf_Screen::detect_list_table_context()` to
     * recognise the caller, so the pass runs from a wrapper mapped through
     * `meprmf_list_table_caller_context`, the way PluginTimingTest does it. The wrapper is
     * named for this file: a stub called MeprUser would break the sibling test that evals its
     * own zero-argument MeprUser::list_table().
     *
     * @return array<int, string>
     */
    private function run_members_pass()
    {
        if (! class_exists('Meprmf_Test_Cli_Members', false)) {
            eval(
                'class Meprmf_Test_Cli_Members {
                    public static function list_table($args) {
                        return \\Meprmf_Plugin::filter_list_table_args($args);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Cli_Members' === $class && 'list_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
                }
                return $ctx;
            },
            10,
            3
        );

        return \Meprmf_Test_Cli_Members::list_table([ '1=1' ]);
    }

    /**
     * @return array<int, string>
     */
    private function run_transactions_pass()
    {
        if (! class_exists('Meprmf_Test_Cli_Transactions', false)) {
            eval(
                'class Meprmf_Test_Cli_Transactions {
                    public static function list_table($args) {
                        return \\Meprmf_Plugin::filter_list_table_args($args);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Cli_Transactions' === $class && 'list_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
                }
                return $ctx;
            },
            10,
            3
        );

        return \Meprmf_Test_Cli_Transactions::list_table([ '1=1' ]);
    }

    /**
     * @return array<int, string>
     */
    private function run_subscriptions_pass()
    {
        if (! class_exists('Meprmf_Test_Cli_Subscriptions', false)) {
            eval(
                'class Meprmf_Test_Cli_Subscriptions {
                    public static function subscr_table($args) {
                        return \\Meprmf_Plugin::filter_list_table_args($args);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Cli_Subscriptions' === $class && 'subscr_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');
                }
                return $ctx;
            },
            10,
            3
        );

        return \Meprmf_Test_Cli_Subscriptions::subscr_table([ '1=1' ]);
    }

    /**
     * @return array<int, string>
     */
    private function run_lifetimes_pass()
    {
        if (! class_exists('Meprmf_Test_Cli_Lifetimes', false)) {
            eval(
                'class Meprmf_Test_Cli_Lifetimes {
                    public static function lifetime_subscr_table($args) {
                        return \\Meprmf_Plugin::filter_list_table_args($args);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Cli_Lifetimes' === $class && 'lifetime_subscr_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_LIFETIMES, 'txn.user_id');
                }
                return $ctx;
            },
            10,
            3
        );

        return \Meprmf_Test_Cli_Lifetimes::lifetime_subscr_table([ '1=1' ]);
    }

    /**
     * @return void
     */
    private function ensure_mepr_user_stub(): void
    {
        if (! class_exists('MeprUser', false)) {
            eval(
                'class MeprUser {
                    public static function list_table() {
                        return [];
                    }
                }'
            );
        }
    }

    public function test_the_cli_override_builds_the_same_conditions_as_an_admin_request()
    {
        $params = [
            'mpf_cli_country'     => 'DE,FR',
            'mpf_cli_country__op' => 'is_one_of',
        ];

        $this->register_country_field();
        $_GET = array_merge([ 'page' => Meprmf_Screen::PAGE_MEMBERS ], $params);
        $admin = $this->run_members_pass();

        $this->assertGreaterThan(1, count($admin), 'the admin pass should add a condition');

        // Nothing in the request now, which is the CLI's situation: no ?page= and no filter
        // params. Only the screen override and the override stack are left to carry them.
        $_GET = [];
        $GLOBALS['meprmf_test_filters'] = [];
        Meprmf_Members_Provider::clear_filter_fields_cache();
        $this->register_country_field();

        Meprmf_Screen::set_cli_context($this->members_context());
        Meprmf_Util::push_request_overrides($params);
        $cli = $this->run_members_pass();
        Meprmf_Util::pop_request_overrides();
        Meprmf_Screen::set_cli_context(null);

        $this->assertSame($admin, $cli);
    }

    public function test_the_cli_override_builds_the_same_conditions_as_an_admin_request_for_transactions()
    {
        $params = [ 'mpmt_txn_status' => 'complete' ];

        $_GET = array_merge([ 'page' => Meprmf_Screen::PAGE_TRANSACTIONS ], $params);
        $admin = $this->run_transactions_pass();

        $this->assertGreaterThan(1, count($admin), 'the admin pass should add a condition');

        $_GET = [];
        $GLOBALS['meprmf_test_filters'] = [];
        Meprmf_Screen::set_cli_context($this->transactions_context());
        Meprmf_Util::push_request_overrides($params);
        $cli = $this->run_transactions_pass();
        Meprmf_Util::pop_request_overrides();
        Meprmf_Screen::set_cli_context(null);

        $this->assertSame($admin, $cli);
    }

    public function test_the_cli_override_builds_the_same_conditions_as_an_admin_request_for_subscriptions()
    {
        $params = [ 'mpms_sub_status' => 'active' ];

        $_GET = array_merge([ 'page' => Meprmf_Screen::PAGE_SUBSCRIPTIONS ], $params);
        $admin = $this->run_subscriptions_pass();

        $this->assertGreaterThan(1, count($admin), 'the admin pass should add a condition');

        $_GET = [];
        $GLOBALS['meprmf_test_filters'] = [];
        Meprmf_Screen::set_cli_context($this->subscriptions_context());
        Meprmf_Util::push_request_overrides($params);
        $cli = $this->run_subscriptions_pass();
        Meprmf_Util::pop_request_overrides();
        Meprmf_Screen::set_cli_context(null);

        $this->assertSame($admin, $cli);
    }

    public function test_the_cli_override_builds_the_same_conditions_as_an_admin_request_for_lifetimes()
    {
        $params = [ 'mpml_product' => '3' ];

        $_GET = array_merge([ 'page' => Meprmf_Screen::PAGE_LIFETIMES ], $params);
        $admin = $this->run_lifetimes_pass();

        $this->assertGreaterThan(1, count($admin), 'the admin pass should add a condition');

        $_GET = [];
        $GLOBALS['meprmf_test_filters'] = [];
        Meprmf_Screen::set_cli_context($this->lifetimes_context());
        Meprmf_Util::push_request_overrides($params);
        $cli = $this->run_lifetimes_pass();
        Meprmf_Util::pop_request_overrides();
        Meprmf_Screen::set_cli_context(null);

        $this->assertSame($admin, $cli);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_invoke_errors_when_the_user_lacks_filter_capability()
    {
        $this->ensure_mepr_user_stub();
        $GLOBALS['meprmf_test_user_caps']     = [];
        $GLOBALS['meprmf_test_wp_cli_errors'] = [];

        $cmd = new Meprmf_Cli_List_Command();
        try {
            $cmd([], [ 'screen' => 'members' ]);
            $this->fail('Expected WP_CLI error.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_wp_cli_error', $e->getMessage());
        }

        $this->assertNotEmpty($GLOBALS['meprmf_test_wp_cli_errors']);
        $message = $GLOBALS['meprmf_test_wp_cli_errors'][0];
        $this->assertStringContainsString('administrator', strtolower($message));
        $this->assertStringContainsString('--user', $message);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_invoke_errors_when_memberpress_is_not_active()
    {
        $GLOBALS['meprmf_test_user_caps'][ \MeprUtils::get_mepr_admin_capability() ] = true;
        $GLOBALS['meprmf_test_wp_cli_errors']                                        = [];

        $cmd = new Meprmf_Cli_List_Command();
        try {
            $cmd([], [ 'screen' => 'members' ]);
            $this->fail('Expected WP_CLI error.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_wp_cli_error', $e->getMessage());
        }

        $this->assertNotEmpty($GLOBALS['meprmf_test_wp_cli_errors']);
        $this->assertStringContainsString(
            'MemberPress is not active',
            $GLOBALS['meprmf_test_wp_cli_errors'][0]
        );
    }

    public function test_a_comma_separated_override_becomes_one_in_value_per_country()
    {
        $params = [
            'mpf_cli_country'     => 'DE,FR',
            'mpf_cli_country__op' => 'is_one_of',
        ];

        $this->register_country_field();
        Meprmf_Screen::set_cli_context($this->members_context());
        Meprmf_Util::push_request_overrides($params);
        $args = $this->run_members_pass();
        Meprmf_Util::pop_request_overrides();
        Meprmf_Screen::set_cli_context(null);

        $sql = implode(' AND ', $args);
        $this->assertStringContainsString("'DE'", $sql);
        $this->assertStringContainsString("'FR'", $sql);
        $this->assertStringNotContainsString("'DE,FR'", $sql);
    }

    public function test_no_predicates_run_without_the_override()
    {
        $this->register_country_field();
        Meprmf_Util::push_request_overrides(
            [
                'mpf_cli_country'     => 'DE',
                'mpf_cli_country__op' => 'is_one_of',
            ]
        );
        $args = $this->run_members_pass();
        Meprmf_Util::pop_request_overrides();

        $this->assertSame([ '1=1' ], $args, 'no screen means no predicates, which is the bug the override fixes');
    }

    public function test_a_saved_view_resolves_by_id_and_by_name_without_regard_to_case()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings']     = [ 'shared_preset_capability' => 'meprmf_test_share' ];
        $GLOBALS['meprmf_test_user_caps']['meprmf_test_share'] = true;

        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Churn risk',
            [ 'mpf_country' => 'DE' ],
            [ 'mpf_country' ]
        );
        $this->assertTrue($save['success']);
        $id = (string) $save['preset']['id'];

        $by_id = Meprmf_Presets::find_preset_for_screen(self::SCREEN, $id);
        $this->assertNotNull($by_id);
        $this->assertSame($id, $by_id['id']);

        $by_name = Meprmf_Presets::find_preset_for_screen(self::SCREEN, 'CHURN RISK');
        $this->assertNotNull($by_name);
        $this->assertSame($id, $by_name['id']);

        $this->assertNull(Meprmf_Presets::find_preset_for_screen(self::SCREEN, 'churn-risk'));
        $this->assertNull(Meprmf_Presets::find_preset_for_screen(self::SCREEN, ''));
    }

    public function test_an_id_match_beats_a_name_match()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings']     = [ 'shared_preset_capability' => 'meprmf_test_share' ];
        $GLOBALS['meprmf_test_user_caps']['meprmf_test_share'] = true;

        $first = Meprmf_Presets::save_preset(self::SCREEN, 'First', [ 'mpf_country' => 'DE' ], [ 'mpf_country' ]);
        $this->assertTrue($first['success']);
        $id = (string) $first['preset']['id'];

        // A second view named after the first one's id: the id still wins.
        $second = Meprmf_Presets::save_preset(self::SCREEN, $id, [ 'mpf_country' => 'FR' ], [ 'mpf_country' ]);
        $this->assertTrue($second['success']);

        $found = Meprmf_Presets::find_preset_for_screen(self::SCREEN, $id);
        $this->assertNotNull($found);
        $this->assertSame([ 'mpf_country' => 'DE' ], $found['params']);
    }

    public function test_an_explicit_flag_wins_over_the_saved_view_it_came_with()
    {
        $this->register_country_field();

        $out = Meprmf_Cli_List_Command::collect_filter_params(
            $this->members_context(),
            [ 'mpf_cli_country' => 'FR' ],
            [ 'mpf_cli_country' => 'DE', 'status' => 'active' ]
        );

        $this->assertSame([], $out['unknown']);
        $this->assertSame(
            [ 'mpf_cli_country' => 'FR', 'status' => 'active' ],
            $out['params']
        );
    }

    public function test_an_unknown_flag_is_reported_rather_than_dropped()
    {
        $this->register_country_field();

        $out = Meprmf_Cli_List_Command::collect_filter_params(
            $this->members_context(),
            [ 'mpf_cli_contry' => 'DE', 'gateway' => 'stripe' ]
        );

        // gateway is a Transactions and Subscriptions toolbar param, not a Members one.
        $this->assertSame([ 'mpf_cli_contry', 'gateway' ], $out['unknown']);
        $this->assertSame([], $out['params']);
    }

    public function test_a_flag_passed_with_no_value_is_reported_not_read_as_one()
    {
        $this->register_country_field();

        $out = Meprmf_Cli_List_Command::collect_filter_params(
            $this->members_context(),
            [ 'mpf_cli_country' => true ]
        );

        $this->assertSame([ 'mpf_cli_country' ], $out['unknown']);
        $this->assertSame([], $out['params']);
    }
}
