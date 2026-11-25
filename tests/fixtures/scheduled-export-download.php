<?php
/** Exercise the terminating admin-post endpoint in its own PHP process. */
require dirname(__DIR__) . '/bootstrap-unit.php';
require dirname(__DIR__, 2) . '/includes/class-meprmf-scheduled-exports.php';
$GLOBALS['export_uploads'] = dirname(__DIR__, 2) . '/.test-download-' . bin2hex(random_bytes(6));
$run = Meprmf_Scheduled_Exports::store("ID\n12\n");
$mode = $argv[1];
$_GET['token'] = $run['token'];
if ('expired' === $mode) {
    $runs = get_option(Meprmf_Scheduled_Exports::RUNS);
    $runs[$run['token']]['expires'] = time() - 1;
    update_option(Meprmf_Scheduled_Exports::RUNS, $runs);
} elseif ('unknown' === $mode) {
    $_GET['token'] = str_repeat('0', 64);
} elseif ('malformed' === $mode) {
    $_GET['token'] = [ 'bad' ];
} elseif ('decorated' === $mode) {
    $_GET['token'] = '<b>' . $run['token'] . '</b>';
} elseif ('newline' === $mode) {
    $_GET['token'] = $run['token'] . "\n";
} elseif ('missing' === $mode) {
    unset($_GET['token']);
}
ob_start();
register_shutdown_function(static function () {
    $body = ob_get_clean();
    Meprmf_Scheduled_Exports::cleanup(true);
    rmdir($GLOBALS['export_uploads'] . '/meprmf-exports');
    rmdir($GLOBALS['export_uploads']);
    echo json_encode([ 'body' => $body, 'status' => $GLOBALS['export_http_status'] ?? 200,
        'nocache' => $GLOBALS['export_nocache'] ?? false ]);
});
Meprmf_Scheduled_Exports::download();
