<?php
/**
 * Tests for the Settings screen sanitize callback.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Settings;
use Meprmf_Settings_Page;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Settings_Page
 */
class SettingsPageTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['meprmf_test_options'] = [];

        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-settings.php';
        require_once dirname(__DIR__, 2) . '/includes/admin/class-meprmf-settings-page.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_options'] = [];
        parent::tearDown();
    }

    public function test_a_full_save_round_trips_every_key()
    {
        $clean = Meprmf_Settings_Page::sanitize_settings(
            [
                'enabled_screens'          => [ 'memberpress-members', 'memberpress-trans' ],
                'floating_panel_enabled'   => '1',
                'date_range_default'       => '1',
                'shared_preset_capability' => 'manage_options',
            ]
        );

        $this->assertSame(
            [
                'enabled_screens'          => [ 'memberpress-members', 'memberpress-trans' ],
                'floating_panel_enabled'   => true,
                'date_range_default'       => true,
                'shared_preset_capability' => 'manage_options',
            ],
            $clean
        );
    }

    public function test_unknown_screen_slugs_are_dropped_and_duplicates_collapse()
    {
        $clean = Meprmf_Settings_Page::sanitize_settings(
            [
                'enabled_screens' => [ 'memberpress-members', 'memberpress-options', 'memberpress-members', '<script>' ],
            ]
        );

        $this->assertSame([ 'memberpress-members' ], $clean['enabled_screens']);
    }

    public function test_absent_checkboxes_save_as_off_rather_than_reverting_to_the_default()
    {
        $clean = Meprmf_Settings_Page::sanitize_settings([ 'shared_preset_capability' => 'manage_options' ]);

        $this->assertSame([], $clean['enabled_screens']);
        $this->assertFalse($clean['floating_panel_enabled']);
        $this->assertFalse($clean['date_range_default']);

        // And the accessors read the saved value back, not the default.
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = $clean;
        $this->assertFalse(Meprmf_Settings::is_floating_panel_enabled());
        $this->assertFalse(Meprmf_Settings::is_screen_enabled('memberpress-members'));
    }

    public function test_checkbox_values_coerce_to_bool()
    {
        $clean = Meprmf_Settings_Page::sanitize_settings(
            [
                'floating_panel_enabled' => 'on',
                'date_range_default'     => '0',
            ]
        );

        $this->assertTrue($clean['floating_panel_enabled']);
        $this->assertFalse($clean['date_range_default']);
    }

    public function test_an_unknown_capability_falls_back_to_manage_options()
    {
        foreach ([ 'edit_posts', '', 'read' ] as $capability) {
            $clean = Meprmf_Settings_Page::sanitize_settings([ 'shared_preset_capability' => $capability ]);
            $this->assertSame('manage_options', $clean['shared_preset_capability'], $capability);
        }
    }

    public function test_a_non_array_post_value_saves_the_off_state_for_every_key()
    {
        $clean = Meprmf_Settings_Page::sanitize_settings('garbage');

        $this->assertSame(
            [
                'enabled_screens'          => [],
                'floating_panel_enabled'   => false,
                'date_range_default'       => false,
                'shared_preset_capability' => 'manage_options',
            ],
            $clean
        );
    }

    public function test_the_capability_choices_carry_the_memberpress_one_when_it_is_reported()
    {
        $this->assertSame(
            [ 'manage_options', 'mepr_test_admin' ],
            array_keys(Meprmf_Settings_Page::capability_choices())
        );
    }

    /**
     * The class_exists half of the guard cannot be exercised once any test has
     * defined MeprUtils, and one always has by the time this file runs. An
     * empty capability is the reachable case and the production code already
     * drops the choice for it.
     */
    public function test_the_capability_choices_drop_the_memberpress_one_when_none_is_reported()
    {
        $GLOBALS['meprmf_test_mepr_admin_cap'] = '';

        try {
            $this->assertSame([ 'manage_options' ], array_keys(Meprmf_Settings_Page::capability_choices()));
        } finally {
            unset($GLOBALS['meprmf_test_mepr_admin_cap']);
        }
    }
}
