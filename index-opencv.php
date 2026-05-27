<?php
/**
 * OCR KTP Scanner dengan OpenCV Preprocessing
 * Menggantikan Imagick dengan OpenCV (via Python)
 * 
 * Alur:
 * 1. PHP terima upload gambar
 * 2. Python script jalankan OpenCV preprocessing
 * 3. PHP ambil hasil preprocessing
 * 4. Tesseract OCR proses gambar yang sudah dipreprocess
 * 5. Extract & struktur data KTP
 */

// Suppress warnings untuk clean JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering untuk JSON response
ob_start();

require_once __DIR__ . '/vendor/autoload.php';

use thiagoalessio\TesseractOCR\TesseractOCR;

// ============================================
// KONFIGURASI
// ============================================

$uploadsDir = __DIR__ . '/uploads';
$logsDir = __DIR__ . '/logs';
$maxFileSize = 5 * 1024 * 1024; // 5MB
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'];

// Path ke Python executable
// Ubah sesuai dengan instalasi Python Anda
$pythonPath = getPythonPath();  // Auto-detect atau manual

// Path ke script preprocessing
$preprocessScript = __DIR__ . '/preprocess_image.py';

// Buat folder jika belum ada
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// ============================================
// INITIALIZE RESPONSE
// ============================================

$result = [
    'success' => false,
    'message' => '',
    'text' => '',
    'fields' => [],
    'originalImage' => '',
    'preprocessedImage' => '',
    'debug' => [
        'python_path' => '',
        'preprocess_time' => 0,
        'ocr_time' => 0
    ]
];

// ============================================
// MAIN PROCESSING
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $file = $_FILES['image'];
    
    // Validasi file
    $error = validateFile($file, $maxFileSize, $allowedExtensions);
    
    if ($error) {
        $result['message'] = $error;
        logError("Validation failed: " . $error);
    } else {
        // Simpan file original
        $filename = uniqid('ocr_') . '.' . getFileExtension($file['name']);
        $filepath = $uploadsDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $result['originalImage'] = 'uploads/' . $filename;
            logInfo("Original file uploaded: " . $filename);
            
            // Lakukan preprocessing dengan Python + OpenCV
            $preprocessedFilename = uniqid('preprocessed_') . '.png';
            $preprocessedPath = $uploadsDir . '/' . $preprocessedFilename;
            
            $preprocessStart = microtime(true);
            $preprocessResult = preprocessImageWithOpenCV($filepath, $preprocessedPath, $pythonPath, $preprocessScript);
            $preprocessTime = microtime(true) - $preprocessStart;
            
            $result['debug']['python_path'] = $pythonPath;
            $result['debug']['preprocess_time'] = round($preprocessTime, 3);
            
            if (!$preprocessResult['success']) {
                $result['message'] = 'Error preprocessing: ' . $preprocessResult['message'];
                logError("Preprocessing failed: " . json_encode($preprocessResult));
                
                // Cleanup
                if (file_exists($filepath)) unlink($filepath);
                if (file_exists($preprocessedPath)) unlink($preprocessedPath);
            } elseif (!file_exists($preprocessedPath)) {
                $result['message'] = 'File preprocessed tidak ditemukan setelah preprocessing.';
                logError("Preprocessed file not found: " . $preprocessedPath);
                
                if (file_exists($filepath)) unlink($filepath);
            } else {
                // Preprocessing berhasil, lanjut ke OCR
                $result['preprocessedImage'] = 'uploads/' . $preprocessedFilename;
                logInfo("Preprocessing successful, file size: " . filesize($preprocessedPath) . " bytes");
                
                // Lakukan OCR pada gambar yang sudah dipreprocess
                try {
                    if (!file_exists($preprocessedPath)) {
                        throw new Exception('Preprocessed image file tidak ditemukan untuk OCR');
                    }
                    
                    logInfo("Starting OCR on: " . $preprocessedPath);
                    
                    $ocrStart = microtime(true);
                    
                    $ocr = new TesseractOCR($preprocessedPath);
                    $ocr->lang('ind+eng');
                    $ocr->psm(6);
                    $ocr->oem(3);
                    
                    $extractedText = $ocr->run();
                    
                    $ocrTime = microtime(true) - $ocrStart;
                    $result['debug']['ocr_time'] = round($ocrTime, 3);
                    
                    logInfo("OCR completed. Result length: " . strlen($extractedText) . ", Time: " . $ocrTime . "s");
                    
                    // Check if result is empty
                    if (empty($extractedText) || trim($extractedText) === '') {
                        throw new Exception('Tesseract menghasilkan hasil kosong. Coba dengan gambar yang lebih jelas.');
                    }
                    
                    // Post-processing text
                    $rawText = trim((string)$extractedText);
                    $processedText = postProcessOCRText($rawText);
                    
                    if (!is_string($processedText) || trim($processedText) === '') {
                        logInfo("Post-processing returned empty, fallback to raw OCR text");
                        $processedText = $rawText;
                    }
                    
                    if (trim($processedText) === '') {
                        throw new Exception('Teks OCR kosong setelah pemrosesan. Coba gambar lain yang lebih jelas.');
                    }
                    
                    $result['success'] = true;
                    $result['text'] = trim($processedText);
                    $result['message'] = 'Gambar berhasil dikonversi ke teks dengan OpenCV preprocessing!';
                    
                    // Extract KTP fields (pass original image for dedicated NIK OCR)
                    $result['fields'] = extractKtpFields($result['text'], $preprocessedPath, $filepath);
                    
                    logInfo("Final result text length: " . strlen($result['text']));
                    logInfo("Extracted fields: " . json_encode($result['fields']));
                    
                } catch (Exception $e) {
                    $result['message'] = 'Error saat melakukan OCR: ' . $e->getMessage();
                    logError("OCR Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                    
                    // Cleanup
                    if (file_exists($filepath)) unlink($filepath);
                    if (file_exists($preprocessedPath)) unlink($preprocessedPath);
                }
            }
        } else {
            $result['message'] = 'Gagal menyimpan file. Pastikan folder uploads writable.';
            logError("Failed to move uploaded file to: " . $filepath);
        }
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Deteksi path Python yang tersedia di system
 */
function getPythonPath() {
    $paths = [];
    
    // Windows paths
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $paths = [
            'python',                                    // Di PATH
            'C:\\Python311\\python.exe',
            'C:\\Python310\\python.exe',
            'C:\\Python39\\python.exe',
            'C:\\Program Files\\Python311\\python.exe',
            'C:\\Program Files\\Python310\\python.exe',
            'C:\\Program Files\\Python39\\python.exe',
            'C:\\Program Files (x86)\\Python311\\python.exe',
        ];
    } else {
        // Linux/macOS paths
        $paths = [
            'python3',
            'python',
            '/usr/bin/python3',
            '/usr/bin/python',
            '/usr/local/bin/python3',
            '/opt/homebrew/bin/python3',  // macOS with Homebrew
        ];
    }
    
    // Test setiap path
    foreach ($paths as $path) {
        $output = shell_exec($path . ' --version 2>&1');
        if ($output && (stripos($output, 'python') !== false)) {
            return $path;
        }
    }
    
    // Default fallback
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'python' : 'python3';
}

