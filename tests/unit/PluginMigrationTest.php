<?php
/**
 * Ensures list-table filter hook is a no-op when not on the Members screen (migration-safe).
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Plugin;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Plugin
 */
class PluginMigrationTest extends TestCase
{

    protected function tearDown(): void
    {
        unset($_GET['page']);
        parent::tearDown();
    }

    public function test_filter_list_table_args_noops_when_page_missing()
    {
        require_once dirname(__DIR__, 2) . '/includes/meprmf-load.php';

        unset($_GET['page']);
        $args = [ '1=1' ];
        $out  = Meprmf_Plugin::filter_list_table_args($args);
        $this->assertSame($args, $out);
    }

    public function test_filter_list_table_args_noops_for_unknown_page()
    {
        require_once dirname(__DIR__, 2) . '/includes/meprmf-load.php';

        $_GET['page'] = 'not-memberpress-members';
        $args         = [ '1=1' ];
        $out          = Meprmf_Plugin::filter_list_table_args($args);
        $this->assertSame($args, $out);
    }
}
