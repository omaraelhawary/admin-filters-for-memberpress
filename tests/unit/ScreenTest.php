<?php
/**
 * Screen detection tests (no WordPress is_admin; test null branch via unset superglobal).
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Screen;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Screen
 */
class ScreenTest extends TestCase
{

    protected function tearDown(): void
    {
        unset($_GET['page']);
        parent::tearDown();
    }

    public function test_detect_returns_null_when_page_not_set()
    {
        unset($_GET['page']);
        $this->assertNull(Meprmf_Screen::detect());
    }

    public function test_detect_returns_null_for_unknown_page()
    {
        $_GET['page'] = 'some-other-page';
        $this->assertNull(Meprmf_Screen::detect());
    }

    public function test_detect_members_context()
    {
        $_GET['page'] = 'memberpress-members';
        $ctx = Meprmf_Screen::detect();
        $this->assertNotNull($ctx);
        $this->assertSame('u.ID', $ctx->get_user_id_column_sql());
        $this->assertTrue($ctx->is_members());
        $this->assertTrue($ctx->supports_meta_filters_list());
    }

    public function test_detect_subscriptions_recurring_context()
    {
        $_GET['page'] = Meprmf_Screen::PAGE_SUBSCRIPTIONS;
        $ctx          = Meprmf_Screen::detect();
        $this->assertNotNull($ctx);
        $this->assertSame('sub.user_id', $ctx->get_user_id_column_sql());
        $this->assertTrue($ctx->is_subscriptions_recurring());
        $this->assertFalse($ctx->is_members());
    }

    public function test_detect_lifetimes_context()
    {
        $_GET['page'] = Meprmf_Screen::PAGE_LIFETIMES;
        $ctx          = Meprmf_Screen::detect();
        $this->assertNotNull($ctx);
        $this->assertSame('txn.user_id', $ctx->get_user_id_column_sql());
        $this->assertTrue($ctx->is_lifetimes());
    }

    public function test_detect_transactions_context()
    {
        $_GET['page'] = Meprmf_Screen::PAGE_TRANSACTIONS;
        $ctx          = Meprmf_Screen::detect();
        $this->assertNotNull($ctx);
        $this->assertSame('tr.user_id', $ctx->get_user_id_column_sql());
        $this->assertTrue($ctx->is_transactions());
    }
}
