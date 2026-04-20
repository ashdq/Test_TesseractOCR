# OCR Image to Text Converter

Aplikasi web PHP sederhana untuk mengkonversi gambar menjadi teks menggunakan **Tesseract OCR v5** dengan library `thiagoalessio/tesseract_ocr`.

## ✨ Fitur Utama

✅ **Upload Gambar** - Drag & drop atau klik untuk upload  
✅ **Image Preprocessing** - Otomatis meningkatkan kualitas gambar sebelum OCR:
   - Convert ke Grayscale
   - Contrast & Brightness Enhancement
   - Image Sharpening
   - Denoise
   - Auto-resize untuk gambar kecil
   - Density optimization untuk OCR

✅ **Dual Language Support** - Bahasa Indonesia & Inggris (bisa dikustomisasi)  
✅ **Responsive Design** - Bekerja di desktop & mobile  
✅ **Preview Gambar** - Lihat gambar original dan hasil preprocessing  
✅ **Copy to Clipboard** - Copy hasil teks dengan mudah  
✅ **Error Handling** - Validasi file & error messages yang informatif  

## 📋 Requirements

- **PHP 7.4+**
- **Tesseract OCR v5** (sudah terinstall di komputer)
- **ImageMagick atau GD Library** (untuk preprocessing)
  - ImageMagick lebih bagus (via composer: `imagick` extension)
  - GD Library sebagai fallback
- **Composer Dependencies**
  - `thiagoalessio/tesseract_ocr` (sudah terinstall)

## 🚀 Cara Menggunakan

### 1. Verifikasi Dependencies
```bash
cd "d:\Polinema\Skripsi\ocr 5.x"

# Pastikan dependencies sudah terinstall
composer install

# Jika ingin menambah Imagick untuk preprocessing yang lebih baik (optional)
composer require intervention/image
```

### 2. Jalankan Aplikasi
```bash
# Menggunakan PHP built-in server
php -S localhost:8000

# Atau gunakan Apache/Nginx yang sudah dikonfigurasi
# Pastikan document root menunjuk ke folder "ocr 5.x"
```

### 3. Akses di Browser
```
http://localhost:8000
```

### 4. Gunakan Aplikasi
1. Klik area upload atau drag & drop gambar
2. Pilih gambar yang ingin dikonversi (JPG, PNG, GIF, BMP, TIFF)
3. Klik tombol "Konversi ke Teks"
4. Tunggu proses preprocessing dan OCR selesai
5. Lihat hasil teks yang sudah dikonversi
6. Copy hasil teks ke clipboard jika diperlukan

## 📁 Struktur File

```
ocr 5.x/
├── index.php              # File utama aplikasi
├── uploads/               # Folder untuk menyimpan gambar (auto-created)
├── vendor/                # Dependency dari composer
├── composer.json          # Konfigurasi composer
├── composer.lock          # Lock file dependencies
└── README.md              # File ini
```

## ⚙️ Konfigurasi

Edit bagian `Konfigurasi` di file `index.php` jika perlu:

```php
// Ubah ukuran maksimal file (default 5MB)
$maxFileSize = 5 * 1024 * 1024;

// Ubah tipe file yang diizinkan
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'];

// Ubah bahasa OCR di dalam function OCR
// 'ind' untuk Indonesia
// 'eng' untuk English
// 'ind+eng' untuk dual language
$ocr->lang('ind+eng');
```

## 🎨 Image Preprocessing Details

Aplikasi menggunakan preprocessing multi-layer yang agresif untuk meningkatkan akurasi OCR:

### Preprocessing Pipeline:

**Menggunakan GD Library (Fallback):**
1. **Grayscale Conversion** - Convert gambar ke hitam-putih
2. **Initial Denoise** - Kurangi noise dengan smoothing
3. **Contrast Enhancement** - Tingkatkan kontras (35x, agresif)
4. **Brightness Adjustment** - Sesuaikan kecerahan (+15)
5. **Multiple Sharpening** - Tajamkan tepi teks 2x untuk hasil lebih tajam
6. **Additional Denoise** - Cleanup noise sekali lagi
7. **Auto-Resize** - Perbesar gambar kecil (min 300px)

