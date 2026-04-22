<?php
/**
 * Tests MemberPress custom field mapping (no full WP).
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Members_Provider;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Members_Provider
 */
class MembersProviderMapTest extends TestCase
{

    public function test_dropdown_maps_to_select_exact()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        $cf                = new \stdClass();
        $cf->field_key     = 'my_custom_field';
        $cf->field_name    = 'Label';
        $cf->field_type    = 'dropdown';
        $opt               = new \stdClass();
        $opt->option_value = 'val1';
        $opt->option_name  = 'One';
        $cf->options       = [ $opt ];

        $mapped = Meprmf_Members_Provider::map_mepr_custom_field_to_filter($cf);
        $this->assertIsArray($mapped);
        $this->assertSame('exact', $mapped['match']);
        $this->assertSame('select', $mapped['type']);
        $this->assertArrayHasKey('val1', $mapped['options']);
    }

    public function test_multiselect_maps_to_contains()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        $cf                = new \stdClass();
        $cf->field_key     = 'multi_field';
        $cf->field_name    = 'Multi';
        $cf->field_type    = 'multiselect';
        $opt               = new \stdClass();
        $opt->option_value = 'a';
        $opt->option_name  = 'A';
        $cf->options       = [ $opt ];

        $mapped = Meprmf_Members_Provider::map_mepr_custom_field_to_filter($cf);
        $this->assertIsArray($mapped);
        $this->assertSame('contains', $mapped['match']);
    }
}
