<?php
/** Scheduled exports of readable saved views. */
if (! defined('ABSPATH')) {
    exit;
}

class Meprmf_Scheduled_Exports
{
    const HOOK = 'meprmf_export_dispatch';
    const SCHEDULES = 'meprmf_export_schedules';
    const RUNS = 'meprmf_export_runs';
    const LOG = 'meprmf_export_log';
    const LOCK = 'meprmf_export_lock';
    const TTL = 604800;

    public static function init()
    {
        add_action(self::HOOK, [ __CLASS__, 'dispatch' ]);
        add_action('admin_menu', [ __CLASS__, 'menu' ], 20);
        add_action('admin_post_meprmf_export_schedule', [ __CLASS__, 'manage' ]);
        add_action('admin_post_meprmf_export_download', [ __CLASS__, 'download' ]);
        add_action('admin_post_nopriv_meprmf_export_download', [ __CLASS__, 'download' ]);
    }

    public static function activate()
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** Atomic database lease; the next occurrence is persisted before any delivery. */
    private static function lock()
    {
        $value = time() . ':' . bin2hex(random_bytes(16));
        $old = get_option(self::LOCK);
        if ($old && (int) $old < time() - 3600) {
            self::unlock($old);
        }
        return add_option(self::LOCK, $value, '', false) ? $value : false;
    }

