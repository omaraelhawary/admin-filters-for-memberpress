<?php
/**
 * Tests MemberPress core filter field definitions (no full WP).
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Members_Core_Provider;
use Meprmf_Screen;
use Meprmf_Screen_Context;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Members_Core_Provider
 */
class MembersCoreProviderTest extends TestCase
{

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

    public function test_remap_core_field_params_for_transactions()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $fields = Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx);
        $params = array_column($fields, 'param');

        $this->assertContains('mpmt_product', $params);
        $this->assertContains('mpmt_txn_status', $params);
        $this->assertContains('mpmt_created_from', $params);
        $this->assertNotContains('mpm_product', $params);
        $this->assertNotContains('mpm_member_status', $params);
    }

    public function test_transactions_includes_gateway_when_payment_methods_exist()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        if (! class_exists('MeprOptions', false)) {
            eval(
                'class MeprOptions {
                    public $custom_fields = [];
                    public $show_address_fields = false;
                    public $show_address_on_account = true;
                    public $address_fields = [];
                    public static function fetch() { return new self(); }
                    public function payment_methods() {
                        return [ "manual" => (object) [ "label" => "Manual", "name" => "Manual" ] ];
                    }
                }'
            );
        }

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $fields = Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx);
        $params = array_column($fields, 'param');

        $this->assertContains('mpmt_gateway', $params);
    }

    public function test_members_includes_member_status_field()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
        $fields = Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx);
        $params = array_column($fields, 'param');

        $this->assertContains('mpm_member_status', $params);
    }

    public function test_transactions_access_labels_are_row_scoped()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $fields = Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx);
        $access = null;
        foreach ($fields as $field) {
            if (! empty($field['predicate']) && 'access' === $field['predicate']) {
                $access = $field;
                break;
            }
        }

        $this->assertIsArray($access);
        $this->assertArrayHasKey('active', $access['options']);
        $this->assertStringContainsString('this row', (string) $access['options']['active']);
    }

    public function test_transactions_txn_status_includes_confirmed()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_TRANSACTIONS, 'tr.user_id');
        $fields = Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx);
        $status = null;
        foreach ($fields as $field) {
            if (! empty($field['predicate']) && 'txn_status' === $field['predicate']) {
                $status = $field;
                break;
            }
        }

        $this->assertIsArray($status);
        $this->assertArrayHasKey('confirmed', $status['options']);
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

    public function test_members_includes_corp_type_when_corporate_active()
    {
        if (! class_exists('MPCA_Corporate_Account', false)) {
            eval('class MPCA_Corporate_Account {}');
        }

        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_MEMBERS, 'u.ID');
        $fields = Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx);
        $params = array_column($fields, 'param');

        $this->assertContains('mpm_corp_type', $params);
    }

    public function test_lifetimes_includes_coupon_when_mepr_coupon_available()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-core-provider.php';

        if (! class_exists('MeprCptModel', false)) {
            eval(
                'class MeprCptModel {
                    public static function all($model, $unused, $args) {
                        unset($unused, $args);
                        if ("MeprCoupon" === $model) {
                            return [ (object) [ "ID" => 3, "post_title" => "SAVE" ] ];
                        }
                        return [];
                    }
                }'
            );
        }

        $ctx    = new Meprmf_Screen_Context(Meprmf_Screen::PAGE_LIFETIMES, 'txn.user_id');
        $fields = Meprmf_Members_Core_Provider::get_core_filter_fields_for_context($ctx);
        $params = array_column($fields, 'param');

        $this->assertContains('mpml_coupon', $params);
    }
}