/**
 * Jalankan Python script untuk preprocessing dengan OpenCV
 */
function preprocessImageWithOpenCV($inputPath, $outputPath, $pythonPath, $scriptPath) {
    try {
        // Validasi file script ada
        if (!file_exists($scriptPath)) {
            return [
                'success' => false,
                'message' => "Script preprocessing tidak ditemukan: " . $scriptPath
            ];
        }
        
        // Construct command
        // Escape paths untuk cross-platform compatibility
        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonPath),
            escapeshellarg($scriptPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );
        
        logInfo("Executing: " . $command);
        
        // Execute Python script
        $output = shell_exec($command);
        
        if ($output === null) {
            return [
                'success' => false,
                'message' => 'Gagal menjalankan Python script'
            ];
        }
        
        logInfo("Python output: " . $output);
        
        // Parse JSON response dari Python
        $jsonOutput = json_decode(trim($output), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JSON response dari Python: ' . json_last_error_msg(),
                'raw_output' => $output
            ];
        }
        
        return $jsonOutput;
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Exception saat preprocessing: ' . $e->getMessage()
        ];
    }
}

/**
 * Validasi file yang diupload
 */
function validateFile($file, $maxSize, $allowedExtensions) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File terlalu besar (melebihi upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (melebihi MAX_FILE_SIZE)',
            UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak tersedia',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh extension PHP'
        ];
        return $errors[$file['error']] ?? 'Error upload file yang tidak diketahui';
    }
    
    if ($file['size'] > $maxSize) {
        return 'Ukuran file tidak boleh melebihi ' . ($maxSize / 1024 / 1024) . 'MB';
    }
    
    $extension = strtolower(getFileExtension($file['name']));
    if (!in_array($extension, $allowedExtensions)) {
        return 'Tipe file tidak diizinkan. Gunakan: ' . implode(', ', $allowedExtensions);
    }
    
    return null;
}

/**
 * Get file extension
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Logging functions
 */
function logInfo($message) {
    logMessage($message, 'INFO');
}

function logError($message) {
    logMessage($message, 'ERROR');
}

function logMessage($message, $level = 'INFO') {
    global $logsDir;
    
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$level] " . $message . "\n";
    
    $logFile = $logsDir . '/ocr_' . date('Y-m-d') . '.log';
    
    error_log($logLine, 3, $logFile);
    
    // Also log to PHP error log
    error_log($message);
}

/**
 * Post-processing OCR text
 */
/**
 * Inject line breaks sebelum setiap field keyword
 * Memisahkan field-field yang tergabung dalam satu baris panjang
 * PRIORITIZE multi-word keywords dulu untuk mencegah split
 */
function injectLineBreaksBeforeFields($text) {
    // PRIORITY 1: Multi-word field keywords (check dulu sebelum single keywords)
    $multiWordFields = [
        'Jenis\s+Kelamin',                // Match "Jenis Kelamin"
        'Gol(?:ongan)?\.?\s+Darah',       // Match "Gol Darah", "Golongan Darah", "Gol. Darah"
        'Tempat\s*[\/\\]?\s*Tgl',         // Match "Tempat / Tgl", "Tempat/Tgl", "Tempat Tgl"
        'Status\s+Perkawinan',            // Match "Status Perkawinan"
        'Berlaku\s+Hingga',               // Match "Berlaku Hingga"
        'RT\s*[\/\\-]\s*RW',              // Match "RT/RW", "RT-RW", "RT\\RW"
        '(?:Kel(?:urahan)?|Desa)\s*[\/\\]\s*(?:Desa|Kelurahan)?', // Match "Kel/Desa", "Kelurahan/Desa"
        'Kota\s+(?:dan\s+)?Kabupaten',    // Match "Kota dan Kabupaten", "Kota Kabupaten"
    ];
    
    // Replace multi-word fields dengan newline + standardized format
    // Accept any separator: : = — - > . dan kombinasi
    foreach ($multiWordFields as $pattern) {
        // Match pattern dengan flexible separators, inject newline before field name
        // Use word boundary untuk Kel/Desa agar tidak match "Kelamin" dari "Jenis Kelamin"
        if (strpos($pattern, 'Kel') !== false || strpos($pattern, 'Desa') !== false) {
            // Untuk Kel/Desa patterns, gunakan word boundary
            $text = preg_replace(
                '/\b(' . $pattern . ')[\.\s]*[:=—\-\s>\.]*\s+/i',
                "\n$1: ",
                $text
            );
        } else {
            // Untuk patterns lain, gunakan negative lookbehind standar
            $text = preg_replace(
                '/(?<![a-zA-Z])(' . $pattern . ')[\.\s]*[:=—\-\s>\.]*\s+/i',
                "\n$1: ",
                $text
            );
        }
    }
    
    // PRIORITY 2: Single-word field keywords (after multi-word ones)
    $singleWordFields = [
        'PROVINSI',
        'KOTA',
        'KABUPATEN',
        'NIK',
        'Nama',
        'Tempat',
        'Tgl',
        'Lahir',
        'Alamat',
        'Kecamatan',
        'Agama',
        'Pekerjaan',
        'Kewarga',
        'Berlaku',
        'Hingga',
    ];
    
    foreach ($singleWordFields as $keyword) {
        // Match keyword followed by optional spaces/dots and colon/dash/equals/etc
        // But NOT if it's preceded by a letter (to avoid matching partial words)
        $pattern = '/(?<![a-zA-Z])(' . preg_quote($keyword, '/') . ')[\.\s]*[:=—\-\s>\.]*\s+/i';
        $replacement = "\n$1: ";
        $text = preg_replace($pattern, $replacement, $text);
    }
    
    // Clean up multiple consecutive newlines
    $text = preg_replace('/\n\s*\n+/', "\n", $text);
    
    // Clean up leading/trailing whitespace on each line
    $lines = explode("\n", $text);
    $lines = array_map('trim', $lines);
    $text = implode("\n", $lines);
    
    return $text;
}

