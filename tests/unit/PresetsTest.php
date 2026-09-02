<?php
/**
 * Tests for Meprmf_Presets.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Presets;
use Meprmf_Settings;
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
        $GLOBALS['meprmf_test_user_meta']     = [];
        $GLOBALS['meprmf_test_user_caps']     = [];
        $GLOBALS['meprmf_test_current_user_id'] = 0;
        $GLOBALS['meprmf_test_submenus']      = [];
        $GLOBALS['submenu']                     = [];

        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-settings.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-presets.php';

        // Creating a shared view needs a capability. Grant a test-only one so these cases keep
        // testing what they were written for, and manage_options stays ungranted for the
        // cross-admin ownership checks below.
        $GLOBALS['meprmf_test_options']['meprmf_settings']      = [ 'shared_preset_capability' => 'meprmf_test_share' ];
        $GLOBALS['meprmf_test_user_caps']['meprmf_test_share'] = true;
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_options']      = [];
        $GLOBALS['meprmf_test_filters']      = [];
        $GLOBALS['meprmf_preset_id_counter'] = 0;
        $GLOBALS['meprmf_test_user_meta']    = [];
        $GLOBALS['meprmf_test_user_caps']    = [];
        $GLOBALS['meprmf_test_current_user_id'] = 0;
        $GLOBALS['meprmf_test_submenus']     = [];
        $GLOBALS['submenu']                  = [];
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

    public function test_accepts_native_toolbar_params_when_whitelisted()
    {
        $known = array_merge($this->known, [ 'status', 'membership' ]);
        $save  = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Native mix',
            [ 'status' => 'active', 'mpm_access' => 'active' ],
            $known
        );

        $this->assertTrue($save['success']);
        $this->assertSame(
            [ 'status' => 'active', 'mpm_access' => 'active' ],
            $save['preset']['params']
        );
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

    public function test_rejects_invalid_storage_id()
    {
        $result = Meprmf_Presets::save_preset(
            'not_a_real_screen',
            'Bad screen',
            [ 'mpm_access' => 'active' ],
            $this->known
        );

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_screen', $result['code']);
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

    public function test_save_preset_return_id_matches_get_presets_list_id()
    {
        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'ID sync',
            [ 'mpm_access' => 'active' ],
            $this->known
        );

        $this->assertTrue($save['success']);
        $this->assertSame(strtolower($save['preset']['id']), $save['preset']['id']);

        $list = Meprmf_Presets::get_presets_for_screen(self::SCREEN);
        $this->assertCount(1, $list);
        $this->assertSame($list[0]['id'], $save['preset']['id']);
    }

    public function test_upsert_normalizes_legacy_mixed_case_preset_id()
    {
        $GLOBALS['meprmf_test_options'][ Meprmf_Presets::OPTION_KEY ] = [
            self::SCREEN => [
                [
                    'id'      => 'p_AbCdEfGhIjKl',
                    'name'    => 'Legacy',
                    'params'  => [ 'mpm_access' => 'active' ],
                    'updated' => 1,
                ],
            ],
        ];

        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Legacy',
            [ 'mpm_access' => 'inactive' ],
            $this->known
        );

        $this->assertTrue($save['success']);
        $this->assertSame('p_abcdefghijkl', $save['preset']['id']);

        $list = Meprmf_Presets::get_presets_for_screen(self::SCREEN);
        $this->assertCount(1, $list);
        $this->assertSame($save['preset']['id'], $list[0]['id']);
    }

    public function test_operator_params_survive_a_preset_round_trip()
    {
        $known = array_merge($this->known, [ 'mpf_country__op' ]);

        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Not Germany',
            [ 'mpf_country' => 'DE', 'mpf_country__op' => 'is_not' ],
            $known
        );

        $this->assertTrue($save['success']);
        $this->assertSame('is_not', $save['preset']['params']['mpf_country__op']);
    }

    public function test_a_valueless_operator_preset_saves_without_a_value()
    {
        $known = array_merge($this->known, [ 'mpf_country__op' ]);

        // The empty value is dropped as always; the operator alone is the whole filter.
        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Missing country',
            [ 'mpf_country' => '', 'mpf_country__op' => 'is_empty' ],
            $known
        );

        $this->assertTrue($save['success']);
        $this->assertSame([ 'mpf_country__op' => 'is_empty' ], $save['preset']['params']);
    }

    public function test_operator_params_outside_the_whitelist_are_still_rejected()
    {
        $save = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Sneaky',
            [ 'mpm_access' => 'active', 'evil__op' => 'is_not' ],
            $this->known
        );

        $this->assertTrue($save['success']);
        $this->assertArrayNotHasKey('evil__op', $save['preset']['params']);
    }

    /* ---------------------------------------------------------- #8 ownership */

    /**
     * @param int $id User id.
     * @return void
     */
    private function as_user($id)
    {
        $GLOBALS['meprmf_test_current_user_id'] = (int) $id;
    }

    /**
     * @param string $name       View name.
     * @param string $visibility shared|private.
     * @return array<string, mixed>
     */
    private function save($name, $visibility = Meprmf_Presets::VISIBILITY_SHARED)
    {
        return Meprmf_Presets::save_preset(
            self::SCREEN,
            $name,
            [ 'mpm_access' => 'active' ],
            $this->known,
            $visibility
        );
    }

    public function test_a_saved_view_records_its_owner_and_visibility()
    {
        $this->as_user(7);
        $save = $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->assertTrue($save['success']);
        $this->assertSame(7, $save['preset']['owner']);
        $this->assertSame('private', $save['preset']['visibility']);
        $this->assertSame('Mine (private)', $save['preset']['label']);

        $shared = $this->save('Ours');
        $this->assertSame('shared', $shared['preset']['visibility']);
        $this->assertSame('Ours', $shared['preset']['label']);
    }

    public function test_a_private_view_is_not_returned_to_another_admin()
    {
        $this->as_user(7);
        $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);
        $this->save('Ours');

        $this->assertSame([ 'Mine', 'Ours' ], array_column(Meprmf_Presets::get_presets_for_screen(self::SCREEN), 'name'));

        $this->as_user(8);
        $seen = Meprmf_Presets::get_presets_for_screen(self::SCREEN);
        $this->assertSame([ 'Ours' ], array_column($seen, 'name'));
    }

    public function test_deleting_another_admins_shared_view_is_refused()
    {
        $this->as_user(7);
        $shared = $this->save('Ours');

        $this->as_user(8);
        $result = Meprmf_Presets::delete_preset(self::SCREEN, $shared['preset']['id']);

        $this->assertFalse($result['success']);
        $this->assertSame('not_owner', $result['code']);
        $this->assertCount(1, Meprmf_Presets::get_presets_for_screen(self::SCREEN));

        // The owner still can.
        $this->as_user(7);
        $this->assertTrue(Meprmf_Presets::delete_preset(self::SCREEN, $shared['preset']['id'])['success']);
    }

    public function test_a_higher_capability_may_delete_another_admins_view()
    {
        $this->as_user(7);
        $shared = $this->save('Ours');

        $this->as_user(8);
        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;

        $this->assertTrue(Meprmf_Presets::delete_preset(self::SCREEN, $shared['preset']['id'])['success']);
    }

    public function test_another_admins_private_view_reads_as_missing_not_refused()
    {
        $this->as_user(7);
        $private = $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->as_user(8);
        $result = Meprmf_Presets::delete_preset(self::SCREEN, $private['preset']['id']);

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['code']);
        $this->assertCount(1, $GLOBALS['meprmf_test_options']['meprmf_filter_presets'][ self::SCREEN ]);
    }

    public function test_saving_over_another_admins_shared_view_is_refused_by_name()
    {
        $this->as_user(7);
        $this->save('Team view');

        $this->as_user(8);
        $result = $this->save('Team view');

        $this->assertFalse($result['success']);
        $this->assertSame('name_taken', $result['code']);

        $rows = $GLOBALS['meprmf_test_options']['meprmf_filter_presets'][ self::SCREEN ];
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['owner']);
    }

    public function test_two_admins_may_each_have_a_private_view_of_the_same_name()
    {
        $this->as_user(7);
        $this->save('Working set', Meprmf_Presets::VISIBILITY_PRIVATE);
        $this->as_user(8);
        $second = $this->save('Working set', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->assertTrue($second['success']);
        $this->assertCount(2, $GLOBALS['meprmf_test_options']['meprmf_filter_presets'][ self::SCREEN ]);
        $this->assertSame([ 'Working set' ], array_column(Meprmf_Presets::get_presets_for_screen(self::SCREEN), 'name'));
    }

    public function test_the_owner_may_overwrite_and_reclassify_their_own_view()
    {
        $this->as_user(7);
        $first = $this->save('Mine');
        $again = $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->assertTrue($again['success']);
        $this->assertSame($first['preset']['id'], $again['preset']['id']);
        $this->assertSame('private', $again['preset']['visibility']);
        $this->assertCount(1, $GLOBALS['meprmf_test_options']['meprmf_filter_presets'][ self::SCREEN ]);
    }

    /* ------------------------------------------------------- #8 migration */

    public function test_a_pre_2_2_row_reads_as_shared_and_ownerless_without_being_rewritten()
    {
        $GLOBALS['meprmf_test_options']['meprmf_filter_presets'] = [
            self::SCREEN => [
                [
                    'id'      => 'abc123',
                    'name'    => 'Legacy',
                    'params'  => [ 'mpm_access' => 'active' ],
                    'updated' => 1234,
                ],
            ],
        ];

        $this->as_user(9);
        $list = Meprmf_Presets::get_presets_for_screen(self::SCREEN);

        $this->assertCount(1, $list);
        $this->assertSame(0, $list[0]['owner']);
        $this->assertSame('shared', $list[0]['visibility']);
        $this->assertSame('Legacy', $list[0]['label']);

        // Reading is not a write: the stored row is untouched, so the upgrade cannot half-apply.
        $stored = $GLOBALS['meprmf_test_options']['meprmf_filter_presets'][ self::SCREEN ][0];
        $this->assertArrayNotHasKey('owner', $stored);
        $this->assertArrayNotHasKey('visibility', $stored);

        // And nobody loses it: any admin may still use and delete it.
        $this->assertTrue(Meprmf_Presets::delete_preset(self::SCREEN, 'abc123')['success']);
    }

    public function test_a_private_row_with_no_owner_is_treated_as_shared()
    {
        $GLOBALS['meprmf_test_options']['meprmf_filter_presets'] = [
            self::SCREEN => [
                [
                    'id'         => 'orphan1',
                    'name'       => 'Orphan',
                    'params'     => [ 'mpm_access' => 'active' ],
                    'visibility' => 'private',
                ],
            ],
        ];

        $this->as_user(9);
        $list = Meprmf_Presets::get_presets_for_screen(self::SCREEN);

        // Private to nobody would be invisible to everybody, which is worse than shared.
        $this->assertCount(1, $list);
        $this->assertSame('shared', $list[0]['visibility']);
    }

    /* ----------------------------------------------------- #8 default view */

    public function test_default_view_round_trip_is_per_user_and_per_screen()
    {
        $this->as_user(7);
        $view = $this->save('Churn risk');

        $this->assertSame('', Meprmf_Presets::get_default_view_id(self::SCREEN));
        $this->assertTrue(Meprmf_Presets::set_default_view(self::SCREEN, $view['preset']['id'])['success']);
        $this->assertSame($view['preset']['id'], Meprmf_Presets::get_default_view_id(self::SCREEN));

        // Another admin's screen is unaffected.
        $this->as_user(8);
        $this->assertSame('', Meprmf_Presets::get_default_view_id(self::SCREEN));

        $this->as_user(7);
        $this->assertTrue(Meprmf_Presets::set_default_view(self::SCREEN, '')['success']);
        $this->assertSame('', Meprmf_Presets::get_default_view_id(self::SCREEN));
    }

    public function test_a_default_view_cannot_be_set_to_a_view_the_user_cannot_see()
    {
        $this->as_user(7);
        $private = $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->as_user(8);
        $result = Meprmf_Presets::set_default_view(self::SCREEN, $private['preset']['id']);

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['code']);
    }

    public function test_deleting_a_view_clears_it_as_everyones_default()
    {
        $this->as_user(7);
        $view = $this->save('Ours');
        Meprmf_Presets::set_default_view(self::SCREEN, $view['preset']['id']);

        $this->as_user(8);
        Meprmf_Presets::set_default_view(self::SCREEN, $view['preset']['id']);
        $this->assertSame($view['preset']['id'], Meprmf_Presets::get_default_view_id(self::SCREEN));

        $this->as_user(7);
        $this->assertTrue(Meprmf_Presets::delete_preset(self::SCREEN, $view['preset']['id'])['success']);

        $this->as_user(8);
        $this->assertSame('', Meprmf_Presets::get_default_view_id(self::SCREEN));
    }

    public function test_a_dangling_default_view_id_resolves_to_nothing()
    {
        $this->as_user(7);
        $GLOBALS['meprmf_test_user_meta'][7]['meprmf_default_view_memberpress_members'] = 'gone123';

        $this->assertSame('', Meprmf_Presets::get_default_view_id(self::SCREEN));
    }

    public function test_an_explicit_filter_in_the_url_beats_the_default_view()
    {
        $params = [ 'mpm_access', 'mpm_product', 'mpm_exp__op', 'status' ];

        $this->assertFalse(Meprmf_Presets::request_asks_for_filters([ 'page' => 'memberpress-members' ], $params));
        $this->assertFalse(Meprmf_Presets::request_asks_for_filters([ 'mpm_access' => '' ], $params));
        $this->assertFalse(Meprmf_Presets::request_asks_for_filters([ 'paged' => '3', 'orderby' => 'ID' ], $params));

        $this->assertTrue(Meprmf_Presets::request_asks_for_filters([ 'mpm_access' => 'active' ], $params));
        // An operator with no value still filters ("is empty").
        $this->assertTrue(Meprmf_Presets::request_asks_for_filters([ 'mpm_exp__op' => 'is_empty' ], $params));
        // A native MemberPress toolbar filter is just as explicit as one of ours.
        $this->assertTrue(Meprmf_Presets::request_asks_for_filters([ 'status' => 'complete' ], $params));
    }

    /* --------------------------------------- #17 who may create a shared view */

    public function test_creating_a_shared_view_without_the_capability_is_refused()
    {
        unset($GLOBALS['meprmf_test_options']['meprmf_settings']);
        $GLOBALS['meprmf_test_user_caps'] = [];
        $this->as_user(7);

        $result = $this->save('Ours');

        $this->assertFalse($result['success']);
        $this->assertSame('not_allowed', $result['code']);
        $this->assertSame([], Meprmf_Presets::get_presets_for_screen(self::SCREEN));
    }

    public function test_creating_a_shared_view_with_the_configured_capability_succeeds()
    {
        unset($GLOBALS['meprmf_test_options']['meprmf_settings']);
        $GLOBALS['meprmf_test_user_caps']['manage_options'] = true;
        $this->as_user(7);

        $this->assertTrue($this->save('Ours')['success']);
    }

    public function test_a_private_view_needs_no_shared_capability()
    {
        unset($GLOBALS['meprmf_test_options']['meprmf_settings']);
        $GLOBALS['meprmf_test_user_caps'] = [];
        $this->as_user(7);

        $save = $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->assertTrue($save['success']);
        $this->assertSame('private', $save['preset']['visibility']);
    }

    public function test_promoting_a_private_view_to_shared_without_the_capability_is_refused()
    {
        unset($GLOBALS['meprmf_test_options']['meprmf_settings']);
        $GLOBALS['meprmf_test_user_caps'] = [];
        $this->as_user(7);

        $private = $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);
        $this->assertTrue($private['success']);

        $result = $this->save('Mine', Meprmf_Presets::VISIBILITY_SHARED);

        $this->assertFalse($result['success']);
        $this->assertSame('not_allowed', $result['code']);

        $list = Meprmf_Presets::get_presets_for_screen(self::SCREEN);
        $this->assertCount(1, $list);
        $this->assertSame('private', $list[0]['visibility']);
    }

    public function test_the_settings_capability_is_what_gets_checked()
    {
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [ 'shared_preset_capability' => 'mepr_admin' ];
        $GLOBALS['meprmf_test_user_caps']                  = [ 'manage_options' => true ];
        $this->as_user(7);

        $this->assertSame('not_allowed', $this->save('Ours')['code']);

        $GLOBALS['meprmf_test_user_caps']['mepr_admin'] = true;
        $this->assertTrue($this->save('Ours')['success']);
    }

    public function test_overwriting_an_existing_shared_view_is_not_gated_on_the_create_capability()
    {
        // The owner keeps editing a view they already have, even after the capability changes.
        $this->as_user(7);
        $this->assertTrue($this->save('Ours')['success']);

        $GLOBALS['meprmf_test_user_caps'] = [];
        $again = Meprmf_Presets::save_preset(
            self::SCREEN,
            'Ours',
            [ 'mpm_product' => '5' ],
            $this->known
        );

        $this->assertTrue($again['success']);
        $this->assertSame([ 'mpm_product' => '5' ], $again['preset']['params']);
    }

    /* ----------------------------------------------------- #13 pinned views */

    public function test_pinned_views_round_trip_is_per_user_and_per_screen()
    {
        $this->as_user(7);
        $view = $this->save('Churn risk');
        $id   = $view['preset']['id'];

        $this->assertSame([], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
        $this->assertTrue(Meprmf_Presets::pin_view(self::SCREEN, $id)['success']);
        $this->assertSame([ $id ], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));

        // Pinning again is the state already asked for, and must not add a second entry.
        $this->assertTrue(Meprmf_Presets::pin_view(self::SCREEN, $id)['success']);
        $this->assertSame([ $id ], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));

        // Another admin's menu, and this admin's other screens, are unaffected.
        $this->as_user(8);
        $this->assertSame([], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
        $this->as_user(7);
        $this->assertSame([], Meprmf_Presets::get_pinned_view_ids('memberpress_trans'));

        $this->assertTrue(Meprmf_Presets::unpin_view(self::SCREEN, $id)['success']);
        $this->assertSame([], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
    }

    public function test_pinning_past_the_cap_is_rejected_until_the_filter_raises_it()
    {
        $this->as_user(7);

        $ids = [];
        foreach ([ 'One', 'Two', 'Three', 'Four', 'Five', 'Six' ] as $name) {
            $ids[] = $this->save($name)['preset']['id'];
        }

        foreach (array_slice($ids, 0, 5) as $id) {
            $this->assertTrue(Meprmf_Presets::pin_view(self::SCREEN, $id)['success']);
        }

        $over = Meprmf_Presets::pin_view(self::SCREEN, $ids[5]);
        $this->assertFalse($over['success']);
        $this->assertSame('pin_limit_reached', $over['code']);
        $this->assertCount(5, Meprmf_Presets::get_pinned_view_ids(self::SCREEN));

        add_filter(
            'meprmf_max_pinned_views_per_screen',
            static function () {
                return 6;
            }
        );

        $this->assertTrue(Meprmf_Presets::pin_view(self::SCREEN, $ids[5])['success']);
        $this->assertSame($ids, Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
    }

    public function test_a_view_the_user_cannot_see_cannot_be_pinned()
    {
        $this->as_user(7);
        $private = $this->save('Mine', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->as_user(8);
        $result = Meprmf_Presets::pin_view(self::SCREEN, $private['preset']['id']);

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['code']);
        $this->assertSame([], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
    }

    public function test_deleting_a_view_unpins_it_for_every_admin()
    {
        $this->as_user(7);
        $view = $this->save('Ours');
        $kept = $this->save('Keep me');
        Meprmf_Presets::pin_view(self::SCREEN, $view['preset']['id']);

        $this->as_user(8);
        Meprmf_Presets::pin_view(self::SCREEN, $view['preset']['id']);
        Meprmf_Presets::pin_view(self::SCREEN, $kept['preset']['id']);

        $this->as_user(7);
        $this->assertTrue(Meprmf_Presets::delete_preset(self::SCREEN, $view['preset']['id'])['success']);
        $this->assertSame([], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));

        // And only that view: the other admin's remaining pin survives.
        $this->as_user(8);
        $this->assertSame([ $kept['preset']['id'] ], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
    }

    public function test_a_dangling_pinned_view_id_resolves_to_nothing()
    {
        $this->as_user(7);
        $kept = $this->save('Still here');
        Meprmf_Presets::pin_view(self::SCREEN, $kept['preset']['id']);
        $GLOBALS['meprmf_test_user_meta'][7]['meprmf_pinned_view_memberpress_members'][] = 'gone123';

        $this->assertSame([ $kept['preset']['id'] ], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
    }

    public function test_invisible_ghost_pins_do_not_block_new_pins()
    {
        $this->as_user(7);
        $shared = $this->save('Team view');
        $shared_id = $shared['preset']['id'];

        $this->as_user(8);
        $ids = [ $shared_id ];
        foreach ([ 'Two', 'Three', 'Four', 'Five' ] as $name) {
            $ids[] = $this->save($name)['preset']['id'];
        }
        foreach ($ids as $id) {
            $this->assertTrue(Meprmf_Presets::pin_view(self::SCREEN, $id)['success']);
        }
        $this->assertCount(5, Meprmf_Presets::get_pinned_view_ids(self::SCREEN));

        $this->as_user(7);
        $this->save('Team view', Meprmf_Presets::VISIBILITY_PRIVATE);

        $this->as_user(8);
        $this->assertCount(4, Meprmf_Presets::get_pinned_view_ids(self::SCREEN));

        $sixth = $this->save('Sixth')['preset']['id'];
        $result = Meprmf_Presets::pin_view(self::SCREEN, $sixth);
        $this->assertTrue($result['success']);
        $this->assertCount(5, Meprmf_Presets::get_pinned_view_ids(self::SCREEN));
    }

    public function test_a_pinned_view_url_is_the_screen_page_plus_the_views_filters()
    {
        $this->as_user(7);
        $view = $this->save('Active only');

        $url = Meprmf_Presets::get_pinned_view_url(self::SCREEN, $view['preset']['id']);

        $this->assertSame('admin.php?page=memberpress-members&mpm_access=active', $url);
        // Decision #1: filter params only, so nothing has to interpret a view id server-side.
        $this->assertStringNotContainsString(Meprmf_Presets::SUPPRESS_PARAM, $url);
    }

    public function test_a_pinned_view_url_is_empty_for_an_unknown_view_or_screen()
    {
        $this->as_user(7);
        $view = $this->save('Active only');

        $this->assertSame('', Meprmf_Presets::get_pinned_view_url(self::SCREEN, 'gone123'));
        $this->assertSame('', Meprmf_Presets::get_pinned_view_url('not_a_screen', $view['preset']['id']));
    }

    /**
     * MemberPress submenu parent lookup and filter capability for add_pinned_view_menu_items().
     *
     * @return void
     */
    private function setup_pinned_view_menu_fixtures()
    {
        if (! class_exists('MeprUtils', false)) {
            eval(
                'class MeprUtils {
                    public static function get_mepr_admin_capability() { return "mepr_test_admin"; }
                }'
            );
        }

        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-capabilities.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-plugin.php';

        $GLOBALS['meprmf_test_user_caps']['mepr_test_admin'] = true;
        $GLOBALS['submenu']                                   = [
            'memberpress' => [
                [
                    'Members',
                    'mepr_test_admin',
                    'memberpress-members',
                ],
            ],
        ];
    }

    public function test_a_pinned_view_registers_a_submenu_entry_when_the_screen_is_enabled()
    {
        $this->setup_pinned_view_menu_fixtures();
        $this->as_user(7);
        $view = $this->save('Active only');
        Meprmf_Presets::pin_view(self::SCREEN, $view['preset']['id']);

        Meprmf_Presets::add_pinned_view_menu_items();

        $this->assertCount(1, $GLOBALS['meprmf_test_submenus']);
        $entry = $GLOBALS['meprmf_test_submenus'][0];
        $this->assertSame('memberpress', $entry['parent']);
        $this->assertSame('mepr_test_admin', $entry['cap']);
        $this->assertSame(
            htmlspecialchars('admin.php?page=memberpress-members&mpm_access=active', ENT_QUOTES),
            $entry['slug']
        );
    }

    public function test_a_pinned_view_does_not_register_a_submenu_entry_when_the_screen_is_disabled()
    {
        $this->setup_pinned_view_menu_fixtures();
        $GLOBALS['meprmf_test_options']['meprmf_settings'] = [
            'shared_preset_capability' => 'meprmf_test_share',
            'enabled_screens'          => [ 'memberpress-subscriptions', 'memberpress-lifetimes', 'memberpress-trans' ],
        ];

        $this->as_user(7);
        $view = $this->save('Active only');
        Meprmf_Presets::pin_view(self::SCREEN, $view['preset']['id']);

        $this->assertSame([ $view['preset']['id'] ], Meprmf_Presets::get_pinned_view_ids(self::SCREEN));

        Meprmf_Presets::add_pinned_view_menu_items();

        $this->assertSame([], $GLOBALS['meprmf_test_submenus']);
    }
}
