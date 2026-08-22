<?php
/**
 * Uninstall cleanup tests.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class UninstallTest extends TestCase
{

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_options']    = [];
        $GLOBALS['meprmf_test_user_meta']  = [];
        parent::tearDown();
    }

    public function test_uninstall_removes_options_and_user_meta()
    {
        $GLOBALS['meprmf_test_options']['meprmf_additional_filters'] = [ 'legacy' => true ];
        $GLOBALS['meprmf_test_options']['meprmf_filter_presets']       = [ 'memberpress_members' => [] ];
        $GLOBALS['meprmf_test_user_meta'][1]['meprmf_date_custom_fields_use_range'] = '1';
        $GLOBALS['meprmf_test_user_meta'][2]['meprmf_date_custom_fields_use_range'] = '0';
        $GLOBALS['meprmf_test_user_meta'][1]['meprmf_default_view_memberpress_members'] = 'p_abc';
        $GLOBALS['meprmf_test_user_meta'][2]['meprmf_default_view_memberpress_trans']   = 'p_def';
        $GLOBALS['meprmf_test_user_meta'][2]['meprmf_default_view_memberpress_subscriptions'] = 'p_ghi';
        $GLOBALS['meprmf_test_user_meta'][1]['meprmf_default_view_memberpress_lifetimes']    = 'p_jkl';
        $GLOBALS['meprmf_test_user_meta'][1]['unrelated_plugin_meta'] = 'keep me';

        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }

        require dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertArrayNotHasKey('meprmf_additional_filters', $GLOBALS['meprmf_test_options']);
        $this->assertArrayNotHasKey('meprmf_filter_presets', $GLOBALS['meprmf_test_options']);
        $this->assertArrayNotHasKey('meprmf_date_custom_fields_use_range', $GLOBALS['meprmf_test_user_meta'][1]);
        $this->assertArrayNotHasKey('meprmf_date_custom_fields_use_range', $GLOBALS['meprmf_test_user_meta'][2]);

        // Every screen's default view goes too, on every user, or an uninstall leaves rows behind.
        foreach ([ 'members', 'trans', 'subscriptions', 'lifetimes' ] as $screen) {
            $this->assertArrayNotHasKey('meprmf_default_view_memberpress_' . $screen, $GLOBALS['meprmf_test_user_meta'][1]);
            $this->assertArrayNotHasKey('meprmf_default_view_memberpress_' . $screen, $GLOBALS['meprmf_test_user_meta'][2]);
        }

        $this->assertSame('keep me', $GLOBALS['meprmf_test_user_meta'][1]['unrelated_plugin_meta']);
    }
}
