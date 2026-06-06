import os
import json
from PIL import Image

# Konfigurasi
IMAGE_FOLDER = 'uploads' # Ganti dengan nama folder gambar KTP Anda
OUTPUT_FILE = 'dataset.json'

# Daftar field yang ingin Anda jadikan kunci jawaban (hanya NIK dan Nama)
FIELDS_TO_EXTRACT = [
    "nik",
    "nama"
]

def create_dataset():
    print("=== Sistem Pembuat Ground Truth (dataset.json) ===\n")
    
    # Load data yang sudah ada (agar tidak mulai dari nol jika program terhenti)
    if os.path.exists(OUTPUT_FILE):
        with open(OUTPUT_FILE, 'r') as f:
            try:
                dataset = json.load(f)
            except json.JSONDecodeError:
                dataset = []
    else:
        dataset = []

    # Dapatkan daftar gambar yang sudah diproses agar bisa di-skip
    processed_images = [item["image_path"] for item in dataset]

    # Pastikan folder gambar ada
    if not os.path.exists(IMAGE_FOLDER):
        print(f"Folder '{IMAGE_FOLDER}' tidak ditemukan. Buat foldernya dan masukkan gambar KTP.")
        return

    # Ambil semua file gambar
    valid_extensions = ('.jpg', '.jpeg', '.png')
    images = [f for f in os.listdir(IMAGE_FOLDER) if f.lower().endswith(valid_extensions)]

    if not images:
        print(f"Tidak ada gambar KTP (jpg/jpeg/png) di dalam folder '{IMAGE_FOLDER}'.")
        return

    print(f"Ditemukan {len(images)} gambar KTP. Memulai proses...\n")

    for img_name in images:
        # Format path: misal "uploads/ktp1.jpg"
        img_path = f"{IMAGE_FOLDER}/{img_name}" 
        
        # Skip jika gambar ini sudah ada di dataset.json
        if img_path in processed_images:
            continue

        print(f"--- Memproses: {img_name} ---")
        try:
            # Buka gambar di photo viewer bawaan OS agar pengguna bisa membaca datanya
            img = Image.open(img_path)
            img.show()
        except Exception as e:
            print(f"Gagal membuka gambar {img_name}: {e}")
            continue

        print("Ketik data sesuai gambar. (Tekan Enter/biarkan kosong jika teks tidak terbaca):")
        
        ground_truth = {}
        for field in FIELDS_TO_EXTRACT:
            user_input = input(f"Masukkan {field.upper()}: ").strip()
            if user_input:  # Hanya masukkan ke JSON jika tidak dikosongkan
                ground_truth[field] = user_input.upper() # Jadikan huruf besar semua sesuai standar KTP

        # Masukkan ke array dataset
        dataset.append({
            "image_path": img_path,
            "ground_truth": ground_truth
        })

        # Langsung save ke file setiap selesai 1 gambar (mencegah data hilang jika tiba-tiba error)
        with open(OUTPUT_FILE, 'w') as f:
            json.dump(dataset, f, indent=4)
        
        print(f"✓ Data untuk {img_name} berhasil disimpan!\n")

        # Beri opsi untuk jeda istirahat
        cont = input("Lanjut ke gambar berikutnya? (y/n): ").strip().lower()
        if cont == 'n':
            print("\nProses dihentikan sementara. Anda bisa melanjutkannya nanti.")
            break

    print(f"\nSelesai! File kunci jawaban telah diperbarui di: {OUTPUT_FILE}")

if __name__ == "__main__":
    create_dataset()