function postProcessOCRText($text) {
    $processedText = (string)$text;
    
    // STEP 0: Pre-cleanup - remove obvious noise before processing
    // Remove repeated symbols, extra dots, excessive spaces
    $processedText = preg_replace('/\.{2,}/', '.', $processedText);  // Multiple dots -> single dot
    $processedText = preg_replace('/\s{3,}/', ' ', $processedText);  // Triple+ spaces -> single space
    $processedText = preg_replace('/[\s]+([:=—\-\s>\.])/', '$1', $processedText); // Space before operator
    
    // STEP 1: Inject line breaks sebelum field keywords
    $processedText = injectLineBreaksBeforeFields($processedText);
    
    // STEP 2: PRE-PROCESS: Fix common OCR typos BEFORE aggressive cleanup
    $typoFixes = [
        // Fix Tempat variations
        '/Tempav/' => 'Tempat',                     // "Tempav" -> "Tempat"
        '/Tempa([^t])/' => 'Tempat$1',              // "Tempa" -> "Tempat"
        '/TempavTgl/' => 'Tempat/Tgl',              // "TempavTgl" -> "Tempat/Tgl"
        '/Temp\s+Tgl/' => 'Tempat/Tgl',             // "Temp Tgl" -> "Tempat/Tgl"
        
        // Fix Lahir
        '/Lahir\s*—\s*/' => 'Lahir: ',              // "Lahir—" -> "Lahir:"
        '/Lahir\s*-\s*/' => 'Lahir: ',              // "Lahir -" -> "Lahir:"
        
        // Fix Jenis Kelamin
        '/Jenis\s+Kela/' => 'Jenis Kelamin',        // "Jenis Kela" -> "Jenis Kelamin"
        
        // Fix operators sometimes attached to keywords
        '/Alamat\s*[>\-]\s*/' => 'Alamat: ',        // "Alamat > " or "Alamat - " -> "Alamat: "
        
        // Fix Kel/Desa - preserve the format
        '/Kel\/Desa\s*:/' => 'Kel/Desa:',          // Standardize Kel/Desa format
        '/Kel(?:urahan)?\s*\/\s*Desa/' => 'Kel/Desa', // Normalize with flexible spacing
        '/Kelurahan\s*[\/\\]\s*Desa/' => 'Kel/Desa', // "Kelurahan/Desa" or "Kelurahan\Desa"
        '/Kel\s*[\/\\]\s*Desa/' => 'Kel/Desa',      // "Kel / Desa" with spaces
        
        // Fix Kewarganegaraan variations
        '/Kewarga[a-z]*an/' => 'Kewarganegaraan',   // Normalize all variations
        
        // Fix Pekerjaan
        '/Peker[j]?aan/' => 'Pekerjaan',            // Fix typo
        
        // Fix Golongan Darah
        '/Gol\s*\.\s*Darah/' => 'Golongan Darah',   // "Gol. Darah" -> "Golongan Darah"
        '/Gol\s+Darah/' => 'Golongan Darah',        // "Gol Darah" -> "Golongan Darah"
        
        // Fix Status
        '/Status\s+Perkawi[a]?n/' => 'Status Perkawinan', // Various spellings
        
        // Fix Berlaku
        '/Berlaku\s+Hingga/' => 'Berlaku Hingga',   // Standardize
        
        // Fix Kecamatan
        '/Kecamatan\s*[:=]/' => 'Kecamatan:',       // Standardize operator
    ];
    
    foreach ($typoFixes as $pattern => $replacement) {
        $processedText = preg_replace($pattern, $replacement, $processedText, -1, $count);
    }
    
    // STEP 3: AGGRESSIVE cleanup of noise characters
    // Remove most problematic symbols but keep important ones like / - , .
    $processedText = preg_replace('/[~!@#$%^&*\[\]{}()<>?\\|`"\'_+=]+/', '', $processedText);
    
    // STEP 4: Clean up field separators and operators
    $fixes = [
        '/\s*[:=—\-\s>][\s=:—\->\s]+/' => ': ',    // Normalize operators (multiple to single colon)
        '/([a-zA-Z0-9])\s*=\s*([a-zA-Z0-9])/' => '$1 : $2', // "A = B" -> "A : B"
        '/\b(?:sg|tS|o|ok)\b/' => '',               // Remove single-letter OCR noise
        '/\|/' => '',                               // Remove pipes
        '/\s{2,}/' => ' ',                          // Multiple spaces to single
        '/\s+([,.:;])/' => '$1',                    // Remove space before punctuation
    ];
    
    foreach ($fixes as $pattern => $replacement) {
        $updated = preg_replace($pattern, $replacement, $processedText);
        if ($updated !== null) {
            $processedText = $updated;
        }
    }
    
    // STEP 5: Cleanup extra lines and noise per line
    $lines = explode("\n", $processedText);
    $cleanLines = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Remove trailing noise characters
        $lineCleaned = preg_replace('/\s*[~!@#$%^&*()\[\]{};\'",<>?\/\\|`_+= —\-]+$/', '', $line);
        if ($lineCleaned !== null) {
            $line = $lineCleaned;
        }
        
        // Remove leading noise characters
        $lineCleaned = preg_replace('/^[~!@#$%^&*()\[\]{};\'",<>?\/\\|`_+= —\-]+\s*/', '', $line);
        if ($lineCleaned !== null) {
            $line = $lineCleaned;
        }
        
        // Remove single letter noise at the end
        $line = preg_replace('/\s+[a-z0-9sg~]{1,2}$/', '', $line);
        
        if (!empty($line) && strlen($line) > 1) {
            $cleanLines[] = $line;
        }
    }
    
    $processedText = implode("\n", $cleanLines);
    $processedText = trim($processedText);
    
    // Normalize multiple newlines
    $lineBreakFixed = preg_replace('/\n{3,}/', "\n\n", $processedText);
    if ($lineBreakFixed !== null) {
        $processedText = $lineBreakFixed;
    }
    
    return $processedText;
}

/**
 * Clean OCR noise characters - BALANCED cleanup (tidak terlalu aggressive)
 */
function cleanupOCRNoise($text) {
    $text = trim($text);
    
    // Remove most problematic noise symbols but preserve meaningful punctuation
    // Include em-dash (—), tilde (~), and other special chars
    $text = preg_replace('/[~!@#$%^&*\[\]{}<>?|`\'_—]+/', '', $text);
    
    // Remove multiple spaces
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Remove trailing single letters/digits (usually noise)
    $text = preg_replace('/\s+[a-z0-9]\s*$/i', '', $text);
    
    // Remove leading/trailing dots and numbers (OCR artifacts)
    $text = preg_replace('/^[\.\d\s]+/', '', $text);
    $text = preg_replace('/[\.\d\s]+$/', '', $text);
    
    // Remove trailing operators and special chars that are not part of meaningful data
    $text = preg_replace('/[\s=:—\-~>]+$/', '', $text);
    
    // Preserve: letters, numbers, spaces, dashes, commas, dots (for abbreviations), slashes, parentheses, ampersands
    // Only remove remaining special symbols not in whitelist
    $text = preg_replace('/[^a-zA-Z0-9\s\-,.\/()&°]/i', '', $text);
    
    return trim($text);
}

/**
 * Normalize NIK candidate - STRICT hanya accept 16 digit exactly
 */
