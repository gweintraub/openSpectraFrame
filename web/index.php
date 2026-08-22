<?php
// ==========================================
// 1. CONFIGURATION: Load config.php first
// ==========================================
if (!file_exists(__DIR__ . '/config.php')) {
    die("⚠️ Configuration Error: 'config.php' is missing from the data folder.");
}
require_once __DIR__ . '/config.php';

$FAMILY_PASSWORD = PORTAL_PASSWORD;

// ==========================================
// 2. DOMAIN REDIRECT
// ==========================================
if ($_SERVER['HTTP_HOST'] === HARDWARE_DOMAIN) {
    header("Location: " . HUMAN_UI_URL, true, 301);
    exit;
}

$UPLOAD_DIR = __DIR__ . '/photos';

if (!is_dir($UPLOAD_DIR)) {
    if (!@mkdir($UPLOAD_DIR, 0777, true)) {
        die("⚠️ Permissions Error: PHP cannot create the /photos directory.");
    }
}

// ==========================================
// 3. BULLETPROOF & SECURE SESSIONS
// ==========================================
$SESSION_DIR = __DIR__ . '/sessions';

if (!is_dir($SESSION_DIR)) {
    @mkdir($SESSION_DIR, 0777, true);

    // SECURITY: Drop a padlock file in this folder so web browsers cannot read your session files
    file_put_contents($SESSION_DIR . '/.htaccess', "Deny from all\n");
}

// 1. Save login files in our private folder
ini_set('session.save_path', $SESSION_DIR);

// 2. Keep files alive for 30 days (2592000 seconds)
ini_set('session.gc_maxlifetime', 2592000);

// 3. Harden the session cookie (Secure, HttpOnly, SameSite)
session_set_cookie_params([
    'lifetime' => 2592000,
    'path' => '/',
    'secure' => true, // Only send over HTTPS (Cloudflare)
    'httponly' => true, // Hide from JavaScript
    'samesite' => 'Strict' // Prevent CSRF attacks
]);

session_start();

