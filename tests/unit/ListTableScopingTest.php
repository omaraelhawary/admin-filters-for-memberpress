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
}