function normalizeNikCandidate($value) {
    $niks = trim((string)$value);
    $niks = preg_replace('/[\s\-\.\,]/', '', $niks);
    
    // Convert common OCR misreads to digits - ONLY the most likely ones for NIK
    $niks = preg_replace('/[Oo]/', '0', $niks);      // O/o -> 0
    $niks = preg_replace('/[lI|!]/', '1', $niks);    // l/I/|/! -> 1
    $niks = preg_replace('/[Zz]/', '2', $niks);      // Z/z -> 2
    $niks = preg_replace('/[Ss]/', '5', $niks);      // S/s -> 5
    $niks = preg_replace('/[Bb]/', '8', $niks);      // B/b -> 8
    $niks = preg_replace('/[Gg]/', '9', $niks);      // G/g -> 9
    
    // Remove any remaining non-digits
    $niks = preg_replace('/\D/', '', $niks);
    
    // STRICT: Only accept exactly 16 digits
    if (strlen($niks) == 16 && preg_match('/^\d{16}$/', $niks)) {
        return $niks;
    }
    
    // If we have more than 16, try to extract 16-digit sequence from the start
    if (strlen($niks) > 16) {
        $first16 = substr($niks, 0, 16);
        if (preg_match('/^\d{16}$/', $first16)) {
            return $first16;
        }
    }
    
    return null;
}

/**
 * OCR khusus untuk NIK - jalankan Tesseract terpisah pada area NIK yang sudah di-crop
 * Menggunakan Python preprocessing mode 'nik' untuk crop area NIK secara presisi,
 * lalu jalankan Tesseract dengan konfigurasi khusus digit (PSM 7, digits-only whitelist)
 * 
 * @param string $originalImagePath Path ke gambar KTP original (bukan preprocessed)
 * @return string|null NIK 16 digit atau null jika gagal
 */
function ocrNikFromImage($originalImagePath) {
    global $pythonPath, $preprocessScript, $uploadsDir;
    
    if (!file_exists($originalImagePath)) {
        logError("ocrNikFromImage: Original image not found: " . $originalImagePath);
        return null;
    }
    
    // Buat file temporary untuk NIK crop
    $nikCropFilename = uniqid('nik_crop_') . '.png';
    $nikCropPath = $uploadsDir . '/' . $nikCropFilename;
    
    try {
        // Jalankan Python preprocessing mode NIK
        $command = sprintf(
            '%s %s %s %s nik 2>&1',
            escapeshellarg($pythonPath),
            escapeshellarg($preprocessScript),
            escapeshellarg($originalImagePath),
            escapeshellarg($nikCropPath)
        );
        
        logInfo("NIK OCR - Executing crop: " . $command);
        $output = shell_exec($command);
        logInfo("NIK OCR - Python output: " . $output);
        
        if (!file_exists($nikCropPath)) {
            logError("NIK OCR - Crop file not created: " . $nikCropPath);
            return null;
        }
        
        $bestNik = null;
        
        // STRATEGY 0: Use custom OCR-A trained model (highest priority)
        // The OCR-A Extended font is specifically used for NIK numbers on KTP.
        $ocraModelPath = 'C:/Program Files/Tesseract-OCR/tessdata/ocra.traineddata';
        if (file_exists($ocraModelPath)) {
            logInfo("NIK OCR - OCR-A model found, using custom trained model");
            $psmOcra = [7, 13, 8]; // single line, raw line, single word
            
            foreach ($psmOcra as $psm) {
                try {
                    $ocr = new TesseractOCR($nikCropPath);
                    $ocr->lang('ocra');
                    $ocr->psm($psm);
                    $ocr->oem(1); // LSTM only - sesuai training model
                    $ocr->allowlist('0123456789');
                    
                    $nikText = trim((string)$ocr->run());
                    logInfo("NIK OCR - OCR-A PSM {$psm}: '{$nikText}'");
                    
                    if (empty($nikText)) continue;
                    
                    $digits = preg_replace('/\D/', '', $nikText);
                    logInfo("NIK OCR - OCR-A Digits: '{$digits}' (len=" . strlen($digits) . ")");
                    
                    if (strlen($digits) == 16) {
                        $bestNik = $digits;
                        logInfo("NIK OCR - OCR-A SUCCESS PSM {$psm}: {$bestNik}");
                        break;
                    }
                    
                    if (strlen($digits) > 16 && $bestNik === null) {
                        $bestNik = substr($digits, 0, 16);
                        logInfo("NIK OCR - OCR-A Partial PSM {$psm}, first 16: {$bestNik}");
                    }
                    
                    if (strlen($digits) >= 15 && strlen($digits) <= 17 && $bestNik === null) {
                        $bestNik = substr($digits, 0, 16);
                        logInfo("NIK OCR - OCR-A Near-match PSM {$psm}: {$bestNik}");
                    }
                } catch (Exception $e) {
                    logError("NIK OCR - OCR-A PSM {$psm} error: " . $e->getMessage());
                }
            }
            
            if ($bestNik !== null && strlen($bestNik) == 16) {
                // Cleanup temporary file
                if (file_exists($nikCropPath)) {
                    unlink($nikCropPath);
                }
                return $bestNik;
            }
            
            logInfo("NIK OCR - OCR-A model did not produce valid result, falling back...");
        }
        
        // STRATEGY 1: Fallback to eng model
        // Coba beberapa PSM mode untuk mendapatkan hasil terbaik
        // PSM 7: Single text line (paling cocok untuk baris NIK)
        // PSM 8: Single word (jika NIK terbaca sebagai satu kata)
        // PSM 13: Raw line (tanpa OSD/segmentation, paling raw)
        $psmModes = [7, 8, 13];
        $bestNik = null; // Reset for strategy 1
        
        foreach ($psmModes as $psm) {
            try {
                $ocr = new TesseractOCR($nikCropPath);
                $ocr->lang('eng');
                $ocr->psm($psm);
                $ocr->oem(3);
                // Whitelist: hanya digit 0-9 untuk menghindari misread huruf
                $ocr->allowlist('0123456789');
                
                $nikText = $ocr->run();
                $nikText = trim((string)$nikText);
                
                logInfo("NIK OCR - PSM {$psm} raw result: '{$nikText}'");
                
                if (empty($nikText)) continue;
                
                // Hapus semua non-digit
                $digits = preg_replace('/\D/', '', $nikText);
                
                logInfo("NIK OCR - PSM {$psm} digits only: '{$digits}' (len=" . strlen($digits) . ")");
                
                // Cek apakah tepat 16 digit
                if (strlen($digits) == 16) {
                    $bestNik = $digits;
                    logInfo("NIK OCR - SUCCESS with PSM {$psm}: {$bestNik}");
                    break;
                }
                
                // Jika lebih dari 16, ambil 16 digit pertama
                if (strlen($digits) > 16 && $bestNik === null) {
                    $candidate = substr($digits, 0, 16);
                    $bestNik = $candidate;
                    logInfo("NIK OCR - Partial match PSM {$psm}, using first 16: {$bestNik}");
                    // Jangan break, coba PSM lain yang mungkin lebih akurat
                }
                
                // Jika 14-15 digit, simpan sebagai kandidat tapi coba PSM lain
                if (strlen($digits) >= 14 && strlen($digits) <= 15 && $bestNik === null) {
                    logInfo("NIK OCR - Close match PSM {$psm}: '{$digits}' ({$psm} digits, need 16)");
                }
                
            } catch (Exception $e) {
                logError("NIK OCR - PSM {$psm} error: " . $e->getMessage());
            }
        }
        
        // Cleanup temporary file
        if (file_exists($nikCropPath)) {
            unlink($nikCropPath);
        }
        
        return $bestNik;
        
    } catch (Exception $e) {
        logError("ocrNikFromImage error: " . $e->getMessage());
        
        // Cleanup on error
        if (file_exists($nikCropPath)) {
            unlink($nikCropPath);
        }
        
        return null;
    }
}
/**
 * Extract KTP fields dari OCR text - IMPROVED untuk handle OCR typos dan format variations
 * @param string $text OCR text hasil full-page scan
 * @param string|null $imagePath Path ke gambar preprocessed
 * @param string|null $originalImagePath Path ke gambar original (untuk dedicated NIK OCR)
 */