if (isset($_POST['logout'])) {
    unset($_SESSION['authenticated']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ==========================================
// LOGIN RATE LIMITING
// ==========================================
// Cloudflare Tunnel terminates the real connection, so REMOTE_ADDR here would
// just be cloudflared's own address for every visitor. Use the header Cloudflare
// sets with the real client IP, and only trust it because ingress is tunnel-only
// (no port is exposed directly to the internet, so this header can't be spoofed
// by an outside attacker hitting nginx directly).
function get_client_ip()
{
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$ATTEMPTS_FILE = __DIR__ . '/login_attempts.json';
$MAX_ATTEMPTS = 5;
$LOCKOUT_SECONDS = 300; // 5 minutes

$client_ip = get_client_ip();
$attempts = file_exists($ATTEMPTS_FILE) ? json_decode(file_get_contents($ATTEMPTS_FILE), true) : [];
if (!is_array($attempts)) $attempts = [];

// Prune anything for this IP whose lockout window has already expired,
// so the file doesn't grow forever and old strikes don't linger.
if (isset($attempts[$client_ip]) && (time() - $attempts[$client_ip]['first_attempt']) > $LOCKOUT_SECONDS) {
    unset($attempts[$client_ip]);
}

$is_locked_out = isset($attempts[$client_ip]) && $attempts[$client_ip]['count'] >= $MAX_ATTEMPTS;
$seconds_remaining = $is_locked_out
    ? max(0, $LOCKOUT_SECONDS - (time() - $attempts[$client_ip]['first_attempt']))
    : 0;

if ($is_locked_out && $seconds_remaining > 0) {
    http_response_code(429);
    $wait_minutes = ceil($seconds_remaining / 60);
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><link rel="icon" type="image/png" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>"><link rel="apple-touch-icon" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="Frame"><style>body{background:#1c1c1e;color:#fff;font-family:sans-serif;}</style></head><body>';
    echo '<div style="max-width:400px;margin:100px auto;text-align:center;padding:20px;border:1px solid #ff453a;background:#2c1414;border-radius:12px;">';
    echo '<h2 style="color:#ff453a;margin-top:0;font-weight:400;letter-spacing:-0.5px;">🔒 Too Many Attempts</h2>';
    echo '<p style="color:#eaeaea;font-size:14px;line-height:1.5;">Try again in about ' . $wait_minutes . ' minute(s).</p>';
    echo '</div></body></html>';
    exit;
}

if (isset($_POST['password'])) {
    if (hash_equals($FAMILY_PASSWORD, $_POST['password'])) {
        // Success — clear any strikes for this IP
        unset($attempts[$client_ip]);
        file_put_contents($ATTEMPTS_FILE, json_encode($attempts));

        $_SESSION['authenticated'] = true;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        // Failure — record a strike
        if (!isset($attempts[$client_ip])) {
            $attempts[$client_ip] = ['count' => 0, 'first_attempt' => time()];
        }
        $attempts[$client_ip]['count']++;
        file_put_contents($ATTEMPTS_FILE, json_encode($attempts));
    }
}

// ==========================================
// HALT 1: Config Error Screen
// ==========================================
if (isset($config_error) && $config_error) {
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0">    <link rel="icon" type="image/png" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>"><link rel="apple-touch-icon" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="Frame"><style>body{background:#1c1c1e;color:#fff;font-family:sans-serif;}</style></head><body>';
    echo '<div style="max-width:400px;margin:100px auto;text-align:center;padding:20px;border:1px solid #ff453a;background:#2c1414;border-radius:12px;">';
    echo '<h2 style="color:#ff453a;margin-top:0;font-weight:400;letter-spacing:-0.5px;">⚠️ Configuration Error</h2>';
    echo '<p style="color:#eaeaea;font-size:14px;line-height:1.5;">The <strong>PORTAL_PASSWORD</strong> environment variable is missing.</p>';
    echo '</div></body></html>';
    exit;
}

// ==========================================
// HALT 2: Login Screen
// ==========================================
if (!isset($_SESSION['authenticated'])) {
    // 1. Figure out the name before we start generating the HTML
    $display_name = defined('FRAME_NAME') ? htmlspecialchars(FRAME_NAME) : 'Spectra Frame';

    // 2. Stitch the $display_name variable into the HTML string using ' . $variable . '
    echo '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>">
    <link rel="apple-touch-icon" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Frame">
<title>' . $display_name . '</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #000; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; color: #f5f5f7; } .login-card { background: #1c1c1e; padding: 40px 30px; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); width: 100%; max-width: 340px; text-align: center; border: 1px solid #2c2c2e; } h2 { margin: 0 0 8px 0; font-size: 24px; color: #fff; font-weight: 400; letter-spacing: -0.5px;} p.sub { color: #8e8e93; font-size: 14px; margin-bottom: 24px;} input[type="password"] { width: 100%; padding: 16px; margin-bottom: 16px; background: #2c2c2e; border: 1px solid #3a3a3c; border-radius: 12px; font-size: 16px; color: #fff; box-sizing: border-box; outline: none; transition: border 0.2s;} input[type="password"]:focus { border-color: #0a84ff; } button { width: 100%; background: #0a84ff; color: white; border: none; padding: 16px; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; } button:hover { background: #0071e3; }
</style>
</head>
<body>
<div class="login-card">
<h2>' . $display_name . '</h2>
<p class="sub">Enter the password to add photos</p>
<form method="POST"><input type="password" name="password" placeholder="Password" autofocus>
<button type="submit">Unlock</button></form></div>
</body>
</html>';
    exit;
}

// ==========================================
// 3. SECURE AUTHENTICATED AREA
// ==========================================

if (isset($_POST['delete_file'])) {
    $target_file = realpath($UPLOAD_DIR . '/' . basename($_POST['delete_file']));
    if ($target_file && strpos($target_file, realpath($UPLOAD_DIR)) === 0 && is_file($target_file)) {
        unlink($target_file);

        if (isset($_POST['is_ajax'])) {
            echo "OK";
            exit;
        }

        $_SESSION['flash'] = "Photo removed from the Frame.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_POST['is_ajax'])) {
        http_response_code(400);
        echo "Error";
        exit;
    }
}

// ==========================================
// Handle Shuffle, Set Image & Read Current Status
// ==========================================
$MANUAL_REQ_FILE = __DIR__ . '/photos/manual_request.txt';
$HISTORY_FILE = __DIR__ . '/history.json';
$current_on_frame = '';

$req = file_exists($MANUAL_REQ_FILE) ? trim(file_get_contents($MANUAL_REQ_FILE)) : 'SHUFFLE';

if (!empty($req) && $req !== 'SHUFFLE') {
    $current_on_frame = strtolower(basename($req));
} else {
    if (file_exists($HISTORY_FILE)) {
        $history = @json_decode(file_get_contents($HISTORY_FILE), true);
        if (is_array($history) && !empty($history)) {
            $last_img = end($history);
            $current_on_frame = strtolower(basename($last_img));
        }
    }
}

// Polling endpoint for the Javascript dynamic badge updates
if (isset($_GET['get_current_photo'])) {
    echo $current_on_frame;
    exit;
}

if (isset($_POST['shuffle_image'])) {
    // 1. The Web UI picks the random image instantly
    $jpgs = glob($UPLOAD_DIR . '/*.jpg') ?: [];
    $jpegs = glob($UPLOAD_DIR . '/*.jpeg') ?: [];
    $all_files = array_merge($jpgs, $jpegs);

    if (!empty($all_files)) {
        $history = file_exists($HISTORY_FILE) ? @json_decode(file_get_contents($HISTORY_FILE), true) : [];
        if (!is_array($history)) $history = [];

        $available = array_diff($all_files, $history);
        if (empty($available)) {
            $available = $all_files;
        }

        $available = array_values($available);
        $chosen = basename($available[array_rand($available)]);

        // 2. Write the exact chosen filename so the badge can update immediately
        file_put_contents(__DIR__ . '/photos/manual_request.txt', $chosen);
    } else {
        file_put_contents(__DIR__ . '/photos/manual_request.txt', 'SHUFFLE');
    }

    if (isset($_POST['is_ajax'])) {
        echo "OK";
        exit;
    }
    $_SESSION['flash'] = "🎲 Request dropped in the box! Processing in ~1 minute.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['set_file'])) {
    file_put_contents(__DIR__ . '/photos/manual_request.txt', basename($_POST['set_file']));
    if (isset($_POST['is_ajax'])) {
        echo "OK";
        exit;
    }
    $_SESSION['flash'] = "🖼️ Request dropped in the box! Processing in ~1 minute.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_FILES['photo'])) {
    $file_count = count($_FILES['photo']['name']);
    $success_count = 0;
    $error_messages = [];

    for ($i = 0; $i < $file_count; $i++) {
        $error_code = $_FILES['photo']['error'][$i];
        $tmp_name = $_FILES['photo']['tmp_name'][$i];
        $file_size = $_FILES['photo']['size'][$i];
        $original_name = $_FILES['photo']['name'][$i];

        if ($error_code === UPLOAD_ERR_NO_FILE) continue;

        if ($error_code !== UPLOAD_ERR_OK) {
            if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
                $error_messages[] = "❌ '$original_name' exceeds the file size limit.";
            } else {
                $error_messages[] = "❌ '$original_name' failed to upload (Code $error_code).";
            }
            continue;
        }

        if ($file_size > 50 * 1024 * 1024) {
            $error_messages[] = "❌ '$original_name' is too large (Max 50MB).";
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        // SECURITY: Read the first 12 bytes of the file to check its true signature
        $header = file_get_contents($tmp_name, false, null, 0, 12);
        $is_heic_file = false;

        // Real HEIC/Apple files always have 'ftyp' at byte 4, followed by a brand like 'heic' or 'mif1'
        if (strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp') {
            $brand = substr($header, 8, 4);
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'])) {
                $is_heic_file = true;
            }
        }

        if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp']) && !$is_heic_file) {
            $error_messages[] = "❌ '$original_name' is an invalid or corrupted file type.";
            continue;
        }

        if ($is_heic_file) {
            $magick_tmp = $tmp_name . '.jpg';
            exec("magick " . escapeshellarg($tmp_name) . " " . escapeshellarg($magick_tmp) . " 2>&1", $magick_out, $magick_ret);

            if ($magick_ret === 0 && file_exists($magick_tmp)) {
                $tmp_name = $magick_tmp;
                $mime_type = 'image/jpeg';
            } else {
                $error_messages[] = "❌ '$original_name' could not be converted from HEIC on the server.";
                continue;
            }
        }

        $img_info = @getimagesize($tmp_name);
        if ($img_info === false) {
            $error_messages[] = "❌ '$original_name' is corrupted or not an image.";
            continue;
        }

        $px_width = $img_info[0];
        $px_height = $img_info[1];
        $total_pixels = $px_width * $px_height;

        if ($total_pixels > 60000000) {
            $error_messages[] = "❌ '$original_name' is too large (" . round($total_pixels / 1000000, 1) . " MP).";
            continue;
        }

        // Force jpeg extension
        $secure_filename = time() . '_' . uniqid('frame_', true) . '.jpg';
        $target_path = $UPLOAD_DIR . '/' . $secure_filename;

        $FRAME_W = 1200;
        $FRAME_H = 1600;
        $src = null;

        if ($mime_type === 'image/jpeg') {
            $src = @imagecreatefromjpeg($tmp_name);
            if ($src) {
                $exif = @exif_read_data($tmp_name);
                if ($exif && isset($exif['Orientation'])) {
                    switch ($exif['Orientation']) {
                        case 3:
                            $src = imagerotate($src, 180, 0);
                            break;
                        case 6:
                            $src = imagerotate($src, -90, 0);
                            break;
                        case 8:
                            $src = imagerotate($src, 90, 0);
                            break;
                    }
                }
            }
        } elseif ($mime_type === 'image/png') {
            $src = @imagecreatefrompng($tmp_name);
        } elseif ($mime_type === 'image/webp') {
            $src = @imagecreatefromwebp($tmp_name);
        }

        if ($src) {
            $orig_w = imagesx($src);
            $orig_h = imagesy($src);

            $final_img = imagecreatetruecolor($FRAME_W, $FRAME_H);
            $mini_w = (int)round($FRAME_W / 10);
            $mini_h = (int)round($FRAME_H / 10);
            $mini_img = imagecreatetruecolor($mini_w, $mini_h);

            $bg_ratio = max($mini_w / $orig_w, $mini_h / $orig_h);
            $bg_w = (int)round($orig_w * $bg_ratio);
            $bg_h = (int)round($orig_h * $bg_ratio);
            $bg_x = (int)round(($mini_w - $bg_w) / 2);
            $bg_y = (int)round(($mini_h - $bg_h) / 2);

            imagecopyresampled($mini_img, $src, $bg_x, $bg_y, 0, 0, $bg_w, $bg_h, $orig_w, $orig_h);

            for ($j = 0; $j < 10; $j++) {
                imagefilter($mini_img, IMG_FILTER_GAUSSIAN_BLUR);
            }
            imagefilter($mini_img, IMG_FILTER_SMOOTH, -4);

            $mid_w = (int)round($FRAME_W * 0.4);
            $mid_h = (int)round($FRAME_H * 0.4);
            $mid_img = imagecreatetruecolor($mid_w, $mid_h);

            imagecopyresampled($mid_img, $mini_img, 0, 0, 0, 0, $mid_w, $mid_h, $mini_w, $mini_h);
            imagedestroy($mini_img);

            for ($j = 0; $j < 3; $j++) {
                imagefilter($mid_img, IMG_FILTER_GAUSSIAN_BLUR);
            }

            imagecopyresampled($final_img, $mid_img, 0, 0, 0, 0, $FRAME_W, $FRAME_H, $mid_w, $mid_h);
            imagedestroy($mid_img);

            imagefilter($final_img, IMG_FILTER_BRIGHTNESS, -30);

            $fg_ratio = min($FRAME_W / $orig_w, $FRAME_H / $orig_h);
            $fg_w = (int)round($orig_w * $fg_ratio);
            $fg_h = (int)round($orig_h * $fg_ratio);
            $fg_x = (int)round(($FRAME_W - $fg_w) / 2);
            $fg_y = (int)round(($FRAME_H - $fg_h) / 2);

            imagecopyresampled($final_img, $src, $fg_x, $fg_y, 0, 0, $fg_w, $fg_h, $orig_w, $orig_h);

            imagejpeg($final_img, $target_path, 85);
            imagedestroy($src);
            imagedestroy($final_img);
            $success_count++;
        } else {
            $error_messages[] = "❌ '$original_name' could not be processed.";
        }
    }

    $flash_out = [];
    if ($success_count > 0) $flash_out[] = "✨ $success_count photo(s) successfully sent to Frame!";
    if (!empty($error_messages)) $flash_out = array_merge($flash_out, $error_messages);

    // 1. If this is a background upload, just reply with OK (or the errors) and exit.
    // Do NOT set the session flash banner!
    if (isset($_POST['is_ajax'])) {
        if (!empty($error_messages)) {
            echo implode("\n", $error_messages);
        } else {
            echo "OK";
        }
        exit;
    }

    // 2. Fallback for non-ajax submissions
    if (!empty($flash_out)) $_SESSION['flash'] = implode("\n", $flash_out);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$images = [];
$gallery_urls = [];

if (is_dir($UPLOAD_DIR)) {
    $files = array_diff(scandir($UPLOAD_DIR), array('.', '..'));
    foreach ($files as $file) {
        if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'])) {
            $images[] = $file;
        }
    }
    rsort($images);

    foreach ($images as $img) {
        $gallery_urls[] = "/photos/" . htmlspecialchars($img);
    }
}

$battery_html = '';
$battery_file = __DIR__ . '/battery.json';

if (file_exists($battery_file)) {
    $b_data = json_decode(file_get_contents($battery_file), true);
    if ($b_data && isset($b_data['voltage'])) {
        $v = $b_data['voltage'];

        if ($v >= 4.20) $pct = 100;
        elseif ($v >= 4.00) $pct = 80 + (($v - 4.00) / 0.20) * 20;
        elseif ($v >= 3.90) $pct = 60 + (($v - 3.90) / 0.10) * 20;
        elseif ($v >= 3.80) $pct = 40 + (($v - 3.80) / 0.10) * 20;
        elseif ($v >= 3.70) $pct = 15 + (($v - 3.70) / 0.10) * 25;
        elseif ($v >= 3.50) $pct = 5 + (($v - 3.50) / 0.20) * 10;
        elseif ($v >= 3.30) $pct = (($v - 3.30) / 0.20) * 5;
        else $pct = 0;

        $pct = (int)round($pct);
        $color = $pct <= 20 ? '#ff453a' : '#32d74b';

        // Pass the raw epoch timestamp (multiplied by 1000 for JavaScript)
        $file_time_ms = filemtime($battery_file) * 1000;

        $fill_width = max(0, round(($pct / 100) * 12));
        $fill_rect = $fill_width > 0 ? '<rect x="4" y="9" width="' . $fill_width . '" height="6" rx="1" fill="currentColor"></rect>' : '';

        $battery_icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="16" height="10" rx="2" ry="2"></rect><line x1="22" y1="11" x2="22" y2="13"></line>' . $fill_rect . '</svg>';

        // Added an ID and a data-timestamp attribute
        $battery_html = '
<div id="battery-status" class="battery-icon-group" style="color: ' . $color . ';" data-timestamp="' . $file_time_ms . '" title="Loading time...">
' . $battery_icon . ' <span>' . $pct . '%</span>
</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>">
    <link rel="apple-touch-icon" href="/img/apple-touch-icon.png?v=<?php echo time(); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Frame">

    <title><?php echo defined('FRAME_NAME') ? htmlspecialchars(FRAME_NAME) : 'Smart Frame'; ?></title>
    <style>
        /* =========================================
        1. GLOBALS & LAYOUT
        ========================================= */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #000;
            margin: 0;
            padding: 16px;
            color: #f5f5f7;
            -webkit-font-smoothing: antialiased;
        }

        body.no-scroll {
            overflow: hidden;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #1c1c1e;
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }

        h3 {
            font-size: 17px;
            font-weight: 600;
            margin: 24px 0 14px 0;
            color: #e5e5ea;
        }

        .flash {
            background: #32d74b;
            color: #000;
            padding: 14px;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 20px;
            animation: fadeOut 8s forwards;
            line-height: 1.4;
        }

        @keyframes fadeOut {

            0%,
            80% {
                opacity: 1;
            }

            100% {
                opacity: 0;
                display: none;
            }
        }

        /* =========================================
        2. HEADER (ABSOLUTE CORNERS + CENTERED STACK)
        ========================================= */
        .navbar {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-bottom: 24px;
            margin-bottom: 20px;
            border-bottom: 1px solid #2c2c2e;
        }

        .nav-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-top: 28px;
        }

        .nav-logo {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            flex-shrink: 0;
        }

        .nav-title {
            font-size: 30px;
            font-weight: 400;
            letter-spacing: -0.5px;
            color: #fff;
            margin: 0;
            line-height: 1;
            white-space: nowrap;
        }

        .nav-left {
            position: absolute;
            top: 4px;
            left: 0;
        }

        .nav-right {
            position: absolute;
            top: 4px;
            right: 0;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .battery-icon-group {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        .icon-btn {
            background: transparent;
            border: none;
            color: #a0a0a0;
            padding: 0;
            cursor: pointer;
            display: flex;
            transition: color 0.2s ease, transform 0.2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        @media (hover: hover) {
            .icon-btn:hover {
                color: #fff;
            }
        }

        .icon-btn:active {
            color: #0a84ff;
            transform: scale(0.85);
        }

        .icon-btn svg {
            width: 18px;
            height: 18px;
        }

        /* --- MOBILE MEDIA QUERY (iPhone Mini Adjustments) --- */
        @media (max-width: 420px) {
            .nav-logo {
                width: 46px;
                height: 46px;
                border-radius: 10px;
            }

            .nav-title {
                font-size: 26px;
            }

            .nav-left {
                top: 4px;
            }

            .nav-right {
                top: 4px;
                gap: 12px;
            }

            .battery-icon-group {
                font-size: 12px;
            }

            .action-bar {
                gap: 8px;
            }

            .action-btn {
                font-size: 13px;
                padding: 0 4px;
                gap: 6px;
                height: 48px;
            }
        }

        /* =========================================
        3. ACTION BAR (ADD / SHUFFLE BUTTONS)
        ========================================= */
        .action-bar {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .action-btn {
            width: 100%;
            height: 48px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: none;
            transition: transform 0.2s, filter 0.2s, background 0.2s;
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
        }

        .upload-wrapper {
            position: relative;
            width: 100%;
            margin: 0;
        }

        .upload-wrapper input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }

        .secondary-btn {
            background: #2c2c2e;
            color: #fff;
        }

        .secondary-btn svg {
            color: #0a84ff;
        }

        .upload-wrapper:hover .secondary-btn {
            background: #3a3a3c;
        }

        .upload-wrapper:active .secondary-btn {
            transform: scale(0.96);
        }

        .primary-btn {
            background: #0a84ff;
            color: #fff;
        }

        .primary-btn:hover {
            filter: brightness(1.15);
        }

        .primary-btn:active {
            transform: scale(0.96);
            filter: brightness(0.9);
        }

        /* =========================================
        4. GALLERY GRID
        ========================================= */
        .grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        @media (max-width: 480px) {
            .grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
        }

        .card {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            aspect-ratio: 3/4;
            background: #2c2c2e;
        }

        .current-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #32d74b;
            color: #000;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.6);
            pointer-events: none;
            z-index: 5;
        }

        .card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.3s;
            cursor: zoom-in;
        }

        .card:hover img {
            transform: scale(1.05);
        }

        /* =========================================
        5. LIGHTBOX
        ========================================= */
        #lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(0, 0, 0, 0.95);
            z-index: 10000;
            flex-direction: column;
            align-items: center;
            padding: 60px 20px 40px 20px;
            box-sizing: border-box;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            user-select: none;
            overflow: hidden;
        }

        .lightbox-img-wrapper {
            flex: 1;
            min-height: 0;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        #lightbox img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 12px;
            transition: transform 0.2s ease-out, opacity 0.2s ease-out;
            cursor: default;
        }

        .lightbox-bottom {
            flex: 0 0 auto;
            margin-top: 24px;
            display: flex;
            gap: 12px;
            justify-content: center;
            background: rgba(28, 28, 30, 0.85);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            padding: 10px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }

        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 36px;
            font-weight: 300;
            cursor: pointer;
            z-index: 10001;
            padding: 10px;
        }

        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            font-size: 32px;
            font-weight: bold;
            cursor: pointer;
            padding: 20px 24px;
            user-select: none;
            z-index: 10001;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            transition: 0.2s;
        }

        .lightbox-nav:hover {
            background: rgba(0, 0, 0, 0.6);
        }

        .lightbox-nav.left {
            left: 20px;
        }

        .lightbox-nav.right {
            right: 20px;
        }

        @media (max-width: 768px) {
            .lightbox-nav {
                display: none;
            }
        }

        .lb-btn {
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: white;
            transition: transform 0.2s, filter 0.2s;
            white-space: nowrap;
        }

        .lb-send {
            background: #0a84ff;
        }

        .lb-send:hover {
            filter: brightness(1.15);
        }

        .lb-send:active {
            transform: scale(0.95);
            filter: brightness(0.9);
        }

        .lb-delete {
            background: rgba(255, 69, 58, 0.15);
            color: #ff453a;
        }

        .lb-delete:hover {
            background: rgba(255, 69, 58, 0.25);
        }

        .lb-delete:active {
            background: rgba(255, 69, 58, 0.35);
            transform: scale(0.95);
            /* Added the missing click bounce */
        }

        /* =========================================
        6. MODALS & LOADERS
        ========================================= */

        .spinner {
            border: 3px solid rgba(255, 255, 255, 0.15);
            border-top-color: #fff;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            animation: spin 0.8s linear infinite;
            box-sizing: border-box;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal-box {
            background: #1c1c1e;
            padding: 24px;
            border-radius: 20px;
            width: 85%;
            max-width: 300px;
            text-align: center;
            border: 1px solid #2c2c2e;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }

        .modal-box h4 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: #fff;
            font-weight: 600;
        }

        .modal-box p {
            color: #8e8e93;
            font-size: 14px;
            margin: 0 0 20px 0;
            line-height: 1.4;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
        }

        .modal-buttons button {
            flex: 1;
            padding: 12px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            /* Updated to smoothly transition colors, filters, and scaling */
            transition: background 0.2s, filter 0.2s, transform 0.2s;
        }

        /* Make them gently scale down when clicked, like the other UI buttons */
        .modal-buttons button:active {
            transform: scale(0.96);
        }

        .btn-cancel {
            background: #2c2c2e;
            color: #0a84ff;
        }

        /* Lighten the cancel button on hover */
        .btn-cancel:hover {
            background: #3a3a3c;
        }

        .btn-danger {
            background: #ff453a;
            color: white;
        }

        .btn-danger:hover {
            background: #ff6961;
            /* Explicit lighter red instead of filter */
        }

        .btn-danger:active {
            background: #d73a30;
            /* Darker red when pressed */
            /* It inherently gets the scale(0.96) from .modal-buttons button:active */
        }
    </style>
</head>

<body>

    <div class="container">
        <!-- 1. CENTERED STACKED HEADER WITH CORNER ACTIONS -->
        <header class="navbar">
            <!-- Top Left: Battery -->
            <div class="nav-left">
                <?php if (!empty($battery_html)): ?>
                    <?php echo $battery_html; ?>
                <?php endif; ?>
            </div>

            <!-- Center Stack: Logo + Title -->
            <div class="nav-center">
                <img src="/img/logo.png" alt="Logo" class="nav-logo">
                <h1 class="nav-title"><?php echo defined('FRAME_NAME') ? htmlspecialchars(FRAME_NAME) : 'Smart Frame'; ?></h1>
            </div>

            <!-- Top Right: Refresh & Logout -->
            <div class="nav-right">
                <button onclick="window.location.href = window.location.pathname + '?t=' + Date.now();" class="icon-btn" title="Refresh" ontouchstart="">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                    </svg>
                </button>

                <form method="post" action="logout.php" style="margin: 0;">
                    <button type="submit" class="icon-btn" title="Logout" ontouchstart="">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </button>
                </form>
            </div>
        </header>

        <!-- FLASH MESSAGES -->
        <?php
        if (isset($_SESSION['flash'])) {
            echo '<div class="flash">' . nl2br(htmlspecialchars($_SESSION['flash'])) . '</div>';
            unset($_SESSION['flash']);
        }
        ?>

        <!-- 2. SIDE-BY-SIDE ACTION BAR -->
        <div class="action-bar">
            <!-- Upload Button -->
            <form method="POST" enctype="multipart/form-data" id="uploadForm" class="upload-wrapper">
                <input type="file" name="photo[]" accept="image/jpeg, image/png, image/webp, .heic, .heif" multiple onchange="submitUpload()">
                <div class="action-btn secondary-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                    Add Photos
                </div>
            </form>

            <!-- Shuffle Button -->
            <form method="post" style="margin: 0;">
                <input type="hidden" name="shuffle_image" value="1">
                <button type="button" class="action-btn primary-btn" onclick="shufflePhoto(this)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="16 3 21 3 21 8"></polyline>
                        <line x1="4" y1="20" x2="21" y2="3"></line>
                        <polyline points="21 16 21 21 16 21"></polyline>
                        <line x1="15" y1="15" x2="21" y2="21"></line>
                        <line x1="4" y1="4" x2="9" y2="9"></line>
                    </svg>
                    Shuffle Frame
                </button>
            </form>
        </div>

        <!-- 3. PHOTO GALLERY -->
        <div class="grid">
            <?php if (empty($images)): ?>
                <p style="grid-column: 1 / -1; text-align: center; color: #8e8e93; padding: 20px;">No photos yet. Be the first to add one!</p>
            <?php else: ?>
                <?php foreach ($images as $index => $img): ?>
                    <?php
                    $img_file = strtolower(trim(basename($img)));
                    $is_active = ($current_on_frame !== '' && $current_on_frame !== 'shuffle' && $img_file === $current_on_frame);
                    ?>
                    <div class="card">
                        <img src="/photos/<?php echo htmlspecialchars($img); ?>"
                            alt="Family Photo" loading="lazy" decoding="async"
                            onclick="openLightbox(<?php echo $index; ?>)">

                        <!-- Initial PHP Badge Render -->
                        <?php if ($is_active): ?>
                            <div class="current-badge" title="Currently on Frame">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4. LIGHTBOX & MODALS -->
    <div id="lightbox">
        <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
        <div class="lightbox-nav left" onclick="prevImage(event)">&#10094;</div>

        <div class="lightbox-img-wrapper" onclick="closeLightbox()">
            <img id="lightbox-img" src="" alt="Fullscreen Photo" onclick="event.stopPropagation()">
        </div>

        <div class="lightbox-nav right" onclick="nextImage(event)">&#10095;</div>

        <div class="lightbox-bottom" onclick="event.stopPropagation()">
            <button id="lb-send-btn" class="lb-btn lb-send" onclick="sendFromLightbox(event)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                Send to Frame
            </button>
            <button class="lb-btn lb-delete" onclick="deleteFromLightbox(event)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
                Delete
            </button>
        </div>
    </div>

    <div id="delete-modal" class="modal-overlay">
        <div class="modal-box">
            <h4>Delete Photo?</h4>
            <p>This will permanently remove the photo from the Frame.</p>
            <div class="modal-buttons">
                <button class="btn-cancel" onclick="closeConfirm()">Cancel</button>
                <button class="btn-danger" id="confirm-delete-btn">Delete</button>
            </div>
        </div>
    </div>

    <script>
        const galleryImages = <?php echo json_encode($gallery_urls); ?>;
        let currentGalleryIndex = 0;

        // Format the battery timestamp into the browser's local timezone
        const batteryEl = document.getElementById('battery-status');
        if (batteryEl) {
            const timestamp = parseInt(batteryEl.getAttribute('data-timestamp'), 10);
            const date = new Date(timestamp);

            // Formats to: "Oct 24, 4:26 PM" based on the exact timezone of the device viewing it
            const formattedTime = date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });

            batteryEl.setAttribute('title', 'Last updated: ' + formattedTime);
        }

        function submitUpload() {
            const fileInput = document.querySelector('input[type="file"]');
            if (fileInput.files.length === 0) return;

            const btnElement = document.querySelector('.secondary-btn');
            const originalHTML = btnElement.innerHTML;

            // Lock width to prevent jitter
            const originalWidth = btnElement.offsetWidth;
            btnElement.style.width = originalWidth + 'px';
            btnElement.style.pointerEvents = 'none';

            // Build the spinner and a dedicated text span ONCE
            btnElement.innerHTML = `
            <div class="spinner" style="margin: 0; width: 16px; height: 16px; border-width: 2px;"></div>
            <span id="upload-progress-text" style="margin-left: 8px;">0%</span>
            `;

            // Grab a reference to just the text span
            const progressText = document.getElementById('upload-progress-text');

            const formData = new FormData(document.getElementById('uploadForm'));
            formData.append('is_ajax', '1');

            const xhr = new XMLHttpRequest();

            // Update ONLY the text inside the span, leaving the spinner element untouched
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable && progressText) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    if (pct < 100) {
                        progressText.innerText = pct + '%';
                    } else {
                        progressText.innerText = 'Processing';
                    }
                }
            });

            xhr.addEventListener('load', function() {
                if (xhr.responseText.trim() === "OK") {
                    // Success! Turn button green with checkmark
                    btnElement.style.background = '#32d74b';
                    btnElement.style.color = '#000';
                    btnElement.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

                    // Reload the page after 1.5 seconds so the grid updates with the new photos
                    setTimeout(() => {
                        window.location.href = window.location.pathname;
                    }, 1500);
                } else {
                    // If there were PHP upload errors (e.g. file too big), pop them up
                    alert("Upload issues:\n" + xhr.responseText);
                    window.location.href = window.location.pathname;
                }
            });

            xhr.addEventListener('error', function() {
                alert('Network error. Please try again.');
                window.location.href = window.location.pathname;
            });

            xhr.open('POST', window.location.href, true);
            xhr.send(formData);
        }

        function openLightbox(index) {
            currentGalleryIndex = index;
            updateLightboxImage();
            const lightbox = document.getElementById('lightbox');
            lightbox.style.display = 'flex';
            lightbox.style.opacity = '0';

            document.body.classList.add('no-scroll'); // Locks background scrolling

            setTimeout(() => lightbox.style.opacity = '1', 10);
        }

        function closeLightbox() {
            document.getElementById('lightbox').style.display = 'none';
            document.body.classList.remove('no-scroll'); // Unlocks background scrolling
        }

        document.getElementById('lightbox').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLightbox();
            }
        });

        function updateLightboxImage() {
            if (galleryImages.length > 0) {
                document.getElementById('lightbox-img').src = galleryImages[currentGalleryIndex];
            }
        }

        function nextImage(e) {
            if (e) e.stopPropagation();
            currentGalleryIndex = (currentGalleryIndex + 1) % galleryImages.length;
            updateLightboxImage();
        }

        function prevImage(e) {
            if (e) e.stopPropagation();
            currentGalleryIndex = (currentGalleryIndex - 1 + galleryImages.length) % galleryImages.length;
            updateLightboxImage();
        }

        let touchstartX = 0;
        let touchendX = 0;

        const lightboxEl = document.getElementById('lightbox');
        lightboxEl.addEventListener('touchstart', e => {
            touchstartX = e.changedTouches[0].screenX;
        }, {
            passive: true
        });

        lightboxEl.addEventListener('touchend', e => {
            touchendX = e.changedTouches[0].screenX;
            handleSwipe();
        }, {
            passive: true
        });

        function handleSwipe() {
            const threshold = 50;
            if (touchendX < touchstartX - threshold) nextImage();
            if (touchendX > touchstartX + threshold) prevImage();
        }

        document.addEventListener('keydown', e => {
            if (document.getElementById('lightbox').style.display === 'flex') {
                if (e.key === 'ArrowRight') nextImage();
                if (e.key === 'ArrowLeft') prevImage();
                if (e.key === 'Escape') closeLightbox();
            }
        });

        let pendingDelete = null;

        function closeConfirm() {
            document.getElementById('delete-modal').style.display = 'none';
            pendingDelete = null;
        }

        function executeDelete() {
            if (!pendingDelete) return;

            const filename = pendingDelete.file;
            const confirmBtn = document.getElementById('confirm-delete-btn');

            // 1. Save original text and lock dimensions
            const originalHTML = confirmBtn.innerHTML;
            const originalWidth = confirmBtn.offsetWidth;

            confirmBtn.style.width = originalWidth + 'px';
            confirmBtn.style.pointerEvents = 'none';

            // Force the button to stay "pressed down" visually while loading
            confirmBtn.style.transform = 'scale(0.96)';

            confirmBtn.innerHTML = '<div class="spinner" style="margin: 0; width: 16px; height: 16px; border-width: 2px;"></div>';

            const formData = new FormData();
            formData.append('delete_file', filename);
            formData.append('is_ajax', '1');

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(text => {
                    if (text.trim() === "OK") {
                        // 2. Success! Close the modal and lightbox instantly
                        closeConfirm();
                        closeLightbox();

                        // 3. Find the card in the background grid and animate it shrinking away
                        const cards = document.querySelectorAll('.card');
                        const targetCard = cards[currentGalleryIndex];

                        if (targetCard) {
                            targetCard.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                            targetCard.style.opacity = '0';
                            targetCard.style.transform = 'scale(0.5)';

                            // 4. Silently reload the page as soon as the animation finishes.
                            // This completely prevents the "wrong photo" index desync bug!
                            setTimeout(() => {
                                window.location.href = window.location.pathname;
                            }, 400);
                        } else {
                            window.location.href = window.location.pathname;
                        }

                    } else {
                        alert('Failed to delete photo from server.');
                        closeConfirm();
                        confirmBtn.style.pointerEvents = 'auto';
                        confirmBtn.innerHTML = originalHTML;
                    }
                })
                .catch(() => {
                    alert('Network error while deleting.');
                    closeConfirm();
                    confirmBtn.style.pointerEvents = 'auto';
                    confirmBtn.innerHTML = originalHTML;
                });
        }

        function shufflePhoto(btnElement) {
            const originalHTML = btnElement.innerHTML;

            btnElement.style.pointerEvents = 'none';
            // We use inline margin: 0 to ensure the spinner adds NO math to the button height
            btnElement.innerHTML = '<div class="spinner" style="margin: 0;"></div>';

            const formData = new FormData();
            formData.append('shuffle_image', '1');
            formData.append('is_ajax', '1');

            Promise.all([
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    }).then(r => r.text()),
                    new Promise(resolve => setTimeout(resolve, 1200))
                ])
                .then(([text]) => {
                    if (text.trim() === "OK") {
                        btnElement.style.background = '#32d74b';
                        btnElement.style.color = '#000';
                        btnElement.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

                        // Force the UI to update the green badge instantly!
                        pollActiveImage();

                        setTimeout(() => {
                            btnElement.style.background = '';
                            btnElement.style.color = '';
                            btnElement.style.pointerEvents = 'auto';
                            btnElement.innerHTML = originalHTML;
                        }, 2000);
                    } else {
                        alert('Failed to shuffle.');
                        btnElement.style.pointerEvents = 'auto';
                        btnElement.innerHTML = originalHTML;
                    }
                }).catch(() => {
                    alert('Network error while shuffling.');
                    btnElement.style.pointerEvents = 'auto';
                    btnElement.innerHTML = originalHTML;
                });
        }

        // Helper functions for lightbox
        function getCurrentLightboxFilename() {
            const fullPath = galleryImages[currentGalleryIndex];
            return fullPath.substring(fullPath.lastIndexOf('/') + 1);
        }

        function deleteFromLightbox(event) {
            event.stopPropagation();
            const filename = getCurrentLightboxFilename();

            pendingDelete = {
                file: filename,
                isLightbox: true
            };
            document.getElementById('confirm-delete-btn').onclick = executeDelete;
            document.getElementById('delete-modal').style.display = 'flex';
        }

        function sendFromLightbox(event) {
            event.stopPropagation();
            const filename = getCurrentLightboxFilename();
            const btn = document.getElementById('lb-send-btn');

            const originalHTML = btn.innerHTML;

            // LOCK THE WIDTH BEFORE CHANGING TEXT TO PREVENT JITTER
            const originalWidth = btn.offsetWidth;
            btn.style.width = originalWidth + 'px';

            btn.style.pointerEvents = 'none';
            btn.style.transform = 'scale(1.05)';
            // Inline margin: 0 guarantees it won't stretch the lightbox button
            btn.innerHTML = '<div class="spinner" style="margin: 0; width: 16px; height: 16px; border-width: 2px;"></div> Sending...';

            const formData = new FormData();
            formData.append('set_file', filename);
            formData.append('is_ajax', '1');

            Promise.all([
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    }).then(r => r.text()),
                    new Promise(resolve => setTimeout(resolve, 1200))
                ])
                .then(([text]) => {
                    if (text.trim() === "OK") {
                        btn.style.background = '#32d74b';
                        btn.style.color = '#000';
                        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Sent!';

                        // Force the UI to update the green badge instantly!
                        pollActiveImage();

                        setTimeout(() => {
                            btn.style.background = '';
                            btn.style.color = '';
                            btn.style.transform = '';
                            btn.style.pointerEvents = 'auto';
                            btn.style.width = ''; // UNLOCK THE WIDTH
                            btn.innerHTML = originalHTML;
                        }, 2000);
                    } else {
                        alert('Failed to set photo.');
                        btn.style.pointerEvents = 'auto';
                        btn.style.width = ''; // UNLOCK THE WIDTH
                        btn.innerHTML = originalHTML;
                    }
                }).catch(() => {
                    alert('Network error while setting photo.');
                    btn.style.pointerEvents = 'auto';
                    btn.style.width = ''; // UNLOCK THE WIDTH
                    btn.innerHTML = originalHTML;
                });
        }

        // ========================================================
        // LIVE POLLING: Dynamically moves the badge without a refresh
        // ========================================================
        function pollActiveImage() {
            fetch(window.location.href + (window.location.search ? '&' : '?') + 'get_current_photo=1')
                .then(r => r.text())
                .then(filename => {
                    filename = filename.trim().toLowerCase();

                    let activeIndex = -1;
                    if (filename && filename !== 'shuffle') {
                        activeIndex = galleryImages.findIndex(src => {
                            const srcFile = src.split('/').pop().toLowerCase();
                            return srcFile === filename || filename.includes(srcFile);
                        });
                    }

                    const cards = document.querySelectorAll('.card');
                    cards.forEach((card, i) => {
                        const badge = card.querySelector('.current-badge');
                        if (i === activeIndex) {
                            if (!badge) {
                                const badgeHTML = `
                                <div class="current-badge" title="Currently on Frame">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                </div>
                                `;
                                card.insertAdjacentHTML('beforeend', badgeHTML);
                            }
                        } else {
                            if (badge) badge.remove();
                        }
                    });
                })
                .catch(() => {});
        }

        // Pings the PHP endpoint seamlessly in the background every 3 seconds
        setInterval(pollActiveImage, 3000);
    </script>
</body>

</html>