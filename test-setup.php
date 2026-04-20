<?php
/**
 * File untuk test/verifikasi setup Tesseract OCR
 * Akses di: http://localhost:8000/test-setup.php
 */

echo "<!DOCTYPE html>
<html lang='id'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Tesseract OCR Setup Test</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 30px; }
        .test-item { margin-bottom: 20px; padding: 15px; border-left: 4px solid #ddd; background: #f9f9f9; }
        .test-item.success { border-left-color: #28a745; background: #d4edda; }
        .test-item.error { border-left-color: #dc3545; background: #f8d7da; }
        .test-item.warning { border-left-color: #ffc107; background: #fff3cd; }
        .test-item h3 { margin-bottom: 10px; }
        .status { font-weight: bold; margin-bottom: 5px; }
        .details { font-size: 0.9em; color: #666; margin-top: 5px; }
        code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New'; }
        .success .status { color: #28a745; }
        .error .status { color: #dc3545; }
        .warning .status { color: #ff9800; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>✅ Tesseract OCR Setup Verification</h1>";

// 1. Test PHP Version
echo "<div class='test-item " . (version_compare(PHP_VERSION, '7.4') >= 0 ? 'success' : 'error') . "'>
    <h3>PHP Version</h3>
    <div class='status'>" . PHP_VERSION . "</div>
    <div class='details'>Required: PHP 7.4+</div>
</div>";

// 2. Test Composer Autoload
echo "<div class='test-item " . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'success' : 'error') . "'>
    <h3>Composer Autoload</h3>";
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        echo "<div class='status'>✓ Found</div>";
        echo "<div class='details'>File: <code>vendor/autoload.php</code></div>";
    } else {
        echo "<div class='status'>✗ Not Found</div>";
        echo "<div class='details'>Run: <code>composer install</code></div>";
    }
echo "</div>";

// 3. Test Tesseract Library
$tesseractOk = false;
echo "<div class='test-item'>";
try {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('thiagoalessio\\TesseractOCR\\TesseractOCR')) {
        $tesseractOk = true;
        echo "<div class='status success'>✓ thiagoalessio/tesseract_ocr Installed</div>";
        echo "<div class='details'>Library version: 2.13+</div>";
    }
} catch (Exception $e) {
    echo "<div class='status error'>✗ Error: " . $e->getMessage() . "</div>";
}
echo "</div>";

// 4. Test Tesseract Binary
$tesseractPath = null;
$tesseractVersion = null;
$tesseractFound = false;

// Try to find tesseract
$paths = [
    'tesseract',
    'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
    'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
];

foreach ($paths as $path) {
    $output = [];
    $return = 0;
    @exec(escapeshellcmd($path) . ' --version 2>&1', $output, $return);
    
    if ($return === 0 && !empty($output)) {
        $tesseractPath = $path;
        $tesseractVersion = trim($output[0]);
        $tesseractFound = true;
        break;
    }
}

echo "<div class='test-item " . ($tesseractFound ? 'success' : 'error') . "'>
    <h3>Tesseract OCR Binary</h3>";
    if ($tesseractFound) {
        echo "<div class='status'>✓ Installed</div>";
        echo "<div class='details'>Path: <code>$tesseractPath</code><br>Version: <code>$tesseractVersion</code></div>";
    } else {
        echo "<div class='status'>✗ Not Found</div>";
        echo "<div class='details'>
            Tesseract OCR v5 harus terinstall di komputer Anda.<br>
            <strong>Windows:</strong> Download dari https://github.com/UB-Mannheim/tesseract/wiki<br>
            <strong>Linux:</strong> <code>apt-get install tesseract-ocr</code><br>
            <strong>Mac:</strong> <code>brew install tesseract</code>
        </div>";
    }
echo "</div>";

// 5. Test Language Data
if ($tesseractFound) {
    $output = [];
    @exec(escapeshellcmd($tesseractPath) . ' --list-langs 2>&1', $output);
    
    echo "<div class='test-item'>";
    echo "<h3>Tesseract Language Data</h3>";
    echo "<div class='status'>Available Languages:</div>";
    
    $langs = array_filter($output, function($line) {
        return !empty(trim($line)) && $line !== 'osd';
    });
    
    if (!empty($langs)) {
        echo "<div class='details'><code>" . implode(', ', array_slice(array_map('trim', $langs), 0, 10)) . "</code>";
        if (count($langs) > 10) {
            echo " ... dan " . (count($langs) - 10) . " bahasa lainnya";
        }
        echo "</div>";
        
        if (!in_array('ind', array_map('trim', $langs))) {
            echo "<div class='details' style='margin-top: 10px; color: #ff9800;'>
                ⚠️ Bahasa Indonesia (ind) tidak ditemukan. 
                OCR akan menggunakan English (eng) saja.
                <br>Untuk menambah: Download dari https://github.com/UB-Mannheim/tesseract/wiki
            </div>";
        }
    }
    echo "</div>";
}

// 6. Test Image Processing Libraries
$gdInfo = gd_info();
echo "<div class='test-item " . (extension_loaded('gd') ? 'success' : 'error') . "'>
    <h3>GD Library</h3>";
    if (extension_loaded('gd')) {
        echo "<div class='status'>✓ Installed</div>";
        echo "<div class='details'>GD Version: " . $gdInfo['GD Version'] . "</div>";
    } else {
        echo "<div class='status'>✗ Not Installed</div>";
        echo "<div class='details'>GD library missing! Preprocessing tidak akan bekerja.</div>";
    }
echo "</div>";

$imagickOk = extension_loaded('imagick');
echo "<div class='test-item " . ($imagickOk ? 'success' : 'warning') . "'>
    <h3>ImageMagick Extension (Optional)</h3>";
    if ($imagickOk) {
        echo "<div class='status'>✓ Installed</div>";
        echo "<div class='details'>ImageMagick digunakan untuk preprocessing dengan kualitas lebih baik</div>";
    } else {
        echo "<div class='status'>⚠️ Not Installed</div>";
        echo "<div class='details'>Akan fallback ke GD Library (tetap berfungsi). Untuk install:<br><code>composer require intervention/image</code></div>";
    }
echo "</div>";

// 7. Test Uploads Folder
$uploadsDir = __DIR__ . '/uploads';
$uploadsOk = is_dir($uploadsDir) && is_writable($uploadsDir);

echo "<div class='test-item " . ($uploadsOk ? 'success' : 'error') . "'>
    <h3>Uploads Folder</h3>";
    if ($uploadsOk) {
        echo "<div class='status'>✓ Ready</div>";
        echo "<div class='details'>Folder writable: <code>./uploads</code></div>";
    } else {
        echo "<div class='status'>✗ Problem</div>";
        if (!is_dir($uploadsDir)) {
            echo "<div class='details'>Folder tidak ada. Akan dibuat otomatis saat aplikasi dijalankan.</div>";
        } else {
            echo "<div class='details'>Folder ada tapi tidak writable. Run: <code>chmod 755 uploads</code></div>";
        }
    }
echo "</div>";

// Summary
echo "<hr style='margin: 30px 0;'>";
echo "<h2 style='margin-bottom: 15px;'>📊 Summary</h2>";

$allOk = version_compare(PHP_VERSION, '7.4') >= 0 && 
         file_exists(__DIR__ . '/vendor/autoload.php') && 
         $tesseractOk && 
         $tesseractFound && 
         extension_loaded('gd');

if ($allOk) {
    echo "<div class='test-item success'>
        <h3>🎉 Setup Berhasil!</h3>
        <div class='details'>Semua requirement terpenuhi. Aplikasi siap dijalankan.</div>
    </div>";
    
    echo "<div style='background: #e8f5e9; padding: 15px; border-radius: 5px; margin-top: 15px;'>
        <strong>Next Steps:</strong><br>
        1. Jalankan server: <code>php -S localhost:8000</code><br>
        2. Buka: <code>http://localhost:8000</code><br>
        3. Upload gambar dan test OCR functionality
    </div>";
} else {
    echo "<div class='test-item error'>
        <h3>⚠️ Setup Belum Lengkap</h3>
        <div class='details'>Ada beberapa requirement yang belum terpenuhi. Lihat di atas untuk detail.</div>
    </div>";
}

echo "</div></body></html>";
?>
