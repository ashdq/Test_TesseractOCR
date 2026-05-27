# 🚀 QUICK START GUIDE - OCR KTP Scanner dengan OpenCV

**Status**: Ready to use ✅

---

## 📋 Checklist Persiapan

- [ ] Python 3.7+ installed
- [ ] OpenCV library installed (`pip install opencv-python`)
- [ ] NumPy installed (`pip install numpy`)
- [ ] Tesseract OCR installed (sudah ada)
- [ ] PHP 7.4+ dengan exec/shell_exec enabled
- [ ] File sudah dibuat:
  - [x] `preprocess_image.py`
  - [x] `index-opencv.php`

---

## 🔧 INSTALASI CEPAT (5 menit)

### 1. Install Python Dependencies

```bash
# Windows Command Prompt
pip install opencv-python numpy pillow

# Linux/macOS Terminal
pip3 install opencv-python numpy pillow
```

**Verifikasi:**
```bash
python -c "import cv2; print('OpenCV', cv2.__version__)"
```

### 2. Cek Path Python

**Windows Command Prompt:**
```bash
where python
```

**Linux/macOS Terminal:**
```bash
which python3
```

Simpan hasilnya. Contoh:
- Windows: `C:\Python311\python.exe` atau `python`
- Linux: `/usr/bin/python3` atau `python3`

### 3. Test Python Script

Buka command prompt/terminal di folder project:

```bash
# Windows
python preprocess_image.py

# Linux/macOS
python3 preprocess_image.py
```

Harus muncul usage message, bukan error.

### 4. Buka di Browser

```
http://localhost/ocr%205.x/index-opencv.php
```

atau

```
http://your-server/path/to/ocr%205.x/index-opencv.php
```

### 5. Upload Gambar KTP dan Test!

---

## 📊 Arsitektur Sistem

```
┌─────────────────────────────────────────────────────────────┐
│  1. Browser Upload Gambar KTP ke PHP                        │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│  2. PHP Save File & Call Python Script                      │
│     exec("python preprocess_image.py input.jpg output.png") │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│  3. Python (OpenCV) Preprocessing:                          │
│     • Load image                                            │
│     • Convert to grayscale                                  │
│     • Denoise (fastNlMeansDenoising)                        │
│     • Enhance contrast (CLAHE)                              │
│     • Increase brightness                                   │
│     • Sharpen (Unsharp Mask)                                │
│     • Bilateral filter (edge preserve)                      │
│     • Adaptive thresholding                                 │
│     • Morphological operations                              │
│     • Resize if needed                                      │
│     • Save as PNG                                           │
│     • Return JSON: {success: true, ...}                     │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│  4. PHP Get Preprocessed Image & Run Tesseract OCR          │
│     • Check if output file exists                           │
│     • Run Tesseract on preprocessed image                   │
│     • Extract text with lang=ind+eng, psm=6                │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│  5. PHP Extract & Structure KTP Fields                      │
│     • NIK (16 digit)                                        │
│     • Nama, Alamat, RT/RW                                   │
│     • Tempat/Tgl Lahir                                      │
│     • Jenis Kelamin, Gol. Darah                             │
│     • Agama, Status Perkawinan, dll                         │
└────────────────┬────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│  6. Browser Display Results:                                │
│     • Original image & Preprocessed side-by-side            │
│     • Raw extracted text                                    │
│     • Structured KTP fields grid                            │
│     • Copy to clipboard button                              │
└─────────────────────────────────────────────────────────────┘
```

---

## 📁 File Structure

```
ocr 5.x/
├── index.php                      (original - Imagick version)
├── index-opencv.php               (NEW - OpenCV version) ⭐
├── preprocess_image.py            (NEW - Python preprocessing) ⭐
├── config.php                     (existing)
├── composer.json                  (existing)
├── INSTALASI_OPENCV.md            (installation guide)
├── QUICK_START.md                 (this file)
├── uploads/                       (untuk stored images)
│   ├── ocr_xxx.jpg               (original uploads)
│   └── preprocessed_xxx.png      (hasil preprocessing OpenCV)
├── logs/                          (untuk debug logs)
│   └── ocr_2024-01-15.log        (daily log file)
└── vendor/                        (existing - composer packages)
```

---

## 🎯 Perbandingan: Imagick vs OpenCV

| Aspek | Imagick (index.php) | OpenCV (index-opencv.php) |
|-------|-------------------|------------------------|
| **Library** | PHP Extension | Python Script |
| **Install** | `pecl install imagick` | `pip install opencv-python` |
| **Kerumitan** | Medium | Low |
| **Performance** | Medium | High ⭐ |
| **Memory Usage** | High | Low ⭐ |
| **OCR Quality** | Good | Excellent ⭐ |
| **Preprocessing** | Basic | Advanced ⭐ |
| **Cost** | Free | Free |
| **Cross-platform** | Good | Excellent ⭐ |

