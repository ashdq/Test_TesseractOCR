# 📚 API Documentation & Advanced Configuration

## 🔗 API Endpoints

### POST `/index-opencv.php`

Upload gambar KTP untuk di-scan dan ekstraksi data.

#### Request

```http
POST /index-opencv.php HTTP/1.1
Content-Type: multipart/form-data

image: <file>
```

#### Response (JSON)

**Success:**
```json
{
  "success": true,
  "message": "Gambar berhasil dikonversi ke teks dengan OpenCV preprocessing!",
  "text": "NIK : 3278010510850001\nNama : AGUS HARYANTO\n...",
  "fields": {
    "nik": "3278010510850001",
    "nama": "AGUS HARYANTO",
    "nomor_kk": "3278010000012345",
    "tempat_tgl_lahir": "BANDUNG / 05-10-1985",
    "jenis_kelamin": "Laki",
    "gol_darah": "O",
    "alamat": "JL. DIPATI UKUR NO. 112",
    "rt_rw": "005/003",
    "kelurahan": "DAGO",
    "kecamatan": "COBLONG",
    "kota_kabupaten": "KOTA BANDUNG",
    "provinsi": "JAWA BARAT",
    "agama": "ISLAM",
    "status_perkawinan": "KAWIN",
    "pekerjaan": "PEGAWAI NEGERI SIPIL",
    "kewarganegaraan": "WNI",
    "berlaku_hingga": "05-10-2035"
  },
  "originalImage": "uploads/ocr_xxx.jpg",
  "preprocessedImage": "uploads/preprocessed_xxx.png",
  "debug": {
    "python_path": "/usr/bin/python3",
    "preprocess_time": 0.234,
    "ocr_time": 1.567
  }
}
```

**Error:**
```json
{
  "success": false,
  "message": "Error message here",
  "text": "",
  "fields": {},
  "originalImage": "",
  "preprocessedImage": ""
}
```

#### HTTP Status Codes

- `200 OK` - Request successful (check `success` field)
- `400 Bad Request` - Invalid file
- `413 Payload Too Large` - File size exceeds limit
- `415 Unsupported Media Type` - File type not allowed

#### Query Parameters

- `json=1` - Force JSON response (default for POST)

#### Example Usage

**cURL:**
```bash
curl -X POST -F "image=@ktp.jpg" http://localhost/ocr%205.x/index-opencv.php?json=1
```

**JavaScript Fetch:**
```javascript
const formData = new FormData();
formData.append('image', fileInput.files[0]);

const response = await fetch('index-opencv.php?json=1', {
  method: 'POST',
  body: formData
});

const result = await response.json();
console.log(result.fields);
```

**PHP cURL:**
```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://localhost/ocr%205.x/index-opencv.php?json=1");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, array('image' => '@/path/to/ktp.jpg'));
$response = curl_exec($ch);
$result = json_decode($response, true);
```

---

## ⚙️ Configuration Customization

### 1. Modify Python Path

Edit `index-opencv.php` baris 19-20:

```php
// Auto-detect (recommended)
$pythonPath = getPythonPath();

// Manual path (Windows)
$pythonPath = 'C:\\Python311\\python.exe';

// Manual path (Linux/macOS)
$pythonPath = '/usr/bin/python3';
```

### 2. Modify Max File Size

Edit baris 22:
```php
$maxFileSize = 10 * 1024 * 1024; // 10MB instead of 5MB
```

### 3. Modify Allowed File Extensions

Edit baris 23:
```php
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'];
```

### 4. Change OCR Language

Edit dalam `extractKtpFields()` function, change:
```php
$ocr->lang('ind+eng');  // Indonesian + English
$ocr->lang('eng');      // English only
$ocr->lang('ind');      // Indonesian only
```

### 5. Change OCR PSM Mode

PSM (Page Segmentation Mode) values:
- `3` - Fully automatic (default, untuk dokumen)
- `6` - Uniform block of text (untuk KTP form)
- `7` - Single text line (untuk extracted fields)
- `11` - Sparse text

Edit dalam `extractKtpFields()`:
```php
$ocr->psm(3);  // Change ke 6 atau 7 jika perlu
```

### 6. Modify OpenCV Preprocessing Parameters

Edit `preprocess_image.py`:

**Denoising strength** (baris ~65):
```python
denoised = cv2.fastNlMeansDenoising(gray, None, h=12, ...)
# h=10: light denoise
# h=12: moderate (default)
# h=15: aggressive denoise (bisa blur text)
```

**Contrast enhancement** (baris ~70):
```python
clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8, 8))
# clipLimit=2.0: light
# clipLimit=3.0: moderate (default)
# clipLimit=4.0: aggressive
```

**Brightness & contrast** (baris ~75):
```python
alpha = 1.3  # Contrast multiplier (1.0 = normal)
beta = 20    # Brightness addition
```

**Sharpening** (baris ~80):
```python
blurred = cv2.GaussianBlur(brightened, (0, 0), 2.0)
# sigma=2.0: light blur (less sharpening)
# sigma=2.0: moderate (default)
# sigma=3.0: strong blur (more sharpening)
```

**Adaptive threshold blockSize** (baris ~96):
```python
blockSize=11,  # Kernel size for adaptive threshold
# 7, 9: smaller kernel, more detail but more noise
# 11: moderate (default)
# 13, 15: larger kernel, less detail, less noise
```

---

## 🔌 Advanced Integration

### Integration dengan Database

Simpan hasil ke database:

