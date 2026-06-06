<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluasi Akurasi OCR KTP & KK</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 40px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .spinner {
            width: 50px;
            height: 50px;
            margin: 30px auto;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #17a2b8;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .back-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .back-btn:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">⬅ Kembali ke Beranda</a>
        
        <div id="loading" style="text-align:center; margin: 80px 0; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
            <h2 style="color: #333;">Memproses Semua Gambar KTP & KK...</h2>
            <p style="color: #666; font-size: 16px;">Mohon tunggu, proses ini memakan waktu yang cukup lama karena mengevaluasi seluruh dataset OCR di folder uploads.</p>
            <div class="spinner"></div>
        </div>

        <div id="resultSection" style="display:none;"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const loading = document.getElementById('loading');
            const resultSection = document.getElementById('resultSection');

            try {
                // Fetch KTP and KK directly using index.php backend
                const [resKtp, resKk] = await Promise.all([
                    fetch('index.php?run_test=1'),
                    fetch('index.php?run_test_kk=1')
                ]);

                const dataKtp = await resKtp.json();
                const dataKk = await resKk.json();

                loading.style.display = 'none';
                resultSection.style.display = 'block';

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
                        htmlReport += `<div style="padding: 15px; background: #f8d7da; border-left: 4px solid #721c24; margin-bottom: 20px; border-radius: 5px;"><strong>Error:</strong> ${msg}</div>`;
                    });
                }

                if (combinedData.success) {
                    htmlReport += generateReportHTML(combinedData);
                }

                resultSection.innerHTML = htmlReport;

            } catch (error) {
                loading.style.display = 'none';
                resultSection.style.display = 'block';
                resultSection.innerHTML = `<div style="padding: 15px; background: #f8d7da; border-left: 4px solid #721c24; margin-bottom: 20px; border-radius: 5px;"><strong>Terjadi kesalahan sistem:</strong> ${error.message}</div>`;
            }
        });

        function generateReportHTML(data) {
            let html = `
                <div style="background: white; padding: 35px; border-radius: 10px; border-left: 6px solid #17a2b8; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                    <h2 style="margin-top: 0; margin-bottom: 25px; color: #333; display: flex; align-items: center; gap: 10px;">
                        📊 Hasil Pengujian Akurasi (CER) Seluruh Dataset
                    </h2>
                    
                    <h4 style="margin: 25px 0 10px; color: #222; font-size: 17px;">Rata-rata Akurasi per Kolom</h4>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 35px; font-size: 15px;">
                        <tr style="background: #17a2b8; color: white;">
                            <th style="padding: 12px; border: 1px solid #118193; text-align: left;">Kolom (Field)</th>
                            <th style="padding: 12px; border: 1px solid #118193; text-align: center;">Akurasi Rata-rata</th>
                        </tr>`;
            
            for (const [field, stat] of Object.entries(data.stats)) {
                let avgAkurasi = 100 - (stat.sum_cer / stat.count);
                let textColor = avgAkurasi >= 90 ? '#155724' : (avgAkurasi >= 70 ? '#856404' : '#721c24');
                html += `
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #333;">${field.toUpperCase()}</td>
                        <td style="padding: 12px; border: 1px solid #ddd; text-align: center; color: ${textColor}; font-weight: bold;">${avgAkurasi.toFixed(2)}%</td>
                    </tr>`;
            }
            
            html += `</table><h4 style="margin: 30px 0 15px; color: #222; font-size: 17px;">Detail per Gambar</h4>`;

            data.results.forEach(res => {
                if (res.error) {
                    html += `<div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; margin-bottom: 15px; border-radius: 5px;">${res.image} - Error: ${res.error}</div>`;
                    return;
                }

                html += `
                    <div style="margin-bottom: 20px; border: 1px solid #eee; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.02);">
                        <div style="background: #f8f9fa; padding: 12px 15px; font-weight: bold; border-bottom: 1px solid #eee; font-size: 15px; color: #333;">📄 ${res.image}</div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                            <tr style="background: #fdfdfd;">
                                <th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Field</th>
                                <th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Ground Truth (Jawaban)</th>
                                <th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Hasil OCR</th>
                                <th style="padding: 10px; border: 1px solid #ddd; text-align: center; width: 100px;">Akurasi</th>
                            </tr>`;
                            
                for (const [field, val] of Object.entries(res.fields)) {
                    let textColor = val.akurasi >= 90 ? '#155724' : (val.akurasi >= 70 ? '#856404' : '#721c24');
                    html += `
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd; font-weight: 500;">${field}</td>
                            <td style="padding: 10px; border: 1px solid #ddd; color: #444;">${val.expected}</td>
                            <td style="padding: 10px; border: 1px solid #ddd; color: #444;">${val.actual}</td>
                            <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: ${textColor}; font-weight: bold;">${val.akurasi}%</td>
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
