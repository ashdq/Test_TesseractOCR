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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $file = $_FILES['image'];
    
    // Validasi file
    $error = validateFile($file, $maxFileSize, $allowedExtensions);
    
    if ($error) {
        $result['message'] = $error;
    } else {
        // Simpan file original
        $filename = uniqid('ocr_') . '.' . getFileExtension($file['name']);
        $filepath = $uploadsDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $result['originalImage'] = 'uploads/' . $filename;
            
            // Lakukan preprocessing dan simpan versi yang sudah dipreprocess
            $preprocessedFilename = uniqid('preprocessed_') . '.png';
            $preprocessedPath = $uploadsDir . '/' . $preprocessedFilename;
            
            // Try preprocessing
            $preprocessSuccess = preprocessImage($filepath, $preprocessedPath);
            
            if (!$preprocessSuccess) {
                $result['message'] = 'Error saat melakukan preprocessing gambar. Coba dengan gambar lain.';
                error_log("Preprocessing failed for file: " . $filepath);
                if (file_exists($filepath)) unlink($filepath);
                if (file_exists($preprocessedPath)) unlink($preprocessedPath);
            } elseif (!file_exists($preprocessedPath)) {
                $result['message'] = 'File preprocessed tidak ditemukan setelah preprocessing.';
                error_log("Preprocessed file not created at: " . $preprocessedPath);
                if (file_exists($filepath)) unlink($filepath);
            } else {
                // File preprocessing berhasil
                $result['preprocessedImage'] = 'uploads/' . $preprocessedFilename;
                
                // Lakukan OCR pada gambar yang sudah dipreprocess
                try {
                    // Check if file exists sebelum OCR
                    if (!file_exists($preprocessedPath)) {
                        throw new Exception('Preprocessed image file tidak ditemukan untuk OCR');
                    }
                    
                    $ocr = new TesseractOCR($preprocessedPath);
                    // Set bahasa OCR (gunakan 'ind' untuk Indonesia, 'eng' untuk English)
                    $ocr->lang('ind+eng');
                    
                    // Set PSM (Page Segmentation Mode) - 3 adalah auto segmentation yang bagus untuk dokumen
                    // 6 lebih baik untuk uniform text blocks
                    $ocr->psm(6);
                    
                    // Set OEM (OCR Engine Mode) - 3 adalah kombinasi legacy + LSTM (paling akurat)
                    $ocr->oem(3);
                    
                    // Configure Tesseract untuk akurasi lebih baik
                    // Whitelist characters jika menggunakan karakter tertentu (optional)
                    // $ocr->whitelist('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
                    
                    error_log("Starting OCR on: " . $preprocessedPath);
                    $extractedText = $ocr->run();
                    error_log("OCR completed. Result length: " . strlen($extractedText));
                    
                    // Check if result is empty
                    if (empty($extractedText) || trim($extractedText) === '') {
                        throw new Exception('Tesseract menghasilkan hasil kosong. Coba dengan gambar yang lebih jelas.');
                    }
                    
                    // Debug: Log extracted text length
                    error_log("OCR Raw Output Length: " . strlen($extractedText));
                    error_log("OCR Raw Output (first 200 chars): " . substr($extractedText, 0, 200));
                    
                    // Lakukan post-processing untuk cleanup text.
                    // Jika post-processing gagal, fallback ke raw OCR text.
                    $rawText = trim((string)$extractedText);
                    $processedText = postProcessOCRText($rawText);
                    
                    if (!is_string($processedText) || trim($processedText) === '') {
                        error_log("Post-processing returned empty, fallback to raw OCR text");
                        $processedText = $rawText;
                    }
                    
                    if (trim($processedText) === '') {
                        throw new Exception('Teks OCR kosong setelah pemrosesan. Coba gambar lain yang lebih jelas.');
                    }
                    
                    // Debug: Log processed text
                    error_log("Processed Text Length: " . strlen($processedText));
                    error_log("Processed Text (first 200 chars): " . substr($processedText, 0, 200));
                    
                    $result['success'] = true;
                    $result['text'] = trim($processedText);
                    $result['message'] = 'Gambar berhasil dikonversi ke teks!';
                    
                    // Extract KTP fields dari OCR text
                    $result['fields'] = extractKtpFields($result['text']);
                    
                    // Debug: Log final result
                    error_log("Final Result Text Length: " . strlen($result['text']));
                    error_log("Final Result Text: " . $result['text']);
                    error_log("Extracted Fields: " . json_encode($result['fields']));
                    
                    // File asli dipertahankan agar preview "Gambar Original" tidak 404.
                    
                } catch (Exception $e) {
                    $result['message'] = 'Error saat melakukan OCR: ' . $e->getMessage();
                    error_log("OCR Error: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    // Hapus file yang sudah diupload jika terjadi error
                    if (file_exists($filepath)) unlink($filepath);
                    if (file_exists($preprocessedPath)) unlink($preprocessedPath);
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
 * Extract KTP fields dari OCR text
 * Parse text dan return array dengan field-field KTP terstruktur
 * Dengan multiple fallback patterns untuk menangkap berbagai format OCR
 */
function extractKtpFields($text) {
    $fields = [
        'nik' => null,
        'nama' => null,
        'nomor_kk' => null,
        'tempat_tgl_lahir' => null,  // Gabung: "JAKARTA / 25-01-1990"
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
        'kewarganegaraan' => null,
        'berlaku_hingga' => null,
    ];
    
    // Split text menjadi lines untuk processing
    $lines = explode("\n", $text);
    $fullText = strtolower($text); // untuk searching multi-line patterns
    
    // Pattern untuk setiap field dengan multiple fallbacks
    foreach ($lines as $lineIndex => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $lineLower = strtolower($line);
        
        // 1. Extract NIK (16 digit)
        if (preg_match('/NIK\s*[:=]\s*(\d{16})/i', $line, $matches)) {
            $fields['nik'] = $matches[1];
        }
        
        // 2. Extract Nama (text setelah "Nama")
        if (preg_match('/Nama\s*[:=]\s*([^|\n\r]+)/i', $line, $matches)) {
            $nama = trim($matches[1]);
            if (!empty($nama) && strlen($nama) > 2) {
                $fields['nama'] = $nama;
            }
        }
        
        // 3. Extract Nomor Kartu Keluarga (16 digit)
        if (preg_match('/(?:No(?:mor)?\.?\s*)?Kartu\s*Keluarga\s*[:=]\s*(\d{16})/i', $line, $matches)) {
            $fields['nomor_kk'] = $matches[1];
        } elseif (preg_match('/NK\s*[:=]\s*(\d{16})/i', $line, $matches)) {
            $fields['nomor_kk'] = $matches[1];
        }
        
        // 4-5. Extract Tempat & Tanggal Lahir (GABUNG dalam satu field)
        // Handle multiple formats:
        // - "Tempat/Tgl Lahir : JAKARTA / 25-01-1990"
        // - "TempaTgl : Lahir:TANJUNGPINANG, 25 - 02 - 2001"
        // - "Tempat Lahir : JAKARTA" (separate line)
        // - "Tgl Lahir : 25-01-1990" (separate line)
        
        // Format 1: Standard "Tempat/Tgl Lahir" with "/" separator
        if (preg_match('/Tempat\s*\/\s*Tgl\s*Lahir\s*[:=]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3) {
                if (empty($fields['tempat_tgl_lahir'])) {
                    $fields['tempat_tgl_lahir'] = $combined;
                }
            }
        }
        
        // Format 2: "Tempat Tgl Lahir" with spaces
        else if (preg_match('/Tempat\s+Tgl\s+Lahir\s*[:=]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3) {
                if (empty($fields['tempat_tgl_lahir'])) {
                    $fields['tempat_tgl_lahir'] = $combined;
                }
            }
        }
        
        // Format 3: "TempaTgl : Lahir:" (combined word, then lahir prefix on same line)
        else if (preg_match('/TempaTgl\s*[:=]\s*Lahir\s*[:=]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3) {
                if (empty($fields['tempat_tgl_lahir'])) {
                    // Clean up the combined string - remove extra spaces before dates
                    $combined = preg_replace('/\s+(\d{1,2})\s+(-|\/)\s+(\d{1,2})\s+(-|\/)\s+(\d{4})/', ' $1-$3-$5', $combined);
                    $fields['tempat_tgl_lahir'] = $combined;
                }
            }
        }
        
        // Format 4: "TempaTgl" (typo/OCR error) - capture everything after it
        else if (preg_match('/TempaTgl\s*[:=>\s]\s*(.+)/i', $line, $matches)) {
            $combined = trim($matches[1]);
            if (!empty($combined) && strlen($combined) > 3 && stripos($combined, 'lahir') !== false) {
                if (empty($fields['tempat_tgl_lahir'])) {
                    // Remove "Lahir" prefix and clean
                    $combined = preg_replace('/^Lahir\s*[:=]\s*/i', '', $combined);
                    $combined = preg_replace('/\s+(\d{1,2})\s+(-|\/)\s+(\d{1,2})\s+(-|\/)\s+(\d{4})/', ' $1-$3-$5', $combined);
                    $fields['tempat_tgl_lahir'] = trim($combined);
                }
            }
        }
        
        // Format 5: Fallback - "Tempat Lahir" dengan date di baris yang sama
        else if (preg_match('/Tempat\s+Lahir\s*[:=]\s*([^,|\n\r]+?)(?:\s+(\d{1,2}[-\/]\d{1,2}[-\/]\d{4}))?/i', $line, $matches)) {
            $tempat = trim($matches[1]);
            $date = isset($matches[2]) ? trim($matches[2]) : '';
            
            if (!empty($tempat) && strlen($tempat) > 2) {
                if (!empty($date)) {
                    $combined = $tempat . ' / ' . $date;
                    if (empty($fields['tempat_tgl_lahir'])) {
                        $fields['tempat_tgl_lahir'] = $combined;
                    }
                }
            }
        }
        
        // Format 6: Fallback - detect date pattern after tempat/lahir keyword
        if (empty($fields['tempat_tgl_lahir']) && preg_match('/([A-Z][A-Za-z\s,.-]+?)(?:\s+\/?\s+|,\s+)?(\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{4})/i', $line, $matches)) {
            if (stripos($lineLower, 'lahir') !== false) {
                $tempat = trim($matches[1]);
                $date = trim($matches[2]);
                // Normalize date format
                $date = preg_replace('/\s+/', '', $date);
                $date = preg_replace('/[-\/]+/', '-', $date);
                if (!empty($tempat) && strlen($tempat) > 2 && preg_match('/\d{1,2}-\d{1,2}-\d{4}/', $date)) {
                    $fields['tempat_tgl_lahir'] = $tempat . ' / ' . $date;
                }
            }
        }
        
        // 6. Extract Jenis Kelamin
        if (preg_match('/Jenis\s*Kelamin\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $jk = trim($matches[1]);
            if (preg_match('/(Laki|Perempuan)/i', $jk, $jk_matches)) {
                $fields['jenis_kelamin'] = ucfirst(strtolower($jk_matches[1]));
            }
        }
        
        // 7. Extract Golongan Darah
        if (preg_match('/(?:Gol|Golongan)\.?\s*Darah\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $gol = trim($matches[1]);
            if (preg_match('/(A|B|AB|O)/i', $gol, $gol_matches)) {
                $fields['gol_darah'] = $gol_matches[1];
            }
        }
        
        // 8. Extract Alamat
        if (preg_match('/Alamat\s*[:=]\s*(.+?)(?=\s*(?:RT\s*\/\s*RW|RT\/RW|RT\s*[:=>]|RW\s*[:=>]|Kelurahan|Desa|Kel\.\s*\/?\s*Desa|Kel\.|Kecamatan|Kec\.?|$))/i', $line, $matches)) {
            $alamat = trim($matches[1]);
            $alamat = preg_replace('/\s+["\']?\s*$/', '', $alamat);
            $alamat = rtrim($alamat, ' ,;:.-');
            if (!empty($alamat) && strlen($alamat) > 2) {
                $fields['alamat'] = $alamat;
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
            if (!empty($kec) && strlen($kec) > 2) {
                $fields['kecamatan'] = $kec;
            }
        }
        
        // 13. Extract Kota/Kabupaten
        if (preg_match('/(?:Kota|Kabupaten)\s*(?:[:=]\s*|\s+)([^\n\r|]+)/i', $line, $matches)) {
            $kota = trim($matches[1]);
            $kota = preg_replace('/\s+/', ' ', $kota);
            $kota = preg_replace('/^(KOTA|KABUPATEN)\s+\1\b/i', '$1', $kota);
            if (!empty($kota) && strlen($kota) > 2) {
                $fields['kota_kabupaten'] = $kota;
            }
        }
        
        // 14. Extract Provinsi
        if (preg_match('/Provinsi\s*(?:[:=]\s*|\s+)([^\n\r|]+)/i', $line, $matches)) {
            $prov = trim($matches[1]);
            $prov = preg_replace('/\s+/', ' ', $prov);
            if (!empty($prov) && strlen($prov) > 2) {
                $fields['provinsi'] = $prov;
            }
        }
        
        // 15. Extract Agama
        if (preg_match('/Agama\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $agama = trim($matches[1]);
            if (!empty($agama) && strlen($agama) > 2) {
                $fields['agama'] = $agama;
            }
        }
        
        // 16. Extract Status Perkawinan
        if (preg_match('/(?:Status\s+)?Perkawinan\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $status = trim($matches[1]);
            if (!empty($status) && strlen($status) > 2) {
                // Ambil hanya status perkawinan yang valid, tanpa sisa teks OCR di belakangnya
                if (preg_match('/\b(BELUM\s+KAWIN|KAWIN|CERAI\s+HIDUP|CERAI\s+MATI)\b/i', $status, $statusMatches)) {
                    $fields['status_perkawinan'] = strtoupper(preg_replace('/\s+/', ' ', trim($statusMatches[1])));
                } else {
                    $fields['status_perkawinan'] = strtoupper(preg_replace('/\s+/', ' ', $status));
                }
            }
        }
        
        // 17. Extract Pekerjaan - dengan multiple format fallbacks
        // Handle formats: "Pekerjaan : ...", "Pekerjaan > ...", "Pekerjaan = ..."
        if (preg_match('/Pekerjaan\s*[:=>\s]\s*([^\n\r|]+)/i', $line, $matches)) {
            $pekerjaan = trim($matches[1]);
            $pekerjaan = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{4}\s*$/', '', $pekerjaan);
            if (!empty($pekerjaan) && strlen($pekerjaan) > 2) {
                if (empty($fields['pekerjaan'])) {
                    $fields['pekerjaan'] = $pekerjaan;
                }
            }
        }
        // Fallback for "Kerjaan" if "Pekerjaan" not found
        else if (preg_match('/Kerjaan\s*[:=>\s]\s*([^\n\r|]+)/i', $line, $matches)) {
            $pekerjaan = trim($matches[1]);
            $pekerjaan = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{4}\s*$/', '', $pekerjaan);
            if (!empty($pekerjaan) && strlen($pekerjaan) > 2) {
                if (empty($fields['pekerjaan'])) {
                    $fields['pekerjaan'] = $pekerjaan;
                }
            }
        }
        // Fallback: Look for lines with occupation-related keywords
        else if (empty($fields['pekerjaan']) && preg_match('/(?:Pekerjaan|Kerjaan|Kerja)\s+[>:\-=]\s*(.+)/i', $line, $matches)) {
            $pekerjaan = trim($matches[1]);
            $pekerjaan = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}\s*[-\/]\s*\d{4}\s*$/', '', $pekerjaan);
            if (!empty($pekerjaan) && strlen($pekerjaan) > 2) {
                $fields['pekerjaan'] = $pekerjaan;
            }
        }
        
        // 18. Extract Kewarganegaraan
        if (preg_match('/Kewarganegaraan\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $kewarga = trim($matches[1]);
            if (!empty($kewarga) && strlen($kewarga) > 2) {
                $fields['kewarganegaraan'] = $kewarga;
            }
        }
        
        // 19. Extract Berlaku Hingga - dengan multiple format fallbacks
        // Format 1: "Berlaku Hingga : 25-01-2035" (with date)
        if (preg_match('/Berlaku\s+(?:Hingga|sampai|s\.d\.?)\s*[:=]\s*(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/i', $line, $matches)) {
            if (empty($fields['berlaku_hingga'])) {
                $fields['berlaku_hingga'] = trim($matches[1]);
            }
        }
        // Format 2: "Berlaku Hingga : SEUMUR HIDUP" (text-based)
        else if (preg_match('/Berlaku\s+(?:Hingga|sampai|Hing[g]?ga)\s*[:=]\s*([^\n\r|]+)/i', $line, $matches)) {
            $berlaku = trim($matches[1]);
            if (!empty($berlaku) && strlen($berlaku) > 2) {
                if (empty($fields['berlaku_hingga'])) {
                    // Check if it's a date or text
                    if (preg_match('/(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/', $berlaku, $dateMatches)) {
                        $fields['berlaku_hingga'] = $dateMatches[1];
                    } else {
                        // It's a text value like "SEUMUR HIDUP"
                        $fields['berlaku_hingga'] = $berlaku;
                    }
                }
            }
        }
        // Format 3: Fallback - "Berlaku" keyword with value after
        else if (empty($fields['berlaku_hingga']) && preg_match('/Berlaku\s+[>:\-=]\s*(.+)/i', $line, $matches)) {
            $berlaku = trim($matches[1]);
            if (!empty($berlaku) && strlen($berlaku) > 2) {
                $fields['berlaku_hingga'] = $berlaku;
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
            <h1>🔤 OCR Converter</h1>
            <p>Konversi Gambar menjadi Teks menggunakan Tesseract OCR</p>
        </div>
        
        <div class="content">
            <form id="ocrForm" enctype="multipart/form-data">
                <div class="form-group">
                    <div class="upload-area" id="uploadArea">
                        <div class="upload-icon">📸</div>
                        <div class="upload-text">
                            <h3>Klik atau Drag & Drop Gambar</h3>
                            <p>Supported: JPG, PNG, GIF, BMP, TIFF (Max 5MB)</p>
                        </div>
                    </div>
                    <input type="file" id="imageInput" name="image" accept="image/*" required>
                    <div class="filename" id="filename"></div>
                    <button type="submit" class="btn btn-primary">Konversi ke Teks</button>
                    <button type="reset" class="btn btn-secondary">Reset</button>
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
        
        imageInput.addEventListener('change', updateFilename);
        
        function updateFilename() {
            if (imageInput.files.length > 0) {
                filename.textContent = '✓ ' + imageInput.files[0].name;
                filename.style.color = '#28a745';
            }
        }
        
        // Form Submit
        ocrForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (!imageInput.files.length) {
                showMessage('Pilih gambar terlebih dahulu!', 'error');
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
                        <h4>Gambar Original</h4>
                        <img src="${result.originalImage}" alt="Original Image">
                    </div>
                    <div class="image-preview">
                        <h4>Gambar Setelah Preprocessing</h4>
                        <img src="${result.preprocessedImage}" alt="Preprocessed Image">
                    </div>
                `;
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
                    'tempat_tgl_lahir': '📍 Tempat / Tanggal Lahir',
                    'jenis_kelamin': '⚧️ Jenis Kelamin',
                    'gol_darah': '🩸 Golongan Darah',
                    'alamat': '🏠 Alamat Lengkap',
                    'rt_rw': '🏘️ RT/RW',
                    'kelurahan': '🏞️ Kelurahan/Desa',
                    'kecamatan': '🗺️ Kecamatan',
                    'kota_kabupaten': '🏙️ Kota/Kabupaten',
                    'provinsi': '🌎 Provinsi',
                    'agama': '⛪ Agama',
                    'status_perkawinan': '💍 Status Perkawinan',
                    'pekerjaan': '👔 Pekerjaan',
                    'kewarganegaraan': '🛂 Kewarganegaraan',
                    'berlaku_hingga': '⏰ Berlaku Hingga'
                };
                
                let fieldsHTML = '';
                let hasFields = false;
                
                for (const [key, label] of Object.entries(fieldLabels)) {
                    const value = fields[key];
                    if (value) {
                        hasFields = true;
                        fieldsHTML += `
                            <div class="field-item">
                                <span class="field-label">${label}</span>
                                <div class="field-value">${escapeHtml(value)}</div>
                            </div>
                        `;
                    }
                }
                
                if (hasFields) {
                    fieldsGrid.innerHTML = fieldsHTML;
                    fieldsSection.style.display = 'block';
                    tabButtons.style.display = 'flex';
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
            filename.textContent = '';
            resultSection.classList.remove('show');
            loading.classList.remove('show');
        });
    </script>
</body>
</html>
