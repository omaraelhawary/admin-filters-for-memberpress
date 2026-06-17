<?php
/**
 * Tests for Meprmf_Addon_Provider.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Addon_Provider;
use Meprmf_Screen;
use Meprmf_Screen_Context;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Addon_Provider
 */
class AddonProviderTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-addon-provider.php';

        Meprmf_Addon_Provider::clear_cache();
        $GLOBALS['meprmf_test_post_types'] = [];
        $GLOBALS['meprmf_test_posts']      = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_post_types'] = [];
        $GLOBALS['meprmf_test_posts']      = [];
        Meprmf_Addon_Provider::clear_cache();
        parent::tearDown();
    }

    public function test_members_returns_empty_without_addons()
    {
        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
        $fields = Meprmf_Addon_Provider::get_passthrough_fields_for_context($ctx);

        $this->assertSame([], $fields);
    }

    public function test_members_course_field_uses_native_param()
    {
        $GLOBALS['meprmf_test_post_types'] = [ 'mpcs-course' ];
        $GLOBALS['meprmf_test_posts']      = [
            (object) [ 'ID' => 10, 'post_title' => 'Intro Course' ],
        ];

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
        $fields = Meprmf_Util::normalize_passthrough_filter_fields(
            Meprmf_Addon_Provider::get_passthrough_fields_for_context($ctx)
        );

        $this->assertCount(1, $fields);
        $this->assertSame('course', $fields[0]['param']);
        $this->assertSame('native', $fields[0]['source']);
        $this->assertArrayHasKey(10, $fields[0]['options']);
    }

    public function test_transactions_gifting_type_when_addon_class_exists()
    {
        if (! class_exists('memberpress\\gifting\\controllers\\App', false)) {
            eval('namespace memberpress\\gifting\\controllers; class App {}');
        }

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $fields = Meprmf_Addon_Provider::get_passthrough_fields_for_context($ctx);
        $params = array_column($fields, 'param');

        $this->assertContains('type', $params);
    }
}