**Menggunakan ImageMagick (Jika tersedia):**
1. **Grayscale Conversion**
2. **Triple Denoise** - `enhanceImage()` 3x untuk hasil lebih bersih
3. **Histogram Normalization** - Auto-levelkan contrast
4. **Aggressive Contrast Boost** - Double contrast (2.0x) untuk clarity
5. **Dual Sharpening** - Apply sharpening 2x dengan berbagai parameters
6. **Histogram Equalization** - Improve tonal distribution
7. **Auto-Resize** untuk gambar kecil
8. **DPI Optimization** - Set 300 DPI untuk kualitas OCR

### OCR Accuracy Improvements:

Selain preprocessing, aplikasi juga menggunakan **smart post-processing**:

✅ **Character Correction**
- Fix OCR misreads: `:` (colon) sering dibaca sebagai `=` → auto-fix
- Fix `tgl!` → `tgl`, `tg!` → `tgl`
- Fix `RT[!|]RW` → `RT/RW` untuk alamat

✅ **Context-Aware Fixes**
- NIK (Nomor Induk) parsing: Bersihkan OCR errors (O→0, S→5, dll)
- Tanggal/Date standardization: Format konsisten
- Punctuation & spacing cleanup

✅ **Tesseract Optimization**
- **PSM Mode 6** - Fokus pada uniform text blocks (lebih akurat untuk dokumen ID)
- **OEM Mode 3** - Kombinasi Legacy + LSTM engine (paling akurat)
- **Support dual language** - Indonesia + English

## 🎨 Quality Tips

Untuk hasil OCR paling akurat:
1. **Gambar harus jelas** - Minimal 300 DPI / high resolution
2. **Pencahayaan rata** - Hindari shadow atau background yang gelap
3. **Sudut right/lurus** - Jangan rotated atau miring
4. **Crop tepat** - Fokus ke area teks saja, jauhkan border yang berlebihan
5. **Warna kontras** - Text hitam dengan background putih paling ideal

## 🛠️ Troubleshooting

### "Tesseract tidak ditemukan"
```bash
# Windows: Pastikan Tesseract terinstall dan di PATH
# Cek path di terminal:
tesseract --version

# Jika error, tambahkan path ke dalam PHP:
# Di index.php, tambahkan sebelum $ocr = new TesseractOCR():
# putenv('PATH=' . 'C:\Program Files\Tesseract-OCR' . ';' . getenv('PATH'));
```

### "Folder uploads tidak writable"
```bash
# Windows: Jalankan cmd as Administrator
icacls "d:\Polinema\Skripsi\ocr 5.x" /grant:r Everyone:F

# Linux/Mac:
chmod -R 755 uploads
```

### "Imagick extension tidak ditemukan"
```bash
# PHP akan otomatis fallback ke GD library
# GD sudah built-in di kebanyakan PHP installation
# Preprocessing tetap bekerja hanya sedikit berbeda kualitasnya
```

### Hasil OCR masih kurang akurat
1. Pastikan gambar cukup jelas dan terang
2. Gunakan gambar dengan resolusi tinggi (min 300 DPI)
3. Coba crop gambar untuk fokus pada area teks saja
4. Adjust bahasa OCR sesuai konten gambar

## 📝 Testing

Coba dengan gambar yang berbeda:

✅ **Good Examples:**
- Dokumen scan (clear, terang)
- Screenshot teks (resolution tinggi)
- Printed text photos (sudut lurus, pencahayaan baik)

❌ **Bad Examples:**
- Gambar blurry/kabur
- Tangan-written text
- Angle/rotated text
- Low resolution/pixelated

## 📧 Support

Jika mengalami masalah:
1. Cek console browser (F12 > Console) untuk JS errors
2. Cek PHP error log
3. Cek permissions folder uploads
4. Pastikan Tesseract OCR v5 terinstall dengan benar

## 📄 Lisensi

Project ini menggunakan library `thiagoalessio/tesseract_ocr` dengan lisensi MIT.

---

**Created:** 2026  
**Version:** 1.0  
**PHP Version:** 7.4+
