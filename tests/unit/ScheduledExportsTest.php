<?php
namespace Meprmf\Tests\Unit;

use Meprmf_Scheduled_Exports as Exports;
use Meprmf_Presets;
use Meprmf_Screen;
use Meprmf_Util;
use PHPUnit\Framework\TestCase;

/**
 * @covers Meprmf_Scheduled_Exports
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ScheduledExportsTest extends TestCase
{
    private $uploads;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/fixtures/scheduled-export-boundaries.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-capabilities.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-presets.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-native-params.php';
        require_once dirname(__DIR__, 2) . '/includes/class-meprmf-scheduled-exports.php';
        $GLOBALS['wpdb'] = new \ScheduledExportDb();
        $GLOBALS['meprmf_test_options'] = [];
        $GLOBALS['meprmf_test_filters'] = [];
        $GLOBALS['export_caps'] = [ 7 => [ \MeprUtils::get_mepr_admin_capability() => true ], 8 => [ \MeprUtils::get_mepr_admin_capability() => true ] ];
        wp_set_current_user(7);
        $this->uploads = dirname(__DIR__, 2) . '/.test-exports-' . bin2hex(random_bytes(6));
        $GLOBALS['export_uploads'] = $this->uploads;
        $GLOBALS['export_mails'] = [];
        $GLOBALS['export_fetches'] = [];
        $GLOBALS['export_cron'] = [];
        $_GET = [];
        $_POST = [];
        $this->preset();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . '/meprmf-exports/*') ?: [] as $file) { unlink($file); }
        if (is_dir($this->uploads . '/meprmf-exports')) { rmdir($this->uploads . '/meprmf-exports'); }
        if (is_dir($this->uploads)) { rmdir($this->uploads); }
    }

    private function preset($owner = 7, $visibility = 'private')
    {
        $GLOBALS['meprmf_test_options'][Meprmf_Presets::OPTION_KEY] = [ 'memberpress_members' => [ [
            'id' => 'p_fixture', 'name' => 'Expiring members', 'owner' => $owner,
            'visibility' => $visibility, 'params' => [ 'mpf_country' => 'DE', 's' => 'smith' ],
        ] ] ];
    }

    private function input($overrides = [])
    {
        return array_merge([ 'screen' => 'memberpress_members', 'preset' => 'p_fixture',
            'frequency' => 'weekly', 'weekday' => '1', 'recipient' => '' ], $overrides);
    }

    private function create($overrides = [])
    {
        $this->assertSame('saved', Exports::change('save', $this->input($overrides)));
        return array_key_first(Exports::schedules());
    }

    private function due($id)
    {
        $all = Exports::schedules();
        $all[$id]['next'] = time() - 60;
        update_option(Exports::SCHEDULES, $all);
    }

    public function test_crud_is_per_user_and_duplicate_save_updates_one_row()
    {
        $id = $this->create();
        $this->assertSame('owner@example.test', Exports::schedules()[$id]['recipient']);
        $this->assertSame('hourly', $GLOBALS['export_cron_frequency']);
        $this->create([ 'frequency' => 'daily', 'recipient' => 'recipient@example.test' ]);
        $this->assertCount(1, Exports::schedules());
        wp_set_current_user(8);
        $this->assertSame('not_found', Exports::change('delete', [ 'id' => $id ]));
        $this->assertSame('not_found', Exports::change('save', $this->input()));
        wp_set_current_user(7);
        $this->assertSame('saved', Exports::change('pause', [ 'id' => $id ]));
        $this->assertTrue(Exports::schedules()[$id]['paused']);
        $this->create();
        $this->assertFalse(Exports::schedules()[$id]['paused']);
        $this->assertSame('saved', Exports::change('delete', [ 'id' => $id ]));
        $this->assertSame([], Exports::schedules());
        $this->assertFalse(wp_next_scheduled(Exports::HOOK));
    }

    public function test_shared_preset_can_be_scheduled_without_mutating_another_users_schedule()
    {
        $this->preset(7, 'shared');
        $first = $this->create();
        wp_set_current_user(8);
        $this->create();
        $this->assertCount(2, Exports::schedules());
        $this->assertSame(7, Exports::schedules()[$first]['owner']);
        $this->assertSame('not_found', Exports::change('save', $this->input([ 'id' => $first ])));
    }

    /** @dataProvider invalidInput */
    public function test_rejects_invalid_input($input)
    {
        $this->assertSame('invalid_input', Exports::change('save', $this->input($input)));
        $this->assertSame([], Exports::schedules());
    }

    public static function invalidInput(): array
    {
        return [ [ [ 'frequency' => 'hourly' ] ], [ [ 'weekday' => '7' ] ],
            [ [ 'weekday' => 'oops' ] ], [ [ 'recipient' => 'bad' ] ],
            [ [ 'recipient' => "valid@example.test\r\nBcc: bad@example.test" ] ] ];
    }

    public function test_management_requires_capability_and_nonce_before_changes()
    {
        $GLOBALS['export_caps'][7] = [];
        wp_set_current_user(7);
        $this->assertSame('forbidden', Exports::change('save', $this->input()));
        $GLOBALS['meprmf_test_user_caps'][\MeprUtils::get_mepr_admin_capability()] = true;
        $_POST = array_merge($this->input(), [ 'operation' => 'save' ]);
        try { Exports::manage(); $this->fail('Missing nonce accepted'); }
        catch (\RuntimeException $error) { $this->assertSame('Invalid nonce', $error->getMessage()); }
        $this->assertSame('meprmf_export_schedule', $GLOBALS['export_nonce_action']);
        $this->assertSame([], Exports::schedules());
    }

    public function test_activation_and_hook_wiring()
    {
        Exports::init();
        $this->assertContains([ Exports::class, 'dispatch' ], $GLOBALS['meprmf_test_actions'][Exports::HOOK]);
        $this->assertContains([ Exports::class, 'download' ], $GLOBALS['meprmf_test_actions']['admin_post_nopriv_meprmf_export_download']);
        Exports::activate();
        $first = wp_next_scheduled(Exports::HOOK);
        Exports::activate();
        $this->assertSame($first, wp_next_scheduled(Exports::HOOK));
        Exports::deactivate();
        $this->assertFalse(wp_next_scheduled(Exports::HOOK));
        $this->create();
        Exports::deactivate();
        Exports::activate();
        $this->assertNotFalse(wp_next_scheduled(Exports::HOOK));
    }

    public function test_dispatch_fetches_saved_params_as_owner_and_attaches_csv_once()
    {
        $id = $this->create();
        $this->due($id);
        wp_set_current_user(8);
        $GLOBALS['export_during_mail'] = static function () { Exports::dispatch(); };
        Exports::dispatch();
        Exports::dispatch();
        $this->assertCount(1, $GLOBALS['export_fetches']);
        $this->assertSame([ 'memberpress_members', [ 'mpf_country' => 'DE', 's' => 'smith' ], 'smith', 0, 7, 'memberpress_members', 'DE' ], $GLOBALS['export_fetches'][0]);
        $this->assertSame(8, get_current_user_id());
        $this->assertNull(Meprmf_Screen::get_cli_context());
        $mail = $GLOBALS['export_mails'][0];
        $this->assertSame('owner@example.test', $mail['to']);
        foreach ([ 'Fixture site', 'Expiring members', '1 rows', gmdate('Y-m-d') ] as $part) {
            $this->assertStringContainsString($part, $mail['subject']);
        }
        foreach ([ 'Site: Fixture site', 'Preset: Expiring members', 'Rows: 1', 'Generated:' ] as $part) {
            $this->assertStringContainsString($part, $mail['body']);
        }
        $this->assertSame("ID,email\n12,member@example.test\n", $GLOBALS['export_mail_csv']);
        $this->assertSame(0600, $GLOBALS['export_mail_mode']);
        $this->assertStringEndsWith('.csv', $mail['attachments'][0]);
        $this->assertFileDoesNotExist($mail['attachments'][0]);
        $this->assertSame('sent', Exports::schedules()[$id]['status']);
    }

    public function test_csv_matches_fixture_byte_for_byte_and_handles_empty_results()
    {
        $rows = [ (object) [ 'ID' => 12, 'email' => 'member@example.test', 'note' => '=HYPERLINK("bad")' ],
            (object) [ 'ID' => 13, 'note' => "line one\nline two" ] ];
        $this->assertSame(file_get_contents(dirname(__DIR__) . '/fixtures/scheduled-export.csv'), Exports::csv($rows));
        $this->assertSame('', Exports::csv([]));
        $this->assertStringContainsString("'@bad", Exports::csv([ [ 'value' => '@bad' ] ]));
        $this->assertStringContainsString("' -12", Exports::csv([ [ 'value' => ' -12' ] ]));
    }

    public function test_large_export_uses_expiring_token_and_encrypted_public_storage()
    {
        add_filter('meprmf_export_attachment_threshold', static function () { return 0; });
        $id = $this->create();
        $this->due($id);
        Exports::dispatch();
        $mail = $GLOBALS['export_mails'][0];
        $runs = get_option(Exports::RUNS);
        $run = reset($runs);
        $this->assertSame([], $mail['attachments']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $run['token']);
        $this->assertEqualsWithDelta(time() + 604800, $run['expires'], 2);
        $this->assertStringContainsString('token=' . $run['token'], $mail['body']);
        $raw = file_get_contents($this->uploads . '/meprmf-exports/' . $run['file']);
        $this->assertStringNotContainsString('member@example.test', $raw);
        wp_set_current_user(0);
        $this->assertSame("ID,email\n12,member@example.test\n", Exports::read_download($run['token']));
        $this->assertNull(Exports::read_download(str_repeat('0', 64)));
        $this->assertNull(Exports::read_download([ $run['token'] ]));
        $runs[$run['token']]['expires'] = time();
        update_option(Exports::RUNS, $runs);
        $this->assertNull(Exports::read_download($run['token']));
        Exports::dispatch();
        $this->assertFileDoesNotExist($this->uploads . '/meprmf-exports/' . $run['file']);
        $this->assertSame([], get_option(Exports::RUNS));
    }

    /** @dataProvider failureModes */
    public function test_failures_are_logged_and_do_not_retry_the_occurrence($mode)
    {
        $id = $this->create();
        $this->due($id);
        if ('permission' === $mode) { $GLOBALS['export_caps'][7] = []; }
        if ('private' === $mode) { $this->preset(8); }
        if ('deleted' === $mode) { update_option(Meprmf_Presets::OPTION_KEY, []); }
        if ('disabled' === $mode) { update_option('meprmf_settings', [ 'enabled_screens' => [] ]); }
        if ('query' === $mode) { $GLOBALS['export_fetch_throw'] = true; }
        if ('predicate' === $mode) { $GLOBALS['export_predicates'] = false; }
        if ('mail' === $mode) {
            $GLOBALS['export_mail_result'] = false;
            add_filter('meprmf_export_attachment_threshold', static function () { return 0; });
        }
        Exports::dispatch();
        Exports::dispatch();
        $this->assertSame('failed', Exports::schedules()[$id]['status']);
        $this->assertCount(1, get_option(Exports::LOG));
        $this->assertStringNotContainsString('Sensitive', implode('', get_option(Exports::LOG)));
        $this->assertLessThanOrEqual(1, count($GLOBALS['export_fetches']));
        $this->assertEmpty(get_option(Exports::RUNS, []));
        $this->assertSame(7, get_current_user_id());
        $this->assertNull(Meprmf_Screen::get_cli_context());
    }

    public static function failureModes(): array
    {
        return array_map(static function ($mode) { return [ $mode ]; }, [ 'permission', 'private', 'deleted', 'disabled', 'query', 'predicate', 'mail' ]);
    }

    public function test_pause_future_and_live_lease_block_delivery_but_stale_lease_recovers()
    {
        $id = $this->create();
        Exports::dispatch();
        $this->assertSame([], $GLOBALS['export_fetches']);
        $this->due($id);
        update_option(Exports::LOCK, time() . ':another-worker');
        $this->assertSame('busy', Exports::change('delete', [ 'id' => $id ]));
        Exports::dispatch();
        $this->assertSame([], $GLOBALS['export_fetches']);
        update_option(Exports::LOCK, (time() - 3601) . ':crashed-worker');
        Exports::dispatch();
        $this->assertCount(1, $GLOBALS['export_mails']);
        $this->due($id);
        Exports::change('pause', [ 'id' => $id ]);
        Exports::dispatch();
        $this->assertCount(1, $GLOBALS['export_mails']);
    }

    public function test_calendar_boundaries()
    {
        $this->assertSame(strtotime('2026-12-01 09:00 UTC'), Exports::next_run('monthly', 0, strtotime('2026-11-30 23:00 UTC')));
        $this->assertSame(strtotime('2027-01-01 09:00 UTC'), Exports::next_run('monthly', 0, strtotime('2026-12-01 09:00 UTC')));
        $this->assertSame(strtotime('2026-11-16 09:00 UTC'), Exports::next_run('daily', 0, strtotime('2026-11-15 09:00 UTC')));
        $this->assertSame(strtotime('2026-11-22 09:00 UTC'), Exports::next_run('weekly', 0, strtotime('2026-11-15 09:00 UTC')));
        $GLOBALS['meprmf_test_timezone'] = 'America/New_York';
        $this->assertSame(strtotime('2026-03-08 13:00 UTC'), Exports::next_run('daily', 0, strtotime('2026-03-07 14:00 UTC')));
        $this->assertSame(strtotime('2026-11-01 14:00 UTC'), Exports::next_run('daily', 0, strtotime('2026-10-31 13:00 UTC')));
    }

    public function test_download_rejects_traversal_symlinks_and_tampering()
    {
        $run = Exports::store('secret csv');
        $path = $this->uploads . '/meprmf-exports/' . $run['file'];
        file_put_contents($path, 'tampered ciphertext');
        $this->assertNull(Exports::read_download($run['token']));
        unlink($path);
        symlink(__FILE__, $path);
        $this->assertNull(Exports::read_download($run['token']));
        unlink($path);
        $runs = get_option(Exports::RUNS);
        $runs[$run['token']]['file'] = '../../uninstall.php';
        update_option(Exports::RUNS, $runs);
        $this->assertNull(Exports::read_download($run['token']));
    }

    public function test_cleanup_retains_manifest_when_wordpress_blocks_file_deletion()
    {
        $run = Exports::store('csv');
        $path = $this->uploads . '/meprmf-exports/' . $run['file'];
        add_filter('wp_delete_file', static function ($file) { return ''; });
        Exports::cleanup(true);
        $this->assertFileExists($path);
        $this->assertArrayHasKey($run['token'], get_option(Exports::RUNS));

        $GLOBALS['meprmf_test_filters']['wp_delete_file'] = [];
        Exports::cleanup(true);
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], get_option(Exports::RUNS));
    }

    public function test_cleanup_is_bounded_and_delete_keeps_download_cleanup_scheduled()
    {
        $id = $this->create();
        $run = Exports::store('csv');
        Exports::change('delete', [ 'id' => $id ]);
        $this->assertNotFalse(wp_next_scheduled(Exports::HOOK));
        $runs = [];
        for ($i = 0; $i < 101; ++$i) {
            $runs[(string) $i] = [ 'file' => $run['file'], 'expires' => 1 ];
        }
        update_option(Exports::RUNS, $runs);
        Exports::dispatch();
        $this->assertCount(1, get_option(Exports::RUNS));
        Exports::dispatch();
        $this->assertSame([], get_option(Exports::RUNS));
        $this->assertFalse(wp_next_scheduled(Exports::HOOK));
    }

    public function test_ui_shows_only_readable_presets_and_own_recipient()
    {
        $this->create();
        ob_start(); Exports::render(); $html = ob_get_clean();
        foreach ([ 'Schedule export', 'weekly', 'daily', 'monthly', 'Weekday', 'owner@example.test', 'Pause', 'Delete schedule', '_wpnonce' ] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        wp_set_current_user(8);
        ob_start(); Exports::render(); $html = ob_get_clean();
        $this->assertStringNotContainsString('Expiring members', $html);
        $this->assertStringNotContainsString('owner@example.test', $html);
    }

    /** @dataProvider downloadModes */
    public function test_download_endpoint_returns_csv_or_empty_404($mode, $status, $body)
    {
        $process = proc_open([ PHP_BINARY, dirname(__DIR__) . '/fixtures/scheduled-export-download.php', $mode ],
            [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $this->assertSame('', $error);
        $this->assertSame([ 'body' => $body, 'status' => $status, 'nocache' => true ], json_decode($output, true));
    }

    public static function downloadModes(): array
    {
        return [ [ 'valid', 200, "ID\n12\n" ], [ 'unknown', 404, '' ], [ 'expired', 404, '' ], [ 'malformed', 404, '' ] ];
    }

    public function test_deleted_id_cannot_resolve_to_a_different_preset_with_the_same_name()
    {
        $id = $this->create();
        $this->due($id);
        $all = get_option(Meprmf_Presets::OPTION_KEY);
        $all['memberpress_members'][0]['id'] = 'p_other';
        $all['memberpress_members'][0]['name'] = 'p_fixture';
        update_option(Meprmf_Presets::OPTION_KEY, $all);
        Exports::dispatch();
        $this->assertSame([], $GLOBALS['export_fetches']);
        $this->assertSame('failed', Exports::schedules()[$id]['status']);
        $this->assertSame('not_found', Exports::change('save', $this->input()));
    }

    public function test_failed_attachment_mail_deletes_temporary_csv()
    {
        $id = $this->create();
        $this->due($id);
        $GLOBALS['export_mail_result'] = false;
        Exports::dispatch();
        $this->assertFileDoesNotExist($GLOBALS['export_mails'][0]['attachments'][0]);
        $this->assertSame('failed', Exports::schedules()[$id]['status']);
    }

    public function test_query_restores_ambient_request_and_nested_context_on_failure()
    {
        $id = $this->create();
        $this->due($id);
        $_GET = [ 'mpf_country' => 'US', 'mpf_other' => 'ambient', 'page' => 'elsewhere' ];
        $ctx = Meprmf_Screen::context_for_storage_id('memberpress_trans');
        Meprmf_Screen::set_cli_context($ctx);
        Meprmf_Util::push_request_overrides([ 'mpf_country' => 'outer' ]);
        $GLOBALS['export_fetch_throw'] = true;
        Exports::dispatch();
        $this->assertSame([ 'mpf_country' => 'US', 'mpf_other' => 'ambient', 'page' => 'elsewhere' ], $_GET);
        $this->assertSame($ctx, Meprmf_Screen::get_cli_context());
        $this->assertSame('outer', Meprmf_Util::get_request_value('mpf_country'));
    }

    /** @dataProvider thresholdSizes */
    public function test_default_attachment_threshold_boundary($bytes, $attached)
    {
        $id = $this->create();
        $this->due($id);
        $GLOBALS['export_rows'] = [ [ 'x' => str_repeat('a', $bytes - 3) ] ];
        Exports::dispatch();
        $this->assertSame($attached ? 1 : 0, count($GLOBALS['export_mails'][0]['attachments']));
        $this->assertSame('sent', Exports::schedules()[$id]['status']);
    }

    public static function thresholdSizes(): array
    {
        return [ [ 2 * 1024 * 1024, true ], [ 2 * 1024 * 1024 + 1, false ] ];
    }

    public function test_failed_run_log_is_bounded_and_disabled_views_remain_deletable()
    {
        $id = $this->create();
        $GLOBALS['export_mail_result'] = false;
        for ($i = 0; $i < 22; ++$i) {
            $this->due($id);
            Exports::dispatch();
        }
        $this->assertCount(20, get_option(Exports::LOG));
        update_option('meprmf_settings', [ 'enabled_screens' => [] ]);
        ob_start(); Exports::render(); $html = ob_get_clean();
        $this->assertStringContainsString('Delete schedule', $html);
        $this->assertSame('saved', Exports::change('delete', [ 'id' => $id ]));
    }

    public function test_retired_filter_fails_closed_instead_of_exporting_every_member()
    {
        $id = $this->create();
        $this->due($id);
        $all = get_option(Meprmf_Presets::OPTION_KEY);
        $all['memberpress_members'][0]['params'] = [ 'mpf_retired' => 'yes' ];
        update_option(Meprmf_Presets::OPTION_KEY, $all);
        Exports::dispatch();
        $this->assertSame([], $GLOBALS['export_fetches']);
        $this->assertSame('failed', Exports::schedules()[$id]['status']);
    }

    public function test_native_only_preset_with_match_mode_does_not_require_plugin_predicates()
    {
        $id = $this->create();
        $this->due($id);
        $all = get_option(Meprmf_Presets::OPTION_KEY);
        $all['memberpress_members'][0]['params'] = [ 'status' => 'active', Meprmf_Util::MATCH_MODE_PARAM => 'all' ];
        update_option(Meprmf_Presets::OPTION_KEY, $all);
        $GLOBALS['export_predicates'] = false;
        Meprmf_Util::push_request_overrides([ 'mpf_country' => 'US' ]);
        Exports::dispatch();
        $this->assertSame('sent', Exports::schedules()[$id]['status']);
        $this->assertSame($all['memberpress_members'][0]['params'], $GLOBALS['export_fetches'][0][1]);
        $this->assertSame('', $GLOBALS['export_fetches'][0][6]);
        $this->assertSame('US', Meprmf_Util::get_request_value('mpf_country'));
    }

    /** @dataProvider screens */
    public function test_due_schedule_uses_its_saved_screen($screen)
    {
        $presets = get_option(Meprmf_Presets::OPTION_KEY);
        update_option(Meprmf_Presets::OPTION_KEY, [ $screen => $presets['memberpress_members'] ]);
        $id = $this->create([ 'screen' => $screen ]);
        $this->due($id);
        Exports::dispatch();
        $this->assertSame($screen, $GLOBALS['export_fetches'][0][0]);
        $this->assertSame('sent', Exports::schedules()[$id]['status']);
    }

    public static function screens(): array
    {
        return [ [ 'memberpress_members' ], [ 'memberpress_subscriptions' ], [ 'memberpress_lifetimes' ], [ 'memberpress_trans' ] ];
    }
}
