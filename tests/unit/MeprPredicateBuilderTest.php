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
        require_once dirname(__DIR__, 2) . '/includes/sql/class-meprmf-corporate-predicates.php';
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activity_field_defs()
    {
        return [
            [
                'param'     => 'mpm_registered_from',
                'label'     => 'Registered from',
                'type'      => 'date',
                'source'    => 'mepr_member',
                'predicate' => 'registered_from',
            ],
            [
                'param'     => 'mpm_registered_to',
                'label'     => 'Registered to',
                'type'      => 'date',
                'source'    => 'mepr_member',
                'predicate' => 'registered_to',
            ],
            [
                'param'     => 'mpm_last_login_from',
                'label'     => 'Last login from',
                'type'      => 'date',
                'source'    => 'mepr_member',
                'predicate' => 'last_login_from',
            ],
            [
                'param'     => 'mpm_spent_min',
                'label'     => 'Spent min',
                'type'      => 'text',
                'source'    => 'mepr_member',
                'predicate' => 'spent_min',
            ],
            [
                'param'     => 'mpm_trial',
                'label'     => 'Trial',
                'type'      => 'checkbox',
                'source'    => 'mepr_member',
                'predicate' => 'trial',
            ],
        ];
    }

    public function test_members_activity_registered_and_trial_predicates()
    {
        $_GET['mpm_registered_from'] = '2025-01-01';
        $_GET['mpm_registered_to']   = '2025-12-31';
        $_GET['mpm_trial']           = '1';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->activity_field_defs());

        // A from/to pair is one fragment, so registered + trial is two.
        $this->assertGreaterThanOrEqual(2, count($args));
        $combined = implode("\n", $args);
        $this->assertStringContainsString('u.user_registered', $combined);
        $this->assertStringContainsString('trial_txn_count', $combined);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function range_activity_field_defs()
    {
        return [
            [ 'param' => 'mpm_registered_from', 'label' => 'RF', 'type' => 'date', 'source' => 'mepr_member', 'predicate' => 'registered_from', 'range_of' => 'mpm_registered', 'range_part' => 'from' ],
            [ 'param' => 'mpm_registered_to', 'label' => 'RT', 'type' => 'date', 'source' => 'mepr_member', 'predicate' => 'registered_to', 'range_of' => 'mpm_registered', 'range_part' => 'to' ],
            [ 'param' => 'mpm_last_login_from', 'label' => 'LF', 'type' => 'date', 'source' => 'mepr_member', 'predicate' => 'last_login_from', 'range_of' => 'mpm_last_login', 'range_part' => 'from' ],
            [ 'param' => 'mpm_last_login_to', 'label' => 'LT', 'type' => 'date', 'source' => 'mepr_member', 'predicate' => 'last_login_to', 'range_of' => 'mpm_last_login', 'range_part' => 'to' ],
        ];
    }

    public function test_registered_after_fills_only_the_lower_bound_in_one_fragment()
    {
        $_GET['mpm_registered_from'] = '2025-01-01';
        $_GET['mpm_registered_to']   = '2025-12-31';
        $_GET['mpm_registered__op']  = 'after';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->range_activity_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString("u.user_registered >= '2025-01-01 00:00:00'", $args[0]);
        $this->assertStringNotContainsString('<=', $args[0]);
    }

    public function test_last_login_not_in_last_keeps_members_who_never_logged_in()
    {
        $_GET['mpm_last_login__op'] = 'not_in_last';
        $_GET['mpm_last_login__n']  = '90';
        $_GET['mpm_last_login__u']  = 'days';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->range_activity_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('last_login.created_at IS NULL OR NOT (', $args[0]);

        $from = \Meprmf_Util::resolve_relative_bounds(90, 'days');
        $this->assertStringContainsString($from['from'] . ' 00:00:00', $args[0]);
    }

    public function test_registered_in_last_is_not_negated()
    {
        $_GET['mpm_registered__op'] = 'in_last';
        $_GET['mpm_registered__n']  = '7';
        $_GET['mpm_registered__u']  = 'days';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->range_activity_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringNotContainsString('NOT (', $args[0]);
        $this->assertStringContainsString('u.user_registered >=', $args[0]);
        $this->assertStringContainsString('u.user_registered <=', $args[0]);
    }

    public function test_members_corp_type_sub_account_predicate()
    {
        if (! class_exists('MPCA_Db', false)) {
            eval(
                'class MPCA_Db {
                    public $corporate_accounts = "wp_mepr_corporate_accounts";
                    public static function fetch() { return new self(); }
                }'
            );
        }

        $_GET['mpm_corp_type'] = 'sub_account';

        $fields = $this->core_field_defs();
        $fields[] = [
            'param'     => 'mpm_corp_type',
            'label'     => 'Corporate type',
            'type'      => 'select',
            'source'    => 'mepr_member',
            'predicate' => 'corp_type',
            'options'   => [ 'sub_account' => 'Sub account' ],
        ];

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $fields);

        $this->assertCount(1, $args);
        $this->assertStringContainsString('mpca_corporate_account_id', $args[0]);
    }

    public function test_lifetimes_coupon_predicate()
    {
        $GLOBALS['meprmf_test_posts'] = [
            5 => (object) [ 'post_type' => 'memberpresscoupon' ],
        ];

        $_GET['mpml_coupon'] = '5';

        $fields = $this->lifetimes_core_field_defs();
        $fields[] = [
            'param'     => 'mpml_coupon',
            'label'     => 'Coupon',
            'type'      => 'select',
            'source'    => 'mepr_transaction',
            'predicate' => 'coupon',
            'options'   => [ 5 => 'Save10' ],
        ];

        $ctx  = new Meprmf_Screen_Context('memberpress-lifetimes', 'txn.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $fields);

        $this->assertCount(1, $args);
        $this->assertStringContainsString('txn.coupon_id', $args[0]);
        $this->assertStringContainsString('5', $args[0]);
    }

    /**
     * Amount pair, Subscription text field, and the subscriptions Transaction count pair (#25).
     *
     * @return array<int, array<string, mixed>>
     */
    private function transactions_amount_field_defs()
    {
        return [
            [ 'param' => 'mpmt_amount_min', 'label' => 'Amount (min)', 'type' => 'number', 'source' => 'mepr_transaction', 'predicate' => 'amount_min', 'range_of' => 'mpmt_amount', 'range_part' => 'min', 'unit' => '$' ],
            [ 'param' => 'mpmt_amount_max', 'label' => 'Amount (max)', 'type' => 'number', 'source' => 'mepr_transaction', 'predicate' => 'amount_max', 'range_of' => 'mpmt_amount', 'range_part' => 'max', 'unit' => '$' ],
            [ 'param' => 'mpmt_subscription', 'label' => 'Subscription', 'type' => 'text', 'source' => 'mepr_subscription', 'predicate' => 'subscr_id', 'operator_aware' => true ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function subscriptions_txn_count_field_defs()
    {
        return [
            [ 'param' => 'mpms_txn_count_min', 'label' => 'Transaction count (min)', 'type' => 'number', 'source' => 'mepr_subscription', 'predicate' => 'txn_count_min', 'range_of' => 'mpms_txn_count', 'range_part' => 'min' ],
            [ 'param' => 'mpms_txn_count_max', 'label' => 'Transaction count (max)', 'type' => 'number', 'source' => 'mepr_subscription', 'predicate' => 'txn_count_max', 'range_of' => 'mpms_txn_count', 'range_part' => 'max' ],
        ];
    }

    public function test_transactions_amount_between_bounds_the_row_total()
    {
        $_GET['mpmt_amount_min']  = '25';
        $_GET['mpmt_amount_max']  = '100';
        $_GET['mpmt_amount__op']  = 'between';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(2, $args);
        $this->assertStringContainsString('tr.total >=', $args[0]);
        $this->assertStringContainsString('25', $args[0]);
        $this->assertStringContainsString('tr.total <=', $args[1]);
        $this->assertStringContainsString('100', $args[1]);
    }

    public function test_transactions_amount_at_least_leaves_the_upper_bound_open()
    {
        $_GET['mpmt_amount_min'] = '50';
        $_GET['mpmt_amount__op'] = 'at_least';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('tr.total >=', $args[0]);
        $this->assertStringNotContainsString('<=', $args[0]);
    }

    public function test_amount_bounds_are_ignored_on_a_screen_without_the_field()
    {
        $_GET['mpmt_amount_min'] = '50';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_core_field_defs());

        $this->assertSame([], $args);
    }

    public function test_subscription_field_defaults_to_a_substring_match()
    {
        $_GET['mpmt_subscription'] = 'sub_123';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('sub.subscr_id LIKE', $args[0]);
        // esc_like escapes the underscore, so `sub_123` is a literal, not a single-char wildcard.
        $this->assertStringContainsString('%sub\\_123%', $args[0]);
    }

    public function test_subscription_is_operator_matches_exactly()
    {
        $_GET['mpmt_subscription']      = 'sub_123';
        $_GET['mpmt_subscription__op']  = 'is';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('sub.subscr_id =', $args[0]);
        $this->assertStringNotContainsString('LIKE', $args[0]);
    }

    public function test_subscription_negative_operators_keep_rows_with_no_subscription()
    {
        $_GET['mpmt_subscription']     = 'sub_123';
        $_GET['mpmt_subscription__op'] = 'not_contains';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('sub.subscr_id IS NULL', $args[0]);
        $this->assertStringContainsString('NOT LIKE', $args[0]);

        $_GET['mpmt_subscription__op'] = 'is_not';
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('sub.subscr_id IS NULL', $args[0]);
        $this->assertStringContainsString('<>', $args[0]);
    }

    public function test_subscription_is_empty_needs_no_value()
    {
        $_GET['mpmt_subscription__op'] = 'is_empty';

        $ctx  = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('sub.subscr_id IS NULL', $args[0]);

        $_GET['mpmt_subscription__op'] = 'is_not_empty';
        Meprmf_Mepr_Predicate_Builder::reset_last_fragments();
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->transactions_amount_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('sub.subscr_id IS NOT NULL', $args[0]);
    }

    public function test_transaction_count_counts_only_complete_transactions_of_the_row()
    {
        $_GET['mpms_txn_count_min'] = '2';

        $ctx  = new Meprmf_Screen_Context('memberpress-subscriptions', 'sub.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->subscriptions_txn_count_field_defs());

        $this->assertCount(1, $args);
        $this->assertStringContainsString('SELECT COUNT(*)', $args[0]);
        $this->assertStringContainsString('wp_mepr_transactions', $args[0]);
        $this->assertStringContainsString('meprmf_txn_cnt.subscription_id = sub.id', $args[0]);
        $this->assertStringContainsString("'complete'", $args[0]);
        $this->assertStringContainsString('>= 2', $args[0]);
    }

    public function test_transaction_count_between_bounds_both_ends()
    {
        $_GET['mpms_txn_count_min'] = '2';
        $_GET['mpms_txn_count_max'] = '6';

        $ctx  = new Meprmf_Screen_Context('memberpress-subscriptions', 'sub.user_id');
        $args = Meprmf_Mepr_Predicate_Builder::append_mepr_exists([], $ctx, $this->subscriptions_txn_count_field_defs());

        $this->assertCount(2, $args);
        $this->assertStringContainsString('>= 2', $args[0]);
        $this->assertStringContainsString('<= 6', $args[1]);
    }
}
