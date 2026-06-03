<?php
/**
 * Tests MemberPress table predicate SQL generation.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Mepr_Predicate_Builder;
use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Mepr_Predicate_Builder
 */
class MeprPredicateBuilderTest extends TestCase
{

    /** @var array<string, string> */
    private $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrap_stubs();
        $this->original_get = $_GET;
        $_GET               = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->original_get;
        parent::tearDown();
    }

    private function bootstrap_stubs(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/sql/class-meprmf-mepr-predicate-builder.php';

        if (! class_exists('MeprUtils', false)) {
            eval(
                'class MeprUtils {
                    public static function db_now() { return "2026-05-19 12:00:00"; }
                    public static function db_lifetime() { return "0000-00-00 00:00:00"; }
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

        global $wpdb;
        $wpdb = new class() {
            public $prefix = 'wp_';

            /**
             * @param string $query Query.
             * @param mixed  ...$args Args.
             * @return string
             */
            public function prepare($query, ...$args)
            {
                if (empty($args)) {
                    return $query;
                }
                return vsprintf(
                    preg_replace('/%[dfs]/', '%s', $query),
                    array_map(
                        static function ($arg) {
                            return is_numeric($arg) ? (string) $arg : "'" . (string) $arg . "'";
                        },
                        $args
                    )
                );
            }
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function core_field_defs()
    {
        return [
            [ 'param' => 'mpm_product', 'label' => 'Membership', 'type' => 'select', 'source' => 'mepr_transaction', 'predicate' => 'product', 'options' => [ 1 => 'Plan' ] ],
            [ 'param' => 'mpm_access', 'label' => 'Access', 'type' => 'select', 'source' => 'mepr_transaction', 'predicate' => 'access', 'options' => [ 'active' => 'Active' ] ],
            [ 'param' => 'mpm_sub_status', 'label' => 'Sub', 'type' => 'select', 'source' => 'mepr_subscription', 'predicate' => 'sub_status', 'options' => [ 'active' => 'Active' ] ],
            [ 'param' => 'mpm_exp_from', 'label' => 'From', 'type' => 'date', 'source' => 'mepr_transaction', 'predicate' => 'exp_from' ],
            [ 'param' => 'mpm_exp_to', 'label' => 'To', 'type' => 'date', 'source' => 'mepr_transaction', 'predicate' => 'exp_to' ],
            [ 'param' => 'mpm_member_from', 'label' => 'MF', 'type' => 'date', 'source' => 'mepr_member', 'predicate' => 'member_from' ],
            [ 'param' => 'mpm_member_to', 'label' => 'MT', 'type' => 'date', 'source' => 'mepr_member', 'predicate' => 'member_to' ],
        ];
    }

    public function test_active_access_and_sub_status_generate_exists_fragments()
    {
        $_GET['mpm_product']    = '42';
        $_GET['mpm_access']     = 'active';
        $_GET['mpm_sub_status'] = 'active';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->core_field_defs());

        $this->assertNotEmpty($args);
        $combined = implode("\n", $args);
        $this->assertStringContainsString('wp_mepr_transactions', $combined);
        $this->assertStringContainsString('wp_mepr_subscriptions', $combined);
        $this->assertStringContainsString('u.ID', $combined);
        $this->assertStringContainsString('product_id', $combined);
        $this->assertStringContainsString("'active'", $combined);

        $fragments = Meprmf_Mepr_Predicate_Builder::get_last_fragments();
        $this->assertIsArray($fragments);
        $this->assertGreaterThanOrEqual(2, count($fragments));
    }

    public function test_inactive_access_accepts_inactive_and_legacy_expired_value()
    {
        $_GET['mpm_access'] = 'inactive';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->core_field_defs());

        $this->assertNotEmpty($args);
        $this->assertStringContainsString('NOT EXISTS', implode("\n", $args));

        $_GET['mpm_access'] = 'expired';
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();
        $args_legacy = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->core_field_defs());
        $this->assertNotEmpty($args_legacy);
    }

    public function test_member_date_range_uses_members_table_exists()
    {
        $_GET['mpm_member_from'] = '2026-01-01';
        $_GET['mpm_member_to']   = '2026-12-31';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->core_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('EXISTS', $args[0]);
        $this->assertStringContainsString('wp_mepr_members', $args[0]);
        $this->assertStringContainsString('2026-01-01', $args[0]);
        $this->assertStringContainsString('2026-12-31', $args[0]);
        $this->assertStringContainsString('u.ID', $args[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transactions_core_field_defs()
    {
        $fields = $this->core_field_defs();
        $out    = [];
        foreach ($fields as $field) {
            if (! empty($field['param']) && is_string($field['param'])) {
                $field['param'] = 'mpmt_' . substr($field['param'], strlen('mpm_'));
            }
            $out[] = $field;
        }

        return $out;
    }

    public function test_transactions_row_product_filter()
    {
        $_GET['mpmt_product'] = '7';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_core_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('tr.product_id', $args[0]);
        $this->assertStringContainsString('7', $args[0]);
    }

    public function test_subscriptions_row_status_filter()
    {
        $_GET['mpms_sub_status'] = 'active';

        $fields = $this->transactions_core_field_defs();
        $subs   = [];
        foreach ($fields as $field) {
            if (! empty($field['param']) && is_string($field['param'])) {
                $field['param'] = 'mpms_' . substr($field['param'], strlen('mpmt_'));
            }
            $subs[] = $field;
        }

        $ctx  = new Meprmf_Screen_Context('memberpress-subscriptions', 'sub.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $subs);

        $this->assertCount(1, $args);
        $this->assertStringContainsString('sub.status', $args[0]);
        $this->assertStringContainsString("'active'", $args[0]);
    }

    public function test_members_member_status_expired_clause()
    {
        $_GET['mpm_member_status'] = 'expired';

        $fields = $this->core_field_defs();
        $fields[] = [
            'param'     => 'mpm_member_status',
            'label'     => 'Member status',
            'type'      => 'select',
            'source'    => 'mepr_member',
            'predicate' => 'member_status',
            'options'   => [ 'expired' => 'Expired' ],
        ];

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $fields);

        $this->assertCount(1, $args);
        $this->assertStringContainsString('inactive_memberships', $args[0]);
    }

    public function test_transactions_txn_status_filter()
    {
        $_GET['mpmt_txn_status'] = 'complete';

        $fields = $this->transactions_core_field_defs();
        $fields[] = [
            'param'     => 'mpmt_txn_status',
            'label'     => 'Txn status',
            'type'      => 'select',
            'source'    => 'mepr_transaction',
            'predicate' => 'txn_status',
            'options'   => [ 'complete' => 'Complete' ],
        ];

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $fields);

        $this->assertCount(1, $args);
        $this->assertStringContainsString('tr.status', $args[0]);
        $this->assertStringContainsString("'complete'", $args[0]);
    }

    public function test_transactions_confirmed_txn_status_filter()
    {
        $_GET['mpmt_txn_status'] = 'confirmed';

        $fields = $this->transactions_core_field_defs();
        $fields[] = [
            'param'     => 'mpmt_txn_status',
            'label'     => 'Txn status',
            'type'      => 'select',
            'source'    => 'mepr_transaction',
            'predicate' => 'txn_status',
            'options'   => [ 'confirmed' => 'Confirmed' ],
        ];

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $fields);

        $this->assertCount(1, $args);
        $this->assertStringContainsString("'confirmed'", $args[0]);
    }

    public function test_transactions_gateway_filter()
    {
        if (! class_exists('MeprOptions', false)) {
            eval(
                'class MeprOptions {
                    public static function fetch() { return new self(); }
                    public function payment_methods() {
                        return [ "manual" => (object) [ "label" => "Manual", "name" => "Manual" ] ];
                    }
                }'
            );
        }

        $_GET['mpmt_gateway'] = 'manual';

        $fields = $this->transactions_core_field_defs();
        $fields[] = [
            'param'     => 'mpmt_gateway',
            'label'     => 'Gateway',
            'type'      => 'select',
            'source'    => 'mepr_transaction',
            'predicate' => 'gateway',
            'options'   => [ 'manual' => 'Manual' ],
        ];

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $fields);

        $this->assertCount(1, $args);
        $this->assertStringContainsString('tr.gateway', $args[0]);
        $this->assertStringContainsString("'manual'", $args[0]);
    }

    public function test_transactions_created_date_range()
    {
        $_GET['mpmt_created_from'] = '2026-02-01';
        $_GET['mpmt_created_to']   = '2026-02-28';

        $fields = $this->transactions_core_field_defs();
        $fields[] = [
            'param'     => 'mpmt_created_from',
            'label'     => 'Created from',
            'type'      => 'date',
            'source'    => 'mepr_transaction',
            'predicate' => 'created_from',
        ];
        $fields[] = [
            'param'     => 'mpmt_created_to',
            'label'     => 'Created to',
            'type'      => 'date',
            'source'    => 'mepr_transaction',
            'predicate' => 'created_to',
        ];

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $fields);

        $this->assertCount(1, $args);
        $this->assertStringContainsString('tr.created_at', $args[0]);
        $this->assertStringContainsString('2026-02-01', $args[0]);
        $this->assertStringContainsString('2026-02-28', $args[0]);
    }

    public function test_transactions_row_inactive_access()
    {
        $_GET['mpmt_access'] = 'inactive';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_core_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('tr.expires_at', $args[0]);
        $this->assertStringNotContainsString('NOT EXISTS', $args[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lifetimes_core_field_defs()
    {
        $fields = $this->transactions_core_field_defs();
        $out    = [];
        foreach ($fields as $field) {
            if (! empty($field['param']) && is_string($field['param'])) {
                $field['param'] = 'mpml_' . substr($field['param'], strlen('mpmt_'));
            }
            $out[] = $field;
        }

        return $out;
    }

    public function test_lifetimes_row_product_and_sub_status()
    {
        $_GET['mpml_product']    = '3';
        $_GET['mpml_sub_status'] = 'cancelled';

        $ctx  = new Meprmf_Screen_Context('memberpress-lifetimes', 'txn.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->lifetimes_core_field_defs());

        $this->assertGreaterThanOrEqual(2, count($args));
        $combined = implode("\n", $args);
        $this->assertStringContainsString('txn.product_id', $combined);
        $this->assertStringContainsString('wp_mepr_subscriptions', $combined);
        $this->assertStringContainsString("'cancelled'", $combined);
    }

    public function test_combined_access_and_product_on_members()
    {
        $_GET['mpm_product'] = '9';
        $_GET['mpm_access']  = 'active';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->core_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('product_id', $args[0]);
        $this->assertStringContainsString('9', $args[0]);
    }
}
