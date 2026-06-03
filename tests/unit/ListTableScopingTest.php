<?php
/**
 * List-table caller detection and predicate gating.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Screen;
use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Screen
 */
class ListTableScopingTest extends TestCase
{

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_filters'] = [];
        unset($GLOBALS['meprmf_test_current_screen'], $_GET['page']);
        parent::tearDown();
    }

    public function test_context_for_list_table_caller_maps_memberpress_models()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';

        $members = Meprmf_Screen::context_for_list_table_caller('MeprUser', 'list_table');
        $this->assertNotNull($members);
        $this->assertTrue($members->is_members());

        $trans = Meprmf_Screen::context_for_list_table_caller('MeprTransaction', 'list_table');
        $this->assertNotNull($trans);
        $this->assertTrue($trans->is_transactions());

        $subs = Meprmf_Screen::context_for_list_table_caller('MeprSubscription', 'subscr_table');
        $this->assertNotNull($subs);
        $this->assertTrue($subs->is_subscriptions_recurring());

        $life = Meprmf_Screen::context_for_list_table_caller('MeprSubscription', 'lifetime_subscr_table');
        $this->assertNotNull($life);
        $this->assertTrue($life->is_lifetimes());
    }

    public function test_context_for_list_table_caller_returns_null_for_unknown()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';

        $this->assertNull(Meprmf_Screen::context_for_list_table_caller('MeprSubscription', 'upgrade_query'));
        $this->assertNull(Meprmf_Screen::context_for_list_table_caller('SomeClass', 'list_table'));
    }

    public function test_detect_list_table_context_from_backtrace()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';

        if (! class_exists('MeprUser', false)) {
            eval(
                'class MeprUser {
                    public static function list_table() {
                        return \\Meprmf_Screen::detect_list_table_context();
                    }
                }'
            );
        }

        $ctx = \MeprUser::list_table();
        $this->assertNotNull($ctx);
        $this->assertTrue($ctx->is_members());
    }

    public function test_should_apply_list_table_predicates_when_caller_and_page_match()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';

        if (! class_exists('Meprmf_Test_Scope_Trans', false)) {
            eval(
                'class Meprmf_Test_Scope_Trans {
                    public static function list_table() {
                        $page_ctx = \\Meprmf_Screen::detect();
                        return null !== $page_ctx && \\Meprmf_Screen::should_apply_list_table_predicates($page_ctx);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Scope_Trans' === $class && 'list_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
                }
                return $ctx;
            }
        );

        $_GET['page'] = Meprmf_Screen::PAGE_TRANSACTIONS;
        $GLOBALS['meprmf_test_current_screen'] = (object) [ 'id' => 'memberpress_page_memberpress-trans' ];

        $this->assertTrue(\Meprmf_Test_Scope_Trans::list_table());

        unset($_GET['page'], $GLOBALS['meprmf_test_current_screen']);
    }

    public function test_should_apply_list_table_predicates_when_wp_screen_unset()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';

        if (! class_exists('Meprmf_Test_Scope_Members', false)) {
            eval(
                'class Meprmf_Test_Scope_Members {
                    public static function list_table() {
                        $page_ctx = \\Meprmf_Screen::detect();
                        return null !== $page_ctx && \\Meprmf_Screen::should_apply_list_table_predicates($page_ctx);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Scope_Members' === $class && 'list_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
                }
                return $ctx;
            }
        );

        $_GET['page'] = Meprmf_Screen::PAGE_MEMBERS;
        unset($GLOBALS['meprmf_test_current_screen']);

        $this->assertTrue(\Meprmf_Test_Scope_Members::list_table());

        unset($_GET['page']);
    }

    public function test_should_not_apply_when_page_and_caller_mismatch()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';

        if (! class_exists('Meprmf_Test_Scope_Members', false)) {
            eval(
                'class Meprmf_Test_Scope_Members {
                    public static function list_table() {
                        $page_ctx = \\Meprmf_Screen::detect();
                        return null !== $page_ctx && \\Meprmf_Screen::should_apply_list_table_predicates($page_ctx);
                    }
                }'
            );
        }

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('Meprmf_Test_Scope_Members' === $class && 'list_table' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
                }
                return $ctx;
            }
        );

        $_GET['page'] = Meprmf_Screen::PAGE_TRANSACTIONS;
        $GLOBALS['meprmf_test_current_screen'] = (object) [ 'id' => 'memberpress_page_memberpress-trans' ];

        $this->assertFalse(\Meprmf_Test_Scope_Members::list_table());

        unset($_GET['page'], $GLOBALS['meprmf_test_current_screen']);
    }

    public function test_list_table_caller_context_filter()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';

        \add_filter(
            'meprmf_list_table_caller_context',
            static function ($ctx, $class, $function) {
                if ('CustomWrapper' === $class && 'fetch_members' === $function) {
                    return new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
                }
                return $ctx;
            },
            10,
            3
        );

        $ctx = Meprmf_Screen::context_for_list_table_caller('CustomWrapper', 'fetch_members');
        $this->assertNotNull($ctx);
        $this->assertTrue($ctx->is_members());
    }
}
