<?php
/**
 * Subscription tab cross-screen filter translation tests.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Screen;
use Meprmf_Screen_Context;
use Meprmf_Subscription_Tabs;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Subscription_Tabs
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SubscriptionTabsTest extends TestCase
{

    /** @var array<string, mixed> */
    private $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->load_dependencies();
        $this->original_get = $_GET;
        $_GET               = [];
        Meprmf_Util::reset_request_overrides();
    }

    protected function tearDown(): void
    {
        $_GET = $this->original_get;
        Meprmf_Util::reset_request_overrides();
        parent::tearDown();
    }

    private function load_dependencies(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-presets.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/class-meprmf-filter-registry.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-subscription-tabs.php';

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

        if (! class_exists('MeprTransaction', false)) {
            eval(
                'class MeprTransaction {
                    public static $pending_str = "pending";
                    public static $complete_str = "complete";
                    public static $confirmed_str = "confirmed";
                    public static $refunded_str = "refunded";
                    public static $failed_str = "failed";
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
                        unset($unused, $args);
                        if ("MeprProduct" === $model) {
                            return [ (object) [ "ID" => 4, "post_title" => "Gold" ] ];
                        }
                        return [];
                    }
                }'
            );
        }
    }

    public function test_translate_subscriptions_filters_to_lifetimes()
    {
        $_GET['page']             = Meprmf_Screen::PAGE_SUBSCRIPTIONS;
        $_GET['mpms_product']     = '4';
        $_GET['mpms_sub_status']  = 'active';
        $_GET['meprmf_match']      = 'any';

        $from = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');
        $to   = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_LIFETIMES, 'txn.user_id');

        $translated = Meprmf_Subscription_Tabs::translate_request_params($from, $to);

        $this->assertSame('4', $translated['mpml_product']);
        $this->assertSame('active', $translated['mpml_sub_status']);
        $this->assertSame('any', $translated['meprmf_match']);
    }

    public function test_translate_drops_transaction_count_for_lifetimes_peer()
    {
        $_GET['page']                  = Meprmf_Screen::PAGE_SUBSCRIPTIONS;
        $_GET['mpms_product']          = '4';
        $_GET['mpms_txn_count_min']    = '2';

        $from = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');
        $to   = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_LIFETIMES, 'txn.user_id');

        $translated = Meprmf_Subscription_Tabs::translate_request_params($from, $to);

        $this->assertSame('4', $translated['mpml_product']);
        $this->assertArrayNotHasKey('mpml_txn_count_min', $translated);
        $this->assertContains('mpms_txn_count_min', Meprmf_Subscription_Tabs::untranslatable_core_params($from, $to));
    }

    public function test_translate_drops_lifetime_only_filters_for_subscriptions_peer()
    {
        $_GET['page']               = Meprmf_Screen::PAGE_LIFETIMES;
        $_GET['mpml_product']       = '9';
        $_GET['mpml_txn_status']    = 'complete';
        $_GET['mpml_created_from']  = '2026-01-01';

        $from = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_LIFETIMES, 'txn.user_id');
        $to   = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');

        $translated = Meprmf_Subscription_Tabs::translate_request_params($from, $to);

        $this->assertSame('9', $translated['mpms_product']);
        $this->assertArrayNotHasKey('mpms_txn_status', $translated);
        $this->assertArrayNotHasKey('mpms_created_from', $translated);
    }

    public function test_request_overrides_feed_predicate_builders()
    {
        Meprmf_Util::push_request_overrides([ 'mpml_product' => '7' ]);

        $this->assertSame('7', Meprmf_Util::get_request_value('mpml_product'));

        Meprmf_Util::pop_request_overrides();
    }

    public function test_tab_link_config_exposes_peer_prefixes()
    {
        $ctx = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');
        $cfg = Meprmf_Subscription_Tabs::tab_link_config($ctx);

        $this->assertIsArray($cfg);
        $this->assertSame(Meprmf_Screen::PAGE_LIFETIMES, $cfg['peerPage']);
        $this->assertSame('mpms_', $cfg['coreFromPrefix']);
        $this->assertSame('mpml_', $cfg['coreToPrefix']);
    }
}
