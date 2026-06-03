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

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_filters'] = [];
        parent::tearDown();
    }

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

    public function test_date_maps_to_date_exact()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        $cf             = new \stdClass();
        $cf->field_key  = 'birthday';
        $cf->field_name = 'Birthday';
        $cf->field_type = 'date';

        $mapped = Meprmf_Members_Provider::map_mepr_custom_field_to_filter($cf);
        $this->assertIsArray($mapped);
        $this->assertSame('date', $mapped['type']);
        $this->assertSame('exact', $mapped['match']);
    }

    public function test_date_maps_to_date_range_when_filter_enabled()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        \add_filter(
            'meprmf_custom_date_fields_use_range',
            static function () {
                return true;
            }
        );

        $cf             = new \stdClass();
        $cf->field_key  = 'birthday';
        $cf->field_name = 'Birthday';
        $cf->field_type = 'date';

        $mapped = Meprmf_Members_Provider::map_mepr_custom_field_to_filter($cf);
        $this->assertIsArray($mapped);
        $this->assertSame('date_range', $mapped['type']);
        $this->assertArrayNotHasKey('match', $mapped);
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

    public function test_address_filters_enabled_when_show_on_account_only()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        $opts                       = new \stdClass();
        $opts->show_address_fields   = false;
        $opts->show_address_on_account = true;
        $opts->address_fields        = [];

        $fields = Meprmf_Members_Provider::get_address_filter_fields($opts);
        $this->assertNotEmpty($fields);
        $this->assertSame('mpf_country', $fields[0]['param']);
    }

    public function test_address_filters_enabled_when_show_on_signup_only()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        $opts                         = new \stdClass();
        $opts->show_address_fields    = true;
        $opts->show_address_on_account = false;
        $opts->address_fields          = [];

        $fields = Meprmf_Members_Provider::get_address_filter_fields($opts);
        $this->assertNotEmpty($fields);
        $this->assertSame('mpf_country', $fields[0]['param']);
    }

    public function test_remap_field_params_for_subscriptions_screen_prefix()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        $fields = [
            [ 'param' => 'mpf_country', 'meta_key' => 'mepr-address-country' ],
            [ 'param' => 'mpf_custom_thing', 'meta_key' => 'x' ],
        ];
        $out = Meprmf_Members_Provider::remap_field_params_from_mpf_prefix($fields, 'mpfs_');
        $this->assertSame('mpfs_country', $out[0]['param']);
        $this->assertSame('mpfs_custom_thing', $out[1]['param']);
    }

    public function test_remap_field_params_for_transactions_screen_prefix()
    {
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        $fields = [ [ 'param' => 'mpf_zip', 'meta_key' => 'mepr-address-zip' ] ];
        $out    = Meprmf_Members_Provider::remap_field_params_from_mpf_prefix($fields, 'mpft_');
        $this->assertSame('mpft_zip', $out[0]['param']);
    }

    public function test_subscriptions_hook_receives_mpfs_prefixed_params()
    {
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen.php';
        require_once dirname(__DIR__, 2) . '/includes/screen/class-meprmf-screen-context.php';
        require_once dirname(__DIR__, 2) . '/includes/filters/providers/class-meprmf-members-provider.php';

        if (! class_exists('MeprOptions', false)) {
            eval(
                'class MeprOptions {
                    public $custom_fields = [];
                    public $show_address_fields = false;
                    public $show_address_on_account = true;
                    public $address_fields = [];
                    public static function fetch() { return new self(); }
                }'
            );
        }

        $seen = null;
        \add_filter(
            'meprmf_subscriptions_meta_filters_fields',
            static function ($fields) use (&$seen) {
                $seen = ! empty($fields[0]['param']) ? $fields[0]['param'] : null;
                return $fields;
            }
        );

        $ctx    = new \Meprmf_Screen_Context(\Meprmf_Screen::PAGE_SUBSCRIPTIONS, 'sub.user_id');
        $fields = Meprmf_Members_Provider::get_filter_fields_for_context($ctx);

        $this->assertSame('mpfs_country', $seen);
        $this->assertSame('mpfs_country', $fields[0]['param']);
    }
}
