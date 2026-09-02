<?php
/**
 * Batched bulk writer: dry run, batch size, and stop-on-first-error.
 *
 * @package MemberPress_Members_Meta_Filters
 */

namespace Meprmf\Tests\Unit;

use Meprmf_Bulk_Runner;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Bulk_Runner
 */
class BulkRunnerTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/bulk/class-meprmf-bulk-runner.php';

        $GLOBALS['meprmf_test_filters']        = [];
        $GLOBALS['meprmf_test_user_meta']      = [];
        $GLOBALS['meprmf_test_user_meta_fail'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['meprmf_test_filters']        = [];
        $GLOBALS['meprmf_test_user_meta']      = [];
        $GLOBALS['meprmf_test_user_meta_fail'] = [];
        parent::tearDown();
    }

    /**
     * @param int $count How many ids.
     * @return array<int, int>
     */
    private function ids($count)
    {
        return range(101, 100 + $count);
    }

    public function test_default_batch_size_is_fifty()
    {
        $this->assertSame(50, Meprmf_Bulk_Runner::batch_size());
        $this->assertSame(50, Meprmf_Bulk_Runner::batch_size(0));
        $this->assertSame(50, Meprmf_Bulk_Runner::batch_size(-5));
        $this->assertSame(10, Meprmf_Bulk_Runner::batch_size(10));
    }

    public function test_batch_size_filter_overrides_the_request()
    {
        add_filter(
            'meprmf_bulk_batch_size',
            static function () {
                return 7;
            }
        );

        $this->assertSame(7, Meprmf_Bulk_Runner::batch_size(200));
    }

    public function test_preview_returns_the_first_twenty_ids_and_writes_nothing()
    {
        $preview = Meprmf_Bulk_Runner::preview($this->ids(75));

        $this->assertCount(20, $preview);
        $this->assertSame(101, $preview[0]);
        $this->assertSame(120, $preview[19]);
        $this->assertSame([], $GLOBALS['meprmf_test_user_meta']);
    }

    public function test_live_run_writes_every_member_in_batches()
    {
        $result = Meprmf_Bulk_Runner::run($this->ids(25), 'crm_tier', 'gold', 10);

        $this->assertSame(25, $result['processed']);
        $this->assertSame(25, $result['succeeded']);
        $this->assertNull($result['failed_at']);
        $this->assertSame(3, $result['batches']);
        $this->assertSame('gold', $GLOBALS['meprmf_test_user_meta'][125]['crm_tier']);
    }

    public function test_live_run_stops_at_the_first_hard_error()
    {
        $GLOBALS['meprmf_test_user_meta_fail'] = [ 105 ];

        $result = Meprmf_Bulk_Runner::run($this->ids(25), 'crm_tier', 'gold', 10);

        $this->assertSame(5, $result['processed']);
        $this->assertSame(4, $result['succeeded']);
        $this->assertSame(105, $result['failed_at']);
        $this->assertArrayNotHasKey(106, $GLOBALS['meprmf_test_user_meta']);
    }

    public function test_an_unchanged_value_counts_as_succeeded_without_a_write()
    {
        // A member who already has the value: update_user_meta() would return false for a
        // no-op, which must not read as the hard error that stops the run.
        $GLOBALS['meprmf_test_user_meta'][102]  = [ 'crm_tier' => 'gold' ];
        $GLOBALS['meprmf_test_user_meta_fail']  = [ 102 ];

        $result = Meprmf_Bulk_Runner::run($this->ids(4), 'crm_tier', 'gold');

        $this->assertSame(4, $result['processed']);
        $this->assertSame(4, $result['succeeded']);
        $this->assertNull($result['failed_at']);
    }
}