**Keuntungan OpenCV:**
- ✅ Lebih mudah install (hanya pip)
- ✅ Lebih powerful preprocessing
- ✅ Lebih ringan & cepat
- ✅ Lebih akurat untuk OCR KTP
- ✅ Lebih banyak algorithm tersedia

---

## 🔍 Testing & Debugging

### Test 1: Verifikasi Python Installed

```bash
python --version
```

Expected: `Python 3.x.x`

### Test 2: Verifikasi OpenCV

```bash
python -c "import cv2; print('OK -', cv2.__version__)"
```

Expected: `OK - 4.8.x`

### Test 3: Test Python Script Directly

```bash
python preprocess_image.py uploads/ocr_xxx.jpg uploads/test_output.png
```

Expected:
```json
{
  "success": true,
  "message": "Preprocessing berhasil",
  "output_path": "uploads/test_output.png",
  "output_size": 123456,
  "original_dimensions": "1200x800"
}
```

### Test 4: Check PHP Config

```bash
php -r "echo exec('python --version');"
```

Expected: `Python 3.x.x`

If no output, check `php.ini`:
- Verify `disable_functions` tidak include `exec`, `shell_exec`
- Verify `open_basedir` allow akses ke Python

### Test 5: Full Test di Browser

1. Buka `http://localhost/ocr%205.x/index-opencv.php`
2. Upload gambar KTP
3. Klik "Scan KTP"
4. Check hasil & debug info

---

## 🐛 Troubleshooting

### ❌ "python is not recognized"

**Penyebab**: Python tidak di PATH

**Solusi**:
1. Windows: Install ulang Python, centang "Add Python to PATH"
2. Linux/macOS: Install via package manager
3. Atau edit `index-opencv.php` baris 19 dan hardcode path:
   ```php
   $pythonPath = 'C:\\Python311\\python.exe';  // Windows
   $pythonPath = '/usr/bin/python3';           // Linux
   ```

### ❌ "ModuleNotFoundError: No module named 'cv2'"

**Penyebab**: OpenCV belum terinstall

**Solusi**:
```bash
pip install --upgrade opencv-python
```

### ❌ "INVALID JSON response dari Python"

**Penyebab**: Script preprocessing error

**Solusi**:
1. Check `logs/ocr_YYYY-MM-DD.log` untuk error message
2. Test script manually:
   ```bash
   python preprocess_image.py test.jpg output.png
   ```
3. Check permissions folder `uploads/`

### ❌ "File preprocessed tidak ditemukan"

**Penyebab**: Python tidak bisa write ke folder

**Solusi**:
1. Windows: Run PHP as Administrator
2. Linux/macOS: Check folder permissions
   ```bash
   chmod 755 uploads/
   chmod 755 logs/
   ```

### ❌ "Tesseract error"

**Penyebab**: Tesseract tidak terinstall atau tidak di PATH

**Solusi**: Sama seperti instalasi yang sudah ada untuk Imagick version

---

## 📈 Performance Tips

### Untuk Akurasi Lebih Baik:

1. **Gunakan gambar KTP berkualitas tinggi** (min 1200x800 pixels)
2. **Pencahayaan merata** - hindari shadow atau glare
3. **Posisi straight** - KTP tidak miring/tilt

### Untuk Processing Lebih Cepat:

1. Resize image jika > 3000x2000 pixels
2. Convert ke PNG daripada TIFF
3. Reduce file size < 2MB

---

## 🔐 Security Notes

1. **Validate all file uploads** ✅ (sudah di-implement)
2. **Sanitize file paths** ✅ (sudah di-implement)
3. **Limit file size** ✅ (5MB max, sudah di-implement)
4. **Enable logs** ✅ (automatic per-day logging)
5. **Disable directory listing** - Tambah `.htaccess`:
   ```apache
   <FilesMatch "\.php$">
       Deny from all
   </FilesMatch>
   ```

---

## 📞 Support & Questions

Untuk error atau pertanyaan:

1. **Check logs**: `logs/ocr_YYYY-MM-DD.log`
2. **Test script**: `python preprocess_image.py <input> <output>`
3. **Browser console**: F12 → Console tab untuk JS errors
4. **Network tab**: F12 → Network untuk request/response JSON

---

## ✅ Selesai!

Anda sekarang siap menggunakan **OCR KTP Scanner dengan OpenCV**!

**Mulai scanning KTP sekarang di:**
```
http://your-server/ocr%205.x/index-opencv.php
```

---

**Last Updated**: 2024
**Version**: 1.0.0
**Engine**: OpenCV 4.8+ | Tesseract 5.x | PHP 7.4+
