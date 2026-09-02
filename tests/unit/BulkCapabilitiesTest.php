<?php
/**
 * Bulk-action permission gate and request guards.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Bulk;
use Meprmf_Bulk_Match_Set;
use Meprmf_Capabilities;
use Meprmf_Predicate_Builder;
use Meprmf_Screen;
use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Capabilities
 * @covers Meprmf_Bulk
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BulkCapabilitiesTest extends TestCase
{

    /** @var array<string, mixed> */
    private $original_get = [];

    /** @var array<string, mixed> */
    private $original_post = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->load_dependencies();
        $this->original_get                 = $_GET;
        $this->original_post                = $_POST;
        $_GET                               = [];
        $_POST                              = [];
        $GLOBALS['meprmf_test_user_caps']   = [];
        $GLOBALS['meprmf_test_filters']     = [];
        $GLOBALS['meprmf_test_user_meta']   = [];
        $GLOBALS['meprmf_test_json_responses'] = [];
    }

    protected function tearDown(): void
    {
        $_GET                             = $this->original_get;
        $_POST                            = $this->original_post;
        $GLOBALS['meprmf_test_user_caps'] = [];
        $GLOBALS['meprmf_test_filters']   = [];
        $GLOBALS['meprmf_test_user_meta'] = [];
        $GLOBALS['meprmf_test_json_responses'] = [];
        parent::tearDown();
    }

    private function load_dependencies(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-capabilities.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-native-params.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-presets.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-addon-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-activity-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/class-meprmf-filter-registry.php';
        require_once dirname(__DIR__, 2) . '/includes/sql/class-meprmf-predicate-builder.php';
        require_once dirname(__DIR__, 2) . '/includes/sql/class-meprmf-mepr-predicate-builder.php';
        require_once dirname(__DIR__, 2) . '/includes/bulk/class-meprmf-bulk-set-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/bulk/class-meprmf-bulk-runner.php';
        require_once dirname(__DIR__, 2) . '/includes/bulk/class-meprmf-bulk-match-set.php';
        require_once dirname(__DIR__, 2) . '/includes/bulk/class-meprmf-bulk-snapshot.php';
        require_once dirname(__DIR__, 2) . '/includes/bulk/class-meprmf-bulk.php';

        if (! class_exists('MeprOptions', false)) {
            eval(
                'class MeprOptions {
                    public static function fetch() { return new self(); }
                    public function payment_methods() { return []; }
                }'
            );
        }

        if (! class_exists('MeprCptModel', false)) {
            eval(
                'class MeprCptModel {
                    public static function all($model, $unused, $args) {
                        unset($model, $unused, $args);
                        return [];
                    }
                }'
            );
        }
    }

    private function members_context(): Meprmf_Screen_Context
    {
        return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
    }

    public function test_bulk_capability_defaults_to_manage_options()
    {
        $this->assertFalse(Meprmf_Capabilities::current_user_can_bulk_actions());

        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $this->assertTrue(Meprmf_Capabilities::current_user_can_bulk_actions());
    }

    public function test_bulk_capability_is_not_satisfied_by_the_filter_capability()
    {
        // MemberPress's own admin capability, which reading a filtered list already needs.
        $GLOBALS['meprmf_test_user_caps']['remove_users'] = true;

        $this->assertFalse(Meprmf_Capabilities::current_user_can_bulk_actions());
    }

    public function test_bulk_capability_filter_overrides_the_default()
    {
        $GLOBALS['meprmf_test_user_caps']['edit_users'] = true;

        add_filter(
            'meprmf_bulk_actions_capability',
            static function () {
                return 'edit_users';
            }
        );

        $this->assertTrue(Meprmf_Capabilities::current_user_can_bulk_actions());
    }

    public function test_precheck_refuses_a_request_with_no_filter_params()
    {
        $result = Meprmf_Bulk::precheck_request([ 'page' => Meprmf_Screen::PAGE_MEMBERS ], $this->members_context());

        $this->assertFalse($result['success']);
        $this->assertSame('unfiltered', $result['code']);
        $this->assertSame([], $GLOBALS['meprmf_test_user_meta']);
    }

    public function test_precheck_refuses_an_unknown_screen()
    {
        $result = Meprmf_Bulk::precheck_request([ 'mpm_access' => 'active' ], null);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_screen', $result['code']);
    }

    public function test_precheck_refuses_a_search_restricted_to_one_field()
    {
        $result = Meprmf_Bulk::precheck_request(
            [
                'page'         => Meprmf_Screen::PAGE_MEMBERS,
                'mpm_access'   => 'active',
                'search'       => 'jane',
                'search-field' => 'email',
            ],
            $this->members_context()
        );

        $this->assertFalse($result['success']);
        $this->assertSame('unsupported_search_field', $result['code']);
    }

    public function test_precheck_passes_a_filtered_request()
    {
        $result = Meprmf_Bulk::precheck_request(
            [
                'page'        => Meprmf_Screen::PAGE_MEMBERS,
                'mpm_access'  => 'active',
            ],
            $this->members_context()
        );

        $this->assertTrue($result['success']);
    }

    public function test_has_active_predicates_is_false_until_a_fragment_is_appended()
    {
        Meprmf_Predicate_Builder::reset_last_fragments();
        \Meprmf_Mepr_Predicate_Builder::reset_last_fragments();

        $this->assertFalse(Meprmf_Bulk_Match_Set::has_active_predicates());

        $_GET['mpf_country'] = 'DE';
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
                foreach ($args as $arg) {
                    $query = preg_replace('/%[sdf]/', "'" . (string) $arg . "'", (string) $query, 1);
                }
                return (string) $query;
            }
        };

        Meprmf_Predicate_Builder::append_usermeta_exists(
            [],
            $this->members_context(),
            [
                [
                    'param'    => 'mpf_country',
                    'meta_key' => 'mepr-address-country',
                    'label'    => 'Country',
                    'type'     => 'country',
                    'match'    => 'exact',
                ],
            ]
        );

        $this->assertTrue(Meprmf_Bulk_Match_Set::has_active_predicates());
    }

    public function test_ajax_refuses_live_run_without_a_preview_token()
    {
        if (! class_exists('MeprUser', false)) {
            eval(
                'class MeprUser {
                    public static function list_table() {
                        return [
                            "results" => [
                                (object) [ "ID" => 42 ],
                            ],
                        ];
                    }
                }'
            );
        }

        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $_GET                                               = [
            'page'       => Meprmf_Screen::PAGE_MEMBERS,
            'mpm_access' => 'active',
        ];
        $_POST                                              = [
            'meta_key'   => 'crm_tier',
            'meta_value' => 'gold',
            'nonce'      => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
            $this->fail('Expected ajax handler to send a JSON error.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_error', $e->getMessage());
        }

        $responses = $GLOBALS['meprmf_test_json_responses'];
        $this->assertNotEmpty($responses);
        $last = $responses[ count($responses) - 1 ];
        $this->assertFalse($last['success']);
        $this->assertSame('missing_run_token', $last['data']['code']);
        $this->assertSame([], $GLOBALS['meprmf_test_user_meta']);
    }

    public function test_ajax_dry_run_refuses_when_fetch_produces_no_active_predicates()
    {
        Meprmf_Predicate_Builder::reset_last_fragments();
        \Meprmf_Mepr_Predicate_Builder::reset_last_fragments();

        if (! class_exists('MeprUser', false)) {
            eval(
                'class MeprUser {
                    public static function list_table() {
                        return [
                            "results" => [
                                (object) [ "ID" => 42 ],
                            ],
                        ];
                    }
                }'
            );
        }

        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $_GET                                               = [
            'page'       => Meprmf_Screen::PAGE_MEMBERS,
            'mpm_access' => 'active',
        ];
        $_POST                                              = [
            'meta_key'   => 'crm_tier',
            'meta_value' => 'gold',
            'dry_run'    => '1',
            'nonce'      => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
            $this->fail('Expected ajax handler to send a JSON error.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_error', $e->getMessage());
        }

        $responses = $GLOBALS['meprmf_test_json_responses'];
        $this->assertNotEmpty($responses);
        $last = $responses[ count($responses) - 1 ];
        $this->assertFalse($last['success']);
        $this->assertSame('no_predicates', $last['data']['code']);
        $this->assertSame([], $GLOBALS['meprmf_test_user_meta']);
    }
}
