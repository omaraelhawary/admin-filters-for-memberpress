<?php
/**
 * Tests for Meprmf_Presets.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Presets;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Presets
 */
class PresetsTest extends TestCase
{

    private const SCREEN = 'memberpress_members';

    /** @var array<int, string> */
    private $known = [ 'mpm_product', 'mpm_access', 'mpf_country' ];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['meprmf_test_options']       = [];
        $GLOBALS['meprmf_test_filters']       = [];
        $GLOBALS['meprmf_preset_id_counter']  = 0;

        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-presets.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_options']      = [];
        $GLOBALS['meprmf_test_filters']      = [];
        $GLOBALS['meprmf_preset_id_counter'] = 0;
        parent::tearDown();
    }

    public function test_save_read_delete_round_trip()
    {
        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Active Gold',
            [ 'mpm_product' => '5', 'mpm_access' => 'active' ],
            $this->known
        );

        $this->assertTrue($save['success']);
        $this->assertSame('Active Gold', $save['preset']['name']);
        $this->assertSame(
            [ 'mpm_product' => '5', 'mpm_access' => 'active' ],
            $save['preset']['params']
        );

        $list = Meprmf_Presets::get_presets_for_screen(self::SCREEN);
        $this->assertCount(1, $list);
        $this->assertSame('Active Gold', $list[0]['name']);

        $delete = Meprmf_Presets::delete_preset(self::SCREEN, $list[0]['id']);
        $this->assertTrue($delete['success']);
        $this->assertSame([], Meprmf_Presets::get_presets_for_screen(self::SCREEN));
    }

    public function test_rejects_unknown_param_keys()
    {
        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Bad params',
            [ 'status' => 'active', 'mpm_access' => 'active' ],
            $this->known
        );

        $this->assertTrue($save['success']);
        $this->assertSame([ 'mpm_access' => 'active' ], $save['preset']['params']);
    }

    public function test_rejects_empty_name()
    {
        $result = Meprmf_Presets::save_preset(
            self::SCREEN,
            '   ',
            [ 'mpm_access' => 'active' ],
            $this->known
        );

        $this->assertFalse($result['success']);
        $this->assertSame('empty_name', $result['code']);
    }

    public function test_rejects_empty_params()
    {
        $result = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Nothing here',
            [],
            $this->known
        );

        $this->assertFalse($result['success']);
        $this->assertSame('empty_params', $result['code']);
    }

    public function test_upserts_by_name()
    {
        Meprmf_Presets::save_preset(
            self::SCREEN,
            'Weekly report',
            [ 'mpm_access' => 'active' ],
            $this->known
        );

        $second = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Weekly report',
            [ 'mpm_product' => '9' ],
            $this->known
        );

        $this->assertTrue($second['success']);
        $this->assertSame([ 'mpm_product' => '9' ], $second['preset']['params']);

        $list = Meprmf_Presets::get_presets_for_screen(self::SCREEN);
        $this->assertCount(1, $list);
    }

    public function test_enforces_max_per_screen()
    {
        $GLOBALS['meprmf_test_filters']['meprmf_max_filter_presets_per_screen'] = [
            static function () {
                return 2;
            },
        ];

        $this->assertTrue(
            Meprmf_Presets::save_preset(self::SCREEN, 'One', [ 'mpm_access' => 'active' ], $this->known)['success']
        );
        $this->assertTrue(
            Meprmf_Presets::save_preset(self::SCREEN, 'Two', [ 'mpm_product' => '1' ], $this->known)['success']
        );

        $third = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Three',
            [ 'mpf_country' => 'US' ],
            $this->known
        );

        $this->assertFalse($third['success']);
        $this->assertSame('limit_reached', $third['code']);
    }

    public function test_sanitize_preset_name_truncates()
    {
        $long = str_repeat('A', 120);
        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            $long,
            [ 'mpm_access' => 'active' ],
            $this->known
        );

        $this->assertTrue($save['success']);
        $this->assertSame(80, strlen($save['preset']['name']));
    }

    public function test_sanitize_preset_params_drops_empty_values()
    {
        $clean = Meprmf_Presets::sanitize_preset_params(
            [
                'mpm_access'  => 'active',
                'mpm_product' => '',
                'evil'        => 'x',
            ],
            $this->known
        );

        $this->assertSame([ 'mpm_access' => 'active' ], $clean);
    }
}
