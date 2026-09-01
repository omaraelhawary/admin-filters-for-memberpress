<?php
/**
 * Tests for Meprmf_Settings.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Plugin;
use Meprmf_Screen;
use Meprmf_Screen_Context;
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
        $GLOBALS['meprmf_test_options']         = [];
        $GLOBALS['meprmf_test_user_caps']       = [];

        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-settings.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-plugin.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_user_meta']       = [];
        $GLOBALS['meprmf_test_current_user_id'] = 0;
        $GLOBALS['meprmf_test_filters']         = [];
        $GLOBALS['meprmf_test_options']         = [];
        $GLOBALS['meprmf_test_user_caps']       = [];
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

    /* ------------------------------------------------- site-wide settings option */

    public function test_option_defaults_when_nothing_is_saved()
    {
        $this->assertSame(
            [ 'memberpress-members', 'memberpress-subscriptions', 'memberpress-lifetimes', 'memberpress-trans' ],
            Meprmf_Settings::get_setting('enabled_screens')
        );
        $this->assertTrue(Meprmf_Settings::is_floating_panel_enabled());
        $this->assertTrue(Meprmf_Settings::get_setting('date_range_default'));
        $this->assertSame('manage_options', Meprmf_Settings::shared_preset_capability());
    }

    public function test_a_partial_saved_option_keeps_the_defaults_for_the_other_keys()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'floating_panel_enabled' => false ];

        $this->assertFalse(Meprmf_Settings::is_floating_panel_enabled());
        $this->assertTrue(Meprmf_Settings::get_setting('date_range_default'));
        $this->assertSame('manage_options', Meprmf_Settings::shared_preset_capability());
    }

    public function test_a_corrupt_option_value_falls_back_to_the_defaults()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = 'not an array';

        $this->assertTrue(Meprmf_Settings::is_floating_panel_enabled());
        $this->assertSame('manage_options', Meprmf_Settings::shared_preset_capability());
    }

    public function test_turning_a_screen_off_stops_only_that_screen()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [
            'enabled_screens' => [ 'memberpress-members', 'memberpress-subscriptions', 'memberpress-lifetimes' ],
        ];

        $support = [];
        foreach (Meprmf_Screen::supported_page_contexts() as $ctx) {
            $support[ $ctx->get_page() ] = $ctx->supports_meta_filters_list();
        }

        $this->assertFalse($support['memberpress-trans']);
        $this->assertTrue($support['memberpress-members']);
        $this->assertTrue($support['memberpress-subscriptions']);
        $this->assertTrue($support['memberpress-lifetimes']);
    }

    public function test_core_filters_follow_the_same_screen_toggle()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'enabled_screens' => [] ];

        foreach (Meprmf_Screen::supported_page_contexts() as $ctx) {
            $this->assertFalse($ctx->supports_core_filters(), $ctx->get_page());
        }
    }

    public function test_a_code_filter_overrides_a_screen_turned_off_in_settings()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'enabled_screens' => [] ];
        add_filter(
            'meprmf_screen_filters_enabled',
            static function ($enabled, $page) {
                return 'memberpress-trans' === $page ? true : $enabled;
            }
        );

        $ctx = new Meprmf_Screen_Context('memberpress-trans', 'tr.user_id');
        $this->assertTrue($ctx->supports_meta_filters_list());

        $members = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $this->assertFalse($members->supports_meta_filters_list());
    }

    public function test_an_unknown_screen_stays_unsupported_whatever_the_option_says()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'enabled_screens' => [ 'memberpress-options' ] ];

        $ctx = new Meprmf_Screen_Context('memberpress-options', 'u.ID');
        $this->assertFalse($ctx->supports_meta_filters_list());
    }

    public function test_floating_panel_option_drives_the_default()
    {
        $ctx = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $this->assertTrue(Meprmf_Plugin::use_floating_filter_panel($ctx));

        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'floating_panel_enabled' => false ];
        $this->assertFalse(Meprmf_Plugin::use_floating_filter_panel($ctx));
    }

    public function test_a_code_filter_overrides_the_floating_panel_option()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'floating_panel_enabled' => false ];
        add_filter(
            'meprmf_use_floating_meta_filters_panel',
            static function () {
                return true;
            }
        );

        $ctx = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $this->assertTrue(Meprmf_Plugin::use_floating_filter_panel($ctx));
    }

    public function test_date_range_site_default_applies_when_the_admin_has_no_preference()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'date_range_default' => false ];

        $this->assertFalse(Meprmf_Settings::is_date_custom_fields_use_range_enabled(42));

        // A per-admin preference still beats the site default.
        Meprmf_Settings::set_date_custom_fields_use_range_enabled(true, 42);
        $this->assertTrue(Meprmf_Settings::is_date_custom_fields_use_range_enabled(42));
    }

    public function test_a_code_filter_overrides_the_date_range_site_default()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'date_range_default' => false ];

        $this->assertTrue(
            Meprmf_Settings::apply_date_range_option(true, (object) [ 'field_key' => 'birthday' ])
        );
    }

    public function test_shared_preset_capability_gates_the_current_user()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'shared_preset_capability' => 'mepr_admin' ];

        $this->assertFalse(Meprmf_Settings::current_user_can_create_shared_preset());

        $GLOBALS['meprmf_test_user_caps']['mepr_admin'] = true;
        $this->assertTrue(Meprmf_Settings::current_user_can_create_shared_preset());
    }

    public function test_a_code_filter_overrides_the_shared_preset_capability()
    {
        add_filter(
            'meprmf_can_manage_others_views',
            static function () {
                return true;
            }
        );

        $this->assertTrue(Meprmf_Settings::current_user_can_create_shared_preset());
    }

    /**
     * Declared last on purpose: a constant cannot be undefined, so any case after this one in
     * this class would run with the override still in place.
     */
    public function test_the_constant_overrides_the_date_range_site_default()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'date_range_default' => false ];
        Meprmf_Settings::set_date_custom_fields_use_range_enabled(false, 42);

        if (! defined('MEPRMF_DATE_CUSTOM_FIELDS_USE_RANGE')) {
            define('MEPRMF_DATE_CUSTOM_FIELDS_USE_RANGE', true);
        }

        $this->assertTrue(Meprmf_Settings::is_date_custom_fields_use_range_enabled(42));
    }
}
