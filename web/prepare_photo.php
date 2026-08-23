<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("❌ 403 Forbidden: Direct web access is not allowed.");
}

ini_set('memory_limit', '512M');
set_time_limit(60);

$UPLOAD_DIR = __DIR__ . '/photos';
$OUTPUT_FILE = __DIR__ . '/ready_for_frame.png';
$HISTORY_FILE = __DIR__ . '/history.json';

// 1. Grab JPEGs manually
$jpgs = glob($UPLOAD_DIR . '/*.jpg') ?: [];
$jpegs = glob($UPLOAD_DIR . '/*.jpeg') ?: [];
$files = array_merge($jpgs, $jpegs);
$files = array_filter($files, fn($f) => $f !== $OUTPUT_FILE);

if (empty($files)) {
    die("No photos found in directory.\n");
}

$requested_arg = isset($argv[1]) ? basename($argv[1]) : null;

// Pull up the history early so both scenarios can use it
$history = file_exists($HISTORY_FILE) ? json_decode(file_get_contents($HISTORY_FILE), true) : [];
if (!is_array($history)) $history = [];
$max_history = (int)floor(count($files) * 0.8);

// Scenario A: Specific image requested via "Send to Frame"
if ($requested_arg && $requested_arg !== 'SHUFFLE' && in_array($UPLOAD_DIR . '/' . $requested_arg, $files)) {
    $src_file = $UPLOAD_DIR . '/' . $requested_arg;
    echo "Processing manual override: " . basename($src_file) . "\n";
}
// Scenario B: Shuffle requested
else {
    $available_files = array_diff($files, $history);
    if (empty($available_files)) {
        $available_files = $files;
        $history = [];
    }

    $available_files = array_values($available_files);
    $src_file = $available_files[array_rand($available_files)];
    echo "Processing shuffle: " . basename($src_file) . "\n";
}

// ---------------------------------------------------------
// NEW: Always write the chosen image (manual or shuffle) to history!
// ---------------------------------------------------------
$history[] = $src_file;
if (count($history) > $max_history) {
    $history = array_slice($history, -$max_history);
}
file_put_contents($HISTORY_FILE, json_encode($history));
// ---------------------------------------------------------

$PALETTE_FILE = __DIR__ . '/palette.png';
$BASE_IMG = __DIR__ . '/temp_base.png';
$FG_MASK = __DIR__ . '/temp_fg_mask.png';
$BG_MASK = __DIR__ . '/temp_bg_mask.png';

// =========================================================================
// --- IMAGEMAGICK ACeP 7-COLOR DITHERING ENGINE ---
// =========================================================================

echo "Generating ImageMagick command...\n";

// 1. Generate the 6-color palette image (Original Hardware Pigments)
$PALETTE_FILE = __DIR__ . '/palette_baseline.png';
if (!file_exists($PALETTE_FILE)) {
    $pal_img = imagecreatetruecolor(6, 1);

    // Allocate the colors exactly as you originally had them
    $colors = [
        imagecolorallocate($pal_img, 0, 0, 0),       // Black
        imagecolorallocate($pal_img, 255, 255, 255), // White
        imagecolorallocate($pal_img, 200, 32, 32),   // Realistic Red
        imagecolorallocate($pal_img, 0, 120, 44),    // Realistic Green (RESTORED)
        imagecolorallocate($pal_img, 0, 56, 168),    // Realistic Blue
        imagecolorallocate($pal_img, 229, 184, 11),  // Realistic Yellow
        imagecolorallocate($pal_img, 214, 108, 21)   // Orange (Ghost color intact)
    ];

    // Original 6-pixel loop
    for ($i = 0; $i < 6; $i++) {
        imagesetpixel($pal_img, $i, 0, $colors[$i]);
    }

    imagepng($pal_img, $PALETTE_FILE);
    imagedestroy($pal_img);
}

// 2. Escape the file paths
$safe_src = escapeshellarg($src_file);
$safe_out = escapeshellarg($OUTPUT_FILE);
$safe_pal = escapeshellarg($PALETTE_FILE);

// =========================================================================
// --- PHASE 1: STANDARDIZE THE CANVAS ---
// =========================================================================
echo "Resizing base image...\n";

$BASE_IMG = __DIR__ . '/temp_base.png';
$FG_MASK = __DIR__ . '/temp_fg_mask.png';
$BG_MASK = __DIR__ . '/temp_bg_mask.png';

$safe_base = escapeshellarg($BASE_IMG);
$safe_fg = escapeshellarg($FG_MASK);
$safe_bg = escapeshellarg($BG_MASK);

$cmd_resize = "magick {$safe_src} -auto-orient -resize 1200x1600^ -gravity center -extent 1200x1600 {$safe_base}";
exec($cmd_resize);

// =========================================================================
// --- PHASE 2: AI SEGMENTATION ---
// =========================================================================
echo "Running AI face/body detection...\n";

$python_script = escapeshellarg(__DIR__ . '/masker.py');
$python_cmd = "python3 {$python_script} {$safe_base} {$safe_fg} {$safe_bg}";
exec($python_cmd, $py_out, $py_ret);

if ($py_ret !== 0 || !file_exists($FG_MASK) || !file_exists($BG_MASK)) {
    echo "⚠️ Mask generation failed. Defaulting to Landscape mode.\n";
    exec("magick -size 1200x1600 canvas:black {$safe_fg}");
    exec("magick -size 1200x1600 canvas:white {$safe_bg}");
}

// =========================================================================
// --- PHASE 3: MASKED TUNING & DITHERING (RESTORED TO ORIGINAL) ---
// =========================================================================
echo "Applying localized tuning and dithering...\n";

$cmd_final = "magick " .
    // 1. Bottom Layer: NATURAL BACKGROUND (Restored to 1.5,50% and 100,105)
    "\\( {$safe_base} -sigmoidal-contrast 1.5,50% -modulate 100,105 \\) " .

    // 2. Top Layer: PROTECTED FOREGROUND (Restored original channels)
    "\\( {$safe_base} -level 0%,100%,0.95 -channel R -gamma 1.05 +channel -channel G -gamma 0.95 +channel -modulate 95,95 " .
    "{$safe_fg} -compose CopyOpacity -composite \\) " .

    // 3. Stack them together and brutally flatten
    "-compose Over -composite -background white -flatten " .

    // 4. GLOBAL POLISH & DITHER (No opaque swapping!)
    "-unsharp 1.5x1+0.7+0.02 " .
    "-dither FloydSteinberg -remap {$safe_pal} " .
    
    "PNG24:{$safe_out} 2>&1";

exec($cmd_final, $output, $return_code);

// Cleanup
@unlink($BASE_IMG);
@unlink($FG_MASK);
@unlink($BG_MASK);

if ($return_code === 0) {
    echo "✅ Success! Saved as ready_for_frame.png\n";
} else {
    echo "❌ ImageMagick Error:\n";
    print_r($output);
}