<?php
/**
 * Tests the field catalog the query-builder UI is built from.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Toolbar_Renderer;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Toolbar_Renderer
 */
class FieldCatalogTest extends TestCase
{

    /** @var array<string, string> */
    private $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/ui/class-meprmf-toolbar-renderer.php';
        $this->original_get = $_GET;
        $_GET               = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->original_get;
        parent::tearDown();
    }

    /**
     * @param array<int, array<string, mixed>> $catalog Catalog.
     * @param string                           $param   Base param.
     * @return array<string, mixed>|null
     */
    private function entry(array $catalog, $param)
    {
        foreach ($catalog as $entry) {
            if ($entry['param'] === $param) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function birthday_pair()
    {
        return [
            [
                'param'           => 'mpf_birthday_from',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (from)',
                'type'            => 'date',
                'group'           => Meprmf_Util::GROUP_CUSTOM_FIELDS,
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'from',
            ],
            [
                'param'           => 'mpf_birthday_to',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (to)',
                'type'            => 'date',
                'group'           => Meprmf_Util::GROUP_CUSTOM_FIELDS,
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'to',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function spent_pair()
    {
        return [
            [
                'param'      => 'mpm_spent_min',
                'label'      => 'Total spent (min)',
                'type'       => 'number',
                'source'     => 'mepr_member',
                'group'      => Meprmf_Util::GROUP_ACTIVITY,
                'range_of'   => 'mpm_spent',
                'range_part' => 'min',
                'unit'       => '$',
            ],
            [
                'param'      => 'mpm_spent_max',
                'label'      => 'Total spent (max)',
                'type'       => 'number',
                'source'     => 'mepr_member',
                'group'      => Meprmf_Util::GROUP_ACTIVITY,
                'range_of'   => 'mpm_spent',
                'range_part' => 'max',
                'unit'       => '$',
            ],
        ];
    }

    public function test_a_bound_pair_collapses_into_one_entry_labelled_by_the_shared_prefix()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog($this->birthday_pair());

        $this->assertCount(1, $catalog);
        $entry = $catalog[0];
        $this->assertSame('mpf_birthday', $entry['param']);
        $this->assertSame('Birthday', $entry['label']);
        $this->assertTrue($entry['pair']);
        $this->assertSame('date', $entry['kind']);
        $this->assertSame(Meprmf_Util::GROUP_CUSTOM_FIELDS, $entry['group']);
        $this->assertSame('mpf_birthday_from', $entry['params']['from']);
        $this->assertSame('mpf_birthday_to', $entry['params']['to']);
        $this->assertSame('mpf_birthday__op', $entry['params']['op']);
        $this->assertSame('mpf_birthday__n', $entry['params']['n']);
        $this->assertSame('mpf_birthday__u', $entry['params']['u']);
        $this->assertArrayNotHasKey('value', $entry['params']);
    }

    public function test_a_single_date_entry_carries_its_value_plus_the_between_bounds()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [
                    'param'    => 'mpf_joined',
                    'meta_key' => 'joined',
                    'label'    => 'Joined',
                    'type'     => 'date',
                ],
            ]
        );

        $entry = $catalog[0];
        $this->assertFalse($entry['pair']);
        $this->assertSame('mpf_joined', $entry['params']['value']);
        $this->assertSame('mpf_joined_from', $entry['params']['from']);
        $this->assertSame('mpf_joined_to', $entry['params']['to']);
    }

    public function test_a_pair_label_is_not_cut_mid_word()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog($this->spent_pair());

        // "(min)" / "(max)" share their opening "(m", which is not a label.
        $this->assertSame('Total spent', $catalog[0]['label']);
    }

    public function test_a_multibyte_pair_label_is_not_cut_mid_character()
    {
        $fields = $this->birthday_pair();
        $fields[0]['label'] = 'Geburtstag — von';
        $fields[1]['label'] = 'Geburtstag — bis';

        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog($fields);

        $this->assertSame('Geburtstag', $catalog[0]['label']);
    }

    public function test_operators_are_narrowed_to_what_the_sql_can_express()
    {
        $tokens = static function (array $entry) {
            return array_column($entry['ops'], 'v');
        };

        // A MemberPress-column text field is read as a plain value: only `is` is truthful.
        $core = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [
                    'param'  => 'mpm_email',
                    'label'  => 'Email',
                    'type'   => 'text',
                    'source' => 'mepr_member',
                ],
            ]
        );
        $this->assertSame([ 'is' ], $tokens($core[0]));

        // A numeric pair is two independent bounds, so equality is not expressible.
        $spent = Meprmf_Toolbar_Renderer::build_field_catalog($this->spent_pair());
        $this->assertSame([ 'between', 'at_least', 'at_most' ], array_values($tokens($spent[0])));
        $this->assertSame('$', $spent[0]['unit']);

        // One `<select>` cannot hold a list of values.
        $choice = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [
                    'param'    => 'mpf_country',
                    'meta_key' => 'mepr-address-country',
                    'label'    => 'Country',
                    'type'     => 'country',
                ],
            ]
        );
        $this->assertNotContains('is_one_of', $tokens($choice[0]));
        $this->assertContains('is', $tokens($choice[0]));

        // A date pair keeps the whole date family, source or not.
        $this->assertContains('in_last', $tokens(Meprmf_Toolbar_Renderer::build_field_catalog($this->birthday_pair())[0]));
    }

    public function test_every_operator_offered_has_a_label()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            array_merge(
                $this->birthday_pair(),
                $this->spent_pair(),
                [
                    [ 'param' => 'mpf_city', 'meta_key' => 'mepr-address-city', 'label' => 'City', 'type' => 'text' ],
                ]
            )
        );

        $labels = Meprmf_Util::get_operator_labels();

        foreach ($catalog as $entry) {
            foreach ($entry['ops'] as $op) {
                $this->assertArrayHasKey($op['v'], $labels, 'Operator ' . $op['v'] . ' has no label.');
                $this->assertSame($labels[ $op['v'] ], $op['l']);
            }
        }
    }

    public function test_a_checkbox_entry_offers_the_checked_option_and_no_operator()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [
                    'param'    => 'mpf_optin',
                    'meta_key' => 'optin',
                    'label'    => 'Opt-in',
                    'type'     => 'checkbox',
                ],
            ]
        );

        $this->assertSame('choice', $catalog[0]['kind']);
        $this->assertSame([], $catalog[0]['ops']);
        $this->assertSame([ '1' ], array_column($catalog[0]['options'], 'v'));
    }

    public function test_active_count_counts_entries_not_params()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            array_merge(
                $this->birthday_pair(),
                [
                    [ 'param' => 'mpf_city', 'meta_key' => 'mepr-address-city', 'label' => 'City', 'type' => 'text' ],
                ]
            )
        );

        $this->assertSame(0, Meprmf_Toolbar_Renderer::count_active_entries($catalog));

        // Two bounds of one "is between" are one filter.
        $_GET['mpf_birthday__op']  = 'between';
        $_GET['mpf_birthday_from'] = '2025-01-01';
        $_GET['mpf_birthday_to']   = '2025-12-31';
        $this->assertSame(1, Meprmf_Toolbar_Renderer::count_active_entries($catalog));

        $_GET['mpf_city'] = 'Berlin';
        $this->assertSame(2, Meprmf_Toolbar_Renderer::count_active_entries($catalog));
    }

    public function test_active_count_includes_a_valueless_operator_and_the_relative_window()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            array_merge(
                $this->birthday_pair(),
                [
                    [ 'param' => 'mpf_city', 'meta_key' => 'mepr-address-city', 'label' => 'City', 'type' => 'text' ],
                ]
            )
        );

        // "is empty" constrains the list with no value at all.
        $_GET['mpf_city__op'] = 'is_empty';
        $this->assertSame(1, Meprmf_Toolbar_Renderer::count_active_entries($catalog));

        $_GET['mpf_birthday__op'] = 'in_last';
        $_GET['mpf_birthday__n']  = '30';
        $_GET['mpf_birthday__u']  = 'days';
        $this->assertSame(2, Meprmf_Toolbar_Renderer::count_active_entries($catalog));
    }

    public function test_the_catalog_is_filterable()
    {
        add_filter(
            'meprmf_field_catalog',
            static function ($catalog) {
                return array_slice($catalog, 0, 1);
            }
        );

        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [ 'param' => 'mpf_city', 'meta_key' => 'mepr-address-city', 'label' => 'City', 'type' => 'text' ],
                [ 'param' => 'mpf_state', 'meta_key' => 'mepr-address-state', 'label' => 'State', 'type' => 'text' ],
            ]
        );

        $this->assertCount(1, $catalog);
        $GLOBALS['meprmf_test_filters']['meprmf_field_catalog'] = [];
    }

    public function test_an_operator_aware_core_text_field_keeps_its_text_operators()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [
                    'param'          => 'mpmt_subscription',
                    'label'          => 'Subscription',
                    'type'           => 'text',
                    'source'         => 'mepr_subscription',
                    'operator_aware' => true,
                ],
            ]
        );

        $tokens = array_column($catalog[0]['ops'], 'v');

        $this->assertContains('contains', $tokens);
        $this->assertContains('not_contains', $tokens);
        $this->assertContains('is', $tokens);
        $this->assertContains('is_not', $tokens);
        $this->assertContains('is_empty', $tokens);
        $this->assertContains('is_not_empty', $tokens);
        // One input cannot hold a list of values, whatever backs the field.
        $this->assertNotContains('is_one_of', $tokens);
        $this->assertFalse($catalog[0]['pair']);
        $this->assertSame('mpmt_subscription', $catalog[0]['params']['value']);
        $this->assertSame('mpmt_subscription__op', $catalog[0]['params']['op']);
    }

    public function test_the_transactions_amount_pair_reads_as_one_money_row()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [
                    'param'      => 'mpmt_amount_min',
                    'label'      => 'Amount (min)',
                    'type'       => 'number',
                    'source'     => 'mepr_transaction',
                    'range_of'   => 'mpmt_amount',
                    'range_part' => 'min',
                    'unit'       => '$',
                ],
                [
                    'param'      => 'mpmt_amount_max',
                    'label'      => 'Amount (max)',
                    'type'       => 'number',
                    'source'     => 'mepr_transaction',
                    'range_of'   => 'mpmt_amount',
                    'range_part' => 'max',
                    'unit'       => '$',
                ],
            ]
        );

        $this->assertCount(1, $catalog);
        $this->assertSame('Amount', $catalog[0]['label']);
        $this->assertSame('number', $catalog[0]['kind']);
        $this->assertTrue($catalog[0]['pair']);
        $this->assertSame('$', $catalog[0]['unit']);
        $this->assertSame('mpmt_amount_min', $catalog[0]['params']['from']);
        $this->assertSame('mpmt_amount_max', $catalog[0]['params']['to']);
        $this->assertSame([ 'between', 'at_least', 'at_most' ], array_values(array_column($catalog[0]['ops'], 'v')));
    }

    public function test_the_transaction_count_pair_has_no_currency_unit()
    {
        $catalog = Meprmf_Toolbar_Renderer::build_field_catalog(
            [
                [
                    'param'      => 'mpms_txn_count_min',
                    'label'      => 'Transaction count (min)',
                    'type'       => 'number',
                    'source'     => 'mepr_subscription',
                    'range_of'   => 'mpms_txn_count',
                    'range_part' => 'min',
                ],
                [
                    'param'      => 'mpms_txn_count_max',
                    'label'      => 'Transaction count (max)',
                    'type'       => 'number',
                    'source'     => 'mepr_subscription',
                    'range_of'   => 'mpms_txn_count',
                    'range_part' => 'max',
                ],
            ]
        );

        $this->assertCount(1, $catalog);
        $this->assertSame('Transaction count', $catalog[0]['label']);
        $this->assertArrayNotHasKey('unit', $catalog[0]);
    }
}
