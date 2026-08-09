<?php
/**
 * Tests usermeta EXISTS predicate SQL generation.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Predicate_Builder;
use Meprmf_Screen_Context;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Predicate_Builder
 */
class UsermetaPredicateBuilderTest extends TestCase
{

    /** @var array<string, string> */
    private $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrap_stubs();
        $this->original_get = $_GET;
        $_GET               = [];
        Meprmf_Predicate_Builder::reset_last_fragments();
    }

    protected function tearDown(): void
    {
        $_GET = $this->original_get;
        Meprmf_Predicate_Builder::reset_last_fragments();
        parent::tearDown();
    }

    private function bootstrap_stubs(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/sql/class-meprmf-predicate-builder.php';

        global $wpdb;
        $wpdb = new class() {
            public $prefix   = 'wp_';
            public $usermeta = 'wp_usermeta';

            /**
             * @param string $text Text.
             * @return string
             */
            public function esc_like($text)
            {
                return addcslashes((string) $text, '_%\\');
            }

            /**
             * @param string $query Query.
             * @param mixed  ...$args Args.
             * @return string
             */
            public function prepare($query, ...$args)
            {
                if (empty($args)) {
                    return $query;
                }
                foreach ($args as $arg) {
                    $replacement = is_numeric($arg)
                        ? (string) $arg
                        : "'" . str_replace("'", "''", (string) $arg) . "'";
                    $query       = preg_replace('/%[sdf]/', $replacement, $query, 1);
                }
                return preg_replace('/%%/', '%', $query);
            }
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function country_field($match = 'exact')
    {
        return [
            [
                'param'    => 'mpf_country',
                'meta_key' => 'mepr-address-country',
                'label'    => 'Country',
                'type'     => 'country',
                'match'    => $match,
            ],
        ];
    }

    public function test_exact_match_produces_prepared_sql_with_meta_value_equals()
    {
        $_GET['mpf_country'] = 'US';
        $ctx                 = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $out                 = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $this->country_field());

        $this->assertCount(1, $out);
        $this->assertStringContainsString("meta_value = 'US'", $out[0]);
        $this->assertStringContainsString('mepr-address-country', $out[0]);
        $this->assertStringContainsString('mpf_um_mpf_country', $out[0]);
    }

    public function test_hostile_meta_value_is_escaped()
    {
        $_GET['mpf_country'] = "' OR '1'='1";
        $ctx                 = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $out                 = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $this->country_field());

        $this->assertCount(1, $out);
        $this->assertStringContainsString("meta_value = ''' OR ''1''=''1'", $out[0]);
        $this->assertStringNotContainsString("meta_value = '' OR '1'='1'", $out[0]);
    }

    public function test_checkbox_only_matches_on_value_1()
    {
        $_GET['mpf_optin'] = '0';
        $ctx               = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields            = [
            [
                'param'    => 'mpf_optin',
                'meta_key' => 'newsletter',
                'label'    => 'Opt in',
                'type'     => 'checkbox',
            ],
        ];
        $out               = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(0, $out);
    }

    public function test_serialized_needle_for_contains_match()
    {
        $_GET['mpf_multi'] = 'café';
        $ctx                = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields             = [
            [
                'param'    => 'mpf_multi',
                'meta_key' => 'multi_key',
                'label'    => 'Multi',
                'type'     => 'select',
                'match'    => 'contains',
                'options'  => [ 'café' => 'Café' ],
            ],
        ];
        $out                = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('s:5:"café";', $out[0]);
    }

    public function test_empty_value_skips_predicate()
    {
        $_GET['mpf_country'] = '';
        $ctx                 = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $out                 = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $this->country_field());

        $this->assertCount(0, $out);
    }

    public function test_date_field_uses_exact_match()
    {
        $_GET['mpf_birthday'] = '2024-06-03';
        $ctx                  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields               = [
            [
                'param'    => 'mpf_birthday',
                'meta_key' => 'birthday',
                'label'    => 'Birthday',
                'type'     => 'date',
                'match'    => 'exact',
            ],
        ];
        $out                  = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('meta_value IN', $out[0]);
        $this->assertStringContainsString("'2024-06-03'", $out[0]);
        $this->assertStringContainsString("'June 3, 2024'", $out[0]);
        $this->assertStringNotContainsString('meta_value LIKE', $out[0]);
    }

    public function test_invalid_date_skips_predicate()
    {
        $_GET['mpf_birthday'] = '06/03/2024';
        $ctx                  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields               = [
            [
                'param'    => 'mpf_birthday',
                'meta_key' => 'birthday',
                'label'    => 'Birthday',
                'type'     => 'date',
                'match'    => 'exact',
            ],
        ];
        $out                  = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(0, $out);
    }

    public function test_date_range_from_and_to()
    {
        $_GET['mpf_birthday_from'] = '2024-01-01';
        $_GET['mpf_birthday_to']   = '2024-12-31';
        $ctx                       = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields                    = [
            [
                'param'           => 'mpf_birthday_from',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (from)',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'from',
            ],
            [
                'param'           => 'mpf_birthday_to',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (to)',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'to',
            ],
        ];
        $out                       = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('STR_TO_DATE', $out[0]);
        $this->assertStringContainsString("'2024-01-01'", $out[0]);
        $this->assertStringContainsString("'2024-12-31'", $out[0]);
        $this->assertStringContainsString('%M %e, %Y', $out[0]);
        $this->assertStringNotContainsString("meta_value >= '2024-01-01'", $out[0]);
    }

    public function test_date_range_from_only()
    {
        $_GET['mpf_birthday_from'] = '2024-06-01';
        $ctx                       = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields                    = [
            [
                'param'           => 'mpf_birthday_from',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (from)',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'from',
            ],
            [
                'param'           => 'mpf_birthday_to',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (to)',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'to',
            ],
        ];
        $out                       = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('STR_TO_DATE', $out[0]);
        $this->assertStringContainsString("'2024-06-01'", $out[0]);
        $this->assertStringNotContainsString("'2024-12-31'", $out[0]);
    }

    public function test_date_range_invalid_bounds_skipped()
    {
        $_GET['mpf_birthday_from'] = 'not-a-date';
        $ctx                       = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields                    = [
            [
                'param'           => 'mpf_birthday_from',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (from)',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'from',
            ],
            [
                'param'           => 'mpf_birthday_to',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday (to)',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'to',
            ],
        ];
        $out                       = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(0, $out);
    }

    public function test_like_match_uses_like_clause()
    {
        $_GET['mpf_city'] = 'Paris';
        $ctx              = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $fields           = [
            [
                'param'    => 'mpf_city',
                'meta_key' => 'mepr-address-city',
                'label'    => 'City',
                'type'     => 'text',
                'match'    => 'like',
            ],
        ];
        $out              = Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('meta_value LIKE', $out[0]);
        $this->assertStringContainsString('%Paris%', $out[0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function city_field($match = 'like')
    {
        return [
            [
                'param'    => 'mpf_city',
                'meta_key' => 'mepr-address-city',
                'label'    => 'City',
                'type'     => 'text',
                'match'    => $match,
            ],
        ];
    }

    /**
     * Run the builder for one field with an operator set.
     *
     * @param array<int, array<string, mixed>> $fields Fields.
     * @return array<int, string>
     */
    private function build(array $fields)
    {
        $ctx = new Meprmf_Screen_Context('memberpress-members', 'u.ID');

        return Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);
    }

    public function test_operator_is_behaves_like_exact_match()
    {
        $_GET['mpf_country']     = 'DE';
        $_GET['mpf_country__op'] = 'is';
        $out                     = $this->build($this->country_field());

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('EXISTS', $out[0]);
        $this->assertStringContainsString("meta_value = 'DE'", $out[0]);
    }

    public function test_operator_is_not_uses_not_exists_so_rows_without_the_key_are_included()
    {
        $_GET['mpf_country']     = 'DE';
        $_GET['mpf_country__op'] = 'is_not';
        $out                     = $this->build($this->country_field());

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('NOT EXISTS', $out[0]);
        $this->assertStringContainsString("meta_value = 'DE'", $out[0]);
    }

    public function test_operator_not_contains_negates_the_like_clause()
    {
        $_GET['mpf_city']     = 'Paris';
        $_GET['mpf_city__op'] = 'not_contains';
        $out                  = $this->build($this->city_field());

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('NOT EXISTS', $out[0]);
        $this->assertStringContainsString('%Paris%', $out[0]);
    }

    public function test_operator_not_contains_keeps_serialized_matching_for_contains_fields()
    {
        $_GET['mpf_multi']     = 'blue';
        $_GET['mpf_multi__op'] = 'not_contains';
        $out                   = $this->build(
            [
                [
                    'param'    => 'mpf_multi',
                    'meta_key' => 'multi_key',
                    'label'    => 'Multi',
                    'type'     => 'select',
                    'match'    => 'contains',
                    'options'  => [ 'blue' => 'Blue' ],
                ],
            ]
        );

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('NOT EXISTS', $out[0]);
        $this->assertStringContainsString('s:4:"blue";', $out[0]);
    }

    public function test_operator_is_empty_matches_missing_row_and_empty_string()
    {
        $_GET['mpf_city__op'] = 'is_empty';
        $out                  = $this->build($this->city_field());

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('NOT EXISTS', $out[0]);
        $this->assertStringContainsString("meta_value <> ''", $out[0]);
    }

    public function test_operator_is_not_empty_requires_a_non_empty_row()
    {
        $_GET['mpf_city__op'] = 'is_not_empty';
        $out                  = $this->build($this->city_field());

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('EXISTS', $out[0]);
        $this->assertStringContainsString("meta_value <> ''", $out[0]);
    }

    public function test_valueless_operators_do_not_need_a_value_in_the_request()
    {
        $_GET['mpf_city']     = '';
        $_GET['mpf_city__op'] = 'is_empty';
        $out                  = $this->build($this->city_field());

        $this->assertCount(1, $out);
    }

    public function test_operator_is_one_of_builds_a_single_in_clause()
    {
        $_GET['mpf_country']     = 'DE,AT,CH';
        $_GET['mpf_country__op'] = 'is_one_of';
        $out                     = $this->build($this->country_field());

        $this->assertCount(1, $out, 'is_one_of must produce one fragment, not one per value.');
        $this->assertStringContainsString("IN ('DE', 'AT', 'CH')", $out[0]);
    }

    public function test_operator_is_one_of_accepts_an_array_from_a_multi_select()
    {
        $_GET['mpf_country']     = [ 'DE', 'AT' ];
        $_GET['mpf_country__op'] = 'is_one_of';
        $out                     = $this->build($this->country_field());

        $this->assertCount(1, $out);
        $this->assertStringContainsString("IN ('DE', 'AT')", $out[0]);
    }

    public function test_operator_is_one_of_with_no_values_adds_no_constraint()
    {
        $_GET['mpf_country']     = ' , ,';
        $_GET['mpf_country__op'] = 'is_one_of';
        $out                     = $this->build($this->country_field());

        $this->assertCount(0, $out);
    }

    public function test_is_one_of_values_are_escaped()
    {
        $_GET['mpf_country']     = "DE,' OR '1'='1";
        $_GET['mpf_country__op'] = 'is_one_of';
        $out                     = $this->build($this->country_field());

        $this->assertCount(1, $out);
        $this->assertStringNotContainsString("'DE', '' OR '1'='1'", $out[0]);
        $this->assertStringContainsString("''' OR ''1''=''1'", $out[0]);
    }

    public function test_unknown_operator_falls_back_to_default_match_mode()
    {
        $_GET['mpf_country']     = 'DE';
        $_GET['mpf_country__op'] = 'drop_table';
        $out                     = $this->build($this->country_field());

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('EXISTS', $out[0]);
        $this->assertStringContainsString("meta_value = 'DE'", $out[0]);
    }

    public function test_absent_operator_leaves_pre_existing_behaviour_untouched()
    {
        $_GET['mpf_city'] = 'Paris';
        $out              = $this->build($this->city_field());

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('EXISTS', $out[0]);
        $this->assertStringContainsString('%Paris%', $out[0]);
    }

    public function test_operators_are_ignored_on_checkbox_fields()
    {
        $_GET['mpf_optin']     = '1';
        $_GET['mpf_optin__op'] = 'is_not';
        $out                   = $this->build(
            [
                [
                    'param'    => 'mpf_optin',
                    'meta_key' => 'newsletter',
                    'label'    => 'Opt in',
                    'type'     => 'checkbox',
                ],
            ]
        );

        $this->assertCount(1, $out);
        $this->assertStringStartsWith('EXISTS', $out[0], 'Checkbox semantics must not be negated by an operator param.');
    }
}
