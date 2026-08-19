<?php
require_once __DIR__ . '/config.php';

// Safely pull the header, ignoring getallheaders() bugs
$provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';

$SECRET_KEY = defined('FRAME_API_KEY') ? FRAME_API_KEY : getenv('FRAME_API_KEY');
if (empty($SECRET_KEY) || $provided_key !== $SECRET_KEY) {
    http_response_code(403);
    exit;
}

$STATE_FILE = __DIR__ . '/frame_state.json';
if (file_exists($STATE_FILE)) {
    $state = json_decode(file_get_contents($STATE_FILE), true);
    if (isset($state['pending_update']) && $state['pending_update'] === true) {
        echo "1";
        exit;
    }
}
echo "0";
