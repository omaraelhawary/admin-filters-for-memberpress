<?php
/**
 * Tests active filter chip building.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Active_Filters;
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
        $this->assertSame('Country', $chips[0]['label']);
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

        $this->assertSame('Country', $chips[0]['label']);
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
        $this->assertSame('2026-01-01 to 2026-06-30', $chips[0]['value']);
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
        $this->assertSame('from 2026-01-01', $chips[0]['value']);
    }

    public function test_native_toolbar_params_get_their_own_chips()
    {
        $chips = Meprmf_Active_Filters::build_chips(
            $this->fields(),
            [ 'status', 'gateway' ],
            [ 'status' => 'complete', 'gateway' => 'stripe' ]
        );

        $labels = array_column($chips, 'label');
        $this->assertContains('Status', $labels);
        $this->assertContains('Gateway', $labels);
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
        $this->assertSame('Membership', $chips[0]['label']);
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
        $this->assertSame('Subscription status', $chips[0]['label']);
    }
}
