<?php
// Block all web browsers! Only allow execution via local command line (Cron / Docker exec)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("❌ 403 Forbidden: Direct web access is not allowed.");
}

$flag_file = __DIR__ . '/photos/manual_request.txt';
$state_file = __DIR__ . '/frame_state.json';
$log_file = __DIR__ . '/photos/debug.txt';

if (file_exists($flag_file)) {
    // Read the requested file and throw away the note
    $target = trim(file_get_contents($flag_file));
    unlink($flag_file);

    // Write a header to our debug log
    file_put_contents($log_file, "=== NEW REQUEST: " . date('H:i:s') . " ===\n");
    file_put_contents($log_file, "Target requested: '" . $target . "'\n\n", FILE_APPEND);

    // Run ImageMagick as Root and force it to log everything to debug.txt
    if ($target === 'SHUFFLE') {
        exec("php " . escapeshellarg(__DIR__ . "/prepare_photo.php") . " >> " . escapeshellarg($log_file) . " 2>&1");
    } else {
        exec("php " . escapeshellarg(__DIR__ . "/prepare_photo.php") . " " . escapeshellarg($target) . " >> " . escapeshellarg($log_file) . " 2>&1");
    }

    // Tell the frame a new photo is ready
    $state = ['current_image' => $target, 'pending_update' => true];
    file_put_contents($state_file, json_encode($state));
    chmod($state_file, 0666);
}
