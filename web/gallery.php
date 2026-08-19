<?php
// Make sure config.php is loaded so we can access HARDWARE_DOMAIN
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// 1. Pull the secret key (Check config.php first, fallback to Docker)
$SECRET_KEY = defined('FRAME_API_KEY') ? FRAME_API_KEY : getenv('FRAME_API_KEY');

// 2. Read the hidden HTTP header sent by the ESP32
$requestKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

// 3. If the key is missing or wrong, lock down the script immediately
if (empty($SECRET_KEY) || $requestKey !== $SECRET_KEY) {
    http_response_code(403);
    die("❌ 403 Forbidden: Server key empty or mismatch.");
}

// Force the ESP32 to strictly use the admin-frame domain
if ($_SERVER['HTTP_HOST'] !== HARDWARE_DOMAIN) {
    http_response_code(404);
    die("❌ 404 Not found");
}

// Catch telemetry data from the ESP32
$v = isset($_GET['v']) ? floatval($_GET['v']) : 0;
$state = isset($_GET['state']) ? intval($_GET['state']) : 0;

// Save telemetry to json for the web dashboard
if ($v > 0) {
    $battery_data = [
        'voltage' => $v,
        'timestamp' => time(),
        'state' => $state
    ];
    file_put_contents(__DIR__ . '/battery.json', json_encode($battery_data));
}

// ==========================================
// NEW: Clear the pending update flag
// ==========================================
$STATE_FILE = __DIR__ . '/frame_state.json';
if (file_exists($STATE_FILE)) {
    $state_data = json_decode(file_get_contents($STATE_FILE), true);
    if (isset($state_data['pending_update']) && $state_data['pending_update'] === true) {
        $state_data['pending_update'] = false;
        file_put_contents($STATE_FILE, json_encode($state_data));
    }
}
// ==========================================

$READY_FILE = __DIR__ . '/ready_for_frame.png';

// Instead of dying, flag that the photo is missing so we can build a placeholder
$is_missing_photo = !file_exists($READY_FILE);

// Ensure the ESP32 never caches the image
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// STATE 0 & PHOTO EXISTS: Normal operation. Serve the image as-is instantly.
if ($state == 0 && !$is_missing_photo) {
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($READY_FILE));
    readfile($READY_FILE);
    exit;
}

// STATE > 0 OR MISSING PHOTO: Draw dynamic UI overlay.
if ($is_missing_photo) {
    // Create a blank canvas matching the panel resolution (1200x1600 based on your ImageMagick sizing)
    $img = imagecreatetruecolor(1200, 1600);
    $white_bg = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $white_bg);
} else {
    $img = imagecreatefrompng($READY_FILE);
}

// E-Ink physical pigment palette (Matches prepare_photo.php)
$black = imagecolorallocate($img, 0, 0, 0);
$fake_black = imagecolorallocate($img, 0, 0, 1);
$white = imagecolorallocate($img, 255, 255, 255);
$red = imagecolorallocate($img, 200, 32, 32); // Realistic Red (0xC904)
$yellow = imagecolorallocate($img, 229, 184, 11); // Realistic Yellow (0xE5C1)
$green = imagecolorallocate($img, 0, 120, 44); // Realistic Green (0x03C5)

// Turn OFF anti-aliasing
$black_text = -$fake_black;
$white_text = -$white;

// Set message, background color, and text color based on state
$line1 = "";
$line2 = "";
$bg_color = $yellow; // Default
$text_color = $black_text; // Default

// Override the messaging if the backend hasn't generated a photo yet
if ($is_missing_photo) {
    $line1 = "SYSTEM READY";
    $line2 = "Waiting for first photo sync.";
    $bg_color = $yellow;
    $text_color = $black_text;
} else {
    if ($state == 1) {
        $line1 = "LOW BATTERY";
        $line2 = "Please recharge soon.";
        $bg_color = $red;
        $text_color = $white_text;
    }
    if ($state == 2) {
        $line1 = "CHARGING...";
        $line2 = "Leave plugged in.";
        $bg_color = $yellow;
        $text_color = $black_text;
    }
    if ($state == 3) {
        $line1 = "FULLY CHARGED";
        $line2 = "Please unplug.";
        $bg_color = $green;
        $text_color = $white_text;
    }
}

// 1. Draw a thin, elegant 2px Black outer border (Width: 560, Height: 140)
imagefilledrectangle($img, 20, 20, 580, 160, $black);

// 2. Draw the colored inner background, leaving just the 2px border
imagefilledrectangle($img, 22, 22, 578, 158, $bg_color);

// 3. Draw the text with dialed-back sizes and NO anti-aliasing
$font_path = __DIR__ . '/OpenSans-Medium.ttf'; // Ensure this matches your actual filename
$font_size_1 = 36;
$font_size_2 = 24;

if (file_exists($font_path)) {
    // Mathematically center Line 1 horizontally
    $bbox1 = imagettfbbox($font_size_1, 0, $font_path, $line1);
    $text_width_1 = $bbox1[2] - $bbox1[0];
    $x1 = 22 + ((556 - $text_width_1) / 2);

    // Mathematically center Line 2 horizontally
    $bbox2 = imagettfbbox($font_size_2, 0, $font_path, $line2);
    $text_width_2 = $bbox2[2] - $bbox2[0];
    $x2 = 22 + ((556 - $text_width_2) / 2);

    // Draw the razor-sharp text
    imagettftext($img, $font_size_1, 0, (int)$x1, 80, $text_color, $font_path, $line1);
    imagettftext($img, $font_size_2, 0, (int)$x2, 135, $text_color, $font_path, $line2);
} else {
    imagestring($img, 5, 168, 50, $line1, $black);
    imagestring($img, 5, 48, 98, $line2, $black);
}

// --- OUTPUT BUFFERING BLOCK ---
ob_start();
imagepng($img);
$image_data = ob_get_clean();

header('Content-Type: image/png');
header('Content-Length: ' . strlen($image_data));

echo $image_data;
imagedestroy($img);
exit;
