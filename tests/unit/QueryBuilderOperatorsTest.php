<?php
/**
 * Tests the query-builder operator vocabulary, range bounds and match mode.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Plugin;
use Meprmf_Predicate_Builder;
use Meprmf_Screen_Context;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Util
 * @covers Meprmf_Predicate_Builder
 * @covers Meprmf_Plugin
 */
class QueryBuilderOperatorsTest extends TestCase
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
        require_once dirname(__DIR__, 2) . '/includes/sql/class-meprmf-mepr-predicate-builder.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-plugin.php';

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
             * @param string $query   Query.
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
    private function birthday_range_field()
    {
        return [
            [
                'param'           => 'mpf_birthday_from',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday from',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'from',
            ],
            [
                'param'           => 'mpf_birthday_to',
                'meta_key'        => 'birthday',
                'label'           => 'Birthday to',
                'type'            => 'date',
                'date_range_of'   => 'mpf_birthday',
                'date_range_part' => 'to',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields Field rows.
     * @return array<int, string>
     */
    private function build(array $fields)
    {
        $ctx = new Meprmf_Screen_Context('memberpress-members', 'u.ID');

        return Meprmf_Predicate_Builder::append_usermeta_exists([], $ctx, $fields);
    }

    public function test_operators_offered_per_field_type()
    {
        $date = Meprmf_Util::get_operators_for_field([ 'type' => 'date' ]);
        $this->assertContains('after', $date);
        $this->assertContains('before', $date);
        $this->assertContains('between', $date);
        $this->assertContains('in_last', $date);
        $this->assertContains('not_in_last', $date);
        $this->assertNotContains('contains', $date);

        $number = Meprmf_Util::get_operators_for_field([ 'type' => 'number' ]);
        $this->assertContains('at_least', $number);
        $this->assertContains('at_most', $number);
        $this->assertContains('between', $number);
        $this->assertNotContains('after', $number);

        $text = Meprmf_Util::get_operators_for_field([ 'type' => 'text' ]);
        $this->assertContains('contains', $text);
        $this->assertNotContains('after', $text);
        $this->assertNotContains('at_least', $text);

        $this->assertSame([], Meprmf_Util::get_operators_for_field([ 'type' => 'checkbox' ]));
    }

    public function test_operator_from_another_family_is_rejected_for_the_field()
    {
        $_GET['mpf_city__op'] = 'in_last';
        $this->assertSame(
            Meprmf_Util::OPERATOR_DEFAULT,
            Meprmf_Util::get_field_operator('mpf_city', [ 'type' => 'text' ])
        );

        $_GET['mpf_joined__op'] = 'contains';
        $this->assertSame(
            Meprmf_Util::OPERATOR_DEFAULT,
            Meprmf_Util::get_field_operator('mpf_joined', [ 'type' => 'date' ])
        );
    }

    public function test_relative_param_names_fit_the_cap_and_do_not_collide()
    {
        $long   = str_repeat('a', 40);
        $names  = Meprmf_Util::relative_param_names($long);
        $op     = Meprmf_Util::operator_param_name($long);
        $range  = Meprmf_Util::date_range_param_names($long);
        $unique = array_unique([ $names['n'], $names['unit'], $op, $range['from'], $range['to'] ]);

        $this->assertLessThanOrEqual(Meprmf_Util::PARAM_MAX_LENGTH, strlen($names['n']));
        $this->assertLessThanOrEqual(Meprmf_Util::PARAM_MAX_LENGTH, strlen($names['unit']));
        $this->assertCount(5, $unique);
        $this->assertTrue(Meprmf_Util::is_operator_param($names['n']));
        $this->assertTrue(Meprmf_Util::is_operator_param($names['unit']));
    }

    public function test_after_keeps_only_the_lower_bound()
    {
        $_GET['mpf_birthday_from'] = '2025-01-01';
        $_GET['mpf_birthday_to']   = '2025-12-31';
        $_GET['mpf_birthday__op']  = 'after';

        $bounds = Meprmf_Util::resolve_range_bounds('mpf_birthday', [ 'type' => 'date_range' ]);
        $this->assertSame('2025-01-01', $bounds['from']);
        $this->assertNull($bounds['to']);
        $this->assertFalse($bounds['negate']);

        $out = $this->build($this->birthday_range_field());
        $this->assertCount(1, $out);
        $this->assertStringContainsString(">= STR_TO_DATE('2025-01-01'", $out[0]);
        $this->assertStringNotContainsString('<=', $out[0]);
    }

    public function test_before_keeps_only_the_upper_bound()
    {
        $_GET['mpf_birthday_from'] = '2025-01-01';
        $_GET['mpf_birthday_to']   = '2025-12-31';
        $_GET['mpf_birthday__op']  = 'before';

        $out = $this->build($this->birthday_range_field());
        $this->assertCount(1, $out);
        $this->assertStringContainsString("<= STR_TO_DATE('2025-12-31'", $out[0]);
        $this->assertStringNotContainsString('>=', $out[0]);
    }

    public function test_between_keeps_both_bounds()
    {
        $_GET['mpf_birthday_from'] = '2025-01-01';
        $_GET['mpf_birthday_to']   = '2025-12-31';
        $_GET['mpf_birthday__op']  = 'between';

        $out = $this->build($this->birthday_range_field());
        $this->assertCount(1, $out);
        $this->assertStringContainsString(">= STR_TO_DATE('2025-01-01'", $out[0]);
        $this->assertStringContainsString("<= STR_TO_DATE('2025-12-31'", $out[0]);
    }

    public function test_after_on_a_single_date_field_uses_its_own_value()
    {
        $_GET['mpf_joined']     = '2025-06-01';
        $_GET['mpf_joined__op'] = 'after';

        $out = $this->build(
            [
                [
                    'param'    => 'mpf_joined',
                    'meta_key' => 'joined',
                    'label'    => 'Joined',
                    'type'     => 'date',
                ],
            ]
        );

        $this->assertCount(1, $out);
        $this->assertStringContainsString(">= STR_TO_DATE('2025-06-01'", $out[0]);
        $this->assertStringNotContainsString('<=', $out[0]);
    }

    public function test_in_last_resolves_relative_bounds_at_build_time()
    {
        $_GET['mpf_birthday__op'] = 'in_last';
        $_GET['mpf_birthday__n']  = '30';
        $_GET['mpf_birthday__u']  = 'days';

        $bounds = Meprmf_Util::resolve_range_bounds('mpf_birthday', [ 'type' => 'date_range' ]);
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->assertSame($now->format('Y-m-d'), $bounds['to']);
        $this->assertSame($now->sub(new \DateInterval('P30D'))->format('Y-m-d'), $bounds['from']);
        $this->assertFalse($bounds['negate']);

        $out = $this->build($this->birthday_range_field());
        $this->assertCount(1, $out);
        $this->assertStringContainsString('EXISTS', $out[0]);
        $this->assertStringNotContainsString('NOT EXISTS', $out[0]);
        $this->assertStringContainsString("STR_TO_DATE('" . $bounds['from'] . "'", $out[0]);
    }

    public function test_in_last_units_and_junk_magnitudes()
    {
        $_GET['mpf_birthday__op'] = 'in_last';
        $_GET['mpf_birthday__n']  = '2';
        $_GET['mpf_birthday__u']  = 'months';

        $bounds = Meprmf_Util::resolve_range_bounds('mpf_birthday', [ 'type' => 'date_range' ]);
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->assertSame($now->sub(new \DateInterval('P2M'))->format('Y-m-d'), $bounds['from']);

        $_GET['mpf_birthday__n'] = 'drop table';
        $bounds                  = Meprmf_Util::resolve_range_bounds('mpf_birthday', [ 'type' => 'date_range' ]);
        $this->assertNull($bounds['from']);
        $this->assertNull($bounds['to']);
    }

    public function test_not_in_last_negates_the_window_and_keeps_rows_without_a_date()
    {
        $_GET['mpf_birthday__op'] = 'not_in_last';
        $_GET['mpf_birthday__n']  = '7';
        $_GET['mpf_birthday__u']  = 'days';

        $bounds = Meprmf_Util::resolve_range_bounds('mpf_birthday', [ 'type' => 'date_range' ]);
        $this->assertTrue($bounds['negate']);

        $out = $this->build($this->birthday_range_field());
        $this->assertCount(1, $out);

        // NOT EXISTS excludes rows inside the window, and keeps both out-of-window rows
        // and users with no meta row at all.
        $this->assertStringStartsWith('NOT EXISTS', $out[0]);
        $this->assertStringContainsString("STR_TO_DATE('" . $bounds['from'] . "'", $out[0]);
        $this->assertStringContainsString("STR_TO_DATE('" . $bounds['to'] . "'", $out[0]);
    }

    public function test_group_survives_normalization_and_date_range_expansion()
    {
        $fields = Meprmf_Util::normalize_filter_fields(
            Meprmf_Util::finalize_meta_filter_fields(
                [
                    [
                        'param'    => 'mpf_birthday',
                        'meta_key' => 'birthday',
                        'label'    => 'Birthday',
                        'type'     => 'date_range',
                        'group'    => Meprmf_Util::GROUP_CUSTOM_FIELDS,
                    ],
                    [
                        'param'    => 'mpf_city',
                        'meta_key' => 'mepr-address-city',
                        'label'    => 'City',
                        'type'     => 'text',
                        'group'    => Meprmf_Util::GROUP_LOCATION,
                    ],
                    [
                        'param'    => 'mpf_nogroup',
                        'meta_key' => 'nogroup',
                        'label'    => 'No group',
                        'type'     => 'text',
                    ],
                ]
            )
        );

        $groups = [];
        foreach ($fields as $field) {
            $groups[ $field['param'] ] = $field['group'];
        }

        $this->assertSame(Meprmf_Util::GROUP_CUSTOM_FIELDS, $groups['mpf_birthday_from']);
        $this->assertSame(Meprmf_Util::GROUP_CUSTOM_FIELDS, $groups['mpf_birthday_to']);
        $this->assertSame(Meprmf_Util::GROUP_LOCATION, $groups['mpf_city']);
        $this->assertSame(Meprmf_Util::GROUP_CUSTOM_FIELDS, $groups['mpf_nogroup']);
    }

    public function test_match_any_ors_only_our_own_fragments()
    {
        $_GET['mpf_city']         = 'Berlin';
        $_GET['mpf_country']      = 'DE';
        $_GET[ Meprmf_Util::MATCH_MODE_PARAM ] = 'any';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Predicate_Builder::append_usermeta_exists(
            [ 'tr.status = "complete"' ],
            $ctx,
            [
                [ 'param' => 'mpf_city', 'meta_key' => 'mepr-address-city', 'label' => 'City', 'type' => 'text', 'match' => 'like' ],
                [ 'param' => 'mpf_country', 'meta_key' => 'mepr-address-country', 'label' => 'Country', 'type' => 'country', 'match' => 'exact' ],
            ]
        );
        $this->assertCount(3, $args);

        $out = Meprmf_Plugin::apply_match_mode($args, $ctx);

        $this->assertCount(2, $out);
        $this->assertSame('tr.status = "complete"', $out[0]);
        $this->assertStringStartsWith('( ( ', $out[1]);
        $this->assertStringContainsString(' ) OR ( ', $out[1]);
        $this->assertStringContainsString('mepr-address-city', $out[1]);
        $this->assertStringContainsString('mepr-address-country', $out[1]);
    }

    public function test_match_all_is_the_default_and_leaves_fragments_anded()
    {
        $_GET['mpf_city']    = 'Berlin';
        $_GET['mpf_country'] = 'DE';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Predicate_Builder::append_usermeta_exists(
            [],
            $ctx,
            [
                [ 'param' => 'mpf_city', 'meta_key' => 'mepr-address-city', 'label' => 'City', 'type' => 'text', 'match' => 'like' ],
                [ 'param' => 'mpf_country', 'meta_key' => 'mepr-address-country', 'label' => 'Country', 'type' => 'country', 'match' => 'exact' ],
            ]
        );

        $this->assertSame('all', Meprmf_Util::get_match_mode($ctx));
        $this->assertSame($args, Meprmf_Plugin::apply_match_mode($args, $ctx));
    }

    public function test_match_any_needs_two_fragments_of_ours()
    {
        $_GET['mpf_city']                      = 'Berlin';
        $_GET[ Meprmf_Util::MATCH_MODE_PARAM ] = 'any';

        $ctx  = new Meprmf_Screen_Context('memberpress-members', 'u.ID');
        $args = Meprmf_Predicate_Builder::append_usermeta_exists(
            [ 'tr.status = "complete"' ],
            $ctx,
            [
                [ 'param' => 'mpf_city', 'meta_key' => 'mepr-address-city', 'label' => 'City', 'type' => 'text', 'match' => 'like' ],
            ]
        );

        $this->assertSame($args, Meprmf_Plugin::apply_match_mode($args, $ctx));
    }
}
