<?php
/**
 * Bulk dry-run snapshot: store, validate, and live-batch reuse.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Bulk;
use Meprmf_Bulk_Snapshot;
use Meprmf_Predicate_Builder;
use Meprmf_Screen;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Bulk_Snapshot
 * @covers Meprmf_Bulk::ajax_bulk_set_meta
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BulkSnapshotTest extends TestCase
{

    /** @var array<string, mixed> */
    private $original_get = [];

    /** @var array<string, mixed> */
    private $original_post = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->load_dependencies();
        $this->original_get                    = $_GET;
        $this->original_post                   = $_POST;
        $_GET                                  = [];
        $_POST                                 = [];
        $GLOBALS['meprmf_test_user_caps']      = [];
        $GLOBALS['meprmf_test_filters']        = [];
        $GLOBALS['meprmf_test_user_meta']      = [];
        $GLOBALS['meprmf_test_json_responses'] = [];
        $GLOBALS['meprmf_test_transients']     = [];
        $GLOBALS['meprmf_test_current_user_id'] = 7;
        $GLOBALS['meprmf_test_list_table_calls'] = 0;
    }

    protected function tearDown(): void
    {
        $_GET                                  = $this->original_get;
        $_POST                                 = $this->original_post;
        $GLOBALS['meprmf_test_user_caps']      = [];
        $GLOBALS['meprmf_test_filters']        = [];
        $GLOBALS['meprmf_test_user_meta']      = [];
        $GLOBALS['meprmf_test_json_responses'] = [];
        $GLOBALS['meprmf_test_transients']     = [];
        $GLOBALS['meprmf_test_current_user_id'] = 0;
        $GLOBALS['meprmf_test_list_table_calls'] = 0;
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

        if (! class_exists('MeprUser', false)) {
            eval(
                'class MeprUser {
                    public static function list_table() {
                        $GLOBALS["meprmf_test_list_table_calls"] = (int) ($GLOBALS["meprmf_test_list_table_calls"] ?? 0) + 1;
                        return [
                            "results" => [
                                (object) [ "ID" => 201 ],
                                (object) [ "ID" => 202 ],
                            ],
                        ];
                    }
                }'
            );
        }

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

    private function activate_predicate(): void
    {
        Meprmf_Predicate_Builder::reset_last_fragments();
        \Meprmf_Mepr_Predicate_Builder::reset_last_fragments();

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
            new \Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID'),
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
    }

    private function filtered_get(): array
    {
        return [
            'page'         => Meprmf_Screen::PAGE_MEMBERS,
            'mpm_access'   => 'active',
            'mpf_country'  => 'DE',
        ];
    }

    public function test_store_and_load_roundtrip_for_current_user()
    {
        $request = $this->filtered_get();
        $data    = [
            'user_ids'          => [ 201, 202 ],
            'rows'              => 2,
            'members'           => 2,
            'meta_key'          => 'crm_tier',
            'meta_value'        => 'gold',
            'query_fingerprint' => Meprmf_Bulk_Snapshot::query_fingerprint($request),
        ];

        $token = Meprmf_Bulk_Snapshot::store($data);
        $this->assertNotSame('', $token);

        $loaded = Meprmf_Bulk_Snapshot::load($token);
        $this->assertSame($data, $loaded);
    }

    public function test_load_returns_null_for_another_user()
    {
        $token = Meprmf_Bulk_Snapshot::store(
            [
                'user_ids'   => [ 1 ],
                'rows'       => 1,
                'members'    => 1,
                'meta_key'   => 'crm_tier',
                'meta_value' => 'gold',
            ]
        );

        $GLOBALS['meprmf_test_current_user_id'] = 99;
        $this->assertNull(Meprmf_Bulk_Snapshot::load($token));
    }

    public function test_meta_matches_rejects_a_changed_key_or_value()
    {
        $snapshot = [
            'meta_key'   => 'crm_tier',
            'meta_value' => 'gold',
        ];

        $this->assertTrue(Meprmf_Bulk_Snapshot::meta_matches($snapshot, 'crm_tier', 'gold'));
        $this->assertFalse(Meprmf_Bulk_Snapshot::meta_matches($snapshot, 'crm_tier', 'silver'));
        $this->assertFalse(Meprmf_Bulk_Snapshot::meta_matches($snapshot, 'other_key', 'gold'));
    }

    public function test_query_matches_rejects_a_changed_filter_query()
    {
        $request  = $this->filtered_get();
        $snapshot = [
            'query_fingerprint' => Meprmf_Bulk_Snapshot::query_fingerprint($request),
        ];

        $this->assertTrue(Meprmf_Bulk_Snapshot::query_matches($snapshot, $request));

        $changed = $request;
        $changed['mpm_access'] = 'inactive';
        $this->assertFalse(Meprmf_Bulk_Snapshot::query_matches($snapshot, $changed));
    }

    public function test_dry_run_returns_a_run_token_and_stores_the_snapshot()
    {
        $this->activate_predicate();
        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $_GET                                               = $this->filtered_get();
        $_POST                                              = [
            'meta_key'   => 'crm_tier',
            'meta_value' => 'gold',
            'dry_run'    => '1',
            'nonce'      => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
            $this->fail('Expected ajax handler to send JSON success.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_success', $e->getMessage());
        }

        $last = $GLOBALS['meprmf_test_json_responses'][ count($GLOBALS['meprmf_test_json_responses'] ) - 1 ];
        $this->assertTrue($last['success']);
        $this->assertSame(2, $last['data']['members']);
        $this->assertNotEmpty($last['data']['runToken']);

        $snapshot = Meprmf_Bulk_Snapshot::load((string) $last['data']['runToken']);
        $this->assertSame([ 201, 202 ], $snapshot['user_ids']);
        $this->assertSame([], $GLOBALS['meprmf_test_user_meta']);
    }

    public function test_live_batch_uses_the_snapshot_without_refetching_the_match_set()
    {
        $this->activate_predicate();
        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $_GET                                               = $this->filtered_get();
        $_POST                                              = [
            'meta_key'   => 'crm_tier',
            'meta_value' => 'gold',
            'dry_run'    => '1',
            'nonce'      => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_success', $e->getMessage());
        }

        $dry_run = $GLOBALS['meprmf_test_json_responses'][ count($GLOBALS['meprmf_test_json_responses'] ) - 1 ];
        $token   = (string) $dry_run['data']['runToken'];
        $this->assertSame(1, $GLOBALS['meprmf_test_list_table_calls']);

        $_POST = [
            'meta_key'    => 'crm_tier',
            'meta_value'  => 'gold',
            'run_token'   => $token,
            'batch_size'  => '50',
            'batch_index' => '0',
            'nonce'       => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_success', $e->getMessage());
        }

        $this->assertSame(1, $GLOBALS['meprmf_test_list_table_calls']);
        $this->assertSame('gold', $GLOBALS['meprmf_test_user_meta'][201]['crm_tier']);
        $this->assertSame('gold', $GLOBALS['meprmf_test_user_meta'][202]['crm_tier']);
    }

    public function test_live_batch_rejects_a_missing_run_token()
    {
        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $_GET                                               = $this->filtered_get();
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

        $last = $GLOBALS['meprmf_test_json_responses'][ count($GLOBALS['meprmf_test_json_responses'] ) - 1 ];
        $this->assertSame('missing_run_token', $last['data']['code']);
    }

    public function test_live_batch_rejects_a_meta_key_mismatch()
    {
        $this->activate_predicate();
        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $_GET                                               = $this->filtered_get();
        $_POST                                              = [
            'meta_key'   => 'crm_tier',
            'meta_value' => 'gold',
            'dry_run'    => '1',
            'nonce'      => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_success', $e->getMessage());
        }

        $dry_run = $GLOBALS['meprmf_test_json_responses'][ count($GLOBALS['meprmf_test_json_responses'] ) - 1 ];
        $_POST   = [
            'meta_key'    => 'other_key',
            'meta_value'  => 'gold',
            'run_token'   => (string) $dry_run['data']['runToken'],
            'batch_index' => '0',
            'nonce'       => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
            $this->fail('Expected ajax handler to send a JSON error.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_error', $e->getMessage());
        }

        $last = $GLOBALS['meprmf_test_json_responses'][ count($GLOBALS['meprmf_test_json_responses'] ) - 1 ];
        $this->assertSame('snapshot_mismatch', $last['data']['code']);
    }

    public function test_live_batch_rejects_a_changed_filter_query()
    {
        $this->activate_predicate();
        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $_GET                                               = $this->filtered_get();
        $_POST                                              = [
            'meta_key'   => 'crm_tier',
            'meta_value' => 'gold',
            'dry_run'    => '1',
            'nonce'      => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_success', $e->getMessage());
        }

        $dry_run = $GLOBALS['meprmf_test_json_responses'][ count($GLOBALS['meprmf_test_json_responses'] ) - 1 ];
        $_GET['mpm_access'] = 'inactive';
        $_POST              = [
            'meta_key'    => 'crm_tier',
            'meta_value'  => 'gold',
            'run_token'   => (string) $dry_run['data']['runToken'],
            'batch_index' => '0',
            'nonce'       => 'test-nonce',
        ];

        try {
            Meprmf_Bulk::ajax_bulk_set_meta();
            $this->fail('Expected ajax handler to send a JSON error.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meprmf_test_json_error', $e->getMessage());
        }

        $last = $GLOBALS['meprmf_test_json_responses'][ count($GLOBALS['meprmf_test_json_responses'] ) - 1 ];
        $this->assertSame('snapshot_mismatch', $last['data']['code']);
    }
}
