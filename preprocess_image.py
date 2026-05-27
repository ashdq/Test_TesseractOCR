#!/usr/bin/env python3
"""
OCR Image Preprocessing dengan OpenCV
Menggantikan Imagick untuk preprocessing gambar KTP
Input: gambar KTP
Output: gambar preprocessed yang siap untuk Tesseract OCR
"""

import cv2
import numpy as np
import sys
import json
import os
from pathlib import Path

def preprocess_image_opencv(input_path, output_path):
    """
    Preprocessing gambar menggunakan OpenCV - Mengikuti logic dari Imagick di index.php
    OPTIMIZED untuk menghasilkan grayscale yang balanced, bukan binary
    
    Steps:
    1. Load gambar & convert ke grayscale
    2. Mild denoising (2x, tidak 3x yang terlalu agresif)
    3. Normalize gambar untuk auto-level contrast
    4. Moderate CLAHE untuk contrast enhancement
    5. Manual brightness/contrast yang balanced
    6. Mild sharpening
    7. Skip aggressive histogram equalization
    8. Resize jika perlu
    
    Args:
        input_path: path ke gambar input
        output_path: path untuk menyimpan gambar hasil
        
    Returns:
        dict: {"success": bool, "message": str, "output_size": int}
    """
    try:
        # 1. Load gambar
        image = cv2.imread(input_path)
        if image is None:
            return {
                "success": False,
                "message": f"Gagal membaca file gambar: {input_path}",
                "error_code": "FILE_READ_ERROR"
            }
        
        # Log dimensi gambar original
        height, width = image.shape[:2]
        
        # 2. Convert ke grayscale
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        
        # 3. Mild Denoising (2x, bukan 3x yang terlalu agresif)
        # Gunakan h=7 untuk balance antara noise removal dan detail preservation
        denoised = cv2.fastNlMeansDenoising(gray, None, h=7, templateWindowSize=7, searchWindowSize=21)
        denoised = cv2.fastNlMeansDenoising(denoised, None, h=7, templateWindowSize=7, searchWindowSize=21)
        
        # 4. Normalize image untuk auto-levelkan contrast
        normalized = cv2.normalize(denoised, None, 0, 255, cv2.NORM_MINMAX)
        
        # 5. Moderate CLAHE untuk contrast enhancement (tidak terlalu agresif)
        # Gunakan clipLimit 2.0 (bukan 3.0) untuk hasil yang lebih balanced
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        contrast = clahe.apply(normalized)
        
        # 6. Manual brightness/contrast adjustment (lebih moderat)
        # Gunakan alpha=1.2, beta=15 (bukan 1.3, 25) untuk hasil yang balanced
        contrast_adjusted = cv2.convertScaleAbs(contrast, alpha=1.2, beta=15)
        
        # 7. Mild sharpening (1x, bukan 2x yang terlalu kuat)
        # Using unsharp mask technique dengan parameter yang lebih mild
        blurred = cv2.GaussianBlur(contrast_adjusted, (0, 0), 1.2)
        sharpened = cv2.addWeighted(contrast_adjusted, 1.3, blurred, -0.3, 0)
        
        # 8. Bilateral filter untuk preserve edges dan maintain grayscale detail
        # Ini membantu preserve detail teks sambil tetap smooth
        bilateral = cv2.bilateralFilter(sharpened, d=7, sigmaColor=50, sigmaSpace=50)
        
        # 9. SKIP histogram equalization - ini yang membuat image terlalu ekstrem
        # Gunakan weighted combination dari bilateral dan original untuk smooth result
        final = cv2.addWeighted(bilateral, 0.9, sharpened, 0.1, 0)
        
        # 10. Resize jika terlalu kecil (min 300px width)
        if width < 300:
            scale = 300 / width
            new_width = 300
            new_height = int(height * scale)
            final = cv2.resize(final, (new_width, new_height), interpolation=cv2.INTER_CUBIC)
        
        # 11. Simpan hasil sebagai PNG dengan kualitas tinggi
        success = cv2.imwrite(output_path, final, [cv2.IMWRITE_PNG_COMPRESSION, 0])
        
        if not success:
            return {
                "success": False,
                "message": f"Gagal menyimpan file output: {output_path}",
                "error_code": "FILE_WRITE_ERROR"
            }
        
        # Verifikasi file berhasil dibuat
        if not os.path.exists(output_path):
            return {
                "success": False,
                "message": f"File output tidak tercipta: {output_path}",
                "error_code": "FILE_NOT_CREATED"
            }
        
        output_size = os.path.getsize(output_path)
        
        return {
            "success": True,
            "message": "Preprocessing berhasil",
            "output_path": output_path,
            "output_size": output_size,
            "original_dimensions": f"{width}x{height}",
            "error_code": None
        }
        
    except Exception as e:
        return {
            "success": False,
            "message": f"Error saat preprocessing: {str(e)}",
            "error_code": "PROCESSING_ERROR",
            "exception": str(e)
        }


