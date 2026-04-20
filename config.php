<?php
/**
 * OCR Configuration File
 * Konfigurasi untuk aplikasi OCR Image to Text Converter
 */

// ============================================
// DIRECTORY & FILE CONFIGURATION
// ============================================

// Folder untuk menyimpan uploaded files
define('UPLOADS_DIR', __DIR__ . '/uploads');

// Maximum file size (dalam bytes)
// Default: 5MB = 5 * 1024 * 1024
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

// Format file yang diizinkan
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp']);

// Hapus file original setelah OCR berhasil
define('DELETE_ORIGINAL_AFTER_OCR', true);

// Hapus file preprocessed setelah error
define('DELETE_FILES_ON_ERROR', true);

// ============================================
// TESSERACT OCR CONFIGURATION
// ============================================

// Path ke executable tesseract (kosong jika sudah di PATH)
// Windows example: 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'
// Linux example: '/usr/bin/tesseract'
define('TESSERACT_PATH', '');

// Bahasa OCR yang digunakan
// 'eng' = English
// 'ind' = Indonesian
// 'ind+eng' = Indonesian + English (dual language)
// Multiple: 'chi_sim+chi_tra+eng'
define('OCR_LANGUAGE', 'ind+eng');

// Set PSM (Page Segmentation Mode) untuk optimize parsing
// 0 = Orientation and script detection (OSD) only
// 1 = Automatic page segmentation with OSD
// 3 = Fully automatic page segmentation (default)
// 6 = Uniform block of text
// 7 = Single text line
define('OCR_PSM_MODE', 3);

// Set OEM (Engine Mode)
// 0 = Legacy engine only
// 1 = Neural nets LSTM engine only
// 2 = Legacy + LSTM
// 3 = Default, based on what's available
define('OCR_ENGINE_MODE', 3);

// ============================================
// IMAGE PREPROCESSING CONFIGURATION
// ============================================

// Enable image preprocessing (sangat disarankan)
define('ENABLE_PREPROCESSING', true);

// Prefer method untuk preprocessing
// 'imagick' = ImageMagick (lebih baik, jika tersedia)
// 'gd' = GD Library (fallback)
// 'auto' = Auto-detect
define('PREPROCESSING_METHOD', 'auto');

// Preprocessing parameters
define('PREPROCESSING_GRAYSCALE', true);      // Convert ke grayscale
define('PREPROCESSING_CONTRAST', 1.5);        // Kontras multiplier (1.0-2.0)
define('PREPROCESSING_BRIGHTNESS', 10);       // Brightness offset (-50 to 50)
define('PREPROCESSING_CONTRAST_GD', 20);      // GD contrast level (-100 to 100)
define('PREPROCESSING_SHARPEN', true);        // Apply sharpening
define('PREPROCESSING_DENOISE', true);        // Apply denoise
define('PREPROCESSING_ENHANCE', 2);           // Enhancement iterations
define('PREPROCESSING_MIN_WIDTH', 300);       // Minimum width untuk auto-resize
define('PREPROCESSING_TARGET_DPI', 300);      // Target DPI untuk OCR

// ============================================
// ERROR & LOGGING CONFIGURATION
// ============================================

// Enable detailed error logging
define('DEBUG_MODE', false);

// Log file location (opsional)
// Uncomment untuk enable logging
// define('LOG_FILE', __DIR__ . '/logs/ocr.log');

// ============================================
// SECURITY CONFIGURATION
// ============================================

// Session timeout dalam detik
define('SESSION_TIMEOUT', 3600);

// Enable CSRF token protection
define('ENABLE_CSRF', true);

// ============================================
// DISPLAY CONFIGURATION
// ============================================

// Tampilkan gambar original di result
define('SHOW_ORIGINAL_IMAGE', true);

// Tampilkan gambar preprocessed di result
define('SHOW_PREPROCESSED_IMAGE', true);

// Limit karakter untuk preview teks (0 = unlimited)
define('TEXT_PREVIEW_LIMIT', 0);

// ============================================
// PROCESSING CONFIGURATION
// ============================================

// Timeout untuk proses OCR dalam detik
define('OCR_TIMEOUT', 300);

// Clean up files older than (dalam hari)
// 0 = Disable cleanup
define('CLEANUP_DAYS', 7);

// ============================================
// EXPORT CONFIGURATION
// ============================================

// Format export hasil OCR
// 'text' = Plain text
// 'pdf' = PDF (requires TCPDF)
// 'docx' = Word (requires PHPWord)
define('EXPORT_FORMAT', 'text');

// Automatically include metadata dalam exported file
define('INCLUDE_METADATA', true);

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Dapatkan konfigurasi
 */
function getConfig($key, $default = null) {
    return defined($key) ? constant($key) : $default;
}

/**
 * Log message
 */
function logMessage($message, $type = 'info') {
    if (!defined('LOG_FILE')) {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message" . PHP_EOL;
    
    @file_put_contents(constant('LOG_FILE'), $logMessage, FILE_APPEND);
}

/**
 * Validate configuration
 */
function validateConfiguration() {
    $errors = [];
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4') < 0) {
        $errors[] = 'PHP version harus 7.4 atau lebih tinggi';
    }
    
    // Check uploads directory
    if (!is_dir(UPLOADS_DIR)) {
        if (!@mkdir(UPLOADS_DIR, 0755, true)) {
            $errors[] = 'Tidak dapat membuat folder uploads';
        }
    }
    
    if (!is_writable(UPLOADS_DIR)) {
        $errors[] = 'Folder uploads tidak writable';
    }
    
    // Check GD
    if (!extension_loaded('gd')) {
        $errors[] = 'GD Library tidak terinstall';
    }
    
    // Check Composer autoload
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        $errors[] = 'Composer dependencies tidak terinstall. Jalankan: composer install';
    }
    
    return $errors;
}

// Auto-validate on load
$configErrors = validateConfiguration();
if (!empty($configErrors) && getConfig('DEBUG_MODE')) {
    foreach ($configErrors as $error) {
        logMessage($error, 'ERROR');
    }
}

?>
