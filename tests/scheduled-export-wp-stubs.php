<?php
/** WordPress boundaries for scheduled-export tests. No network or mail is used. */
function wp_next_scheduled($hook) { return $GLOBALS['export_cron'][$hook] ?? false; }
function wp_schedule_event($time, $frequency, $hook) {
    $GLOBALS['export_cron'][$hook] = $time;
    $GLOBALS['export_cron_frequency'] = $frequency;
    return true;
}
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['export_cron'][$hook]); return 1; }
function add_option($key, $value, $deprecated = '', $autoload = true) {
    if (array_key_exists($key, $GLOBALS['meprmf_test_options'])) { return false; }
    $GLOBALS['meprmf_test_options'][$key] = $value;
    return true;
}
function wp_cache_delete($key, $group = '') { return true; }
function wp_set_current_user($id) {
    $GLOBALS['meprmf_test_current_user_id'] = (int) $id;
    $GLOBALS['meprmf_test_user_caps'] = $GLOBALS['export_caps'][$id] ?? [];
}
function wp_get_current_user() { return (object) [ 'user_email' => 'owner@example.test' ]; }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function wp_upload_dir() { return [ 'basedir' => $GLOBALS['export_uploads'], 'error' => false ]; }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0700, true); }
function wp_delete_file($file) {
    $file = apply_filters('wp_delete_file', $file);
    if ($file) { @unlink($file); }
}
function wp_salt($scheme) { return 'fixture-secret-not-a-real-wordpress-salt'; }
function get_bloginfo($key) { return 'Fixture site'; }
function wp_mail($to, $subject, $body, $headers, $attachments) {
    $GLOBALS['export_mails'][] = compact('to', 'subject', 'body', 'headers', 'attachments');
    foreach ($attachments as $path) {
        $GLOBALS['export_mail_csv'] = file_get_contents($path);
        $GLOBALS['export_mail_mode'] = fileperms($path) & 0777;
    }
    if (isset($GLOBALS['export_during_mail'])) { ($GLOBALS['export_during_mail'])(); }
    return $GLOBALS['export_mail_result'] ?? true;
}
function check_admin_referer($action) {
    $GLOBALS['export_nonce_action'] = $action;
    if (empty($GLOBALS['export_nonce_valid'])) { throw new RuntimeException('Invalid nonce'); }
}
function wp_nonce_field($action) { echo '<input name="_wpnonce" value="fixture">'; }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException($message); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function selected($value, $current, $echo = true) {
    $html = (string) $value === (string) $current ? ' selected="selected"' : '';
    if ($echo) { echo $html; }
    return $html;
}
function status_header($code) { $GLOBALS['export_http_status'] = $code; }
function nocache_headers() { $GLOBALS['export_nocache'] = true; }
