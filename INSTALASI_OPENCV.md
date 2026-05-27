# 📖 Panduan Instalasi OpenCV + Python untuk KTP Scanner

## Prasyarat
- Python 3.7 atau lebih baru
- PHP 7.4 atau lebih baru (sudah ada)
- Tesseract OCR (sudah terinstall)
- Windows/Linux/Mac

---

## **STEP 1: Install Python**

### Windows
1. Download dari https://www.python.org/downloads/
2. Pilih versi **3.10 atau 3.11**
3. **PENTING**: Centang `Add Python to PATH` saat install
4. Klik Install Now

### Linux (Ubuntu/Debian)
```bash
sudo apt-get update
sudo apt-get install python3 python3-pip
```

### macOS
```bash
brew install python3
```

---

## **STEP 2: Verifikasi Instalasi Python**

Buka command prompt/terminal dan jalankan:
```bash
python --version
pip --version
```

Harus muncul versi Python dan pip. Contoh:
```
Python 3.11.0
pip 22.3.1 from C:\Python311\lib\site-packages\pip
```

---

## **STEP 3: Install Python Dependencies untuk OpenCV**

### Windows (Command Prompt)
```bash
pip install opencv-python numpy pillow
```

### Linux/macOS (Terminal)
```bash
pip3 install opencv-python numpy pillow
```

**Expected output:**
```
Successfully installed opencv-python-4.x.x numpy-1.x.x pillow-9.x.x
```

---

## **STEP 4: Verifikasi OpenCV Installation**

Jalankan Python dan test import:

### Windows
```bash
python
>>> import cv2
>>> print(cv2.__version__)
```

### Linux/macOS
```bash
python3
>>> import cv2
>>> print(cv2.__version__)
```

Harus muncul versi OpenCV. Contoh: `4.8.0`

Ketik `exit()` untuk keluar dari Python shell.

---

## **STEP 5: Setup Folder Project**

Buat struktur folder di `d:\Polinema\Skripsi\ocr 5.x\`:

```
ocr 5.x/
├── index.php                 (existing)
├── index-opencv.php         (FILE BARU - akan dibuat)
├── config.php               (existing)
├── composer.json            (existing)
├── preprocess_image.py      (FILE BARU - akan dibuat)
├── INSTALASI_OPENCV.md      (panduan ini)
├── uploads/                 (existing)
├── vendor/                  (existing)
└── logs/                    (untuk debug)
```

---

## **STEP 6: Konfigurasi Python Path di PHP**

### Windows
Cek di mana Python terinstall:
```bash
where python
```

Biasanya:
- `C:\Python311\python.exe`
- `C:\Program Files\Python311\python.exe`

### Linux/macOS
```bash
which python3
```

Biasanya: `/usr/bin/python3`

**Simpan path ini untuk digunakan di PHP nanti**

---

## **STEP 7: Verifikasi Semua sudah Berjalan**

Pastikan file-file berikut berjalan tanpa error:

### Test 1: Python & OpenCV
```bash
python preprocess_image.py
```

### Test 2: PHP dapat memanggil Python
Buka `index-opencv.php` di browser dan upload gambar KTP

---

## **Troubleshooting**

### ❌ "python is not recognized"
**Solusi:** Python belum di PATH
- Windows: Install ulang Python, **CENTANG "Add Python to PATH"**
- Linux: `sudo apt-get install python3`

### ❌ "ModuleNotFoundError: No module named 'cv2'"
**Solusi:** OpenCV belum terinstall
```bash
pip install --upgrade opencv-python
```

### ❌ "PHP tidak bisa memanggil Python"
**Solusi:** Check PHP config
```bash
php -r "echo exec('python --version');"
```

Kalau tidak keluar, cek `php.ini`:
- `disable_functions` - jangan ada `exec`, `shell_exec`, `proc_open`
- `open_basedir` - pastikan allow akses ke Python

### ❌ "Error: Image file not found"
**Solusi:** Check permissions
- Folder `uploads/` harus writable (chmod 755)
- Python harus bisa read file dari `uploads/`

---

## **Selesai! ✅**

Sekarang Anda siap untuk:
1. Membuat file `preprocess_image.py`
2. Membuat file `index-opencv.php`
3. Melakukan scan KTP dengan OpenCV preprocessing!

Lanjut ke **BAGIAN 2: Buat Python Script**
