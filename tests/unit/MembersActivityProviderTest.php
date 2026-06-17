<?php
/**
 * Tests for Meprmf_Members_Activity_Provider.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Members_Activity_Provider;
use Meprmf_Screen;
use Meprmf_Screen_Context;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Members_Activity_Provider
 */
class MembersActivityProviderTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-activity-provider.php';
    }

    public function test_members_activity_fields_present()
    {
        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
        $fields = Meprmf_Util::normalize_core_filter_fields(
            Meprmf_Members_Activity_Provider::get_activity_fields_for_context($ctx)
        );
        $params = array_column($fields, 'param');

        $expected = [
            'mpm_registered_from',
            'mpm_registered_to',
            'mpm_last_login_from',
            'mpm_last_login_to',
            'mpm_spent_min',
            'mpm_spent_max',
            'mpm_trial',
        ];

        foreach ($expected as $param) {
            $this->assertContains($param, $params, "Missing {$param}");
        }
    }

    public function test_transactions_context_returns_empty()
    {
        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $fields = Meprmf_Members_Activity_Provider::get_activity_fields_for_context($ctx);

        $this->assertSame([], $fields);
    }
}
