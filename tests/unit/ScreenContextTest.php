<?php
/**
 * Screen context identifiers (no WordPress).
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Screen_Context
 */
class ScreenContextTest extends TestCase
{

    public function test_wp_screen_id_follows_memberpress_submenu_pattern()
    {
        $ctx = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $this->assertSame('memberpress_page_memberpress-trans', $ctx->get_wp_screen_id());
    }

    public function test_storage_id_normalizes_hyphens()
    {
        $ctx = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $this->assertSame('memberpress_members', $ctx->get_storage_id());
    }
}