function extractKtpFields($text, $imagePath = null, $originalImagePath = null) {
    $fields = [
        'nik' => null,
        'nama' => null,
        'nomor_kk' => null,
        'tempat_tgl_lahir' => null,
        'jenis_kelamin' => null,
        'gol_darah' => null,
        'alamat' => null,
        'rt_rw' => null,
        'kelurahan' => null,
        'kecamatan' => null,
        'kota_kabupaten' => null,
        'provinsi' => null,
        'agama' => null,
        'status_perkawinan' => null,
        'pekerjaan' => null,
        'kewarganegaraan' => null,
        'berlaku_hingga' => null,
    ];
    
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $lineLower = strtolower($line);
        
        // Extract PROVINSI (first, karena biasanya di paling atas)
        if (preg_match('/^PROVINSI\s+([A-Z\s]+?)(?:\s+KABUPATEN|\s+KOTA|$)/i', $line, $matches)) {
            $prov = trim($matches[1]);
            if (empty($fields['provinsi'])) { // Only if not found yet
                $prov = cleanupOCRNoise($prov);
                if (!empty($prov) && strlen($prov) > 2) {
                    $fields['provinsi'] = $prov;
                }
            }
        }
        
        // Extract KOTA/KABUPATEN (usually after PROVINSI)
        if (preg_match('/(?:KOTA|KABUPATEN)\s+([A-Z\s]+?)(?:\s*$|\s+NIK)/i', $line, $matches)) {
            $kota = trim($matches[1]);
            if (empty($fields['kota_kabupaten'])) {
                $kota = cleanupOCRNoise($kota);
                if (!empty($kota) && strlen($kota) > 2) {
                    $fields['kota_kabupaten'] = $kota;
                }
            }
        }
        
        // Extract NIK - lebih fleksibel terhadap format dan operator
        // (ini sebagai fallback, dedicated NIK OCR akan dijalankan setelah loop)
        if (stripos($lineLower, 'nik') !== false) {
            // Handle variations: "NIK : 123", "NIK= 123", "NIK 123", "NIK123", "NIK. : 123", "NIK.: 123"
            // Accept any separator between NIK and digits, including noise: : = — - > . | ! +
            if (preg_match('/NIK[\.\s]*[:=—\-\s>\|\.\!\+]*\s*([0-9OolISZB8\?\s]+)/i', $line, $matches)) {
                $nikCandidate = normalizeNikCandidate($matches[1]);
                if ($nikCandidate !== null && empty($fields['nik'])) {
                    $fields['nik'] = $nikCandidate;
                    logInfo("NIK from text extraction: " . $fields['nik']);
                }
            }
        }
        
        // Extract Nama - lebih fleksibel, capture semuanya ke end of line, lalu bersihkan dengan cleanupOCRNoise
        if (preg_match('/Nama\s*[:=—\-\s>\|\.\!\+]*\s*(.+?)(?:\s*$|(?=\n))/i', $line, $matches)) {
            $nama = trim($matches[1]);
            $nama = cleanupOCRNoise($nama);
            if (!empty($nama) && strlen($nama) > 2 && empty($fields['nama'])) {
                $fields['nama'] = $nama;
            }
        }
        
        // Extract Nomor Kartu Keluarga
        if (preg_match('/(?:No(?:mor)?\.?\s*)?Kartu\s*Keluarga\s*[:=—\-\s>\|\.\!\+]*\s*(\d{16})/i', $line, $matches)) {
            if (empty($fields['nomor_kk'])) {
                $fields['nomor_kk'] = $matches[1];
            }
        }
        
        // Extract Tempat/Tgl Lahir - handle typos seperti "Tempav", "Tempa", "TempavTgl"
        if (preg_match('/Temp[av]*t?\s*[\/\\\\]?\s*Tgl\s*(?:Lahir)?\s*[:=—\-\s>\|\.\!\+]*\s*(.+?)(?:\s*$|(?=\n))/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3 && empty($fields['tempat_tgl_lahir'])) {
                $fields['tempat_tgl_lahir'] = $combined;
            }
        }
        // Fallback: jika ada "Lahir" sendirian dengan data setelah
        if (empty($fields['tempat_tgl_lahir']) && preg_match('/Lahir\s*[:=—\-\s>\.]*\s*(.+?)(?:\s*$|(?=\n))/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3) {
                $fields['tempat_tgl_lahir'] = $combined;
            }
        }
        
        // Extract Jenis Kelamin - accept any operator before value, stop at first meaningful keyword or end
        if (preg_match('/Jenis\s+Kelamin\s*[:=—\-\s>\|\.\!\+]*\s*([A-Za-z\s\-]+?)(?:\s+(?:Gol|Darah|Alamat|RT|Agama)|\s*$)/i', $line, $matches)) {
            $jk = trim($matches[1]);
            // Extract gender keyword
            if (preg_match('/(Laki|Perempuan)/i', $jk, $jk_matches)) {
                if (empty($fields['jenis_kelamin'])) {
                    $fields['jenis_kelamin'] = ucfirst(strtolower($jk_matches[1]));
                }
            }
        }
        
        // Extract Golongan Darah - accept any operator, extract only blood type
        // Handle: "Gol. Darah : AB = =" or "Golongan Darah: O" etc
        if (preg_match('/Gol(?:ongan)?\.?\s+Darah\s*[:=—\-\s>\.]*\s*([A-Za-z0-9\+\-]+?)(?:[\s=:—\-~>]+|$)/i', $line, $matches)) {
            $gol = trim($matches[1]);
            // Extract blood type (A, B, AB, O with optional +/-)
            if (preg_match('/(AB|A|B|O)[\+\-]?/i', $gol, $gol_matches)) {
                if (empty($fields['gol_darah'])) {
                    $fields['gol_darah'] = strtoupper($gol_matches[1]);
                }
            }
        }
        
        // Extract Alamat - accept any operator (including >), stop at next field keyword or end
        if (preg_match('/Alamat\s*[:=—\-\s>\.]*\s*([A-Za-z0-9\s\.\-\,]+?)(?:\s+(?:RT|Kel|Kec|Agama)|\s*$)/i', $line, $matches)) {
            $alamat = trim($matches[1]);
            $alamat = cleanupOCRNoise($alamat);
            if (!empty($alamat) && strlen($alamat) > 2 && empty($fields['alamat'])) {
                $fields['alamat'] = $alamat;
            }
        }
        
        // Extract RT/RW - match "010/020" format dengan flexible operator
        if (preg_match('/RT\s*[\/\\-]\s*RW\s*[:=—\-\s>\|\.\!\+]*\s*(\d+)\s*[\/\\-]\s*(\d+)/i', $line, $matches)) {
            $rt = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
            $rw = str_pad($matches[2], 3, '0', STR_PAD_LEFT);
            if (empty($fields['rt_rw'])) {
                $fields['rt_rw'] = $rt . '/' . $rw;
            }
        }
        
        // Extract Kelurahan/Desa - Multiple patterns untuk handle berbagai format
        // Pattern 1: "Kel/Desa :" atau "Kelurahan/Desa :" - lebih prioritas, start dari line beginning
        if (preg_match('/^\s*(?:Kel(?:urahan)?|Desa)\s*[\/\\]\s*(?:Desa|Kelurahan)?\s*[:=—\-\s>\.]+\s*([A-Z][A-Za-z\s\-]+?)(?:[\s—~=:]+|$)/i', $line, $matches)) {
            $kel = trim($matches[1]);
            $kel = cleanupOCRNoise($kel);
            if (!empty($kel) && strlen($kel) > 2 && empty($fields['kelurahan'])) {
                $fields['kelurahan'] = $kel;
            }
        }
        // Pattern 2: "Kelurahan:" atau "Desa:" standalone - MUST start line, dengan word boundary
        if (empty($fields['kelurahan']) && preg_match('/^\s*\b(?:Kelurahan|Desa)\b\s*[:=—\-\s>\.]+\s*([A-Z][A-Za-z\s\-]+?)(?:[\s—~=:]+|$)/i', $line, $matches)) {
            $kel = trim($matches[1]);
            $kel = cleanupOCRNoise($kel);
            if (!empty($kel) && strlen($kel) > 2) {
                $fields['kelurahan'] = $kel;
            }
        }
        
        // Extract Kecamatan - flexible operator, stop at closing paren, special chars or next field
        if (preg_match('/Kecamatan\s*[:=—\-\s>\.]*\s*([A-Za-z\s\-]+?)(?:[\s=:—\-~\)]+|$)/i', $line, $matches)) {
            $kec = trim($matches[1]);
            $kec = cleanupOCRNoise($kec);
            if (!empty($kec) && strlen($kec) > 2 && empty($fields['kecamatan'])) {
                $fields['kecamatan'] = $kec;
            }
        }
        
        // Extract Agama - flexible operator, stop at punctuation or next field
        if (preg_match('/Agama\s*[:=—\-\s>\|\.\!\+]*\s*([A-Za-z\s]+?)(?:[\,\;\.><\|]|\s+(?:Status|Pekerjaan|Kewarga)|\s*$)/i', $line, $matches)) {
            $agama = trim($matches[1]);
            $agama = cleanupOCRNoise($agama);
            if (!empty($agama) && strlen($agama) > 2 && empty($fields['agama'])) {
                $fields['agama'] = $agama;
            }
        }
        
        // Extract Status Perkawinan - match known valid marital statuses to avoid capturing right-side text (e.g. city names)
        if (preg_match('/Status\s+Perkawinan\s*[:=—\-\s>\|\.\!\+]*\s*(BELUM\s+KAWIN|KAWIN|CERAI\s+HIDUP|CERAI\s+MATI)/i', $line, $matches)) {
            $status = trim($matches[1]);
            $status = preg_replace('/\s+/', ' ', $status); // Normalize spaces
            if (!empty($status) && strlen($status) > 2 && empty($fields['status_perkawinan'])) {
                $fields['status_perkawinan'] = strtoupper($status);
            }
        }
        
        // Extract Pekerjaan - flexible operator, allow slash in value (e.g. PELAJAR/MAHASISWA)
        // Stop at "Kewarga", date pattern (dd-mm-yyyy), or trailing uppercase city-like words
        if (preg_match('/Pekerjaan\s*[:=—\-\s>\|\.\!\+]*\s*([A-Za-z\/\s\-]+?)(?:\s+(?:Kewarga)|\s+\d{2}[\-\/]\d{2}[\-\/]\d{2,4}|\s*$)/i', $line, $matches)) {
            $pekerjaan = trim($matches[1]);
            $pekerjaan = cleanupOCRNoise($pekerjaan);
            if (!empty($pekerjaan) && strlen($pekerjaan) > 2 && empty($fields['pekerjaan'])) {
                $fields['pekerjaan'] = $pekerjaan;
            }
        }
        
        // Extract Kewarganegaraan - flexible operator (note: double colon case), stop at "Berlaku" or end
        if (preg_match('/Kewarga(?:negara)?an\s*[:=—\-\s>\|\.\!\+]*\s*:?\s*([A-Za-z\s]+?)(?:[\,\;\.]|\s+(?:Berlaku|$)|\s*$)/i', $line, $matches)) {
            $kewarga = trim($matches[1]);
            $kewarga = cleanupOCRNoise($kewarga);
            if (!empty($kewarga) && strlen($kewarga) > 2 && empty($fields['kewarganegaraan'])) {
                $fields['kewarganegaraan'] = $kewarga;
            }
        }
        
        // Extract Berlaku Hingga - flexible operator, stop at special chars or end
        if (preg_match('/Berlaku\s+Hingga\s*[:=—\-\s>\.]*\s*([A-Za-z\s]+?)(?:[\(\)~—]|\s*$)/i', $line, $matches)) {
            $berlaku = trim($matches[1]);
            $berlaku = cleanupOCRNoise($berlaku);
            if (!empty($berlaku) && empty($fields['berlaku_hingga'])) {
                $fields['berlaku_hingga'] = $berlaku;
            }
        }
    }
    
    // =============================================
    // DEDICATED NIK OCR - Jalankan OCR terpisah pada area NIK yang di-crop
    // Ini memberikan hasil yang JAUH lebih akurat daripada extract dari full-page text
    // karena menggunakan preprocessing khusus digit dan Tesseract digit-only mode
    // =============================================
    if ($originalImagePath !== null && file_exists($originalImagePath)) {
        logInfo("Running dedicated NIK OCR on original image: " . $originalImagePath);
        $dedicatedNik = ocrNikFromImage($originalImagePath);
        
        if ($dedicatedNik !== null) {
            $previousNik = $fields['nik'];
            $fields['nik'] = $dedicatedNik;
            logInfo("Dedicated NIK OCR result: {$dedicatedNik} (replaced text-based: " . ($previousNik ?? 'null') . ")");
        } else {
            logInfo("Dedicated NIK OCR returned null, keeping text-based result: " . ($fields['nik'] ?? 'null'));
        }
    }
    
    // FALLBACK: Untuk field yang masih belum terdeteksi, coba extract dari seluruh text
    // Ini handle kasus dimana field masih merged di satu baris panjang
    if (empty($fields['gol_darah'])) {
        if (preg_match('/(?:Gol|Golongan)\.?\s+Darah\s*[:=—\-\s>\.]*\s*([A-Za-z0-9\+\-]+?)(?:[\s=:—\-~>]+|\s+\w+|$)/i', $text, $matches)) {
            $gol = trim($matches[1]);
            if (preg_match('/(AB|A|B|O)[\+\-]?/i', $gol, $gol_matches)) {
                $fields['gol_darah'] = strtoupper($gol_matches[1]);
            }
        }
    }
    
    if (empty($fields['kelurahan'])) {
        // Try pattern 1: "Kel/Desa" atau "Kelurahan/Desa" format dengan slash
        if (preg_match('/(?:^|\n)\s*(?:Kel(?:urahan)?|Desa)\s*[\/\\]\s*(?:Desa|Kelurahan)?\s*[:=—\-\s>\.]+\s*([A-Z][A-Za-z\s\-]+?)(?:[\s—~=:]+|$)/i', $text, $matches)) {
            $kel = trim($matches[1]);
            $kel = cleanupOCRNoise($kel);
            if (!empty($kel) && strlen($kel) > 2) {
                $fields['kelurahan'] = $kel;
            }
        }
        // Try pattern 2: "Kelurahan:" atau "Desa:" standalone dengan word boundary dan start anchor
        if (empty($fields['kelurahan']) && preg_match('/(?:^|\n)\s*\b(?:Kelurahan|Desa)\b\s*[:=—\-\s>\.]+\s*([A-Z][A-Za-z\s\-]+?)(?:[\s—~=:]+|\s+(?:Kec|Agama)|$)/i', $text, $matches)) {
            $kel = trim($matches[1]);
            $kel = cleanupOCRNoise($kel);
            if (!empty($kel) && strlen($kel) > 2) {
                $fields['kelurahan'] = $kel;
            }
        }
    }
    
    if (empty($fields['kecamatan'])) {
        if (preg_match('/Kecamatan\s*[:=—\-\s>\.]*\s*([A-Za-z\s\-]+?)(?:[\s=:—\-~\)\|]|$)/i', $text, $matches)) {
            $kec = trim($matches[1]);
            $kec = cleanupOCRNoise($kec);
            if (!empty($kec) && strlen($kec) > 2) {
                $fields['kecamatan'] = $kec;
            }
        }
    }
    
    // Cleanup fields - remove trailing operators, noise, and normalize
    foreach ($fields as $key => &$value) {
        if ($value !== null) {
            $value = trim($value);
            
            // Remove multiple consecutive spaces
            $value = preg_replace('/\s+/', ' ', $value);
            
            // Remove trailing operators, special chars, and noise
            $value = preg_replace('/[\s=:—\-~>\.()!?|;,]+$/', '', $value);
            
            // Also remove leading operators
            $value = preg_replace('/^[\s=:—\-~>\.()!?|;,]+/', '', $value);
            
            // Final trim
            $value = trim($value);
        }
    }
    
    return $fields;
}

