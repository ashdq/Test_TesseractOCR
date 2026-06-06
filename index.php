<?php
// Suppress warnings untuk clean JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering untuk JSON response
ob_start();

require_once __DIR__ . '/vendor/autoload.php';

use thiagoalessio\TesseractOCR\TesseractOCR;

// Konfigurasi
$uploadsDir = __DIR__ . '/uploads';
$maxFileSize = 5 * 1024 * 1024; // 5MB
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'];

// Buat folder uploads jika belum ada
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

$result = [
    'success' => false,
    'message' => '',
    'text' => '',
    'fields' => [],
    'originalImage' => '',
    'preprocessedImage' => ''
];

// Proses upload dan OCR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_ktp']) && isset($_FILES['image_kk'])) {
    $fileKtp = $_FILES['image_ktp'];
    $fileKk = $_FILES['image_kk'];
    
    // Validasi file
    $errorKtp = validateFile($fileKtp, $maxFileSize, $allowedExtensions);
    $errorKk = validateFile($fileKk, $maxFileSize, $allowedExtensions);
    
    if ($errorKtp) {
        $result['message'] = 'KTP: ' . $errorKtp;
    } elseif ($errorKk) {
        $result['message'] = 'KK: ' . $errorKk;
    } else {
        // Simpan file original
        $filenameKtp = uniqid('ocr_ktp_') . '.' . getFileExtension($fileKtp['name']);
        
        // Buat folder khusus KTP jika belum ada
        $uploadsKtpDir = __DIR__ . '/uploads/upload ktp';
        if (!is_dir($uploadsKtpDir)) {
            mkdir($uploadsKtpDir, 0755, true);
        }
        $filepathKtp = $uploadsKtpDir . '/' . $filenameKtp;
        
        // Buat folder khusus KK jika belum ada
        $uploadsKkDir = __DIR__ . '/uploads/upload kk';
        if (!is_dir($uploadsKkDir)) {
            mkdir($uploadsKkDir, 0755, true);
        }
        
        $filenameKk = uniqid('ocr_kk_') . '.' . getFileExtension($fileKk['name']);
        $filepathKk = $uploadsKkDir . '/' . $filenameKk;
        
        if (move_uploaded_file($fileKtp['tmp_name'], $filepathKtp) && move_uploaded_file($fileKk['tmp_name'], $filepathKk)) {
            $result['originalImage'] = 'uploads/upload ktp/' . $filenameKtp;
            
            // PREPROCESSING KTP
            $preprocessedFilenameKtp = uniqid('preprocessed_ktp_') . '.png';
            $preprocessedPathKtp = $uploadsKtpDir . '/' . $preprocessedFilenameKtp;
            $preprocessSuccessKtp = preprocessImage($filepathKtp, $preprocessedPathKtp);
            
            // PREPROCESSING KK
            $preprocessedFilenameKk = uniqid('preprocessed_kk_') . '.png';
            $preprocessedPathKk = $uploadsKkDir . '/' . $preprocessedFilenameKk;
            $preprocessSuccessKk = preprocessImage($filepathKk, $preprocessedPathKk);
            
            if (!$preprocessSuccessKtp || !$preprocessSuccessKk) {
                $result['message'] = 'Error saat melakukan preprocessing gambar.';
                if (file_exists($filepathKtp)) unlink($filepathKtp);
                if (file_exists($filepathKk)) unlink($filepathKk);
                if (file_exists($preprocessedPathKtp)) unlink($preprocessedPathKtp);
                if (file_exists($preprocessedPathKk)) unlink($preprocessedPathKk);
            } else {
                $result['preprocessedImage'] = 'uploads/upload ktp/' . $preprocessedFilenameKtp;
                $result['originalImageKk'] = 'uploads/upload kk/' . $filenameKk;
                $result['preprocessedImageKk'] = 'uploads/upload kk/' . $preprocessedFilenameKk;
                
                try {
                    // --- 1. PROSES KTP ---
                    $ocrKtp = new TesseractOCR($preprocessedPathKtp);
                    $ocrKtp->lang('ind+eng')->psm(6)->oem(3);
                    $extractedTextKtp = $ocrKtp->run();
                    
                    if (empty($extractedTextKtp) || trim($extractedTextKtp) === '') {
                        throw new Exception('Tesseract KTP menghasilkan hasil kosong.');
                    }
                    
                    $processedTextKtp = postProcessOCRText(trim((string)$extractedTextKtp));
                    if (!is_string($processedTextKtp) || trim($processedTextKtp) === '') $processedTextKtp = trim((string)$extractedTextKtp);
                    
                    $result['text'] = trim($processedTextKtp); // Raw text preview KTP
                    $result['fields'] = extractKtpFields($result['text'], $preprocessedPathKtp);
                    
                    // --- 2. PROSES KK ---
                    $ocrKk = new TesseractOCR($preprocessedPathKk);
                    $ocrKk->lang('ind+eng')->psm(6)->oem(3);
                    $extractedTextKk = $ocrKk->run();
                    
                    if (!empty($extractedTextKk)) {
                        $nomorKk = extractNomorKK($extractedTextKk);
                        if ($nomorKk) {
                            $result['fields']['nomor_kk'] = $nomorKk;
                            // Tambahkan Nomor KK ke teks mentah bagian bawah
                            $result['text'] .= "\nNomor KK: " . $nomorKk;
                        } else {
                            error_log("Gagal menemukan Nomor KK dari teks OCR KK.");
                        }
                    } else {
                        error_log("Tesseract KK menghasilkan hasil kosong.");
                    }
                    
                    $result['success'] = true;
                    $result['message'] = 'Gambar KTP & KK berhasil dikonversi!';
                    
                } catch (Exception $e) {
                    $result['message'] = 'Error saat melakukan OCR: ' . $e->getMessage();
                    if (file_exists($filepathKtp)) unlink($filepathKtp);
                    if (file_exists($filepathKk)) unlink($filepathKk);
                    if (file_exists($preprocessedPathKtp)) unlink($preprocessedPathKtp);
                    if (file_exists($preprocessedPathKk)) unlink($preprocessedPathKk);
                }
            }
        } else {
            $result['message'] = 'Gagal menyimpan file. Pastikan folder uploads writable.';
        }
    }
}

/**
 * Validasi file yang diupload
 */
function validateFile($file, $maxSize, $allowedExtensions) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File terlalu besar (melebihi upload_max_filesize di php.ini)',
            UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (melebihi MAX_FILE_SIZE di form)',
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
 * Ambil ekstensi file
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Helper function: Clean common OCR noise characters from extracted fields
 * Removes: *, ~, !, ^, |, #, @, $, %, &, etc.
 * Also removes dangling partial words/letters at the end
 */
function cleanupOCRNoise($text) {
    $text = trim($text);
    
    // Potong teks di pipa | karena biasanya itu batas kolom foto
    if (($pipePos = strpos($text, '|')) !== false) {
        $text = substr($text, 0, $pipePos);
    }
    
    // Remove common OCR noise characters (tidak termasuk '-' agar alamat/tanggal tetap utuh)
    $text = preg_replace('/[\*~!^#@$%&+=\(\)\[\]{}\\<>\?`"\'\.]{2,}/i', '', $text);
    $text = preg_replace('/[\*~!^#@$%&+=\[\]{}\\<>\?`"\']/', '', $text);
    
    // Remove multiple spaces
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Remove trailing noise: single letters/digits with space
    // e.g., "SIMBOLON 11 sa" â†’ "SIMBOLON"
    $text = preg_replace('/\s+\d+\s+[a-z]{1,3}\s*$/i', '', $text);
    $text = preg_replace('/\s+[a-z]{1,2}\s*$/i', '', $text);
    
    // Remove patterns like "Kk ~~" or similar garbage at end
    $text = preg_replace('/\s+[a-z]{1,2}\s*~+\s*[a-z]?\s*$/i', '', $text);
    
    return trim($text);
}

/**
 * Post-process a KTP field value: strip watermark words, trailing dates,
 * trailing city names from the stamp area, and normalize spacing.
 * @param string $value   The raw extracted value
 * @param string $field   Field name hint for context-specific cleaning
 * @return string
 */
function cleanupFieldValue($value, $field = '') {
    $value = trim($value);
    
    // Potong di karakter pipe |
    if (($pipePos = strpos($value, '|')) !== false) {
        $value = substr($value, 0, $pipePos);
    }
    
    // Hapus kata-kata watermark KTP yang sering ikut terbaca
    $watermarkWords = [
        'KARTU', 'TANDA', 'PENDUDUK', 'REPUBLIK', 'INDONESIA',
        'BERLAKU', 'SEUMUR', 'HIDUP',
        'PENDU', 'NDUK', 'TAND', 'ANDA',
    ];
    // Hanya hapus watermark jika bukan field berlaku_hingga
    if ($field !== 'berlaku_hingga') {
        foreach ($watermarkWords as $ww) {
            // Hapus jika muncul di AKHIR teks (bukan bagian dari nilai asli)
            $value = preg_replace('/\s+' . preg_quote($ww, '/') . '\s*$/i', '', $value);
        }
    }
    
    // Khusus field nama: hapus noise watermark yang lebih agresif
    if ($field === 'nama') {
        // Hapus pola kata pendek yang berulang di akhir: "SA SA", "KA KA", "DU DU", dll
        // Ini adalah artefak watermark yang terpotong OCR
        $value = preg_replace('/(?:\s+([A-Z]{1,4}))\s+\1(?:\s+\1)*\s*$/i', '', $value);
        
        // Hapus satu kata pendek (1-4 huruf) yang berdiri sendiri di akhir
        // tapi BUKAN gelar umum seperti S.E, M.M, drg., dr., S.Sos, dll
        // Gelar yang diizinkan: huruf besar + titik atau kombinasi
        $value = preg_replace('/\s+[A-Z]{1,3}\s*-\s*$/i', '', $value);  // hapus "SA -", "KA -"
        $value = preg_replace('/\s+-\s*$/', '', $value);  // hapus trailing " -"
    }
    
    // Strip trailing tanggal (dd-mm-yyyy atau dd - mm - yyyy)
    $value = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{2,4}\s*$/i', '', $value);
    
    // Strip trailing angka tunggal yang tidak relevan
    $value = preg_replace('/\s+\d{1,2}\s*$/', '', $value);
    
    // Strip kata pendek acak di akhir (1-3 huruf saja) yang bukan singkatan umum
    // Pengecualian: A, B, AB, O (golongan darah), WNI, WNA
    if (!in_array($field, ['gol_darah', 'kewarganegaraan'])) {
        $value = preg_replace('/\s+(?!WNI|WNA|A\b|AB\b|B\b|O\b)[A-Z]{1,2}\s*$/u', '', $value);
    }
    
    // Normalize spaces
    $value = preg_replace('/\s+/', ' ', $value);
    
    return trim($value);
}

/**
 * Helper function: Clean dan normalize Tempat/Tgl Lahir string
 * Handles:
 * - Removing corrupted prefix like "Te, mpat/Tgl: Lahir =:"
 * - Removing "Lahir:" prefix if present
 * - Extracting location name and date
 * - Fixing broken dates like "197 ) 1" â†’ "1971"
 * - Removing non-date characters from date portion
 */