    private static function unlock($value)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::LOCK,
            $value
        ));
        wp_cache_delete(self::LOCK, 'options');
    }

    public static function schedules()
    {
        return (array) get_option(self::SCHEDULES, []);
    }

    private static function preset($screen, $id)
    {
        foreach (Meprmf_Presets::get_presets_for_screen($screen) as $preset) {
            if ($preset['id'] === $id) {
                return $preset;
            }
        }
        return null;
    }

    /** All frequencies run at 09:00 site time; monthly uses the first day. */
    public static function next_run($frequency, $weekday, $now)
    {
        $date = (new DateTimeImmutable('@' . $now))->setTimezone(wp_timezone());
        $next = $date->setTime(9, 0);
        if ('monthly' === $frequency) {
            $next = $next->modify('first day of this month');
            if ($next->getTimestamp() <= $now) {
                $next = $next->modify('first day of next month');
            }
        } else {
            if ('weekly' === $frequency) {
                $next = $next->modify('+' . (($weekday - (int) $next->format('w') + 7) % 7) . ' days');
            }
            if ($next->getTimestamp() <= $now) {
                $next = $next->modify('weekly' === $frequency ? '+7 days' : '+1 day');
            }
        }
        return $next->getTimestamp();
    }

    /** A schedule belongs to its creator, independently of the view's owner. */
    public static function change($action, array $input)
    {
        if (! get_current_user_id() || ! Meprmf_Capabilities::current_user_can_filter()) {
            return 'forbidden';
        }
        $lease = self::lock();
        if (! $lease) {
            return 'busy';
        }
        try {
            $all = self::schedules();
            $id = (string) ($input['id'] ?? '');
            if ('' !== $id && (! isset($all[$id]) || $all[$id]['owner'] !== get_current_user_id())) {
                return 'not_found';
            }
            if (in_array($action, [ 'pause', 'delete' ], true)) {
                if ('' === $id) {
                    return 'not_found';
                }
                if ('delete' === $action) {
                    unset($all[$id]);
                } else {
                    $all[$id]['paused'] = true;
                }
            } elseif ('save' === $action) {
                $screen = (string) ($input['screen'] ?? '');
                $preset = self::preset($screen, (string) ($input['preset'] ?? ''));
                $ctx = Meprmf_Screen::context_for_storage_id($screen);
                $frequency = (string) ($input['frequency'] ?? '');
                $weekday = filter_var($input['weekday'] ?? 0, FILTER_VALIDATE_INT);
                $recipient = trim((string) ($input['recipient'] ?? ''));
                if ('' === $recipient) {
                    $recipient = (string) wp_get_current_user()->user_email;
                }
                if (! $preset || ! $ctx || ! $ctx->supports_meta_filters_list()) {
                    return 'not_found';
                }
                if (! in_array($frequency, [ 'daily', 'weekly', 'monthly' ], true)
                    || false === $weekday || $weekday < 0 || $weekday > 6
                    || preg_match('/[\r\n]/', $recipient) || ! is_email($recipient)) {
                    return 'invalid_input';
                }
                foreach ($all as $key => $row) {
                    if ($row['owner'] === get_current_user_id() && $row['screen'] === $screen && $row['preset'] === $preset['id']) {
                        if ('' !== $id && $id !== $key) {
                            return 'duplicate';
                        }
                        $id = $key;
                    }
                }
                if ('' === $id && count($all) >= 100) {
                    return 'limit_reached';
                }
                $id = '' === $id ? bin2hex(random_bytes(16)) : $id;
                $all[$id] = [
                    'owner' => get_current_user_id(), 'screen' => $screen, 'preset' => $preset['id'],
                    'frequency' => $frequency, 'weekday' => $weekday, 'recipient' => $recipient,
                    'paused' => false, 'next' => self::next_run($frequency, $weekday, time()),
                ];
            } else {
                return 'invalid_input';
            }
            if ($all !== self::schedules() && ! update_option(self::SCHEDULES, $all, false)) {
                return 'storage_failed';
            }
            // Keep cleanup alive while download files remain, even with no schedules.
            if ($all || get_option(self::RUNS, [])) {
                self::activate();
            } else {
                self::deactivate();
            }
            return 'saved';
        } finally {
            self::unlock($lease);
        }
    }

    public static function dispatch()
    {
        $lease = self::lock();
        if (! $lease) {
            return;
        }
        $original_user = get_current_user_id();
        try {
            self::cleanup();
            foreach (self::schedules() as $id => $row) {
                if (! empty($row['paused']) || $row['next'] > time()) {
                    continue;
                }
                if (time() >= (int) $lease + 3500) {
                    break;
                }
                // Claim before generation: a timeout or mail failure skips this occurrence.
                $all = self::schedules();
                $claimed_next = self::next_run($row['frequency'], $row['weekday'], time());
                $all[$id]['next'] = $claimed_next;
                $all[$id]['status'] = 'failed';
                if (! update_option(self::SCHEDULES, $all, false)) {
                    continue;
                }
                try {
                    wp_set_current_user($row['owner']);
                    self::deliver($row, $lease);
                    $status = 'sent';
                } catch (Throwable $error) {
                    $status = 'failed';
                    self::log_failure($id);
                } finally {
                    wp_set_current_user($original_user);
                }
                $all = self::schedules();
                if (isset($all[$id]) && $all[$id]['next'] === $claimed_next && time() < (int) $lease + 3500) {
                    $all[$id]['status'] = $status;
                    update_option(self::SCHEDULES, $all, false);
                }
            }
            if (! self::schedules() && ! get_option(self::RUNS, [])) {
                self::deactivate();
            }
        } finally {
            wp_set_current_user($original_user);
            self::unlock($lease);
        }
    }

    private static function log_failure($id)
    {
        $log = (array) get_option(self::LOG, []);
        // Never persist exception messages, member data, addresses, paths or tokens.
        array_unshift($log, gmdate('c') . ' Scheduled export ' . $id . ' failed; occurrence skipped.');
        update_option(self::LOG, array_slice($log, 0, 20), false);
    }

    private static function deliver(array $row, $lease)
    {
        if (! class_exists('MeprUtils')) {
            throw new RuntimeException('MemberPress unavailable.');
        }
        $ctx = Meprmf_Screen::context_for_storage_id($row['screen']);
        $preset = self::preset($row['screen'], $row['preset']);
        if (! get_current_user_id() || ! Meprmf_Capabilities::current_user_can_filter()
            || ! $ctx || ! $ctx->supports_meta_filters_list() || ! $preset
            || ! is_email($row['recipient']) || preg_match('/[\r\n]/', $row['recipient'])) {
            throw new RuntimeException('Export access denied.');
        }
        $params = (array) $preset['params'];
        $known = Meprmf_Presets::get_known_params_for_storage_id($row['screen']);
        $allowed = array_merge(Meprmf_Presets::request_filter_params($ctx), [ 's' ]);
        if (array_diff(array_keys($params), $allowed)) {
            throw new RuntimeException('Saved filters unavailable.');
        }
        $predicate_params = array_diff($known, [ Meprmf_Util::MATCH_MODE_PARAM ]);
        $previous_context = Meprmf_Screen::get_cli_context();
        $previous_get = $_GET;
        $_GET = $params;
        Meprmf_Screen::set_cli_context($ctx);
        Meprmf_Util::push_request_overrides(array_merge(array_fill_keys($allowed, ''), $params));
        try {
            $match = Meprmf_Bulk_Match_Set::fetch($ctx, $params, (string) ($params['s'] ?? ''));
            if (null === $match || (Meprmf_Presets::request_asks_for_filters($params, $predicate_params)
                && ! Meprmf_Bulk_Match_Set::has_active_predicates())) {
                throw new RuntimeException('Export query unavailable.');
            }
        } finally {
            Meprmf_Util::pop_request_overrides();
            Meprmf_Screen::set_cli_context($previous_context);
            $_GET = $previous_get;
        }
        if (time() >= (int) $lease + 3500) {
            throw new RuntimeException('Export lease expired.');
        }
        $csv = self::csv($match['results']);
        $site = sanitize_text_field(get_bloginfo('name'));
        $name = sanitize_text_field($preset['name']);
        $generated = wp_date('Y-m-d H:i:s T', time());
        $subject = sprintf('%s: %s (%d rows, %s)', $site, $name, $match['rows'], $generated);
        $body = sprintf("Preset: %s\nSite: %s\nRows: %d\nGenerated: %s\n", $name, $site, $match['rows'], $generated);
        $attachments = [];
        $temporary = null;
        $run = null;
        try {
            if (strlen($csv) > max(0, (int) apply_filters('meprmf_export_attachment_threshold', 2 * 1024 * 1024))) {
                $run = self::store($csv);
                $body .= "\n" . add_query_arg([ 'action' => 'meprmf_export_download', 'token' => $run['token'] ], admin_url('admin-post.php'))
                    . "\n" . __('Download expires in 7 days. Anyone with this link can download the CSV.', 'admin-filters-for-memberpress');
            } else {
                $temporary = self::attachment($csv);
                $attachments[] = $temporary;
            }
            if (! wp_mail($row['recipient'], $subject, $body, [], $attachments)) {
                throw new RuntimeException('Export mail failed.');
            }
        } catch (Throwable $error) {
            if ($run) {
                self::remove_run($run['token']);
            }
            throw $error;
        } finally {
            if ($temporary) {
                unlink($temporary);
            }
        }
    }

    /** Columns follow the list-table rows, including keys absent from the first row. */
    public static function csv(array $rows)
    {
        $columns = [];
        foreach ($rows as $row) {
            $columns = array_values(array_unique(array_merge($columns, array_keys((array) $row))));
        }
        $stream = fopen('php://memory', 'w+');
        if (false === $stream) {
            throw new RuntimeException('CSV stream unavailable.');
        }
        try {
            if ($columns) {
                if (false === fputcsv($stream, array_map([ __CLASS__, 'cell' ], $columns), ',', '"', '')) {
                    throw new RuntimeException('CSV write failed.');
                }
            }
            foreach ($rows as $row) {
                $values = (array) $row;
                $written = fputcsv($stream, array_map(static function ($column) use ($values) {
                    return self::cell($values[$column] ?? '');
                }, $columns), ',', '"', '');
                if (false === $written) {
                    throw new RuntimeException('CSV write failed.');
                }
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if (false === $csv) {
                throw new RuntimeException('CSV read failed.');
            }
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private static function cell($value)
    {
        $value = is_scalar($value) ? str_replace("\0", '', (string) $value) : '';
        return preg_match('/^[\s\x00-\x20]*[=+@-]/', $value) || preg_match('/^[\t\r\n]/', $value) ? "'" . $value : $value;
    }

    private static function directory()
    {
        $uploads = wp_upload_dir();
        if (! empty($uploads['error'])) {
            throw new RuntimeException('Uploads unavailable.');
        }
        $dir = $uploads['basedir'] . '/meprmf-exports';
        if (is_link($dir) || (! is_dir($dir) && ! wp_mkdir_p($dir))) {
            throw new RuntimeException('Export directory unavailable.');
        }
        return $dir;
    }

    private static function path($file)
    {
        if (! is_string($file) || ! preg_match('/^[a-f0-9]{64}\.bin$/D', $file)) {
            throw new RuntimeException('Invalid export path.');
        }
        $path = self::directory() . '/' . $file;
        if (is_link($path)) {
            throw new RuntimeException('Invalid export path.');
        }
        return $path;
    }

    private static function attachment($csv)
    {
        $dir = realpath(sys_get_temp_dir());
        if (! $dir) {
            throw new RuntimeException('Private temporary directory unavailable.');
        }
        $uploads = wp_upload_dir();
        foreach ([ ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH, $uploads['basedir'] ] as $public) {
            $public = realpath($public);
            if ($public && ($dir === $public || strpos($dir . '/', rtrim($public, '/') . '/') === 0)) {
                throw new RuntimeException('Private temporary directory unavailable.');
            }
        }
        $path = tempnam($dir, 'meprmf-');
        if (! $path) {
            throw new RuntimeException('Temporary file unavailable.');
        }
        if (! rename($path, $path . '.csv')) {
            unlink($path);
            throw new RuntimeException('Temporary file unavailable.');
        }
        $path .= '.csv';
        try {
            if (! chmod($path, 0600) || file_put_contents($path, $csv) !== strlen($csv)) {
                throw new RuntimeException('Export write failed.');
            }
            return $path;
        } catch (Throwable $error) {
            unlink($path);
            throw $error;
        }
    }

    /** Encrypt even on servers that ignore .htaccess; public uploads never contain raw CSV. */
    public static function store($csv)
    {
        if (! function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL unavailable.');
        }
        $token = bin2hex(random_bytes(32));
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($csv, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $cipher) {
            throw new RuntimeException('Encryption failed.');
        }
        $file = bin2hex(random_bytes(32)) . '.bin';
        $path = self::path($file);
        $run = [ 'token' => $token, 'file' => $file, 'expires' => time() + self::TTL ];
        $runs = (array) get_option(self::RUNS, []);
        // Persist the manifest first so interrupted writes are still eligible for cleanup.
        $runs[$token] = $run;
        if (! update_option(self::RUNS, $runs, false)) {
            throw new RuntimeException('Export manifest unavailable.');
        }
        if (file_put_contents($path, $iv . $tag . $cipher, LOCK_EX) !== strlen($iv . $tag . $cipher)) {
            self::remove_run($token);
            throw new RuntimeException('Export write failed.');
        }
        return $run;
    }

    /** Unknown, expired, malformed or tampered exports all produce the same missing result. */
    public static function read_download($token)
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $runs = (array) get_option(self::RUNS, []);
        $run = $runs[$token] ?? null;
        if (! $run || ! hash_equals($run['token'], $token) || $run['expires'] <= time()) {
            return null;
        }
        try {
            $path = self::path($run['file']);
            $data = is_file($path) ? @file_get_contents($path) : false;
            if (false === $data || strlen($data) < 28 || ! function_exists('openssl_decrypt')) {
                return null;
            }
            $csv = openssl_decrypt(substr($data, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true),
                OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
            return false === $csv ? null : $csv;
        } catch (Throwable $error) {
            return null;
        }
    }

    public static function download()
    {
        $csv = self::read_download($_GET['token'] ?? null);
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        if (null === $csv) {
            status_header(404);
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="memberpress-export.csv"');
        header('X-Content-Type-Options: nosniff');
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV attachment.
        exit;
    }

    private static function remove_run($token)
    {
        $runs = (array) get_option(self::RUNS, []);
        if (isset($runs[$token])) {
            $path = self::path($runs[$token]['file']);
            if (is_file($path) && ! unlink($path)) {
                return;
            }
            unset($runs[$token]);
            update_option(self::RUNS, $runs, false);
        }
    }

    public static function cleanup($all = false)
    {
        $count = 0;
        foreach ((array) get_option(self::RUNS, []) as $token => $run) {
            if ($all || $run['expires'] <= time()) {
                if (! $all && ++$count > 100) {
                    break;
                }
                try {
                    self::remove_run($token);
                } catch (Throwable $error) {
                    self::log_failure('cleanup');
                }
            }
        }
    }

    public static function menu()
    {
        if (! class_exists('MeprUtils')) {
            return;
        }
        add_submenu_page('memberpress', __('Scheduled exports', 'admin-filters-for-memberpress'),
            __('Scheduled exports', 'admin-filters-for-memberpress'), MeprUtils::get_mepr_admin_capability(),
            'meprmf-exports', [ __CLASS__, 'render' ]);
    }

    public static function manage()
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            wp_die('Forbidden', '', [ 'response' => 403 ]);
        }
        check_admin_referer('meprmf_export_schedule');
        $input = [];
        foreach ([ 'id', 'screen', 'preset', 'frequency', 'weekday', 'recipient', 'operation' ] as $key) {
            $input[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        }
        $result = self::change($input['operation'], $input);
        wp_safe_redirect(add_query_arg([ 'page' => 'meprmf-exports', 'result' => $result ], admin_url('admin.php')));
        exit;
    }

    public static function render()
    {
        if (! Meprmf_Capabilities::current_user_can_filter()) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Scheduled exports', 'admin-filters-for-memberpress') . '</h1>';
        echo '<p>' . esc_html__('Exports run at 9 AM in the site timezone, when WP-Cron next runs. Monthly exports run on the first day. Saving resumes a paused schedule. Download links expire after 7 days.', 'admin-filters-for-memberpress') . '</p>';
        if (isset($_GET['result']) && is_string($_GET['result'])) {
            echo '<p role="status">' . esc_html('saved' === $_GET['result'] ? __('Schedule updated.', 'admin-filters-for-memberpress') : __('Schedule could not be updated. Check the fields or try again.', 'admin-filters-for-memberpress')) . '</p>';
        }
        foreach (Meprmf_Screen::supported_page_contexts() as $ctx) {
            if (! $ctx->supports_meta_filters_list()) {
                continue;
            }
            foreach (Meprmf_Presets::get_presets_for_screen($ctx->get_storage_id()) as $preset) {
                self::form($ctx, $preset);
            }
        }
        // Stale schedules remain removable after a view is deleted or made private.
        foreach (self::schedules() as $id => $row) {
            $ctx = Meprmf_Screen::context_for_storage_id($row['screen']);
            if ($row['owner'] === get_current_user_id() && (! $ctx || ! $ctx->supports_meta_filters_list()
                || ! self::preset($row['screen'], $row['preset']))) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                self::hidden('action', 'meprmf_export_schedule');
                self::hidden('id', $id);
                wp_nonce_field('meprmf_export_schedule');
                echo '<p>' . esc_html__('Unavailable saved view', 'admin-filters-for-memberpress') . ' <button class="button" name="operation" value="delete">' . esc_html__('Delete schedule', 'admin-filters-for-memberpress') . '</button></p></form>';
            }
        }
        echo '</div>';
    }

    private static function hidden($name, $value)
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    private static function form($ctx, $preset)
    {
        $id = '';
        $row = [ 'frequency' => 'weekly', 'weekday' => 1, 'recipient' => wp_get_current_user()->user_email ];
        foreach (self::schedules() as $key => $schedule) {
            if ($schedule['owner'] === get_current_user_id() && $schedule['screen'] === $ctx->get_storage_id() && $schedule['preset'] === $preset['id']) {
                $id = $key;
                $row = $schedule;
                break;
            }
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><fieldset><legend><h2>'
            . esc_html($preset['name'] . ' (' . $ctx->get_page() . ')') . '</h2></legend>';
        foreach ([ 'action' => 'meprmf_export_schedule', 'id' => $id, 'screen' => $ctx->get_storage_id(), 'preset' => $preset['id'] ] as $key => $value) {
            self::hidden($key, $value);
        }
        wp_nonce_field('meprmf_export_schedule');
        echo '<p><label>' . esc_html__('Frequency', 'admin-filters-for-memberpress') . ' <select name="frequency">';
        foreach ([ 'daily' => __('Daily', 'admin-filters-for-memberpress'), 'weekly' => __('Weekly', 'admin-filters-for-memberpress'), 'monthly' => __('Monthly', 'admin-filters-for-memberpress') ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($row['frequency'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <label>' . esc_html__('Weekday (weekly only)', 'admin-filters-for-memberpress') . ' <select name="weekday">';
        for ($day = 0; $day < 7; ++$day) {
            echo '<option value="' . $day . '"' . selected($row['weekday'], $day, false) . '>' . esc_html(wp_date('l', strtotime('Sunday +' . $day . ' days'), new DateTimeZone('UTC'))) . '</option>';
        }
        echo '</select></label> <label>' . esc_html__('Recipient email', 'admin-filters-for-memberpress')
            . ' <input type="email" name="recipient" required value="' . esc_attr($row['recipient']) . '"></label></p>';
        echo '<p><button class="button button-primary" name="operation" value="save">' . esc_html__('Schedule export', 'admin-filters-for-memberpress') . '</button> ';
        if ('' !== $id) {
            echo '<button class="button" name="operation" value="pause" formnovalidate>' . esc_html__('Pause', 'admin-filters-for-memberpress') . '</button> '
                . '<button class="button" name="operation" value="delete" formnovalidate>' . esc_html__('Delete schedule', 'admin-filters-for-memberpress') . '</button> ';
            echo esc_html(! empty($row['paused']) ? __('Paused', 'admin-filters-for-memberpress') : wp_date('Y-m-d H:i T', $row['next']));
            echo ' ' . esc_html($row['status'] ?? '');
        }
        echo '</p></fieldset></form>';
    }
}
