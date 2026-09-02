<?php
/**
 * Meta key / value validation for the set-user-meta bulk action.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Bulk_Set_Meta;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Bulk_Set_Meta
 */
class BulkSetMetaTest extends TestCase
{

    /** @var mixed */
    private $original_wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/bulk/class-meprmf-bulk-set-meta.php';

        $GLOBALS['meprmf_test_filters'] = [];

        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']     = new class() {
            /**
             * @return string
             */
            public function get_blog_prefix()
            {
                return 'wp_3_';
            }
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_filters'] = [];
        if (null === $this->original_wpdb) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->original_wpdb;
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function blocked_key_provider()
    {
        return [
            'memberpress underscore'  => [ 'mepr_total_spent' ],
            'memberpress hyphen'      => [ 'mepr-address-city' ],
            'this plugin'             => [ 'meprmf_default_view_memberpress_members' ],
            'capabilities'            => [ 'wp_capabilities' ],
            'user level'              => [ 'wp_user_level' ],
            'blog prefixed caps'      => [ 'wp_3_capabilities' ],
            'blog prefixed level'     => [ 'wp_3_user_level' ],
            'session tokens'          => [ 'session_tokens' ],
        ];
    }

    /**
     * @dataProvider blocked_key_provider
     * @param string $key Meta key.
     */
    public function test_blocked_keys_are_refused($key)
    {
        $result = Meprmf_Bulk_Set_Meta::validate($key, 'x');

        $this->assertFalse($result['success']);
        $this->assertSame('blocked_key', $result['code']);
    }

    public function test_empty_and_whitespace_keys_are_refused()
    {
        $this->assertSame('empty_key', Meprmf_Bulk_Set_Meta::validate('', 'x')['code']);
        $this->assertSame('empty_key', Meprmf_Bulk_Set_Meta::validate("   \t", 'x')['code']);
    }

    public function test_blocked_key_filter_extends_the_deny_list()
    {
        add_filter(
            'meprmf_bulk_set_meta_blocked_keys',
            static function ($keys) {
                $keys[] = 'nickname';
                return $keys;
            }
        );

        $this->assertSame('blocked_key', Meprmf_Bulk_Set_Meta::validate('nickname', 'x')['code']);
    }

    public function test_blocked_prefix_filter_extends_the_deny_list()
    {
        add_filter(
            'meprmf_bulk_set_meta_blocked_prefixes',
            static function ($prefixes) {
                $prefixes[] = 'acme_';
                return $prefixes;
            }
        );

        $this->assertSame('blocked_key', Meprmf_Bulk_Set_Meta::validate('acme_tier', 'x')['code']);
    }

    public function test_deny_list_matches_regardless_of_case()
    {
        // wp_usermeta.meta_key is stored with a case-insensitive collation, so a differently
        // cased spelling writes to the same row a blocked key owns.
        $this->assertSame('blocked_key', Meprmf_Bulk_Set_Meta::validate('MEPR_total_spent', 'x')['code']);
        $this->assertSame('blocked_key', Meprmf_Bulk_Set_Meta::validate('WP_Capabilities', 'x')['code']);
        $this->assertSame('blocked_key', Meprmf_Bulk_Set_Meta::validate('Session_Tokens', 'x')['code']);
    }

    public function test_array_and_object_values_are_refused()
    {
        $this->assertSame('unsupported_value', Meprmf_Bulk_Set_Meta::validate('crm_tier', [ 'a' ])['code']);
        $this->assertSame('unsupported_value', Meprmf_Bulk_Set_Meta::validate('crm_tier', (object) [ 'a' => 1 ])['code']);
    }

    public function test_empty_value_is_refused()
    {
        $this->assertSame('empty_value', Meprmf_Bulk_Set_Meta::validate('crm_tier', '   ')['code']);
    }

    public function test_scalar_value_is_sanitized()
    {
        $result = Meprmf_Bulk_Set_Meta::validate('crm_tier', "  gold<script>alert(1)</script>  ");

        $this->assertTrue($result['success']);
        $this->assertSame('crm_tier', $result['key']);
        $this->assertSame('goldalert(1)', $result['value']);
    }
}
