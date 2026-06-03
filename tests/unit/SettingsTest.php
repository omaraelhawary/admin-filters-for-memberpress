<?php
/**
 * Tests for Meprmf_Settings.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Settings
 */
class SettingsTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['meprmf_test_user_meta']       = [];
        $GLOBALS['meprmf_test_current_user_id'] = 42;
        $GLOBALS['meprmf_test_filters']         = [];

        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-settings.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_user_meta']       = [];
        $GLOBALS['meprmf_test_current_user_id'] = 0;
        $GLOBALS['meprmf_test_filters']         = [];
        parent::tearDown();
    }

    public function test_date_range_defaults_on_for_new_user()
    {
        $this->assertTrue(Meprmf_Settings::is_date_custom_fields_use_range_enabled(42));
    }

    public function test_set_and_get_date_range_preference_per_user()
    {
        Meprmf_Settings::set_date_custom_fields_use_range_enabled(false, 42);
        $this->assertFalse(Meprmf_Settings::is_date_custom_fields_use_range_enabled(42));

        Meprmf_Settings::set_date_custom_fields_use_range_enabled(true, 99);
        $this->assertTrue(Meprmf_Settings::is_date_custom_fields_use_range_enabled(99));
        $this->assertFalse(Meprmf_Settings::is_date_custom_fields_use_range_enabled(42));
    }

    public function test_apply_date_range_option_respects_stored_preference()
    {
        Meprmf_Settings::set_date_custom_fields_use_range_enabled(false, 42);
        $GLOBALS['meprmf_test_current_user_id'] = 42;

        $this->assertFalse(
            Meprmf_Settings::apply_date_range_option(false, (object) [ 'field_key' => 'birthday' ])
        );
    }

    public function test_apply_date_range_option_short_circuits_when_already_true()
    {
        Meprmf_Settings::set_date_custom_fields_use_range_enabled(false, 42);

        $this->assertTrue(
            Meprmf_Settings::apply_date_range_option(true, (object) [ 'field_key' => 'birthday' ])
        );
    }
}