// ============================================
// OUTPUT RESPONSE
// ============================================

// Display hasil sebagai JSON
if (isset($_GET['json']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Clear output buffer untuk HTML response
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR KTP Scanner - OpenCV Edition</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .header .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            margin-top: 10px;
        }
        
        .content {
            padding: 40px 20px;
        }
        
        .form-group {
            margin-bottom: 30px;
        }
        
        .upload-area {
            border: 3px dashed #667eea;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9ff;
        }
        
        .upload-area:hover {
            border-color: #764ba2;
            background: #f0f2ff;
        }
        
        .upload-area.dragover {
            border-color: #764ba2;
            background: #e8ecff;
            transform: scale(1.02);
        }
        
        .upload-icon {
            font-size: 3em;
            margin-bottom: 10px;
        }
        
        .upload-text h3 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .upload-text p {
            color: #666;
            font-size: 0.9em;
        }
        
        input[type="file"] {
            display: none;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 100%;
            margin-top: 15px;
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            margin-top: 10px;
            width: 100%;
        }
        
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        
        .result-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            display: none;
        }
        
        .result-section.show {
            display: block;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .images-preview {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        
        .image-preview {
            text-align: center;
        }
        
        .image-preview h4 {
            margin-bottom: 10px;
            color: #333;
            font-size: 0.95em;
        }
        
        .image-preview img {
            max-width: 100%;
            max-height: 300px;
            border-radius: 5px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        .text-result {
            background: white;
            padding: 20px;
            border-radius: 5px;
            border-left: 4px solid #667eea;
            margin: 20px 0;
        }
        
        .text-result h4 {
            margin-bottom: 10px;
            color: #333;
        }
        
        .text-result p {
            color: #555;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .copy-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.9em;
            margin-top: 10px;
        }
        
        .copy-btn:hover {
            background: #764ba2;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .loading.show {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .filename {
            color: #667eea;
            font-weight: 600;
            margin-top: 10px;
            font-size: 0.95em;
        }
        
        .fields-section {
            background: white;
            padding: 20px;
            border-radius: 5px;
            border-left: 4px solid #28a745;
            margin: 20px 0;
        }
        
        .fields-section h4 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .fields-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .field-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 4px;
            border-left: 3px solid #667eea;
        }
        
        .field-label {
            font-size: 0.85em;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .field-value {
            color: #333;
            font-size: 1em;
            word-wrap: break-word;
        }
        
        .field-value.empty {
            color: #999;
            font-style: italic;
        }
        
        .debug-info {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 0.85em;
            color: #666;
        }
        
        @media (max-width: 600px) {
            .header h1 {
                font-size: 1.8em;
            }
            
            .images-preview {
                grid-template-columns: 1fr;
            }
            
            .fields-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔤 OCR KTP Scanner</h1>
            <p>Scan Kartu Tanda Penduduk dengan OpenCV + Tesseract</p>
            <div class="badge">⚡ OpenCV Powered Preprocessing</div>
        </div>
        
        <div class="content">
            <form id="ocrForm" enctype="multipart/form-data">
                <div class="form-group">
                    <div class="upload-area" id="uploadArea">
                        <div class="upload-icon">📸</div>
                        <div class="upload-text">
                            <h3>Klik atau Drag & Drop Gambar KTP</h3>
                            <p>Format: JPG, PNG, GIF, BMP, TIFF (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="imageInput" name="image" accept="image/*" required>
                    <div class="filename" id="filename"></div>
                    <button type="submit" class="btn btn-primary">🚀 Scan KTP</button>
                    <button type="reset" class="btn btn-secondary">🔄 Reset</button>
                </div>
            </form>
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Sedang memproses dengan OpenCV...</p>
                <p style="font-size: 0.9em; color: #666; margin-top: 10px;">1️⃣ Preprocessing  →  2️⃣ OCR  →  3️⃣ Ekstraksi Data</p>
            </div>
            
            <div class="result-section" id="resultSection">
                <div id="resultMessage"></div>
                <div class="images-preview" id="imagesPreview" style="display: none;"></div>
                
                <div class="text-result" id="textResult" style="display: none;">
                    <h4>📄 Hasil Teks yang Dikonversi:</h4>
                    <p id="extractedText"></p>
                    <button type="button" class="copy-btn" onclick="copyToClipboard()">📋 Salin Teks</button>
                </div>
                
                <div class="fields-section" id="fieldsSection" style="display: none;">
                    <h4>🆔 Data Terstruktur dari KTP:</h4>
                    <div class="fields-grid" id="fieldsGrid"></div>
                    <button type="button" class="copy-btn" onclick="copyFieldsToClipboard()" style="margin-top: 20px;">📋 Salin Semua Data</button>
                </div>
                
                <div class="debug-info" id="debugInfo" style="display: none;"></div>
            </div>
        </div>
    </div>
    
    <script>
        const uploadArea = document.getElementById('uploadArea');
        const imageInput = document.getElementById('imageInput');
        const ocrForm = document.getElementById('ocrForm');
        const resultSection = document.getElementById('resultSection');
        const loading = document.getElementById('loading');
        const filename = document.getElementById('filename');
        
        let lastResult = null;
        
        // Drag & Drop
        uploadArea.addEventListener('click', () => imageInput.click());
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                imageInput.files = files;
                updateFilename();
            }
        });
        
        // Update filename saat file dipilih
        imageInput.addEventListener('change', updateFilename);
        
        function updateFilename() {
            if (imageInput.files.length > 0) {
                filename.textContent = '📁 ' + imageInput.files[0].name;
            } else {
                filename.textContent = '';
            }
        }
        
        // Submit form
        ocrForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!imageInput.files.length) {
                alert('Pilih gambar terlebih dahulu!');
                return;
            }
            
            // Show loading
            loading.classList.add('show');
            resultSection.classList.remove('show');
            
            try {
                const formData = new FormData();
                formData.append('image', imageInput.files[0]);
                
                const response = await fetch(window.location.href + '?json=1', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                lastResult = result;
                
                loading.classList.remove('show');
                resultSection.classList.add('show');
                
                // Display message
                const messageDiv = document.getElementById('resultMessage');
                messageDiv.innerHTML = result.success ? 
                    `<div class="alert alert-success">✅ ${result.message}</div>` :
                    `<div class="alert alert-error">❌ ${result.message}</div>`;
                
                if (result.success) {
                    // Display images
                    const imagesPreview = document.getElementById('imagesPreview');
                    imagesPreview.innerHTML = `
                        <div class="image-preview">
                            <h4>Original KTP</h4>
                            <img src="${result.originalImage}" alt="Original">
                        </div>
                        <div class="image-preview">
                            <h4>Preprocessed (OpenCV)</h4>
                            <img src="${result.preprocessedImage}" alt="Preprocessed">
                        </div>
                    `;
                    imagesPreview.style.display = 'grid';
                    
                    // Display extracted text
                    document.getElementById('extractedText').textContent = result.text;
                    document.getElementById('textResult').style.display = 'block';
                    
                    // Display fields
                    displayFields(result.fields);
                    
                    // Display debug info
                    if (result.debug && Object.keys(result.debug).length > 0) {
                        const debugDiv = document.getElementById('debugInfo');
                        debugDiv.innerHTML = `
                            <strong>⏱️ Debug Info:</strong><br>
                            • Python: ${result.debug.python_path || 'auto-detected'}<br>
                            • Preprocessing Time: ${result.debug.preprocess_time}s<br>
                            • OCR Time: ${result.debug.ocr_time}s
                        `;
                        debugDiv.style.display = 'block';
                    }
                }
            } catch (error) {
                loading.classList.remove('show');
                resultSection.classList.add('show');
                
                const messageDiv = document.getElementById('resultMessage');
                messageDiv.innerHTML = `<div class="alert alert-error">❌ Error: ${error.message}</div>`;
            }
        });
        
        function displayFields(fields) {
            const fieldsGrid = document.getElementById('fieldsGrid');
            fieldsGrid.innerHTML = '';
            
            const fieldLabels = {
                'nik': 'NIK',
                'nama': 'Nama Lengkap',
                'nomor_kk': 'Nomor Kartu Keluarga',
                'tempat_tgl_lahir': 'Tempat/Tgl Lahir',
                'jenis_kelamin': 'Jenis Kelamin',
                'gol_darah': 'Golongan Darah',
                'alamat': 'Alamat',
                'rt_rw': 'RT/RW',
                'kelurahan': 'Kelurahan/Desa',
                'kecamatan': 'Kecamatan',
                'kota_kabupaten': 'Kota/Kabupaten',
                'provinsi': 'Provinsi',
                'agama': 'Agama',
                'status_perkawinan': 'Status Perkawinan',
                'pekerjaan': 'Pekerjaan',
                'kewarganegaraan': 'Kewarganegaraan',
                'berlaku_hingga': 'Berlaku Hingga'
            };
            
            for (const [key, value] of Object.entries(fields)) {
                const fieldItem = document.createElement('div');
                fieldItem.className = 'field-item';
                
                const label = fieldLabels[key] || key;
                const displayValue = value || '(tidak terdeteksi)';
                const valueClass = value ? '' : 'empty';
                
                fieldItem.innerHTML = `
                    <div class="field-label">${label}</div>
                    <div class="field-value ${valueClass}">${escapeHtml(displayValue)}</div>
                `;
                
                fieldsGrid.appendChild(fieldItem);
            }
            
            document.getElementById('fieldsSection').style.display = 'block';
        }
        
        function copyToClipboard() {
            const text = document.getElementById('extractedText').textContent;
            navigator.clipboard.writeText(text).then(() => {
                alert('✅ Teks berhasil disalin!');
            });
        }
        
        function copyFieldsToClipboard() {
            if (!lastResult) return;
            
            let text = 'Data KTP:\n\n';
            for (const [key, value] of Object.entries(lastResult.fields)) {
                if (value) {
                    text += `${key}: ${value}\n`;
                }
            }
            
            navigator.clipboard.writeText(text).then(() => {
                alert('✅ Data terstruktur berhasil disalin!');
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