function cleanupTempatTglLahir($text) {
    error_log("[cleanupTempatTglLahir] Input: '" . $text . "'");
    
    $text = trim($text);
    
    // Step 0: Remove corrupted prefix like "Te, mpat/Tgl: Lahir =:" or "Te,mpat/Tgl"
    // Pattern: letters/commas/noise before actual data starts
    $text = preg_replace('/^.*?[Ll][Aa][Hh][Ii][Rr]\s*[^A-Za-z0-9]*\s*/i', '', $text);
    
    // Step 1: Try to extract location and date
    // Pattern: location (text) followed by date (dd-mm-yyyy with possible spacing/malformation)
    // Location ends with comma or digit
    
    if (preg_match('/^([^,\d]+?)\s*,?\s*(\d.*?)\s*$/i', $text, $matches)) {
        $location = trim($matches[1]);
        $dateStr = trim($matches[2]);
        
        // Clean location - remove OCR noise
        $location = cleanupOCRNoise($location);
        
        error_log("[cleanupTempatTglLahir] Location: '" . $location . "', Date raw: '" . $dateStr . "'");
        
        // Step 3: Clean up date - remove non-digit/date chars but preserve structure
        // Handle cases like: "11 - 11 - 197 ) 1" â†’ "11-11-1971"
        $dateStr = trim($dateStr);
        
        // Remove OCR noise from date too
        $dateStr = preg_replace('/[\*~!^|#@$%&+=\(\)\[\]{}\\<>\/\?`"\']/i', '', $dateStr);
        
        // Extract just digit groups separated by - or /
        if (preg_match('/\d+\s*[-\/]\s*\d+\s*[-\/]\s*\d+/i', $dateStr)) {
            // Extract all digit sequences
            preg_match_all('/\d+/', $dateStr, $digitMatches);
            $digits = $digitMatches[0];
            
            if (count($digits) >= 3) {
                // We have at least day-month-year
                $day = str_pad($digits[0], 2, '0', STR_PAD_LEFT);
                $month = str_pad($digits[1], 2, '0', STR_PAD_LEFT);
                $year = $digits[2];
                
                // Fix incomplete year: if year is 3 digits and looks incomplete, check for 4th digit
                if (strlen($year) == 3 && count($digits) >= 4) {
                    $year = $year . $digits[3];
                }
                
                // Ensure 4-digit year
                if (strlen($year) < 4) {
                    // Try to guess: if 3 digits like "197", might be "1970-1979"
                    if (strlen($year) == 3 && $year[0] == '1') {
                        $year = $year . '0';  // 197 â†’ 1970, then check if there's a 4th digit
                    }
                }
                
                $dateStr = $day . '-' . $month . '-' . $year;
                error_log("[cleanupTempatTglLahir] Cleaned date: '" . $dateStr . "'");
                
                $result = $location . ' / ' . $dateStr;
                error_log("[cleanupTempatTglLahir] Result: '" . $result . "'");
                return $result;
            }
        }
    }
    
    // Fallback: return original if parsing failed
    error_log("[cleanupTempatTglLahir] Fallback: returning original text");
    return $text;
}

/**
 * Normalisasi kandidat NIK dari OCR dengan agresif menonormalisir karakter yang sering salah baca.
 * Focus on digits 0-9, handle common OCR confusions.
 */
/**
 * Normalisasi kandidat NIK dari OCR.
 * Hanya konversi karakter yang secara visual SANGAT mirip angka.
 * Jangan konversi terlalu agresif karena bisa merusak angka yang sudah benar.
 */
function normalizeNikCandidate($value) {
    $niks = trim((string)$value);
    error_log("[normalizeNikCandidate] Input: '" . $niks . "'");
    
    // Remove spaces, dashes, dots, colons
    $niks = preg_replace('/[\s\-\.\,:]/', '', $niks);
    
    // Hanya konversi karakter yang secara visual SANGAT MIRIP dengan angka tertentu
    // dan sering salah baca oleh Tesseract pada dokumen cetak:
    $niks = str_replace(['O', 'o'], '0', $niks);   // O -> 0 (sangat mirip)
    $niks = str_replace(['l', 'I'], '1', $niks);   // l/I -> 1 (sangat mirip di font OCR-A)
    $niks = str_replace(['B'], '8', $niks);         // B -> 8 (mirip di font sempit)
    
    // Remove sisa karakter non-digit
    $niks = preg_replace('/\D/', '', $niks);
    
    error_log("[normalizeNikCandidate] After normalization: '" . $niks . "' (length: " . strlen($niks) . ")");

    if (strlen($niks) >= 16) {
        $result = substr($niks, 0, 16);
        error_log("[normalizeNikCandidate] Returning 16 digits: " . $result);
        return $result;
    }
    
    if (strlen($niks) >= 14) {
        error_log("[normalizeNikCandidate] Returning " . strlen($niks) . " digits: " . $niks);
        return $niks;
    }

    error_log("[normalizeNikCandidate] Failed: Only " . strlen($niks) . " digits found (need 14+)");
    return null;
}


/**
 * Create crop of NIK area with standard preprocessing (not aggressive).
 * Using main image preprocessing instead of digit-specific enhancement.
 */
