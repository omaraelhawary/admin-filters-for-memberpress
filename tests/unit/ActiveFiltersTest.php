<?php
/**
 * Tests active filter chip building.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Active_Filters;
use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Active_Filters
 */
class ActiveFiltersTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/ui/class-meprmf-active-filters.php';
        require_once dirname(__DIR__, 2) . '/includes/ui/class-meprmf-toolbar-renderer.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-native-params.php';
    }

    public function test_render_emits_no_block_element_inside_the_memberpress_paragraph()
    {
        $_GET = [ 'mpf_country' => 'DE' ];
        ob_start();
        Meprmf_Active_Filters::render($this->fields(), new Meprmf_Screen_Context('memberpress-members', 'u.ID'));
        $html = (string) ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString('<span class="meprmf-active-filters">', $html);
        // MemberPress fires the hook inside a `<p>`, and a block element there is ejected by the parser.
        $this->assertDoesNotMatchRegularExpression('/<(div|p|ul|ol|li|table|section)[\s>]/', $html);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fields()
    {
        return [
            [
                'param'    => 'mpf_country',
                'meta_key' => 'mepr-address-country',
                'label'    => 'Country',
                'type'     => 'country',
                'options'  => [ 'DE' => 'Germany', 'AT' => 'Austria' ],
            ],
            [
                'param'    => 'mpf_city',
                'meta_key' => 'mepr-address-city',
                'label'    => 'City',
                'type'     => 'text',
            ],
        ];
    }

    public function test_no_active_params_produces_no_chips()
    {
        $this->assertSame([], Meprmf_Active_Filters::build_chips($this->fields(), [], []));
    }

    public function test_a_value_produces_one_chip_with_the_option_label()
    {
        $chips = Meprmf_Active_Filters::build_chips($this->fields(), [], [ 'mpf_country' => 'DE' ]);

        $this->assertCount(1, $chips);
        $this->assertSame('Country is', $chips[0]['label']);
        $this->assertSame('Germany', $chips[0]['value']);
        $this->assertContains('mpf_country', $chips[0]['params']);
    }

    public function test_removing_a_chip_also_drops_its_operator_param()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_country' => 'DE', 'mpf_country__op' => 'is_not' ]
        );

        $this->assertCount(1, $chips);
        $this->assertContains('mpf_country', $chips[0]['params']);
        $this->assertContains('mpf_country__op', $chips[0]['params']);
    }

    public function test_negating_operator_appears_in_the_chip_label()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_country' => 'DE', 'mpf_country__op' => 'is_not' ]
        );

        $this->assertSame('Country is not', $chips[0]['label']);
        $this->assertSame('Germany', $chips[0]['value']);
    }

    public function test_valueless_operator_produces_a_chip_with_no_value()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_city__op' => 'is_empty' ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('City is empty', $chips[0]['label']);
        $this->assertSame('', $chips[0]['value']);
    }

    public function test_is_one_of_lists_every_value_in_one_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_country' => 'DE,AT', 'mpf_country__op' => 'is_one_of' ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('Country is one of', $chips[0]['label']);
        $this->assertSame('Germany, Austria', $chips[0]['value']);
    }

    public function test_unknown_operator_is_ignored_in_the_label()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_country' => 'DE', 'mpf_country__op' => 'nonsense' ]
        );

        // An unrecognised token falls back to the inferred operator instead of leaking through.
        $this->assertSame('Country is', $chips[0]['label']);
        $this->assertStringNotContainsString('nonsense', $chips[0]['text']);
    }

    public function test_a_date_range_renders_as_one_chip_not_two()
    {
        $fields = [
            [
                'param'           => 'mpf_joined_from',
                'meta_key'        => 'joined',
                'label'           => 'Joined from',
                'type'            => 'date',
                'date_range_of'   => 'mpf_joined',
                'date_range_part' => 'from',
            ],
            [
                'param'           => 'mpf_joined_to',
                'meta_key'        => 'joined',
                'label'           => 'Joined to',
                'type'            => 'date',
                'date_range_of'   => 'mpf_joined',
                'date_range_part' => 'to',
            ],
        ];

        $chips = Meprmf_Active_Filters::build_chips(
            $fields,
            [],
            [ 'mpf_joined_from' => '2026-01-01', 'mpf_joined_to' => '2026-06-30' ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('Joined', $chips[0]['label']);
        // Site date format (the test bootstrap's `F j, Y`), not the raw ISO bounds.
        $this->assertSame('January 1, 2026 – June 30, 2026', $chips[0]['value']);
        $this->assertContains('mpf_joined_from', $chips[0]['params']);
        $this->assertContains('mpf_joined_to', $chips[0]['params']);
    }

    public function test_a_half_open_date_range_still_renders_one_chip()
    {
        $fields = [
            [
                'param'           => 'mpf_joined_from',
                'label'           => 'Joined from',
                'type'            => 'date',
                'date_range_of'   => 'mpf_joined',
                'date_range_part' => 'from',
            ],
            [
                'param'           => 'mpf_joined_to',
                'label'           => 'Joined to',
                'type'            => 'date',
                'date_range_of'   => 'mpf_joined',
                'date_range_part' => 'to',
            ],
        ];

        $chips = Meprmf_Active_Filters::build_chips($fields, [], [ 'mpf_joined_from' => '2026-01-01' ]);

        $this->assertCount(1, $chips);
        // One bound and no operator is an "is after" filter, which is how the row reads it too.
        $this->assertSame('Joined is after', $chips[0]['label']);
        $this->assertSame('January 1, 2026', $chips[0]['value']);
        $this->assertSame('Joined is after January 1, 2026', $chips[0]['text']);
    }

    /**
     * A single date field, which owns its value param plus the bounds and relative params.
     *
     * @return array<int, array<string, mixed>>
     */
    private function date_field()
    {
        return [
            [
                'param'    => 'mpf_joined',
                'meta_key' => 'joined',
                'label'    => 'Joined',
                'type'     => 'date',
            ],
        ];
    }

    public function test_a_relative_window_produces_a_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->date_field(),
            [],
            [ 'mpf_joined__op' => 'in_last', 'mpf_joined__n' => '30', 'mpf_joined__u' => 'days' ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('Joined is in the last', $chips[0]['label']);
        $this->assertSame('30 days', $chips[0]['value']);
    }

    public function test_a_relative_window_chip_clears_its_operator_and_window_params()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->date_field(),
            [],
            [ 'mpf_joined__op' => 'not_in_last', 'mpf_joined__n' => '6', 'mpf_joined__u' => 'months' ]
        );

        $this->assertCount(1, $chips);
        // Without all three the chip's remove link — and Clear all — leaves the filter applied.
        $this->assertContains('mpf_joined__op', $chips[0]['params']);
        $this->assertContains('mpf_joined__n', $chips[0]['params']);
        $this->assertContains('mpf_joined__u', $chips[0]['params']);
        $this->assertSame('Joined is not in the last 6 months', $chips[0]['text']);
    }

    public function test_a_relative_window_with_no_magnitude_is_not_a_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->date_field(),
            [],
            [ 'mpf_joined__op' => 'in_last', 'mpf_joined__u' => 'days' ]
        );

        $this->assertSame([], $chips);
    }

    public function test_between_on_a_single_date_field_chips_from_its_bounds()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->date_field(),
            [],
            [
                'mpf_joined__op'   => 'between',
                'mpf_joined_from'  => '2026-01-01',
                'mpf_joined_to'    => '2026-06-30',
            ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('Joined', $chips[0]['label']);
        $this->assertSame('January 1, 2026 – June 30, 2026', $chips[0]['value']);
        $this->assertContains('mpf_joined_from', $chips[0]['params']);
        $this->assertContains('mpf_joined_to', $chips[0]['params']);
        $this->assertContains('mpf_joined__op', $chips[0]['params']);
    }

    public function test_a_date_operator_is_named_in_the_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->date_field(),
            [],
            [ 'mpf_joined' => '2026-01-01', 'mpf_joined__op' => 'after' ]
        );

        // "Joined: January 1, 2026" would be indistinguishable from an exact-date match.
        $this->assertSame('Joined is after January 1, 2026', $chips[0]['text']);
    }

    public function test_an_unparseable_date_is_shown_raw()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->date_field(),
            [],
            [ 'mpf_joined' => 'not-a-date' ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('not-a-date', $chips[0]['value']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function number_pair_fields()
    {
        return [
            [
                'param'      => 'mpm_spent_min',
                'label'      => 'Total spent (min)',
                'type'       => 'number',
                'source'     => 'mepr_member',
                'range_of'   => 'mpm_spent',
                'range_part' => 'min',
                'unit'       => '$',
            ],
            [
                'param'      => 'mpm_spent_max',
                'label'      => 'Total spent (max)',
                'type'       => 'number',
                'source'     => 'mepr_member',
                'range_of'   => 'mpm_spent',
                'range_part' => 'max',
                'unit'       => '$',
            ],
        ];
    }

    public function test_a_numeric_min_max_pair_renders_as_one_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->number_pair_fields(),
            [],
            [ 'mpm_spent_min' => '10', 'mpm_spent_max' => '99' ]
        );

        $this->assertCount(1, $chips, 'A range_of pair is one filter, so it is one chip.');
        $this->assertSame('Total spent', $chips[0]['label']);
        $this->assertSame('$10–$99', $chips[0]['value']);
        $this->assertContains('mpm_spent_min', $chips[0]['params']);
        $this->assertContains('mpm_spent_max', $chips[0]['params']);
    }

    public function test_a_numeric_lower_bound_names_its_operator_and_unit()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->number_pair_fields(),
            [],
            [ 'mpm_spent_min' => '10', 'mpm_spent__op' => 'at_least' ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('Total spent is at least $10', $chips[0]['text']);
    }

    public function test_match_any_mode_gets_its_own_removable_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_country' => 'DE', 'meprmf_match' => 'any' ]
        );

        $this->assertCount(2, $chips);
        $this->assertSame('Matching any filter', $chips[0]['label']);
        $this->assertSame([ 'meprmf_match' ], $chips[0]['params']);
    }

    public function test_match_any_mode_alone_is_not_a_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips($this->fields(), [], [ 'meprmf_match' => 'any' ]);

        $this->assertSame([], $chips, 'The mode only means something beside filters it combines.');
    }

    public function test_a_multi_value_param_array_is_listed_in_one_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_country' => [ 'DE', 'AT' ], 'mpf_country__op' => 'is_one_of' ]
        );

        $this->assertCount(1, $chips);
        $this->assertSame('Germany, Austria', $chips[0]['value']);
    }

    public function test_exact_match_and_substring_chips_do_not_read_the_same()
    {
        $exact = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_city' => 'Berlin', 'mpf_city__op' => 'is' ]
        );
        $substring = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [],
            [ 'mpf_city' => 'Berlin', 'mpf_city__op' => 'contains' ]
        );

        // Both operators are offered on a text field, so an identical chip would leave the
        // admin unable to tell an exact match from a substring one.
        $this->assertSame('City is Berlin', $exact[0]['text']);
        $this->assertSame('City contains Berlin', $substring[0]['text']);
    }

    public function test_native_toolbar_params_get_their_own_chips()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [ 'status', 'gateway' ],
            [ 'status' => 'complete', 'gateway' => 'stripe' ]
        );

        $labels = array_column($chips, 'label');
        $this->assertContains('Status is', $labels);
        $this->assertContains('Gateway is', $labels);
    }

    public function test_native_no_restriction_value_does_not_chip()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [ 'status', 'membership', 'gateway', 'date_range_filter' ],
            [
                'status'            => 'all',
                'membership'        => 'all',
                'gateway'           => 'all',
                'date_range_filter' => 'all',
            ]
        );

        $this->assertSame([], $chips, "MemberPress's `all` means no restriction, so it must not chip.");
    }

    public function test_native_params_still_chip_alongside_a_no_restriction_sibling()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [ 'status', 'gateway' ],
            [ 'status' => 'all', 'gateway' => 'stripe' ]
        );

        $this->assertCount(1, $chips, 'Only the restricting param may chip.');
        $this->assertSame('Gateway is', $chips[0]['label']);
    }

    public function test_native_membership_resolves_to_the_product_name()
    {
        $fields   = $this->fields();
        $fields[] = [
            'param'   => 'mpm_product',
            'label'   => 'Membership',
            'type'    => 'select',
            'options' => [ 42 => 'Gold Plan' ],
        ];

        $chips = Meprmf_Active_Filters::build_chips($fields, [ 'membership' ], [ 'membership' => '42' ]);

        $this->assertCount(1, $chips);
        $this->assertSame('Membership is', $chips[0]['label']);
        $this->assertSame('Gold Plan', $chips[0]['value']);
    }

    public function test_unknown_native_membership_id_falls_back_to_the_raw_value()
    {
        $chips = Meprmf_Active_Filters::build_chips($this->fields(), [ 'membership' ], [ 'membership' => '999' ]);

        $this->assertCount(1, $chips);
        $this->assertSame('999', $chips[0]['value']);
    }

    public function test_empty_and_whitespace_values_are_not_chips()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [ 'status' ],
            [ 'mpf_country' => '', 'mpf_city' => '   ', 'status' => '' ]
        );

        $this->assertSame([], $chips);
    }

    public function test_a_panel_field_is_not_duplicated_by_a_native_key_of_the_same_name()
    {
        $fields = [
            [
                'param' => 'status',
                'label' => 'Subscription status',
                'type'  => 'select',
            ],
        ];

        $chips = Meprmf_Active_Filters::build_chips($fields, [ 'status' ], [ 'status' => 'active' ]);

        $this->assertCount(1, $chips, 'A param owned by a panel field must not also chip as a native param.');
        $this->assertSame('Subscription status is', $chips[0]['label']);
    }
}
