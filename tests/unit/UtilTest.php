<?php
/**
 * Tests for Meprmf_Util.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Util
 */
class UtilTest extends TestCase
{

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_filters'] = [];
        parent::tearDown();
    }

    public function test_sanitize_param_strips_invalid_chars()
    {
        $this->assertSame('mpfcity', Meprmf_Util::sanitize_param('mpf-city'));
        $this->assertSame('mpf_city', Meprmf_Util::sanitize_param('mpf_city'));
        $this->assertSame('', Meprmf_Util::sanitize_param(''));
        $this->assertSame('abc123', Meprmf_Util::sanitize_param('abc123'));
    }

    public function test_sanitize_param_caps_length_at_32()
    {
        $this->assertSame(str_repeat('a', 32), Meprmf_Util::sanitize_param(str_repeat('a', 70)));
        $this->assertSame('mpf_short', Meprmf_Util::sanitize_param('mpf_short'));
        $this->assertSame(32, Meprmf_Util::PARAM_MAX_LENGTH);
    }

    public function test_normalize_filter_fields_dedupes_params()
    {
        $fields = [
            [
                'param'    => 'mpf_a',
                'meta_key' => 'k1',
                'label'    => 'L1',
                'type'     => 'text',
            ],
            [
                'param'    => 'mpf_a',
                'meta_key' => 'k2',
                'label'    => 'L2',
                'type'     => 'text',
            ],
        ];
        $out = Meprmf_Util::normalize_filter_fields($fields);
        $this->assertCount(1, $out);
        $this->assertSame('mpf_a', $out[0]['param']);
    }

    public function test_get_field_match_mode_country_and_select_default_exact()
    {
        $this->assertSame(
            'exact',
            Meprmf_Util::get_field_match_mode([ 'type' => 'country', 'match' => '' ])
        );
        $this->assertSame(
            'exact',
            Meprmf_Util::get_field_match_mode([ 'type' => 'select', 'match' => '' ])
        );
        $this->assertSame(
            'like',
            Meprmf_Util::get_field_match_mode([ 'type' => 'text', 'match' => '' ])
        );
        $this->assertSame(
            'exact',
            Meprmf_Util::get_field_match_mode([ 'type' => 'date', 'match' => '' ])
        );
        $this->assertSame(
            'range',
            Meprmf_Util::get_field_match_mode([ 'type' => 'date_range', 'match' => '' ])
        );
    }

    public function test_collect_field_request_params_for_date_range()
    {
        $params = Meprmf_Util::collect_field_request_params(
            [
                'param'    => 'mpf_birthday',
                'meta_key' => 'birthday',
                'label'    => 'Birthday',
                'type'     => 'date_range',
            ]
        );
        $this->assertSame([ 'mpf_birthday_from', 'mpf_birthday_to' ], $params);
    }

    public function test_finalize_meta_filter_fields_expands_date_range()
    {
        $fields = Meprmf_Util::finalize_meta_filter_fields(
            [
                [
                    'param'    => 'mpf_birthday',
                    'meta_key' => 'birthday',
                    'label'    => 'Birthday',
                    'type'     => 'date_range',
                ],
            ]
        );

        $this->assertCount(2, $fields);
        $this->assertSame('mpf_birthday_from', $fields[0]['param']);
        $this->assertSame('mpf_birthday_to', $fields[1]['param']);
        $this->assertSame('from', $fields[0]['date_range_part']);
        $this->assertSame('to', $fields[1]['date_range_part']);
    }

    public function test_finalize_meta_filter_fields_applies_range_filter_to_date_fields()
    {
        \add_filter(
            'meprmf_custom_date_fields_use_range',
            static function () {
                return true;
            }
        );

        $fields = Meprmf_Util::finalize_meta_filter_fields(
            [
                [
                    'param'    => 'mpf_birthday',
                    'meta_key' => 'birthday',
                    'label'    => 'Birthday',
                    'type'     => 'date',
                    'match'    => 'exact',
                ],
            ]
        );

        $this->assertCount(2, $fields);
        $this->assertSame('date', $fields[0]['type']);
        $this->assertSame('date', $fields[1]['type']);
        $this->assertSame('mpf_birthday_from', $fields[0]['param']);
    }

    public function test_wordpress_date_format_to_mysql_str_to_date()
    {
        $this->assertSame('%M %e, %Y', Meprmf_Util::wordpress_date_format_to_mysql_str_to_date('F j, Y'));
    }

    public function test_parse_date_param_accepts_iso_dates_only()
    {
        $this->assertSame('2024-06-03', Meprmf_Util::parse_date_param('2024-06-03'));
        $this->assertNull(Meprmf_Util::parse_date_param('06/03/2024'));
    }

    public function test_date_range_param_names_reserve_suffix_length()
    {
        $long_base = 'mpf_' . str_repeat('x', 40);
        $range     = Meprmf_Util::date_range_param_names($long_base);

        $this->assertLessThanOrEqual(Meprmf_Util::PARAM_MAX_LENGTH, strlen($range['from']));
        $this->assertLessThanOrEqual(Meprmf_Util::PARAM_MAX_LENGTH, strlen($range['to']));
        $this->assertNotSame($range['from'], $range['to']);
        $this->assertStringEndsWith('_from', $range['from']);
        $this->assertStringEndsWith('_to', $range['to']);
    }

    public function test_get_field_match_mode_respects_explicit_contains()
    {
        $this->assertSame(
            'contains',
            Meprmf_Util::get_field_match_mode([ 'type' => 'select', 'match' => 'contains' ])
        );
    }
}