function createNikCrop($imagePath, $outputPath) {
    error_log("[createNikCrop] Starting for: " . $imagePath);
    
    try {
        // Just use the standard preprocessing on a cropped area
        // Don't apply overly aggressive digit enhancement
        return createNikCropSimple($imagePath, $outputPath);
    } catch (Exception $e) {
        error_log('[createNikCrop] Exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create precise NIK crop using Imagick with digit-optimized preprocessing.
 * Crop area: 10-25% height (skip header), left 65% width (skip photo).
 * Upscale 3x and apply thresholding for clean binary output.
 */
function createNikCropSimple($imagePath, $outputPath) {
    error_log("[createNikCropSimple] Starting for: " . $imagePath);
    
    try {
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return createNikCropSimpleGD($imagePath, $outputPath);
        }
        
        $image = new Imagick($imagePath);
        $dimensions = $image->getImageGeometry();
        $imageWidth = $dimensions['width'];
        $imageHeight = $dimensions['height'];
        error_log("[createNikCropSimple] Image dimensions: " . $imageWidth . "x" . $imageHeight);
        
        // KTP Indonesia: Header = ~18% (Provinsi + Kota), NIK row = 18-32% height
        // Crop lebih ke bawah agar tidak menangkap teks kota/provinsi
        $yStart = (int)($imageHeight * 0.18);
        $yEnd   = (int)($imageHeight * 0.32);
        $cropWidth  = (int)($imageWidth * 0.70);  // 70% kiri, hindari foto
        $cropHeight = $yEnd - $yStart;
        error_log("[createNikCropSimple] Crop: y={$yStart}-{$yEnd}, w={$cropWidth}, h={$cropHeight}");

        $image->cropImage($cropWidth, $cropHeight, 0, $yStart);
        $image->setImagePage(0, 0, 0, 0);
        
        // Upscale 4x untuk digit OCR yang lebih baik
        $newWidth  = $cropWidth * 4;
        $newHeight = $cropHeight * 4;
        $image->scaleImage($newWidth, $newHeight);
        
        // Convert ke grayscale
        $image->setImageFormat('png');
        $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        
        // Normalize dan enhance
        $image->normalizeImage();
        $image->enhanceImage();
        
        // Threshold untuk binary bersih (teks hitam di atas putih)
        $image->thresholdImage(0.55 * \Imagick::getQuantum());
        
        // Padding putih untuk Tesseract
        $image->borderImage('white', 30, 30);
        
        $image->writeImage($outputPath);
        $image->destroy();
        
        error_log("[createNikCropSimple] Crop saved to: " . $outputPath);
        return true;
    } catch (Exception $e) {
        error_log('[createNikCropSimple] Exception: ' . $e->getMessage());
        return createNikCropSimpleGD($imagePath, $outputPath);
    }
}

/**
 * Create precise NIK crop using GD Library (fallback).
 * Same strategy: crop 10-25% height, left 65% width, upscale 3x, threshold.
 */
function createNikCropSimpleGD($imagePath, $outputPath) {
    error_log("[createNikCropSimpleGD] Using GD Library");
    
    try {
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            error_log("[createNikCropSimpleGD] getimagesize failed");
            return false;
        }

        $imageWidth  = $imageInfo[0];
        $imageHeight = $imageInfo[1];
        error_log("[createNikCropSimpleGD] Image dimensions: " . $imageWidth . "x" . $imageHeight);

        // Load image
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($imagePath); break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($imagePath); break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($imagePath); break;
            default:
                $source = imagecreatefromstring(file_get_contents($imagePath));
        }

        if (!$source) {
            error_log("[createNikCropSimpleGD] Failed to load image");
            return false;
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        
        // KTP Indonesia: NIK row is at 18-32% height
        $yStart     = (int)($height * 0.18);
        $cropHeight = (int)($height * 0.14); // 32% - 18% = 14%
        $cropWidth  = (int)($width * 0.70);  // 70% kiri
        error_log("[createNikCropSimpleGD] Crop: y={$yStart}, w={$cropWidth}, h={$cropHeight}");
        
        // Crop
        $crop = imagecreatetruecolor($cropWidth, $cropHeight);
        imagecopy($crop, $source, 0, 0, 0, $yStart, $cropWidth, $cropHeight);
        imagedestroy($source);
        
        // Upscale 4x
        $newWidth  = $cropWidth * 4;
        $newHeight = $cropHeight * 4;
        $upscaled  = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($upscaled, $crop, 0, 0, 0, 0, $newWidth, $newHeight, $cropWidth, $cropHeight);
        imagedestroy($crop);
        
        // Grayscale
        @imagefilter($upscaled, IMG_FILTER_GRAYSCALE);
        
        // Kontrast tinggi untuk teks digit
        @imagefilter($upscaled, IMG_FILTER_BRIGHTNESS, 10);
        @imagefilter($upscaled, IMG_FILTER_CONTRAST, -70);
        
        // Sharpen
        $sharpenMatrix = [
            [-1, -1, -1],
            [-1, 12, -1],
            [-1, -1, -1]
        ];
        @imageconvolution($upscaled, $sharpenMatrix, 4, 0);
        
        // Padding putih 30px
        $bordered = imagecreatetruecolor($newWidth + 60, $newHeight + 60);
        $white    = imagecolorallocate($bordered, 255, 255, 255);
        imagefill($bordered, 0, 0, $white);
        imagecopy($bordered, $upscaled, 30, 30, 0, 0, $newWidth, $newHeight);
        imagedestroy($upscaled);
        
        imagepng($bordered, $outputPath, 0);
        imagedestroy($bordered);
        
        error_log("[createNikCropSimpleGD] Crop saved to: " . $outputPath);
        return true;
    } catch (Exception $e) {
        error_log('[createNikCropSimpleGD] Exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * OCR khusus untuk area NIK - digit-optimized.
 * PRIORITAS: digit-only allowlist dulu (paling akurat untuk angka),
 * lalu fallback tanpa allowlist jika gagal.
 */
function extractNikFromImage($imagePath) {
    error_log("[extractNikFromImage] Starting for: " . $imagePath);
    
    if (!file_exists($imagePath)) {
        error_log("[extractNikFromImage] Image file not found: " . $imagePath);
        return null;
    }

    $tempCropPath = tempnam(sys_get_temp_dir(), 'nik_');
    if ($tempCropPath === false) {
        error_log("[extractNikFromImage] Failed to create temp file");
        return null;
    }
    $tempCropPath .= '.png';

    try {
        if (!createNikCrop($imagePath, $tempCropPath)) {
            return null;
        }

        error_log("[extractNikFromImage] Crop created, attempting OCR-A model first");
        
        $bestNik = null;
        
        // STRATEGY 0 (HIGHEST PRIORITY): Use custom OCR-A trained model
        // The OCR-A Extended font is specifically used for NIK numbers on KTP.
        // This model is trained specifically for that font, giving the best accuracy.
        $ocraModelPath = 'C:/Program Files/Tesseract-OCR/tessdata/ocra.traineddata';
        if (file_exists($ocraModelPath)) {
            error_log("[extractNikFromImage] OCR-A model found, using custom trained model");
            $psmOcra = [7, 13, 8]; // single line, raw line, single word
            
            foreach ($psmOcra as $psm) {
                try {
                    $ocr = new TesseractOCR($tempCropPath);
                    $ocr->lang('ocra');
                    $ocr->psm($psm);
                    $ocr->oem(1); // LSTM only - sesuai training model
                    $ocr->allowlist('0123456789');
                    
                    $nikText = trim((string)$ocr->run());
                    error_log("[extractNikFromImage] OCR-A PSM {$psm}: '{$nikText}'");
                    
                    if (empty($nikText)) continue;
                    
                    $digits = preg_replace('/\D/', '', $nikText);
                    error_log("[extractNikFromImage] OCR-A Digits: '{$digits}' (len=" . strlen($digits) . ")");
                    
                    if (strlen($digits) == 16) {
                        error_log("[extractNikFromImage] OCR-A SUCCESS PSM {$psm}: {$digits}");
                        return $digits;
                    }
                    
                    if (strlen($digits) > 16 && $bestNik === null) {
                        $bestNik = substr($digits, 0, 16);
                        error_log("[extractNikFromImage] OCR-A Partial PSM {$psm}, first 16: {$bestNik}");
                    }
                    
                    if (strlen($digits) >= 15 && strlen($digits) <= 17 && $bestNik === null) {
                        $bestNik = substr($digits, 0, 16);
                        error_log("[extractNikFromImage] OCR-A Near-match PSM {$psm}: {$bestNik}");
                    }
                } catch (Exception $e) {
                    error_log("[extractNikFromImage] OCR-A PSM {$psm} error: " . $e->getMessage());
                }
            }
            
            if ($bestNik !== null) {
                error_log("[extractNikFromImage] OCR-A best result: {$bestNik}");
                return $bestNik;
            }
            
            error_log("[extractNikFromImage] OCR-A model did not produce valid result, falling back...");
        } else {
            error_log("[extractNikFromImage] OCR-A model not found at {$ocraModelPath}, using default engine");
        }
        
        // STRATEGY 1 (SECONDARY): Digit-only allowlist with English model
        // PSM 7 = single line, 8 = single word, 13 = raw line
        $psmDigitOnly = [7, 8, 13];
        $bestNik = null; // Reset for this strategy
        
        foreach ($psmDigitOnly as $psm) {
            try {
                $ocr = new TesseractOCR($tempCropPath);
                $ocr->lang('eng');
                $ocr->psm($psm);
                $ocr->oem(3);
                $ocr->allowlist('0123456789');

                $nikText = trim((string)$ocr->run());
                error_log("[extractNikFromImage] Digit-only PSM {$psm}: '{$nikText}'");
                
                if (empty($nikText)) continue;
                
                $digits = preg_replace('/\D/', '', $nikText);
                error_log("[extractNikFromImage] Digits: '{$digits}' (len=" . strlen($digits) . ")");
                
                if (strlen($digits) == 16) {
                    error_log("[extractNikFromImage] SUCCESS PSM {$psm}: {$digits}");
                    return $digits;
                }
                
                if (strlen($digits) > 16 && $bestNik === null) {
                    $bestNik = substr($digits, 0, 16);
                    error_log("[extractNikFromImage] Partial PSM {$psm}, first 16: {$bestNik}");
                }
            } catch (Exception $e) {
                error_log("[extractNikFromImage] PSM {$psm} digit-only error: " . $e->getMessage());
            }
        }
        
        if ($bestNik !== null) {
            return $bestNik;
        }
        
        // STRATEGY 2 (TERTIARY): No allowlist, use normalizeNikCandidate
        $psmFallback = [6, 7, 8];
        foreach ($psmFallback as $psm) {
            try {
                $ocr = new TesseractOCR($tempCropPath);
                $ocr->lang('eng');
                $ocr->psm($psm);
                $ocr->oem(3);
                
                $nikText = trim((string)$ocr->run());
                error_log("[extractNikFromImage] Fallback PSM {$psm}: '{$nikText}'");
                
                if (!empty($nikText)) {
                    $nik = normalizeNikCandidate($nikText);
                    if ($nik !== null) {
                        error_log("[extractNikFromImage] Fallback SUCCESS PSM {$psm}: {$nik}");
                        return $nik;
                    }
                }
            } catch (Exception $e) {
                error_log("[extractNikFromImage] Fallback PSM {$psm} error: " . $e->getMessage());
            }
        }

        error_log("[extractNikFromImage] Failed after all strategies");
        return null;
    } catch (Exception $e) {
        error_log('[extractNikFromImage] Exception: ' . $e->getMessage());
        return null;
    } finally {
        if (file_exists($tempCropPath)) {
            unlink($tempCropPath);
        }
    }
}

/**
 * Lakukan preprocessing pada gambar
 * - Convert ke grayscale
 * - Increase contrast
 * - Denoise
 * - Resize jika terlalu kecil
 */
function preprocessImage($inputPath, $outputPath) {
    // Check apakah Imagick tersedia
    if (!extension_loaded('imagick') || !class_exists('Imagick')) {
        return preprocessImageWithGD($inputPath, $outputPath);
    }
    
    try {
        $image = new Imagick($inputPath);
        
        // Tentukan format output sebagai PNG
        $image->setImageFormat('png');
        
        // 1. Convert ke grayscale
        $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        
        // 2. Reduce noise aggressively
        $image->enhanceImage();
        $image->enhanceImage();
        $image->enhanceImage();
        
        // 3. Normalize image untuk auto-levelkan contrast
        $image->normalizeImage();
        
        // 4. Increase contrast dan brightness - lebih agresif
        $image->contrastImage(2.0);  // Increased from 1.5
        $image->brightnessContrastImage(20, 35);  // Increased from 10, 20
        
        // 5. Sharpen lebih agresif
        $image->sharpenImage(1, 1);  // parameters untuk sharpening lebih kuat
        $image->sharpenImage(0.5, 1);  // Apply twice
        
        // 6. Equalize histogram untuk better contrast
        $image->equalizeImage();
        
        // 7. Resize jika terlalu kecil (min 300px width)
        $dimensions = $image->getImageGeometry();
        if ($dimensions['width'] < 300) {
            $scale = 300 / $dimensions['width'];
            $newWidth = (int)($dimensions['width'] * $scale);
            $newHeight = (int)($dimensions['height'] * $scale);
            $image->scaleImage($newWidth, $newHeight);
        }
        
        // 8. Set density untuk OCR yang lebih baik
        $image->setImageResolution(300, 300);
        
        // Simpan hasil preprocessing
        $image->writeImage($outputPath);
        $image->destroy();
        
        return true;
    } catch (Exception $e) {
        // Fallback: gunakan GD library jika Imagick tidak tersedia
        return preprocessImageWithGD($inputPath, $outputPath);
    }
}

/**
 * Fallback preprocessing menggunakan GD library
 */
function preprocessImageWithGD($inputPath, $outputPath) {
    error_log("GD Preprocessing started for: " . $inputPath);
    
    try {
        // Check file exists
        if (!file_exists($inputPath)) {
            error_log("Input file not found: " . $inputPath);
            throw new Exception('Input file tidak ditemukan: ' . $inputPath);
        }
        
        // Load image - gunakan berbagai method untuk kompatibilitas
        $image = null;
        
        // Coba dengan getimagesize untuk deteksi type
        $imageInfo = getimagesize($inputPath);
        error_log("Image info: " . print_r($imageInfo, true));
        
        if (!$imageInfo) {
            // Fallback: coba langsung dari string
            $imageString = file_get_contents($inputPath);
            if (!$imageString) {
                error_log("Failed to read image file as string");
                throw new Exception('Tidak dapat membaca file gambar');
            }
            error_log("Image loaded from string, size: " . strlen($imageString));
            $image = imagecreatefromstring($imageString);
        } else {
            $imageType = $imageInfo[2];
            error_log("Image type detected: " . $imageType);
            
            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($inputPath);
                    error_log("Loaded as JPEG");
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($inputPath);
                    error_log("Loaded as PNG");
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($inputPath);
                    error_log("Loaded as GIF");
                    break;
                case IMAGETYPE_BMP:
                    $imageString = file_get_contents($inputPath);
                    $image = imagecreatefromstring($imageString);
                    error_log("Loaded as BMP");
                    break;
                default:
                    $imageString = file_get_contents($inputPath);
                    $image = imagecreatefromstring($imageString);
                    error_log("Loaded as generic image string");
            }
        }
        
        if (!$image) {
            error_log("Failed to create image resource");
            throw new Exception('Tidak dapat membuat image resource dari file');
        }
        
        error_log("Image resource created successfully");
        
        $width = imagesx($image);
        $height = imagesy($image);
        error_log("Image dimensions: {$width}x{$height}");
        
        // 1. Convert ke grayscale
        @imagefilter($image, IMG_FILTER_GRAYSCALE);
        
        // 2. Apply denoise dulu sebelum contrast
        @imagefilter($image, IMG_FILTER_SMOOTH, 1);
        
        // 3. Increase contrast - lebih agresif untuk OCR
        @imagefilter($image, IMG_FILTER_BRIGHTNESS, 15);
        @imagefilter($image, IMG_FILTER_CONTRAST, 35);  // Increased from 20 to 35
        error_log("Applied grayscale, denoise, and contrast");
        
        // 4. Apply multiple sharpening untuk text lebih jelas
        $sharpenMatrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1]
        ];
        @imageconvolution($image, $sharpenMatrix, 8, 0);
        @imageconvolution($image, $sharpenMatrix, 8, 0);  // Apply twice for stronger effect
        error_log("Applied sharpening 2x");
        
        // 5. Additional denoise untuk cleanup
        @imagefilter($image, IMG_FILTER_SMOOTH, 1);
        
        // 6. Resize jika terlalu kecil
        if ($width < 300) {
            $scale = 300 / $width;
            $newWidth = (int)($width * $scale);
            $newHeight = (int)($height * $scale);
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resizedImage;
        }
        
        // Simpan sebagai PNG
        error_log("Attempting to save PNG to: " . $outputPath);
        $saveResult = imagepng($image, $outputPath, 9);
        
        if (!$saveResult) {
            error_log("imagepng failed to save to: " . $outputPath);
            imagedestroy($image);
            throw new Exception('Gagal menyimpan hasil preprocessing ke PNG');
        }
        
        error_log("PNG saved successfully");
        
        // Verify file was actually created
        if (!file_exists($outputPath)) {
            error_log("Output file does not exist after imagepng: " . $outputPath);
            imagedestroy($image);
            throw new Exception('File output tidak tercipta sesudah imagepng');
        }
        
        error_log("Output file verified to exist: " . $outputPath . " (size: " . filesize($outputPath) . ")");
        imagedestroy($image);
        
        return true;
    } catch (Exception $e) {
        error_log("GD Preprocessing failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Extract Nomor KK (16 digit) dari hasil OCR gambar KK.
 */
function extractNomorKK($text) {
    error_log("[KK] extractNomorKK called with text length: " . strlen($text));
    error_log("[KK] Raw KK text (first 300): " . substr($text, 0, 300));
    
    // Clean up typical OCR noise for KK Number specifically
    // Pattern 1: "No." / "Nomor" followed by digits (14-18 chars to be lenient)
    if (preg_match('/(?:No|Nomor)[\.\s,:=]*([0-9OolISZB]{14,18})/i', $text, $matches)) {
        error_log("[KK] Pattern 1 matched: " . $matches[1]);
        return normalizeNikCandidate($matches[1]);
    }
    
    // Pattern 2: Look for "KK" keyword followed by digits
    if (preg_match('/KK[\.\s,:=]*([0-9OolISZB]{14,18})/i', $text, $matches)) {
        error_log("[KK] Pattern 2 (KK keyword) matched: " . $matches[1]);
        return normalizeNikCandidate($matches[1]);
    }
    
    // Fallback: look for 14-18 digit sequences anywhere in text
    if (preg_match_all('/\b([0-9OolISZB]{14,18})\b/i', $text, $matches)) {
        error_log("[KK] Fallback found " . count($matches[1]) . " candidates");
        foreach ($matches[1] as $candidate) {
            error_log("[KK] Fallback candidate: " . $candidate);
            $normalized = normalizeNikCandidate($candidate);
            if ($normalized) {
                error_log("[KK] Fallback SUCCESS: " . $normalized);
                return $normalized;
            }
        }
    }
    
    error_log("[KK] No Nomor KK found in text");
    return null;
}

/**
 * Extract KTP fields dari OCR text
 * Parse text dan return array dengan field-field KTP terstruktur
 * Dengan multiple fallback patterns untuk menangkap berbagai format OCR
 */
function extractKtpFields($text, $imagePath = null) {
    $fields = [
        'nik' => null,
        'nama' => null,
        'nomor_kk' => null,
        'tempat_lahir' => null,      // String: "JAKARTA"
        'tanggal_lahir' => null,     // Date: "25-01-1990"
        'jenis_kelamin' => null,
        'gol_darah' => null,
        'alamat' => null,
        'rt_rw' => null,              // Gabung RT dan RW: "001/002"
        'kelurahan' => null,
        'kecamatan' => null,
        'kota_kabupaten' => null,
        'provinsi' => null,
        'agama' => null,
        'status_perkawinan' => null,
        'pekerjaan' => null,
    ];
    
    // Split text menjadi lines untuk processing
    $lines = explode("\n", $text);
    $fullText = strtolower($text); // untuk searching multi-line patterns
    
    // ============================================================
    // LANGKAH 1: Extract NIK menggunakan crop gambar (PRIORITAS UTAMA)
    // Image crop jauh lebih akurat dari parsing teks OCR yang sering noise
    // ============================================================
    if ($imagePath !== null) {
        $imageNikDirect = extractNikFromImage($imagePath);
        if (!empty($imageNikDirect)) {
            $fields['nik'] = $imageNikDirect;
            error_log("[NIK] Direct image crop SUCCESS: " . $imageNikDirect);
        }
    }

    // ============================================================
    // LANGKAH 2: Parse baris per baris untuk field lainnya (dan NIK sebagai fallback)
    // ============================================================
    foreach ($lines as $lineIndex => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $lineLower = strtolower($line);
        
        // NIK: hanya coba dari teks jika image crop gagal
        if (empty($fields['nik']) && stripos($lineLower, 'nik') !== false) {
            $nikCandidate = null;

            // Step A: Isolasi teks tepat SETELAH keyword NIK, buang semua prefix
            $nikPart = '';
            if (preg_match('/NIK\s*[:\-=]?\s*([0-9OolISZBb8\s\.]{10,25})/i', $line, $preMatches)) {
                $nikPart = trim($preMatches[1]);
                error_log("[NIK] Isolated NIK part after keyword: '" . $nikPart . "'");
            }

            // Step B: Bersihkan nikPart
            if (!empty($nikPart)) {
                $nikCompact = preg_replace('/\s+/', '', $nikPart);
                if (preg_match('/([0-9OolIBb]{14,18})/', $nikCompact, $matches)) {
                    $nikCandidate = $matches[1];
                    error_log("[NIK] Pattern 1 (isolated compact): '" . $nikCandidate . "'");
                } elseif (preg_match('/([0-9OolIBb\.\-]{10,20})/', $nikPart, $matches)) {
                    $nikCandidate = $matches[1];
                    error_log("[NIK] Pattern 1b (isolated spaced): '" . $nikCandidate . "'");
                }
            }

            // Step C: Fallback - cari sequence digit terpanjang di baris
            if (empty($nikCandidate)) {
                preg_match_all('/[0-9OolIBb]{6,}/', $line, $allDigitGroups);
                if (!empty($allDigitGroups[0])) {
                    usort($allDigitGroups[0], function($a, $b) { return strlen($b) - strlen($a); });
                    foreach ($allDigitGroups[0] as $group) {
                        if (strlen($group) >= 14) {
                            $nikCandidate = $group;
                            error_log("[NIK] Pattern C (longest group): '" . $nikCandidate . "'");
                            break;
                        }
                    }
                }
            }

            if (!empty($nikCandidate)) {
                $nikNormalized = normalizeNikCandidate($nikCandidate);
                if ($nikNormalized !== null && $nikNormalized[0] !== '0') {
                    $fields['nik'] = $nikNormalized;
                    error_log("[NIK] Text parsing SUCCESS: " . $nikNormalized);
                }
            } else {
                error_log("[NIK] No pattern matched for line with 'NIK' keyword");
            }
        }
        
        // 2. Extract Nama (text setelah "Nama")
        // PENTING: Potong jika muncul keyword field lain (Tempat, Tgl, Lahir, dll)
        if (preg_match('/Nama\s*[:=]\s*([^|\n\r]+)/i', $line, $matches)) {
            $nama = trim($matches[1]);
            
            // Potong TEPAT sebelum keyword field berikutnya yang mungkin ikut terbaca di baris sama
            $stopKeywords = [
                'Tempat', 'Tgl', 'Lahir', 'Jenis', 'Kelamin', 'Gol', 'Darah',
                'Alamat', 'RT', 'RW', 'Kel', 'Kecamatan', 'Agama', 'Status',
                'Pekerjaan', 'Kewarganegaraan', 'Berlaku'
            ];
            foreach ($stopKeywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $nama, $m, PREG_OFFSET_CAPTURE)) {
                    $nama = substr($nama, 0, $m[0][1]);
                }
            }
            
            // Potong sebelum digit panjang (bukan inisial/angka nama)
            $nama = preg_replace('/\s+\d{3,}.*$/s', '', $nama);
            
            $nama = cleanupOCRNoise($nama);
            $nama = cleanupFieldValue($nama, 'nama');
            
            // Hanya ambil huruf, spasi, titik, koma, tanda hubung, apostrophe
            $nama = preg_replace('/[^A-Za-z\s\.\,\-\']/u', '', $nama);
            $nama = preg_replace('/\s+/', ' ', $nama);
            $nama = trim($nama);
            
            // Hapus pola berulang di akhir: "SA SA", "KA KA", "DU DU"
            // Ini adalah sisa watermark KTP yang terpotong OCR
            $nama = preg_replace('/(?:\s+([A-Za-z]{1,4}))(?:\s+\1)+\s*$/i', '', $nama);
            
            // Hapus trailing noise: kata 1-3 huruf + tanda hubung, atau satu kata 1-3 huruf
            $nama = preg_replace('/\s+[A-Za-z]{1,3}\s*-\s*$/i', '', $nama);
            $nama = preg_replace('/\s+-\s*$/', '', $nama);
            
            // Hapus trailing kata 1-2 huruf yang bukan singkatan gelar (gelar biasanya diikuti titik)
            // Contoh: "SINAGA SA" -> "SINAGA"
            $nama = preg_replace('/\s+[A-Z]{1,2}\s*$/u', '', $nama);
            
            $nama = trim($nama);
            if (!empty($nama) && strlen($nama) > 2) {
                $fields['nama'] = strtoupper($nama);
            }
        }
        
        // 3. Extract Nomor Kartu Keluarga (16 digit)
        if (preg_match('/(?:No(?:mor)?\.?\s*)?Kartu\s*Keluarga\s*[:=]\s*(\d{16})/i', $line, $matches)) {
            $fields['nomor_kk'] = $matches[1];
        } elseif (preg_match('/NK\s*[:=]\s*(\d{16})/i', $line, $matches)) {
            $fields['nomor_kk'] = $matches[1];
        }
        
        // 4-5. Extract Tempat Lahir dan Tanggal Lahir (TERPISAH)
        // Pertama ekstrak gabungan, lalu split ke tempat_lahir dan tanggal_lahir
        // Handle multiple formats:
        // - "Tempat/Tgl Lahir : JAKARTA / 25-01-1990"
        // - "TempaTgl : Lahir:TANJUNGPINANG, 25 - 02 - 2001"
        // - "Tempat Lahir : JAKARTA" (separate line)
        // - "Tgl Lahir : 25-01-1990" (separate line)
        
        $combinedTTL = null; // temporary holder
        
        // Format 1: Standard "Tempat/Tgl Lahir" with "/" separator
        if (preg_match('/Tempat\s*\/\s*Tgl\s*(?:Lahir)?\s*[:=]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3 && empty($fields['tempat_lahir'])) {
                $combinedTTL = cleanupTempatTglLahir($combined);
            }
        }
        // Format 2: "Tempat Tgl Lahir" with spaces
        else if (preg_match('/Tempat\s+Tgl\s+Lahir\s*[:=]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3 && empty($fields['tempat_lahir'])) {
                $combinedTTL = cleanupTempatTglLahir($combined);
            }
        }
        // Format 3: "TempaTgl : Lahir:" (combined word)
        else if (preg_match('/TempaTgl\s*[:=]\s*Lahir\s*[:=]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3 && empty($fields['tempat_lahir'])) {
                $combinedTTL = cleanupTempatTglLahir($combined);
            }
        }
        // Format 4: "TempaTgl" (typo/OCR error)
        else if (preg_match('/TempaTgl\s*[:=>\s]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3 && stripos($combined, 'lahir') !== false && empty($fields['tempat_lahir'])) {
                $combinedTTL = cleanupTempatTglLahir($combined);
            }
        }
        // Format 5: Fallback - "Tempat Lahir" dengan date di baris yang sama
        else if (preg_match('/Tempat\s+Lahir\s*[:=]\s*([^,|\n\r]+?)(?:\s+(\d{1,2}[-\/]\d{1,2}[-\/]\d{4}))?/i', $line, $matches)) {
            $tempat = trim($matches[1]);
            $date = isset($matches[2]) ? trim($matches[2]) : '';
            if (!empty($tempat) && strlen($tempat) > 2 && !empty($date) && empty($fields['tempat_lahir'])) {
                $combinedTTL = $tempat . ' / ' . $date;
            }
        }
        // Format 6: Fallback - detect date pattern after tempat/lahir keyword
        if ($combinedTTL === null && empty($fields['tempat_lahir']) && preg_match('/([A-Z][A-Za-z\s,.-]+?)(?:\s+\/?\s+|,\s+)?(.*)$/i', $line, $matches)) {
            if (stripos($lineLower, 'lahir') !== false) {
                $tempat = trim($matches[1]);
                $dateStr = isset($matches[2]) ? trim($matches[2]) : '';
                if (!empty($dateStr)) {
                    $combinedTTL = cleanupTempatTglLahir($tempat . ', ' . $dateStr);
                }
            }
        }
        
        // Split combined tempat/tgl lahir ke dua field terpisah
        if (!empty($combinedTTL) && empty($fields['tempat_lahir'])) {
            // Cari tanggal dengan format dd-mm-yyyy atau dd/mm/yyyy
            if (preg_match('/^(.+?)\s*[\/,]\s*(\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{2,4})/', $combinedTTL, $splitMatch)) {
                $fields['tempat_lahir'] = trim($splitMatch[1]);
                // Normalisasi tanggal: hapus spasi di sekitar separator dan pakai "-"
                $tgl = preg_replace('/\s*[-\/]\s*/', '-', trim($splitMatch[2]));
                $fields['tanggal_lahir'] = $tgl;
            } else {
                // Jika tidak ada tanggal terdeteksi, simpan semuanya sebagai tempat
                $fields['tempat_lahir'] = $combinedTTL;
            }
        }
        
        // 6. Extract Jenis Kelamin
        // OCR sering membaca ":" sebagai "!" pada separator
        if (preg_match('/Jenis\s*Kelamin\s*[:=!]\s*([^\n\r|]+)/i', $line, $matches)) {
            $jk = trim($matches[1]);
            // Potong sebelum "Gol" agar tidak ikut terbaca
            if (preg_match('/^(.*?)\s*Gol/i', $jk, $jkCut)) {
                $jk = trim($jkCut[1]);
            }
            // Cari keyword valid, abaikan apapun setelahnya
            if (preg_match('/\b(LAKI\s*-\s*LAKI|LAKI|PEREMPUAN)\b/i', $jk, $jk_matches)) {
                $jkVal = strtoupper(preg_replace('/\s+/', ' ', trim($jk_matches[1])));
                $jkVal = str_replace('LAKI LAKI', 'LAKI-LAKI', $jkVal);
                $fields['jenis_kelamin'] = $jkVal;
            }
        }
        // Fallback: cari keyword PEREMPUAN/LAKI-LAKI langsung di baris yang mengandung "Kelamin"
        if (empty($fields['jenis_kelamin']) && stripos($line, 'kelamin') !== false) {
            if (preg_match('/\b(LAKI\s*-\s*LAKI|PEREMPUAN)\b/i', $line, $jk_matches)) {
                $jkVal = strtoupper(preg_replace('/\s+/', ' ', trim($jk_matches[1])));
                $jkVal = str_replace('LAKI LAKI', 'LAKI-LAKI', $jkVal);
                $fields['jenis_kelamin'] = $jkVal;
            }
        }
        
        // 7. Extract Golongan Darah
        if (preg_match('/(?:Gol|Golongan)\.?\s*Darah\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $gol = trim($matches[1]);
            // Pattern harus diurutkan dari yang paling panjang: AB sebelum A/B/O
            if (preg_match('/(AB|O|B|A)[\+\-]?/i', $gol, $gol_matches)) {
                $fields['gol_darah'] = strtoupper($gol_matches[1]);
            }
        }
        
        // 8. Extract Alamat
        // Potong tepat sebelum RT/RW, Kelurahan, atau pipa |
        if (preg_match('/Alamat\s*[:=]\s*(.+?)(?=\s*(?:\||RT\s*\/\s*RW|RT\/RW|RT\s*[:=>]|RW\s*[:=>]|Kelurahan|Desa|Kel\.\s*\/?\s*Desa|Kel\.|Kecamatan|Kec\.?|$))/i', $line, $matches)) {
            $alamat = trim($matches[1]);
            // Potong di pipa |
            if (($pipePos = strpos($alamat, '|')) !== false) {
                $alamat = substr($alamat, 0, $pipePos);
            }
            $alamat = preg_replace('/\s+["\']?\s*$/', '', $alamat);
            $alamat = rtrim($alamat, ' ,;:.-');
            $alamat = cleanupOCRNoise($alamat);
            $alamat = cleanupFieldValue($alamat, 'alamat');
            if (!empty($alamat) && strlen($alamat) > 2) {
                $fields['alamat'] = strtoupper($alamat);
            }
        }
        
        // 9-10. Extract RT/RW (Gabung menjadi satu field dengan format "001/002")
        // Handle multiple formats:
        // - "RT/RW: 006/007"
        // - "RT: 006 / RW: 007"
        // - "006/007"
        
        // Format 1: Combined "RT/RW: 006/007"
        if (preg_match('/RT\s*\/\s*RW\s*[:=>]\s*(\d+)\s*\/\s*(\d+)/i', $line, $matches)) {
            $rt = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
            $rw = str_pad($matches[2], 3, '0', STR_PAD_LEFT);
            if (empty($fields['rt_rw'])) {
                $fields['rt_rw'] = $rt . '/' . $rw;
            }
        }
        
        // Format 2: Separate "RT:" and "RW:" on same line
        else if (preg_match('/RT\s*[:=]?\s*(\d+).*?RW\s*[:=]?\s*(\d+)/i', $line, $matches)) {
            $rt = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
            $rw = str_pad($matches[2], 3, '0', STR_PAD_LEFT);
            if (empty($fields['rt_rw'])) {
                $fields['rt_rw'] = $rt . '/' . $rw;
            }
        }
        
        // Format 3: Just numbers with slash like "006/007"
        else if (preg_match('/\b(\d{1,3})\s*\/\s*(\d{1,3})\b/i', $line, $matches)) {
            if (stripos($lineLower, 'rt') !== false || stripos($lineLower, 'rw') !== false) {
                $rt = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
                $rw = str_pad($matches[2], 3, '0', STR_PAD_LEFT);
                if (empty($fields['rt_rw'])) {
                    $fields['rt_rw'] = $rt . '/' . $rw;
                }
            }
        }
        
        // 11. Extract Kelurahan/Desa
        // Handle formats: "Kelurahan", "Desa", "Kel/Desa", "Kel.", etc.
        if (preg_match('/(?:Kelurahan|Desa|Kel\.\s*\/?\s*Desa|Kel\.)\s*[:=]\s*(.+?)(?=\s*(?:Kecamatan|Kec\.?|Kota|Kabupaten|Provinsi|Agama|Status|Perkawinan|Pekerjaan|Kewarganegaraan|Berlaku|$))/i', $line, $matches)) {
            $kel = trim($matches[1]);
            $kel = rtrim($kel, ' ,;:.-');
            $kel = cleanupOCRNoise($kel);  // Remove OCR noise
            if (!empty($kel) && strlen($kel) > 2) {
                if (empty($fields['kelurahan'])) {
                    $fields['kelurahan'] = $kel;
                }
            }
        }
        
        // 12. Extract Kecamatan
        if (preg_match('/Kecamatan\s*[:=]\s*(.+?)(?=\s*(?:Kota|Kabupaten|Provinsi|Agama|Status|Perkawinan|Pekerjaan|Kewarganegaraan|Berlaku|$))/i', $line, $matches)) {
            $kec = trim($matches[1]);
            $kec = rtrim($kec, ' ,;:.-');
            $kec = cleanupOCRNoise($kec);  // Remove OCR noise
            if (!empty($kec) && strlen($kec) > 2) {
                $fields['kecamatan'] = $kec;
            }
        }
        
        // 13. Extract Kota/Kabupaten
        // Potong bagian NIK dari baris sebelum matching untuk menghindari field tercampur.
        // Contoh: "KOTA TANJUNGBALAI NIK : 75323711058188" -> potong sebelum NIK.
        $lineForKota = $line;
        if (preg_match('/^(.*?)\s+NIK\b/i', $lineForKota, $kotaCut)) {
            $lineForKota = $kotaCut[1]; // Ambil hanya bagian sebelum NIK
        }
        if (preg_match('/(?:Kota\/Kabupaten|Kota|Kabupaten)\s*(?:[:=]\s*|\s+)([a-zA-Z][a-zA-Z\s]*?)(?=\s*(?:NIK|\d{4,}|Nama|Tempat|Tgl|Jenis|Gol|Alamat|RT\/?RW|Kelurahan|Desa|Kecamatan|Agama|Status|Perkawinan|Pekerjaan|Kewarganegaraan|Berlaku|$))/i', $lineForKota, $matches)) {
            $kota = trim($matches[1]);
            $kota = preg_replace('/\s+/', ' ', $kota);
            $kota = preg_replace('/^(KOTA|KABUPATEN)\s+\1\b/i', '$1', $kota);
            $kota = rtrim($kota, ' ,;:.-');
            $kota = cleanupOCRNoise($kota);  // Remove OCR noise
            if (!empty($kota) && strlen($kota) > 2) {
                $fields['kota_kabupaten'] = $kota;
            }
        }
        
        // 14. Extract Provinsi
        if (preg_match('/Provinsi\s*(?:[:=]\s*|\s+)([^\n\r|]+)/i', $line, $matches)) {
            $prov = trim($matches[1]);
            $prov = preg_replace('/\s+/', ' ', $prov);
            $prov = cleanupOCRNoise($prov);  // Remove OCR noise
            if (!empty($prov) && strlen($prov) > 2) {
                $fields['provinsi'] = $prov;
            }
        }
        
        // 15. Extract Agama — hanya kata pertama yang cocok dengan daftar agama valid
        if (preg_match('/Agama\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $agama = trim($matches[1]);
            $agamaList = ['ISLAM', 'KRISTEN', 'KATOLIK', 'HINDU', 'BUDDHA', 'BUDHA', 'KONGHUCU', 'KONG HU CU'];
            $agamaFound = null;
            foreach ($agamaList as $ag) {
                if (stripos($agama, $ag) !== false) {
                    $agamaFound = $ag;
                    break;
                }
            }
            if ($agamaFound !== null) {
                $fields['agama'] = strtoupper($agamaFound);
            } else {
                // Fallback: ambil satu kata pertama saja, uppercase
                $agama = cleanupOCRNoise($agama);
                $words = explode(' ', $agama);
                if (!empty($words[0]) && strlen($words[0]) > 2) {
                    $fields['agama'] = strtoupper($words[0]);
                }
            }
        }
        
        // 16. Extract Status Perkawinan — hanya nilai valid
        if (preg_match('/(?:Status\s+)?Perkawinan\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $status = trim($matches[1]);
            if (!empty($status) && strlen($status) > 2) {
                // Ambil hanya status perkawinan yang valid, buang apapun setelahnya
                if (preg_match('/\b(BELUM\s+KAWIN|KAWIN|CERAI\s+HIDUP|CERAI\s+MATI)\b/i', $status, $statusMatches)) {
                    $fields['status_perkawinan'] = strtoupper(preg_replace('/\s+/', ' ', trim($statusMatches[1])));
                } else {
                    // Fallback: ambil kata-kata pertama sampai bertemu digit atau kata asing
                    $status = cleanupFieldValue($status, 'status_perkawinan');
                    $fields['status_perkawinan'] = strtoupper(preg_replace('/\s+/', ' ', trim($status)));
                }
            }
        }
        
        // 17. Extract Pekerjaan - dengan multiple format fallbacks
        // Handle formats: "Pekerjaan : ...", "Pekerjaan > ...", "Pekerjaan = ...", "Pekerjaan ! ..."
        // OCR sering membaca ":" sebagai "!" pada separator Pekerjaan
        if (preg_match('/Pekerjaan\s*[:=>!\s]\s*([^\n\r|]+)/i', $line, $matches)) {
            $pekerjaan = trim($matches[1]);
            // Hapus karakter noise yang muncul jika OCR salah baca separator
            $pekerjaan = ltrim($pekerjaan, '!>= ');
            // Strip trailing tanggal (dd-mm-yyyy) yang ikut terbaca dari stempel/cap
            $pekerjaan = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{2,4}\s*$/', '', $pekerjaan);
            // Strip trailing kota/kata >= 5 huruf di akhir yang tidak relevan
            $pekerjaan = preg_replace('/\s+[A-Z]{5,}\s*$/', '', $pekerjaan);
            $pekerjaan = cleanupOCRNoise($pekerjaan);
            $pekerjaan = cleanupFieldValue($pekerjaan, 'pekerjaan');
            $pekerjaan = strtoupper(trim($pekerjaan));
            if (!empty($pekerjaan) && strlen($pekerjaan) > 2) {
                if (empty($fields['pekerjaan'])) {
                    $fields['pekerjaan'] = $pekerjaan;
                }
            }
        }
        // Fallback for "Kerjaan" if "Pekerjaan" not found
        else if (preg_match('/Kerjaan\s*[:=>\s]\s*([^\n\r|]+)/i', $line, $matches)) {
            $pekerjaan = trim($matches[1]);
            $pekerjaan = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{2,4}\s*$/', '', $pekerjaan);
            $pekerjaan = cleanupOCRNoise($pekerjaan);
            $pekerjaan = cleanupFieldValue($pekerjaan, 'pekerjaan');
            $pekerjaan = strtoupper(trim($pekerjaan));
            if (!empty($pekerjaan) && strlen($pekerjaan) > 2) {
                if (empty($fields['pekerjaan'])) {
                    $fields['pekerjaan'] = $pekerjaan;
                }
            }
        }
        // Fallback: Look for lines with occupation-related keywords
        else if (empty($fields['pekerjaan']) && preg_match('/(?:Pekerjaan|Kerjaan|Kerja)\s+[>:\-=]\s*(.+)/i', $line, $matches)) {
            $pekerjaan = trim($matches[1]);
            $pekerjaan = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{2,4}\s*$/', '', $pekerjaan);
            $pekerjaan = cleanupOCRNoise($pekerjaan);
            $pekerjaan = cleanupFieldValue($pekerjaan, 'pekerjaan');
            $pekerjaan = strtoupper(trim($pekerjaan));
            if (!empty($pekerjaan) && strlen($pekerjaan) > 2) {
                $fields['pekerjaan'] = $pekerjaan;
            }
        }
    }
    
    // Cleanup dan normalisasi fields
    foreach ($fields as $key => &$value) {
        if ($value !== null) {
            $value = trim($value);
            // Remove extra spaces
            $value = preg_replace('/\s+/', ' ', $value);
            // Remove trailing punctuation
            $value = rtrim($value, '.,;:!?');
        }
    }

    // FALLBACK: Dedicated image crop OCR
    // Jika NIK gagal diekstrak dari teks mentah, coba gunakan metode crop khusus NIK
    if (empty($fields['nik']) && $imagePath !== null) {
        error_log("[NIK] Fallback Crop: Attempting dedicated image crop OCR");
        $imageNik = extractNikFromImage($imagePath);
        error_log("[NIK] Fallback Crop result: " . ($imageNik ?? 'NULL'));
        if (!empty($imageNik)) {
            $fields['nik'] = $imageNik;
            error_log("[NIK] Fallback Crop SUCCESS: {$imageNik}");
        }
    }

    // Fallback 1: kalau NIK masih kosong, cari dari keseluruhan teks OCR dengan label.
    if (empty($fields['nik'])) {
        error_log("[NIK] Fallback 1: Searching fulltext with NIK label");
        if (preg_match_all('/NIK\s*[:=\s]*([0-9OolISZB8\.\-\s]{12,30})/i', $text, $nikMatches)) {
            foreach ($nikMatches[1] as $candidate) {
                error_log("[NIK] Fallback 1 candidate: " . $candidate);
                $normalizedNik = normalizeNikCandidate($candidate);
                if ($normalizedNik !== null) {
                    $fields['nik'] = $normalizedNik;
                    error_log("[NIK] Fallback 1 SUCCESS: " . $normalizedNik);
                    break;
                }
            }
        }
    }

    // Fallback 2: Cari 15-17 digit sequence tanpa label
    if (empty($fields['nik'])) {
        error_log("[NIK] Fallback 2: Searching for 15-17 digit sequence without label");
        if (preg_match_all('/\b([0-9OolISZBdqg]{15,20})\b/i', $text, $digitMatches)) {
            foreach ($digitMatches[1] as $candidate) {
                if (strlen($candidate) >= 15) {
                    error_log("[NIK] Fallback 2 candidate: " . $candidate);
                    $normalizedNik = normalizeNikCandidate($candidate);
                    if ($normalizedNik !== null) {
                        $fields['nik'] = $normalizedNik;
                        error_log("[NIK] Fallback 2 SUCCESS: " . $normalizedNik);
                        break;
                    }
                }
            }
        }
    }

    // Debug: Log final NIK status
    if (empty($fields['nik'])) {
        error_log("[NIK] FINAL RESULT: NIK field is EMPTY after all fallbacks");
    } else {
        error_log("[NIK] FINAL RESULT: " . $fields['nik']);
    }
    
    return $fields;
}

/**
 * Post-processing OCR text untuk meningkatkan akurasi
 * Mengatasi kesalahan umum dari Tesseract
 */
function postProcessOCRText($text) {
    // Preserve original text untuk reference
    $processedText = (string)$text;
    
    // 1. Fix common character misreads
    $fixes = [
        // Colon (:) often read as equal (=) or other characters
        '/([a-zA-Z0-9])\s*=\s*([a-zA-Z0-9])/' => '$1 : $2',
        
        // Fix common Indonesian abbreviations misreads
        '/\btg[!]?\b/' => 'tgl',                    // tg! -> tgl
        '/\bTg[!]?\b/' => 'Tgl',                    // Tg! -> Tgl
        '/\btg([!?])\s/' => 'tgl ',                 // tg! space -> tgl space
        '/\bRT[!|]RW/' => 'RT/RW',                  // RT!RW / RT|RW -> RT/RW
        '/\bsel[!a]tan\b/' => 'selatan',            // selatan yang salah baca
        '/\butk\b/' => 'utk',                        // Cek utk abbreviation
        
        // Fix Kel/Desa variations
        '/Kel\s*[.\/]\s*Desa/' => 'Kel/Desa',       // Kel. Desa / Kel / Desa -> Kel/Desa
        '/Kelurahan\s*[.\/]\s*Desa/' => 'Kelurahan/Desa',
        '/Kel\b/' => 'Kelurahan',                   // Kel -> Kelurahan context-based
        
        // Fix Pekerjaan variations
        '/Peker[]j]aan/' => 'Pekerjaan',            // Peker-jaan -> Pekerjaan
        '/Kerja(?!an)/' => 'Pekerjaan',             // Kerja alone might be Pekerjaan
        
        // Fix Berlaku Hingga variations  
        '/Berlaku\s+Hing[g]ga/' => 'Berlaku Hingga',
        '/Berlaku\s+s\.?d\.?/' => 'Berlaku Hingga', // s/d -> Berlaku Hingga
        '/Sampai/' => 'Berlaku Hingga',              // Sampai -> Berlaku Hingga
        
        // Fix Tempat/Tgl Lahir separator
        '/Tempat\s+[\/|]\s+Tgl/' => 'Tempat / Tgl', // Tempat|Tgl or variations
        '/Tempat\s+Tgl\s+Lahir/' => 'Tempat/Tgl Lahir',
        
        // Fix common punctuation misreads
        '/([A-Z0-9])-\s*([A-Z0-9])/' => '$1 - $2',  // Add space around dash
        '/\s{2,}/' => ' ',                           // Multiple spaces to single space
        
        // Fix common number misreads dalam tanggal
        '/(\d)-([0-9]{2})-(\d{4})/' => '$1-0$2-$3', // Ensure proper date format
        
        // Fix extra spaces around numbers
        '/(\d)\s+([.,])/' => '$1$2',                // Angka spasi titik -> angka titik
        
        // Fix space before colon/period/comma
        '/\s+([,.:;])/' => '$1',
    ];
    
    foreach ($fixes as $pattern => $replacement) {
        $updated = preg_replace($pattern, $replacement, $processedText);
        if ($updated !== null) {
            $processedText = $updated;
        } else {
            error_log("Regex error on pattern: " . $pattern);
        }
    }
    
    // 2. Context-aware fixes based on known patterns
    // Fix NIK (Nomor Induk Kependudukan) format - harus 16 digit
    $nikFixedText = preg_replace_callback(
        '/NIK\s*[:=\s]+([^|\n\r]*)/',
        function($matches) {
            $niks = trim($matches[1]);
            // Clean up common OCR errors in NIK
            $niks = preg_replace('/[O]/i', '0', $niks); // O -> 0
            $niks = preg_replace('/[l]/i', '1', $niks);  // l -> 1
            $niks = preg_replace('/[S]/i', '5', $niks);  // S -> 5
            $niks = preg_replace('/[Z]/i', '2', $niks);  // Z -> 2
            $niks = preg_replace('/[B]/i', '8', $niks);  // B -> 8
            $niks = preg_replace('/[I|!]/', '1', $niks);  // I/|/! -> 1
            $niks = preg_replace('/[^0-9]/', '', $niks); // Remove non-digits
            return 'NIK : ' . $niks;
        },
        $processedText
    );
    if ($nikFixedText !== null) {
        $processedText = $nikFixedText;
    }
    
    // Fix Tanggal/Tgl patterns
    $dateFixedText = preg_replace_callback(
        '/(Tgl|Tanggal)\s*[:=\s]+([^|\n\r,]*)/i',
        function($matches) {
            $date = trim($matches[2]);
            // Standardize date format
            $date = preg_replace('/\s+/', ' ', $date);
            return $matches[1] . ' : ' . $date;
        },
        $processedText
    );
    if ($dateFixedText !== null) {
        $processedText = $dateFixedText;
    }
    
    // 3. Cleanup extra characters at line ends that shouldn't be there
    $lines = explode("\n", $processedText);
    $cleanLines = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        // Remove isolated special characters at the end
        $lineCleaned = preg_replace('/\s+[!@#$%^&*()_+=\[\]{};\'",<>?/\\|`~]$/', '', $line);
        if ($lineCleaned !== null) {
            $line = $lineCleaned;
        }
        if (!empty($line)) {
            $cleanLines[] = $line;
        }
    }
    
    $processedText = implode("\n", $cleanLines);
    
    // 4. Final cleanup
    $processedText = trim($processedText);
    // Remove excessive line breaks
    $lineBreakFixedText = preg_replace('/\n{3,}/', "\n\n", $processedText);
    if ($lineBreakFixedText !== null) {
        $processedText = $lineBreakFixedText;
    }
    
    return $processedText;
}

// Display hasil jika ada error, gunakan JSON
if (isset($_GET['json'])) {
    // Clear output buffer untuk JSON response yang clean
    ob_end_clean();
    
    // Set proper headers untuk JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Output JSON response
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// =========================================================================
// FITUR PENGUJIAN AKURASI KTP (CER)
// =========================================================================
function calculateCER($ocrResult, $groundTruth) {
    $ocrResult = strtolower(trim((string)$ocrResult));
    $groundTruth = strtolower(trim((string)$groundTruth));
    $ocrResult = preg_replace('/\s+/', ' ', $ocrResult);
    $groundTruth = preg_replace('/\s+/', ' ', $groundTruth);
    $N = strlen($groundTruth);
    if ($N === 0) return strlen($ocrResult) > 0 ? 100 : 0;
    return min((levenshtein($ocrResult, $groundTruth) / $N) * 100, 100);
}

if (isset($_GET['run_test']) && $_GET['run_test'] == '1') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(0); // Mencegah timeout karena OCR memakan waktu lama

    $datasetFile = __DIR__ . '/dataset.json';
    if (!file_exists($datasetFile)) {
        echo json_encode(['success' => false, 'message' => 'File dataset.json tidak ditemukan di folder!']);
        exit;
    }

    $dataset = json_decode(file_get_contents($datasetFile), true);
    
    // Filter dataset hanya untuk gambar yang diupload jika parameter filename tersedia
    if (isset($_GET['filename']) && !empty($_GET['filename'])) {
        $targetFilename = $_GET['filename'];
        $dataset = array_filter($dataset, function($data) use ($targetFilename) {
            return basename($data['image_path']) === $targetFilename;
        });
        
        if (empty($dataset)) {
            echo json_encode(['success' => false, 'message' => 'Gambar ' . htmlspecialchars($targetFilename) . ' tidak memiliki ground truth di dataset.json!']);
            exit;
        }
    }

    $testResults = [];
    $fieldStats = [];

    foreach ($dataset as $data) {
        $imagePath = __DIR__ . '/' . $data['image_path'];
        $groundTruth = $data['ground_truth'];
        
        $itemResult = ['image' => basename($imagePath), 'fields' => [], 'error' => null];

        if (!file_exists($imagePath)) {
            $itemResult['error'] = 'Gambar tidak ditemukan';
            $testResults[] = $itemResult;
            continue;
        }

        $tempPreprocessed = __DIR__ . '/uploads/temp_test_' . uniqid() . '.png';
        
        try {
            if (preprocessImage($imagePath, $tempPreprocessed)) {
                $ocr = new TesseractOCR($tempPreprocessed);
                $ocr->lang('ind+eng')->psm(6)->oem(3);
                $rawText = $ocr->run();
                
                $processedText = postProcessOCRText(trim((string)$rawText));
                $extractedFields = extractKtpFields($processedText, $tempPreprocessed);
                
                foreach ($groundTruth as $fieldKey => $expectedValue) {
                    $actualValue = $extractedFields[$fieldKey] ?? '';
                    $cer = calculateCER($actualValue, $expectedValue);
                    $akurasi = 100 - $cer;

                    $itemResult['fields'][$fieldKey] = [
                        'expected' => $expectedValue,
                        'actual' => $actualValue,
                        'cer' => round($cer, 2),
                        'akurasi' => round($akurasi, 2)
                    ];

                    if (!isset($fieldStats[$fieldKey])) {
                        $fieldStats[$fieldKey] = ['sum_cer' => 0, 'count' => 0];
                    }
                    $fieldStats[$fieldKey]['sum_cer'] += $cer;
                    $fieldStats[$fieldKey]['count']++;
                }
                unlink($tempPreprocessed);
            }
        } catch (Exception $e) {
            $itemResult['error'] = $e->getMessage();
        }
        $testResults[] = $itemResult;
    }

    echo json_encode([
        'success' => true, 
        'results' => $testResults, 
        'stats' => $fieldStats
    ]);
    exit;
}
// =========================================================================
// FITUR PENGUJIAN AKURASI KK (CER)
// =========================================================================
if (isset($_GET['run_test_kk']) && $_GET['run_test_kk'] == '1') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(0); // Mencegah timeout karena OCR memakan waktu lama

    $datasetFile = __DIR__ . '/dataset_kk.json';
    if (!file_exists($datasetFile)) {
        echo json_encode(['success' => false, 'message' => 'File dataset_kk.json tidak ditemukan! Pastikan Anda sudah membuatnya.']);
        exit;
    }

    $dataset = json_decode(file_get_contents($datasetFile), true);
    
    // Filter dataset hanya untuk gambar KK yang diupload jika parameter filename tersedia
    if (isset($_GET['filename']) && !empty($_GET['filename'])) {
        $targetFilename = $_GET['filename'];
        $dataset = array_filter($dataset, function($data) use ($targetFilename) {
            return basename($data['image_path']) === $targetFilename;
        });
        
        if (empty($dataset)) {
            echo json_encode(['success' => false, 'message' => 'Gambar KK ' . htmlspecialchars($targetFilename) . ' tidak memiliki kunci jawaban di dataset_kk.json!']);
            exit;
        }
    }

    $testResults = [];
    $fieldStats = [];

    foreach ($dataset as $data) {
        $imagePath = __DIR__ . '/' . $data['image_path'];
        $groundTruth = $data['ground_truth'];
        
        $itemResult = ['image' => basename($imagePath), 'fields' => [], 'error' => null];

        if (!file_exists($imagePath)) {
            $itemResult['error'] = 'Gambar tidak ditemukan di folder';
            $testResults[] = $itemResult;
            continue;
        }

        $tempPreprocessed = __DIR__ . '/uploads/temp_test_kk_' . uniqid() . '.png';
        
        try {
            if (preprocessImage($imagePath, $tempPreprocessed)) {
                $ocr = new TesseractOCR($tempPreprocessed);
                $ocr->lang('ind+eng')->psm(6)->oem(3);
                $rawText = $ocr->run();
                
                // EKSTRAK KHUSUS NOMOR KK
                $extractedNomorKk = extractNomorKK($rawText);
                $extractedFields = ['nomor_kk' => $extractedNomorKk ?? ''];
                
                foreach ($groundTruth as $fieldKey => $expectedValue) {
                    $actualValue = $extractedFields[$fieldKey] ?? '';
                    $cer = calculateCER($actualValue, $expectedValue);
                    $akurasi = 100 - $cer;

                    $itemResult['fields'][$fieldKey] = [
                        'expected' => $expectedValue,
                        'actual' => $actualValue,
                        'cer' => round($cer, 2),
                        'akurasi' => round($akurasi, 2)
                    ];

                    if (!isset($fieldStats[$fieldKey])) {
                        $fieldStats[$fieldKey] = ['sum_cer' => 0, 'count' => 0];
                    }
                    $fieldStats[$fieldKey]['sum_cer'] += $cer;
                    $fieldStats[$fieldKey]['count']++;
                }
                unlink($tempPreprocessed);
            }
        } catch (Exception $e) {
            $itemResult['error'] = $e->getMessage();
        }
        $testResults[] = $itemResult;
    }

    echo json_encode([
        'success' => true, 
        'results' => $testResults, 
        'stats' => $fieldStats
    ]);
    exit;
}
// =========================================================================

// Clear output buffer untuk HTML response
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR - Image to Text Converter</title>
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
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            margin-top: 10px;
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
        
        /* KTP Fields Styling */
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
            display: flex;
            align-items: center;
            gap: 10px;
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
            display: block;
        }
        
        .field-value {
            color: #333;
            font-size: 1em;
            word-wrap: break-word;
        }

        .field-value[contenteditable="true"] {
            min-height: 24px;
            padding: 4px 6px;
            border-radius: 4px;
            outline: none;
            transition: background-color 0.2s, box-shadow 0.2s;
            white-space: pre-wrap;
        }

        .field-value[contenteditable="true"]:focus {
            background: #eef3ff;
            box-shadow: inset 0 0 0 1px #667eea;
        }

        .field-value[contenteditable="true"]:empty::before {
            content: attr(data-placeholder);
            color: #999;
            font-style: italic;
        }
        
        .field-value.empty {
            color: #999;
            font-style: italic;
        }
        
        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .tab-button {
            padding: 10px 20px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1em;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab-button.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-button:hover {
            color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
            <h1>🔍 OCR Converter</h1>
            <p>Konversi Gambar menjadi Teks menggunakan Tesseract OCR</p>
        </div>
        
        <div class="content">
            <form id="ocrForm" enctype="multipart/form-data">
                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <div class="upload-area" id="uploadAreaKtp">
                            <div class="upload-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;">
                                    <rect x="2" y="4" width="20" height="16" rx="2" ry="2"></rect>
                                    <circle cx="8" cy="10" r="2"></circle>
                                    <path d="M4 16c0-1.5 2-2.5 4-2.5s4 1 4 2.5"></path>
                                    <line x1="14" y1="9" x2="20" y2="9"></line>
                                    <line x1="14" y1="13" x2="18" y2="13"></line>
                                    <line x1="14" y1="17" x2="19" y2="17"></line>
                                </svg>
                            </div>
                            <div class="upload-text">
                                <h3>Upload Gambar KTP</h3>
                                <p>Supported: JPG, PNG, dll (Max 5MB)</p>
                            </div>
                        </div>
                        <input type="file" id="imageInputKtp" name="image_ktp" accept="image/*" required style="display: none;">
                        <div class="filename" id="filenameKtp"></div>
                    </div>
                    <div>
                        <div class="upload-area" id="uploadAreaKk">
                            <div class="upload-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#764ba2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="7" y1="7" x2="17" y2="7" stroke-width="2"></line>
                                    <circle cx="8" cy="13" r="1.5"></circle>
                                    <path d="M6 17c0-.6.4-1 1-1h2c.6 0 1 .4 1 1v1H6v-1z"></path>
                                    <circle cx="13" cy="13" r="1.5"></circle>
                                    <path d="M11 17c0-.6.4-1 1-1h2c.6 0 1 .4 1 1v1h-4v-1z"></path>
                                    <circle cx="17.5" cy="14" r="1"></circle>
                                    <path d="M16 17c0-.4.3-.7.7-.7h1.6c.4 0 .7.3.7.7v1h-3v-1z"></path>
                                </svg>
                            </div>
                            <div class="upload-text">
                                <h3>Upload Gambar KK</h3>
                                <p>Supported: JPG, PNG, dll (Max 5MB)</p>
                            </div>
                        </div>
                        <input type="file" id="imageInputKk" name="image_kk" accept="image/*" required style="display: none;">
                        <div class="filename" id="filenameKk"></div>
                    </div>
                </div>
               <div class="form-group">
    <button type="submit" class="btn btn-primary">Konversi ke Teks</button>
    <div style="display: flex; gap: 10px; margin-top: 10px;">
        <button type="reset" class="btn btn-secondary" style="flex: 1; margin-top: 0;">Reset Form</button>
        <button type="button" id="btnTestAkurasiCombined" class="btn btn-secondary" style="flex: 2; margin-top: 0; background: #17a2b8; color: white; font-weight: bold; border: none; cursor: pointer;">📊 Uji Akurasi KTP & KK Sekaligus</button>
    </div>
</div>
            </form>
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Sedang memproses gambar... Mohon tunggu sebentar.</p>
            </div>
            
            <div class="result-section" id="resultSection">
                <div id="resultMessage"></div>
                
                <div class="images-preview" id="imagesPreview" style="display: none;"></div>
                
                <!-- Tab buttons untuk switch antara Teks Raw dan Fields -->
                <div class="tab-buttons" id="tabButtons" style="display: none;">
                    <button class="tab-button active" onclick="switchTab('raw-text')">📄 Teks Mentah</button>
                    <button class="tab-button" onclick="switchTab('fields')">📋 Data Terstruktur</button>
                </div>
                
                <!-- Raw Text Result -->
                <div class="tab-content active" id="tab-raw-text">
                    <div class="text-result" id="textResult" style="display: none;">
                        <h4>📄 Hasil Teks yang Dikonversi:</h4>
                        <p id="extractedText"></p>
                        <button class="copy-btn" onclick="copyToClipboard()">📋 Salin Teks</button>
                    </div>
                </div>
                
                <!-- Structured Fields Result -->
                <div class="tab-content" id="tab-fields">
                    <div class="fields-section" id="fieldsSection" style="display: none;">
                        <h4>🆔 Data Identitas KTP (Terstruktur)</h4>
                        <div class="fields-grid" id="fieldsGrid"></div>
                        <button class="copy-btn" onclick="copyFieldsToClipboard()" style="margin-top: 20px;">📋 Salin Semua Data</button>
                    </div>
                </div>
                
                <!-- Tempat untuk Hasil Uji Akurasi -->
                <div id="accuracyResult" style="margin-top: 30px;"></div>
            </div>
        </div>
    </div>
    
    <script>
        const uploadAreaKtp = document.getElementById('uploadAreaKtp');
        const imageInputKtp = document.getElementById('imageInputKtp');
        const filenameKtp = document.getElementById('filenameKtp');

        const uploadAreaKk = document.getElementById('uploadAreaKk');
        const imageInputKk = document.getElementById('imageInputKk');
        const filenameKk = document.getElementById('filenameKk');
        
        const ocrForm = document.getElementById('ocrForm');
        const resultSection = document.getElementById('resultSection');
        const loading = document.getElementById('loading');
        
        // Drag & Drop Functionality Helper
        function setupDragAndDrop(area, input, filenameEl) {
            area.addEventListener('click', () => input.click());
            
            area.addEventListener('dragover', (e) => {
                e.preventDefault();
                area.classList.add('dragover');
            });
            
            area.addEventListener('dragleave', () => {
                area.classList.remove('dragover');
            });
            
            area.addEventListener('drop', (e) => {
                e.preventDefault();
                area.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    input.files = files;
                    updateFilename(input, filenameEl);
                }
            });
            
            input.addEventListener('change', () => updateFilename(input, filenameEl));
        }

        function updateFilename(input, filenameEl) {
            if (input.files.length > 0) {
                filenameEl.textContent = 'âœ“ ' + input.files[0].name;
                filenameEl.style.color = '#28a745';
            } else {
                filenameEl.textContent = '';
            }
        }

        // Setup both areas
        setupDragAndDrop(uploadAreaKtp, imageInputKtp, filenameKtp);
        setupDragAndDrop(uploadAreaKk, imageInputKk, filenameKk);
        
        // Form Submit
        ocrForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!imageInputKtp.files.length || !imageInputKk.files.length) {
                showMessage('Pilih gambar KTP dan KK terlebih dahulu!', 'error');
                return;
            }
            
            const formData = new FormData(ocrForm);
            console.log("Form submitted, sending to server...");
            
            resultSection.classList.add('show');
            loading.classList.add('show');
            
            try {
                // Fetch JSON response langsung
                console.log("Fetching: " + window.location.href + '?json=1');
                const jsonResponse = await fetch(window.location.href + '?json=1', {
                    method: 'POST',
                    body: formData
                });
                
                console.log("Response status:", jsonResponse.status);
                
                if (!jsonResponse.ok) {
                    throw new Error('Server error: ' + jsonResponse.status);
                }
                
                const responseText = await jsonResponse.text();
                console.log("Raw response length:", responseText.length);
                console.log("Raw response (first 500 chars):", responseText.substring(0, 500));
                
                // Validate JSON response
                if (!responseText || responseText.trim() === '') {
                    throw new Error('Server tidak memberikan response');
                }
                
                let result;
                try {
                    result = JSON.parse(responseText);
                    console.log("Parsed JSON result:", result);
                } catch (parseError) {
                    console.error('JSON Parse Error:', parseError);
                    console.error('Response:', responseText.substring(0, 500));
                    throw new Error('Response tidak valid: ' + parseError.message);
                }
                
                loading.classList.remove('show');
                
                console.log("Result object keys:", Object.keys(result));
                console.log("Result.success:", result.success);
                console.log("Result.message:", result.message);
                console.log("Result.text:", result.text);
                console.log("Result.text type:", typeof result.text);
                console.log("Result.text length:", result.text ? result.text.length : 'undefined');
                
                if (result.success) {
                    console.log("Success! Displaying result...");
                    showMessage(result.message, 'success');
                    displayResult(result);
                } else {
                    console.log("Error from server:", result.message);
                    showMessage(result.message || 'Terjadi error saat memproses gambar', 'error');
                }
            } catch (error) {
                loading.classList.remove('show');
                console.error('Fetch Error:', error);
                showMessage('Error: ' + error.message, 'error');
            }
        });
        
        function showMessage(message, type) {
            const messageDiv = document.getElementById('resultMessage');
            messageDiv.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
        }
        
        function displayResult(result) {
            console.log('Display Result called with:', result);
            
            const imagesPreview = document.getElementById('imagesPreview');
            const textResult = document.getElementById('textResult');
            const extractedText = document.getElementById('extractedText');
            const fieldsSection = document.getElementById('fieldsSection');
            const fieldsGrid = document.getElementById('fieldsGrid');
            const tabButtons = document.getElementById('tabButtons');
            
            // Display images
            if (result.originalImage && result.preprocessedImage) {
                console.log('Displaying images...');
                imagesPreview.style.display = 'grid';
                imagesPreview.innerHTML = `
                    <div class="image-preview">
                        <h4>Gambar Original KTP</h4>
                        <img src="${result.originalImage}" alt="Original Image KTP">
                    </div>
                    <div class="image-preview">
                        <h4>Gambar Setelah Preprocessing KTP</h4>
                        <img src="${result.preprocessedImage}" alt="Preprocessed Image KTP">
                    </div>
                `;
                
                if (result.originalImageKk && result.preprocessedImageKk) {
                    imagesPreview.innerHTML += `
                        <div class="image-preview" style="margin-top: 20px;">
                            <h4>Gambar Original KK</h4>
                            <img src="${result.originalImageKk}" alt="Original Image KK">
                        </div>
                        <div class="image-preview" style="margin-top: 20px;">
                            <h4>Gambar Setelah Preprocessing KK</h4>
                            <img src="${result.preprocessedImageKk}" alt="Preprocessed Image KK">
                        </div>
                    `;
                }
            }
            
            // Display extracted text
            console.log('Result text field:', result.text);
            console.log('Result fields:', result.fields);
            
            if (result.text) {
                const trimmedText = result.text.trim();
                
                if (trimmedText.length > 0) {
                    if (extractedText) {
                        extractedText.textContent = trimmedText;
                    }
                    if (textResult) {
                        textResult.style.display = 'block';
                    }
                }
            }
            
            // Display structured fields
            if (result.fields && typeof result.fields === 'object') {
                const fields = result.fields;
                console.log('Processing fields:', fields);
                
                // Filter fields yang memiliki nilai
                const fieldLabels = {
                    'nik': '📌 Nomor NIK',
                    'nama': '👤 Nama Lengkap',
                    'nomor_kk': '👨‍👩‍👧‍👦 Nomor Kartu Keluarga',
                    'tempat_lahir': '📍 Tempat Lahir',
                    'tanggal_lahir': '📅 Tanggal Lahir',
                    'jenis_kelamin': '⚧️ Jenis Kelamin',
                    'gol_darah': '🩸 Golongan Darah',
                    'alamat': '🏠 Alamat Lengkap',
                    'rt_rw': '🏘️ RT/RW',
                    'kelurahan': '🏡 Kelurahan/Desa',
                    'kecamatan': '🗺️ Kecamatan',
                    'kota_kabupaten': '🏙️ Kota/Kabupaten',
                    'provinsi': '🌎 Provinsi',
                    'agama': '⛪ Agama',
                    'status_perkawinan': '💍 Status Perkawinan',
                    'pekerjaan': '👔 Pekerjaan'
                };
                
                let fieldsHTML = '';
                let hasFields = false;
                
                for (const [key, label] of Object.entries(fieldLabels)) {
                    const rawValue = fields[key] ?? '';
                    const value = String(rawValue).trim();
                    hasFields = true;
                    fieldsHTML += `
                        <div class="field-item">
                            <span class="field-label">${label}</span>
                            <div class="field-value"
                                 contenteditable="true"
                                 spellcheck="false"
                                 data-field-key="${key}"
                                 data-placeholder="Klik untuk isi">${escapeHtml(value)}</div>
                        </div>
                    `;
                }
                
                if (hasFields) {
                    fieldsGrid.innerHTML = fieldsHTML;
                    fieldsSection.style.display = 'block';
                    tabButtons.style.display = 'flex';
                    bindEditableFieldEvents();
                } else {
                    console.log('No fields extracted');
                    fieldsSection.style.display = 'none';
                }
            }
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        function sanitizeEditableText(text) {
            return String(text || '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function bindEditableFieldEvents() {
            document.querySelectorAll('.field-value[contenteditable="true"]').forEach((element) => {
                element.addEventListener('paste', (event) => {
                    event.preventDefault();
                    const text = (event.clipboardData || window.clipboardData).getData('text/plain');
                    document.execCommand('insertText', false, text);
                });

                element.addEventListener('input', () => {
                    const sanitized = sanitizeEditableText(element.textContent);
                    element.textContent = sanitized;
                    moveCaretToEnd(element);
                });

                element.addEventListener('blur', () => {
                    element.textContent = sanitizeEditableText(element.textContent);
                });
            });
        }

        function moveCaretToEnd(element) {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(element);
            range.collapse(false);
            selection.removeAllRanges();
            selection.addRange(range);
        }
        
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            const selectedTab = document.getElementById('tab-' + tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        function copyFieldsToClipboard() {
            const fieldsGrid = document.getElementById('fieldsGrid');
            const fieldItems = fieldsGrid.querySelectorAll('.field-item');
            
            let text = 'DATA IDENTITAS KTP\n';
            text += '===================\n\n';
            
            fieldItems.forEach(item => {
                const label = item.querySelector('.field-label').textContent.trim();
                const value = item.querySelector('.field-value').textContent.trim();
                text += label + ': ' + value + '\n';
            });
            
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '✓ Tersalin!';
                
                setTimeout(() => {
                    btn.textContent = originalText;
                }, 2000);
            });
        }
        
        function copyToClipboard() {
            const extractedText = document.getElementById('extractedText');
            const text = extractedText.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '✓ Tersalin!';
                
                setTimeout(() => {
                    btn.textContent = originalText;
                }, 2000);
            });
        }
        
        // Reset form
        ocrForm.addEventListener('reset', () => {
            filenameKtp.textContent = '';
            filenameKk.textContent = '';
            resultSection.classList.remove('show');
            loading.classList.remove('show');
        });

        // Handler untuk Tombol Uji Akurasi KTP & KK (Gabungan)
        document.getElementById('btnTestAkurasiCombined').addEventListener('click', async () => {
            const imageInputKtp = document.getElementById('imageInputKtp');
            const imageInputKk = document.getElementById('imageInputKk');

            // Validasi form harus terisi dua-duanya
            if (!imageInputKtp.files.length || !imageInputKk.files.length) {
                showMessage('Harap upload gambar KTP dan KK terlebih dahulu untuk diuji.', 'error');
                return;
            }

            const ktpFilename = imageInputKtp.files[0].name;
            const kkFilename = imageInputKk.files[0].name;

            const resultSection = document.getElementById('resultSection');
            const accuracyResult = document.getElementById('accuracyResult');
            const loading = document.getElementById('loading');
            
            resultSection.classList.add('show');
            loading.classList.add('show');
            accuracyResult.innerHTML = '';
            
            try {
                // Fetch API KTP dan KK secara BERSAMAAN (Paralel) menggunakan Promise.all
                const [resKtp, resKk] = await Promise.all([
                    fetch(window.location.href + '?run_test=1&filename=' + encodeURIComponent(ktpFilename)),
                    fetch(window.location.href + '?run_test_kk=1&filename=' + encodeURIComponent(kkFilename))
                ]);

                const dataKtp = await resKtp.json();
                const dataKk = await resKk.json();
                
                loading.classList.remove('show');
                
                // Gabungkan Laporan KTP dan KK
                let combinedData = {
                    stats: {},
                    results: [],
                    success: false
                };
                
                let errorMessages = [];

                if (dataKtp.success) {
                    combinedData.success = true;
                    Object.assign(combinedData.stats, dataKtp.stats);
                    dataKtp.results.forEach(res => {
                        res.image = res.image + ' (KTP)';
                        combinedData.results.push(res);
                    });
                } else {
                    errorMessages.push(`KTP: ${dataKtp.message}`);
                }

                if (dataKk.success) {
                    combinedData.success = true;
                    Object.assign(combinedData.stats, dataKk.stats);
                    dataKk.results.forEach(res => {
                        res.image = res.image + ' (KK)';
                        combinedData.results.push(res);
                    });
                } else {
                    errorMessages.push(`KK: ${dataKk.message}`);
                }
                
                let htmlReport = '';
                
                if (errorMessages.length > 0) {
                    errorMessages.forEach(msg => {
                        htmlReport += `<div style="padding: 15px; background: #f8d7da; border-left: 4px solid #721c24; margin-bottom: 20px;"><strong>Error:</strong> ${msg}</div>`;
                    });
                }

                if (combinedData.success) {
                    htmlReport += generateReportHTML(combinedData, 'KTP & KK (Gabungan)', '#17a2b8'); // Gunakan satu warna dan tabel
                }

                accuracyResult.innerHTML = htmlReport;

            } catch (error) {
                loading.classList.remove('show');
                showMessage('Terjadi kesalahan saat memproses pengujian: ' + error.message, 'error');
            }
        });

        /**
         * Helper function untuk merender tabel laporan HTML agar kode tidak berulang
         */
        function generateReportHTML(data, title, color) {
            let html = `
                <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid ${color}; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                    <h3 style="margin-bottom: 15px; color: #333;">📊 Laporan Akurasi ${title}</h3>
                    
                    <h4 style="margin: 15px 0 10px;">Rata-rata Akurasi per Kolom</h4>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;">
                        <tr style="background: ${color}; color: white;">
                            <th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Kolom (Field)</th>
                            <th style="padding: 10px; border: 1px solid #ddd; text-align: center;">Akurasi Rata-rata</th>
                        </tr>`;
            
            for (const [field, stat] of Object.entries(data.stats)) {
                let avgAkurasi = 100 - (stat.sum_cer / stat.count);
                let textColor = avgAkurasi >= 90 ? '#155724' : (avgAkurasi >= 70 ? '#856404' : '#721c24');
                html += `
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">${field.toUpperCase()}</td>
                        <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: ${textColor}; font-weight: bold;">${avgAkurasi.toFixed(2)}%</td>
                    </tr>`;
            }
            
            html += `</table><h4 style="margin: 20px 0 10px;">Detail Pembacaan OCR</h4>`;

            data.results.forEach(res => {
                if (res.error) {
                    html += `<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin-bottom: 10px;">${res.image} - Error: ${res.error}</div>`;
                    return;
                }

                html += `
                    <div style="margin-bottom: 15px; border: 1px solid #eee; border-radius: 5px; overflow: hidden;">
                        <div style="background: #f8f9fa; padding: 10px; font-weight: bold; border-bottom: 1px solid #eee;">📄 ${res.image}</div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                            <tr style="background: #f1f3f5;">
                                <th style="padding: 8px; border: 1px solid #ddd;">Field</th>
                                <th style="padding: 8px; border: 1px solid #ddd;">Ground Truth (Jawaban)</th>
                                <th style="padding: 8px; border: 1px solid #ddd;">Hasil Mesin OCR</th>
                                <th style="padding: 8px; border: 1px solid #ddd; text-align: center;">Akurasi</th>
                            </tr>`;
                            
                for (const [field, val] of Object.entries(res.fields)) {
                    let textColor = val.akurasi >= 90 ? '#155724' : (val.akurasi >= 70 ? '#856404' : '#721c24');
                    html += `
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">${field}</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${val.expected}</td>
                            <td style="padding: 8px; border: 1px solid #ddd;">${val.actual}</td>
                            <td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: ${textColor}; font-weight: bold;">${val.akurasi}%</td>
                        </tr>`;
                }
                html += `</table></div>`;
            });

            html += `</div>`;
            return html;
        }
    </script>
</body>
</html>