```php
// Di index-opencv.php, setelah successful OCR:

if ($result['success']) {
    // Save ke database
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=ocr_db', 'user', 'pass');
        
        $stmt = $pdo->prepare("
            INSERT INTO ktp_scans (nik, nama, alamat, scan_date, file_path)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        
        $stmt->execute([
            $result['fields']['nik'],
            $result['fields']['nama'],
            $result['fields']['alamat'],
            $result['preprocessedImage']
        ]);
        
        $result['db_id'] = $pdo->lastInsertId();
    } catch (Exception $e) {
        logError("Database insert failed: " . $e->getMessage());
    }
}
```

### Integration dengan REST API Service

Create wrapper API:

```php
// api.php

header('Content-Type: application/json');

// Validasi API key
$apiKey = $_GET['key'] ?? '';
if ($apiKey !== 'your-secret-key') {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

// Handle file dari URL
if (isset($_POST['image_url'])) {
    $imageUrl = $_POST['image_url'];
    $tempFile = tempnam(sys_get_temp_dir(), 'ocr_');
    
    $ch = curl_init($imageUrl);
    $fp = fopen($tempFile, 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    $_FILES['image'] = [
        'tmp_name' => $tempFile,
        'name' => basename($imageUrl),
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tempFile),
        'type' => mime_content_type($tempFile)
    ];
}

// Process seperti biasa
include 'index-opencv.php';
```

### Batch Processing

Process multiple images:

```bash
#!/bin/bash
# batch_process.sh

for image in *.jpg; do
    echo "Processing $image..."
    python preprocess_image.py "$image" "${image%.jpg}_processed.png"
    
    # Call PHP OCR API
    curl -F "image=@${image%.jpg}_processed.png" \
         http://localhost/ocr%205.x/index-opencv.php?json=1 \
         > "${image%.jpg}_result.json"
done
```

---

## 📊 Logging & Monitoring

### Log File Location

```
logs/ocr_YYYY-MM-DD.log
```

### Log Format

```
[2024-01-15 14:30:45] [INFO] Original file uploaded: ocr_123456.jpg
[2024-01-15 14:30:45] [INFO] Executing: python preprocess_image.py ...
[2024-01-15 14:30:46] [INFO] Preprocessing successful, file size: 456789 bytes
[2024-01-15 14:30:47] [INFO] OCR completed. Result length: 1250, Time: 1.234s
[2024-01-15 14:30:47] [INFO] Extracted fields: {...}
```

### Monitor Logs

**Linux/macOS:**
```bash
tail -f logs/ocr_*.log
```

**Windows PowerShell:**
```powershell
Get-Content logs/ocr_*.log -Wait
```

---

## 🎯 Optimization Tips

### 1. Image Preprocessing Speed

```python
# Disable unnecessary preprocessing if image quality is good
if is_good_quality(image):
    # Skip denoise
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
else:
    # Full preprocessing
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    denoised = cv2.fastNlMeansDenoising(gray, None, h=12)
```

### 2. OCR Speed

```php
// Use specific region of interest (ROI) if possible
$ocr->psm(7);  // Single line mode = faster
$ocr->lang('ind');  // Single language = faster
```

### 3. Memory Usage

```python
# Release resources immediately
image = None
gray = None
denoised = None
```

### 4. Parallel Processing

```bash
#!/bin/bash
# Process 4 images in parallel

images=(image1.jpg image2.jpg image3.jpg image4.jpg)

for img in "${images[@]}"; do
    python preprocess_image.py "$img" "${img%.jpg}_processed.png" &
done

wait
```

---

## 🔒 Security Best Practices

1. **Validate file uploads:**
   ```php
   if (!getimagesize($filepath)) {
       unlink($filepath);
       throw new Exception('Invalid image file');
   }
   ```

2. **Sanitize output:**
   ```php
   htmlspecialchars($field_value, ENT_QUOTES, 'UTF-8')
   ```

3. **Disable directory listing:**
   ```apache
   # .htaccess
   Options -Indexes
   ```

4. **Disable script execution in uploads:**
   ```apache
   <Directory "uploads">
       php_flag engine off
       AddType text/plain .jpg .png .gif
   </Directory>
   ```

5. **Use HTTPS in production:**
   ```php
   if (empty($_SERVER['HTTPS'])) {
       header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
   }
   ```

---

## 🧪 Testing

### Unit Test Example

```php
// test_preprocess.php

function testPreprocessing() {
    $testImage = 'test_ktp.jpg';
    $output = 'test_output.png';
    
    $result = preprocessImageWithOpenCV(
        $testImage, 
        $output, 
        getPythonPath(), 
        'preprocess_image.py'
    );
    
    assert($result['success'] === true, 'Preprocessing failed');
    assert(file_exists($output), 'Output file not created');
    assert(filesize($output) > 0, 'Output file is empty');
    
    echo "✅ All tests passed!";
}

testPreprocessing();
```

---

## 📈 Performance Benchmarks

Typical performance on modern hardware:

| Operation | Time | Notes |
|-----------|------|-------|
| Upload | 0.5s | Network dependent |
| Preprocessing | 0.2-0.5s | Depends on image size |
| OCR | 0.5-2s | Depends on image quality |
| Field Extraction | 0.1s | Fast text parsing |
| **Total** | **1-3s** | End-to-end |

---

## 🔄 Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2024-01-15 | Initial release with OpenCV support |
| 0.9.0 | 2024-01-10 | Beta version |

---

**Last Updated**: 2024-01-15
**Maintained by**: Your Name
**Support**: [Your Contact Info]
