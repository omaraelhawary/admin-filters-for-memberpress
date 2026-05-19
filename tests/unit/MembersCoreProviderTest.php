<?php
/**
 * Tests MemberPress core filter field definitions (no full WP).
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Members_Core_Provider;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Members_Core_Provider
 */
class MembersCoreProviderTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('MeprSubscription', false)) {
            eval(
                'class MeprSubscription {
                    public static $active_str = "active";
                    public static $pending_str = "pending";
                    public static $cancelled_str = "cancelled";
                    public static $suspended_str = "suspended";
                }'
            );
        }
    }

    public function test_build_core_filter_fields_includes_expected_params()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        $fields = Meprmf_Members_Core_Provider::build_core_filter_fields(
            [
                10 => 'Gold Plan',
                20 => 'Silver Plan',
            ]
        );

        $params = array_column($fields, 'param');
        $this->assertContains('mpm_product', $params);
        $this->assertContains('mpm_access', $params);
        $this->assertContains('mpm_sub_status', $params);
        $this->assertContains('mpm_exp_from', $params);
        $this->assertContains('mpm_exp_to', $params);
        $this->assertContains('mpm_member_from', $params);
        $this->assertContains('mpm_member_to', $params);
    }

    public function test_product_field_has_options()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        $fields = Meprmf_Members_Core_Provider::build_core_filter_fields([ 5 => 'Basic' ]);
        $product = null;
        foreach ($fields as $field) {
            if ('mpm_product' === $field['param']) {
                $product = $field;
                break;
            }
        }

        $this->assertIsArray($product);
        $this->assertSame('mepr_transaction', $product['source']);
        $this->assertArrayHasKey(5, $product['options']);
    }

    public function test_normalize_core_filter_fields_requires_source()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';

        $fields = Meprmf_Members_Core_Provider::build_core_filter_fields([ 1 => 'Plan' ]);
        $valid  = Meprmf_Util::normalize_core_filter_fields($fields);

        $this->assertNotEmpty($valid);
        $this->assertSame('mpm_product', $valid[0]['param']);

        $invalid = Meprmf_Util::normalize_core_filter_fields(
            [
                [
                    'param'  => 'mpm_bad',
                    'label'  => 'Bad',
                    'type'   => 'text',
                    'source' => 'not_a_table',
                ],
            ]
        );
        $this->assertEmpty($invalid);
    }
}
