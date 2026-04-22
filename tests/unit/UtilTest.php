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

    public function test_sanitize_param_strips_invalid_chars()
    {
        $this->assertSame('mpfcity', Meprmf_Util::sanitize_param('mpf-city'));
        $this->assertSame('mpf_city', Meprmf_Util::sanitize_param('mpf_city'));
        $this->assertSame('', Meprmf_Util::sanitize_param(''));
        $this->assertSame('abc123', Meprmf_Util::sanitize_param('abc123'));
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
    }

    public function test_get_field_match_mode_respects_explicit_contains()
    {
        $this->assertSame(
            'contains',
            Meprmf_Util::get_field_match_mode([ 'type' => 'select', 'match' => 'contains' ])
        );
    }
}
