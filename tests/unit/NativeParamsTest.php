<?php
/**
 * Tests for Meprmf_Native_Params.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Native_Params;
use Meprmf_Screen;
use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Native_Params
 */
class NativeParamsTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-native-params.php';
    }

    public function test_members_native_keys()
    {
        $ctx  = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
        $keys = Meprmf_Native_Params::for_context($ctx);

        $this->assertSame([ 'status', 'membership' ], $keys);
    }

    public function test_transactions_native_keys_include_dates()
    {
        $ctx  = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $keys = Meprmf_Native_Params::for_context($ctx);

        $this->assertContains('status', $keys);
        $this->assertContains('membership', $keys);
        $this->assertContains('gateway', $keys);
        $this->assertContains('date_range_filter', $keys);
        $this->assertContains('date_start', $keys);
        $this->assertContains('date_end', $keys);
        $this->assertContains('date_field', $keys);
    }

    public function test_transactions_includes_gifting_type_when_active()
    {
        if (! class_exists('memberpress\\gifting\\controllers\\App', false)) {
            eval('namespace memberpress\\gifting\\controllers; class App {}');
        }

        $ctx  = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $keys = Meprmf_Native_Params::for_context($ctx);

        $this->assertContains('type', $keys);
    }
}