def preprocess_image_nik_crop(input_path, output_path):
    """
    Preprocessing khusus untuk NIK crop area - Optimized untuk clarity digit
    Menggunakan preprocessing yang lebih targeted untuk NIK extraction
    
    Strategi:
    - Crop area NIK secara presisi (baris NIK ada di ~10-25% tinggi KTP)
    - Hanya ambil sisi kiri (65% lebar) untuk hindari area foto
    - Upscale 3x agar Tesseract lebih akurat membaca digit
    - Otsu thresholding untuk binary image yang bersih
    - Morphological cleanup untuk memperjelas stroke digit
    """
    try:
        image = cv2.imread(input_path)
        if image is None:
            return {"success": False, "message": "Gagal membaca file gambar"}
        
        height, width = image.shape[:2]
        
        # Crop area NIK secara presisi
        # Pada KTP, baris NIK berada di ~10-25% dari tinggi total
        # (setelah header PROVINSI/KOTA, sebelum Nama)
        # Hanya ambil 65% lebar kiri untuk menghindari area foto
        y_start = int(height * 0.10)
        y_end = int(height * 0.25)
        x_end = int(width * 0.65)
        cropped = image[y_start:y_end, 0:x_end]
        
        crop_h, crop_w = cropped.shape[:2]
        
        # Upscale 3x untuk meningkatkan akurasi OCR digit
        scale_factor = 3
        upscaled = cv2.resize(cropped, (crop_w * scale_factor, crop_h * scale_factor), 
                              interpolation=cv2.INTER_CUBIC)
        
        # Convert ke grayscale
        gray = cv2.cvtColor(upscaled, cv2.COLOR_BGR2GRAY)
        
        # Mild denoising - jangan terlalu agresif agar digit tetap tajam
        denoised = cv2.fastNlMeansDenoising(gray, None, h=5, templateWindowSize=7, searchWindowSize=21)
        
        # Normalize contrast
        normalized = cv2.normalize(denoised, None, 0, 255, cv2.NORM_MINMAX)
        
        # CLAHE untuk contrast enhancement lokal
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        contrast = clahe.apply(normalized)
        
        # Otsu thresholding untuk binary image yang bersih
        # Ini menghasilkan teks hitam di background putih yang optimal untuk Tesseract
        _, binary = cv2.threshold(contrast, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        
        # Morphological operations untuk membersihkan noise kecil
        # dan memperjelas stroke digit
        kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (2, 2))
        # Opening: hilangkan noise kecil
        cleaned = cv2.morphologyEx(binary, cv2.MORPH_OPEN, kernel, iterations=1)
        # Closing: tutup gap kecil pada stroke digit
        cleaned = cv2.morphologyEx(cleaned, cv2.MORPH_CLOSE, kernel, iterations=1)
        
        # Tambahkan border putih di sekeliling agar Tesseract lebih mudah parse
        border_size = 20
        final_img = cv2.copyMakeBorder(cleaned, border_size, border_size, border_size, border_size,
                                        cv2.BORDER_CONSTANT, value=255)
        
        # Simpan sebagai PNG
        success = cv2.imwrite(output_path, final_img, [cv2.IMWRITE_PNG_COMPRESSION, 0])
        
        if not success:
            return {"success": False, "message": "Gagal menyimpan file"}
        
        return {
            "success": True, 
            "message": "NIK crop preprocessing berhasil",
            "crop_area": f"y:{y_start}-{y_end}, x:0-{x_end}",
            "upscale_factor": scale_factor
        }
        
    except Exception as e:
        return {"success": False, "message": f"Error: {str(e)}"}


def main():
    """
    Main entry point untuk script
    Usage: python preprocess_image.py <input> <output> [mode]
    mode: 'normal' (default) atau 'nik' untuk NIK crop
    """
    
    # Validasi arguments
    if len(sys.argv) < 3:
        result = {
            "success": False,
            "message": "Usage: python preprocess_image.py <input_path> <output_path> [mode]",
            "error_code": "INVALID_ARGS"
        }
        print(json.dumps(result, ensure_ascii=False))
        sys.exit(1)
    
    input_path = sys.argv[1]
    output_path = sys.argv[2]
    mode = sys.argv[3] if len(sys.argv) > 3 else "normal"
    
    # Validasi input file exists
    if not os.path.exists(input_path):
        result = {
            "success": False,
            "message": f"File input tidak ditemukan: {input_path}",
            "error_code": "FILE_NOT_FOUND"
        }
        print(json.dumps(result, ensure_ascii=False))
        sys.exit(1)
    
    # Buat parent directory jika belum ada
    output_dir = os.path.dirname(output_path)
    if output_dir and not os.path.exists(output_dir):
        try:
            os.makedirs(output_dir, exist_ok=True)
        except Exception as e:
            result = {
                "success": False,
                "message": f"Gagal membuat directory output: {str(e)}",
                "error_code": "DIR_CREATE_ERROR"
            }
            print(json.dumps(result, ensure_ascii=False))
            sys.exit(1)
    
    # Jalankan preprocessing sesuai mode
    if mode == "nik":
        result = preprocess_image_nik_crop(input_path, output_path)
    else:
        result = preprocess_image_opencv(input_path, output_path)
    
    # Output hasil sebagai JSON untuk dibaca PHP
    print(json.dumps(result, ensure_ascii=False))
    
    # Exit code 0 jika success, 1 jika error
    sys.exit(0 if result.get("success", False) else 1)


if __name__ == "__main__":
    main()
