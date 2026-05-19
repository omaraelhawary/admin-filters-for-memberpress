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

        if (! class_exists('MeprTransaction', false)) {
            eval(
                'class MeprTransaction {
                    public static $payment_str = "payment";
                    public static $sub_account_str = "sub_account";
                    public static $woo_txn_str = "wc_transaction";
                    public static $fallback_str = "fallback";
                    public static $complete_str = "complete";
                    public static $subscription_confirmation_str = "subscription_confirmation";
                    public static $confirmed_str = "confirmed";
                }'
            );
        }

        if (! class_exists('MeprSubscription', false)) {
            eval(
                'class MeprSubscription {
                    public static $active_str = "active";
                    public static $pending_str = "pending";
                    public static $cancelled_str = "cancelled";
                    public static $suspended_str = "suspended";
                }'
            );
        }

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
            [ 'param' => 'mpm_product', 'label' => 'Membership', 'type' => 'select', 'source' => 'mepr_transaction', 'options' => [ 1 => 'Plan' ] ],
            [ 'param' => 'mpm_access', 'label' => 'Access', 'type' => 'select', 'source' => 'mepr_transaction', 'options' => [ 'active' => 'Active' ] ],
            [ 'param' => 'mpm_sub_status', 'label' => 'Sub', 'type' => 'select', 'source' => 'mepr_subscription', 'options' => [ 'active' => 'Active' ] ],
            [ 'param' => 'mpm_exp_from', 'label' => 'From', 'type' => 'date', 'source' => 'mepr_transaction' ],
            [ 'param' => 'mpm_exp_to', 'label' => 'To', 'type' => 'date', 'source' => 'mepr_transaction' ],
            [ 'param' => 'mpm_member_from', 'label' => 'MF', 'type' => 'date', 'source' => 'mepr_member' ],
            [ 'param' => 'mpm_member_to', 'label' => 'MT', 'type' => 'date', 'source' => 'mepr_member' ],
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

    public function test_member_date_range_uses_members_table()
    {
        $_GET['mpm_member_from'] = '2026-01-01';
        $_GET['mpm_member_to']   = '2026-12-31';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->core_field_defs());

        $this->assertCount(2, $args);
        $this->assertStringContainsString('m.created_at', $args[0]);
        $this->assertStringContainsString('2026-01-01', $args[0]);
        $this->assertStringContainsString('m.created_at', $args[1]);
        $this->assertStringContainsString('2026-12-31', $args[1]);
    }

    public function test_skips_on_non_members_context()
    {
        $_GET['mpm_product'] = '1';

        $ctx  = new Meprmf_Screen_Context('memberpress-subscriptions', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->core_field_defs());

        $this->assertSame([], $args);
    }
}
