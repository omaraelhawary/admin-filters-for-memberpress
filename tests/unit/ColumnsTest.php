<?php
/**
 * Tests filtered-field columns.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Columns;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Columns
 */
class ColumnsTest extends TestCase
{

    /** @var array<string, mixed> */
    private $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-util.php';
        require_once dirname(__DIR__, 2) . '/includes/ui/class-meprmf-columns.php';
        $this->original_get = $_GET;
        $_GET               = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->original_get;
        parent::tearDown();
    }

    public function test_add_columns_passes_non_arrays_through_untouched()
    {
        $this->assertSame('nope', Meprmf_Columns::add_columns('nope'));
        $this->assertNull(Meprmf_Columns::add_columns(null));
    }

    public function test_add_columns_leaves_the_set_alone_when_nothing_is_filtered()
    {
        $cols = [ 'col_id' => 'Id', 'col_email' => 'Email' ];

        $this->assertSame($cols, Meprmf_Columns::add_columns($cols));
    }

    public function test_column_prefix_is_namespaced()
    {
        $this->assertSame('meprmf_col_', Meprmf_Columns::COL_PREFIX);
    }
}
