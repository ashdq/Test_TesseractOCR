# OCR-A Training Script for Tesseract
<#
.SYNOPSIS
    Train custom Tesseract model for OCR-A Extended font (digit recognition).
    Optimized for reading NIK numbers on Indonesian KTP.

.DESCRIPTION
    This script uses Tesseract's built-in training tools to create a custom
    traineddata file specifically for the OCR-A Extended font used in KTP NIK fields.
    
    Prerequisites:
    - Tesseract v5.x installed at C:\Program Files\Tesseract-OCR
    - OCR A Extended font installed (OCRAEXT.TTF in C:\Windows\Fonts)

.NOTES
    Run this script ONCE to generate the trained model.
    After completion, ocra.traineddata will be available for use.
#>

$ErrorActionPreference = "Stop"

# === Configuration ===
$TesseractDir = "C:\Program Files\Tesseract-OCR"
$TessdataDir = "$TesseractDir\tessdata"
$Text2Image = "$TesseractDir\text2image.exe"
$LstmTraining = "$TesseractDir\lstmtraining.exe"
$CombineTessdata = "$TesseractDir\combine_tessdata.exe"

$FontName = "OCR A Extended"
$FontsDir = "C:\Windows\Fonts"
$ModelName = "ocra"
$BaseModel = "eng"
$MaxIterations = 400

# Use a temp directory WITHOUT spaces to avoid quoting issues with text2image
$WorkDir = "C:\temp\ocra_training"

Write-Host "=======================================" -ForegroundColor Cyan
Write-Host " Tesseract OCR-A Training Script" -ForegroundColor Cyan
Write-Host "=======================================" -ForegroundColor Cyan
Write-Host ""

# === Verify prerequisites ===
Write-Host "[1/7] Verifying prerequisites..." -ForegroundColor Yellow

if (-not (Test-Path $Text2Image)) {
    Write-Error "text2image.exe not found at $Text2Image"
    exit 1
}
if (-not (Test-Path $LstmTraining)) {
    Write-Error "lstmtraining.exe not found at $LstmTraining"
    exit 1
}
if (-not (Test-Path "$TessdataDir\$BaseModel.traineddata")) {
    Write-Error "Base model $BaseModel.traineddata not found in $TessdataDir"
    exit 1
}
if (-not (Test-Path "C:\Windows\Fonts\OCRAEXT.TTF")) {
    Write-Error "OCR A Extended font not found at C:\Windows\Fonts\OCRAEXT.TTF"
    exit 1
}

Write-Host "  [OK] All prerequisites verified" -ForegroundColor Green

# === Create working directory ===
Write-Host "[2/7] Setting up working directory..." -ForegroundColor Yellow

if (Test-Path $WorkDir) {
    Remove-Item $WorkDir -Recurse -Force
}
New-Item -ItemType Directory -Path $WorkDir -Force | Out-Null
New-Item -ItemType Directory -Path "$WorkDir\gt" -Force | Out-Null

Write-Host "  [OK] Working directory: $WorkDir" -ForegroundColor Green

# === Generate training text ===
Write-Host "[3/7] Generating training text..." -ForegroundColor Yellow

$trainingLines = @(
    "0123456789"
    "9876543210"
    "0000000000000000"
    "1111111111111111"
    "2222222222222222"
    "3333333333333333"
    "4444444444444444"
    "5555555555555555"
    "6666666666666666"
    "7777777777777777"
    "8888888888888888"
    "9999999999999999"
    "1234567890123456"
    "6543210987654321"
    "3516041234567890"
    "3517082345678901"
    "3201011201970001"
    "3275014403920007"
    "3578260101850001"
    "1271065207850010"
    "6471032101990001"
    "3171014502880001"
    "6205031512000001"
    "3516082907930002"
    "3515076803920004"
    "7371012508890003"
    "1802040509000001"
    "3273022212900005"
    "5201032006950001"
    "3402021507870003"
    "1207264109920002"
    "3328020411850004"
    "0 1 2 3 4 5 6 7 8 9"
    "00 11 22 33 44 55 66 77 88 99"
    "01 10 02 20 03 30 04 40 05 50"
    "06 60 07 70 08 80 09 90"
    "12 21 13 31 14 41 15 51"
    "16 61 17 71 18 81 19 91"
    "23 32 24 42 25 52 26 62"
    "27 72 28 82 29 92 34 43"
    "35 53 36 63 37 73 38 83"
    "39 93 45 54 46 64 47 74"
    "48 84 49 94 56 65 57 75"
    "58 85 59 95 67 76 68 86"
    "69 96 78 87 79 97 89 98"
)

$trainingText = $trainingLines -join "`n"
$trainingTextPath = "$WorkDir\training_text.txt"
[System.IO.File]::WriteAllText($trainingTextPath, $trainingText, [System.Text.Encoding]::UTF8)

Write-Host "  [OK] Training text generated ($($trainingLines.Count) lines)" -ForegroundColor Green

# === Generate training images with text2image ===
Write-Host "[4/7] Generating training images from OCR A Extended font..." -ForegroundColor Yellow

$outputBase = "$WorkDir\gt\$ModelName"

Write-Host "  Generating images..." -ForegroundColor Gray

# Use Start-Process with a single argument string for correct quoting
$text2imageArgStr = "--text `"$trainingTextPath`" --outputbase `"$outputBase`" --font `"$FontName`" --fonts_dir `"$FontsDir`" --ptsize 20 --exposure 0 --xsize 3600 --ysize 480 --char_spacing 1.0 --leading 40 --margin 20"

$proc = Start-Process -FilePath $Text2Image -ArgumentList $text2imageArgStr -Wait -PassThru -NoNewWindow -RedirectStandardError "$WorkDir\t2i_err.log"

if ($proc.ExitCode -ne 0) {
    Write-Host "  [WARN] text2image exit code: $($proc.ExitCode)" -ForegroundColor DarkYellow
    if (Test-Path "$WorkDir\t2i_err.log") {
        Get-Content "$WorkDir\t2i_err.log" | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkYellow }
    }
}

# Verify output files
if (Test-Path "${outputBase}.tif") {
    $fileSize = (Get-Item "${outputBase}.tif").Length
    Write-Host "  [OK] Image generated ($fileSize bytes)" -ForegroundColor Green
} else {
    Write-Error "Failed to generate training image: ${outputBase}.tif"
    exit 1
}

if (Test-Path "${outputBase}.box") {
    Write-Host "  [OK] Box file generated" -ForegroundColor Green
} else {
    Write-Error "Failed to generate box file: ${outputBase}.box"
    exit 1
}

# === Download best (float) model and extract LSTM ===
Write-Host "[5/7] Preparing base LSTM model for fine-tuning..." -ForegroundColor Yellow

# The installed eng.traineddata is an integer (fast) model which CANNOT be fine-tuned.
# We need the float (best) version from tessdata_best for LSTM training.
$bestModelUrl = "https://github.com/tesseract-ocr/tessdata_best/raw/main/eng.traineddata"
$bestModelPath = "$WorkDir\eng_best.traineddata"

if (-not (Test-Path $bestModelPath)) {
    Write-Host "  Downloading tessdata_best/eng.traineddata (required for fine-tuning)..." -ForegroundColor Gray
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -Uri $bestModelUrl -OutFile $bestModelPath -UseBasicParsing
        $dlSize = (Get-Item $bestModelPath).Length
        Write-Host "  [OK] Downloaded eng_best.traineddata ($dlSize bytes)" -ForegroundColor Green
    } catch {
        Write-Error "Failed to download tessdata_best model: $_"
        Write-Host "  Please manually download from:" -ForegroundColor Yellow
        Write-Host "    $bestModelUrl" -ForegroundColor White
        Write-Host "  and save to: $bestModelPath" -ForegroundColor White
        exit 1
    }
} else {
    Write-Host "  [OK] Using cached eng_best.traineddata" -ForegroundColor Green
}

# Extract the LSTM component from the best (float) model
Start-Process -FilePath $CombineTessdata -ArgumentList "-e `"$bestModelPath`" `"$WorkDir\$BaseModel.lstm`"" -Wait -NoNewWindow

if (Test-Path "$WorkDir\$BaseModel.lstm") {
    Write-Host "  [OK] Base LSTM model extracted (float/best)" -ForegroundColor Green
} else {
    Write-Error "Failed to extract LSTM from base model"
    exit 1
}

# === Generate lstmf and run training ===
Write-Host "[6/7] Running LSTM training (this may take a few minutes)..." -ForegroundColor Yellow

Write-Host "  Generating LSTMF training data..." -ForegroundColor Gray

Start-Process -FilePath "$TesseractDir\tesseract.exe" -ArgumentList "`"${outputBase}.tif`" `"$outputBase`" --psm 6 lstm.train" -Wait -NoNewWindow

if (Test-Path "${outputBase}.lstmf") {
    Write-Host "  [OK] LSTMF generated" -ForegroundColor Green
} else {
    Write-Error "Failed to generate LSTMF file"
    exit 1
}

# Create list of training files
$lstmfListPath = "$WorkDir\training_files.txt"
[System.IO.File]::WriteAllText($lstmfListPath, "${outputBase}.lstmf", [System.Text.Encoding]::ASCII)

# Create output directory
$outputDir = "$WorkDir\output"
New-Item -ItemType Directory -Path $outputDir -Force | Out-Null

Write-Host "  Running LSTM fine-tuning ($MaxIterations iterations)..." -ForegroundColor Gray

$lstmArgStr = "--model_output `"$outputDir\$ModelName`" --continue_from `"$WorkDir\$BaseModel.lstm`" --traineddata `"$bestModelPath`" --train_listfile `"$lstmfListPath`" --max_iterations $MaxIterations --target_error_rate 0.01 --debug_interval 0"

$proc = Start-Process -FilePath $LstmTraining -ArgumentList $lstmArgStr -Wait -PassThru -NoNewWindow -RedirectStandardError "$WorkDir\lstm_train.log"

Write-Host "  LSTM training exit code: $($proc.ExitCode)" -ForegroundColor Gray

# Show training progress from log
if (Test-Path "$WorkDir\lstm_train.log") {
    Get-Content "$WorkDir\lstm_train.log" | Where-Object { $_ -match "iteration|At iteration|best|Finished" } | ForEach-Object {
        Write-Host "  [train] $_" -ForegroundColor Gray
    }
}

# Check for best model checkpoint (lstmtraining saves .checkpoint files, not .lstm)
# Files are named like: ocra_3.457_56_400.checkpoint (error_iteration_totaliter)
$bestModel = Get-ChildItem "$outputDir\${ModelName}_*.checkpoint" -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne "${ModelName}_checkpoint" } |
    Sort-Object { 
        # Extract error rate from filename: ocra_3.457_56_400.checkpoint -> 3.457
        if ($_.Name -match "${ModelName}_(\d+\.?\d*)_") { [double]$Matches[1] } else { 999 }
    } |
    Select-Object -First 1

if (-not $bestModel) {
    # Fallback: try the generic checkpoint file
    $bestModel = Get-ChildItem "$outputDir\${ModelName}_checkpoint" -ErrorAction SilentlyContinue
}

if (-not $bestModel) {
    # Last resort: any checkpoint
    $bestModel = Get-ChildItem "$outputDir\*.checkpoint" -ErrorAction SilentlyContinue | Select-Object -First 1
}
if (-not $bestModel) {
    Write-Host "  [ERROR] No checkpoint found in $outputDir" -ForegroundColor Red
    Get-ChildItem $outputDir -ErrorAction SilentlyContinue | ForEach-Object { Write-Host "    $($_.Name)" -ForegroundColor Gray }
    Write-Error "LSTM training failed - no checkpoint found"
    exit 1
}

Write-Host "  [OK] Best model: $($bestModel.Name)" -ForegroundColor Green

# === Package into traineddata ===
Write-Host "[7/7] Packaging traineddata..." -ForegroundColor Yellow

$stopArgStr = "--stop_training --continue_from `"$($bestModel.FullName)`" --traineddata `"$bestModelPath`" --model_output `"$WorkDir\$ModelName.traineddata`""

Start-Process -FilePath $LstmTraining -ArgumentList $stopArgStr -Wait -NoNewWindow

if (Test-Path "$WorkDir\$ModelName.traineddata") {
    $finalSize = (Get-Item "$WorkDir\$ModelName.traineddata").Length
    Write-Host "  [OK] traineddata created: $ModelName.traineddata ($finalSize bytes)" -ForegroundColor Green
    
    # Install to tessdata directory (needs admin privileges)
    try {
        Copy-Item "$WorkDir\$ModelName.traineddata" "$TessdataDir\$ModelName.traineddata" -Force
        Write-Host "  [OK] Installed to: $TessdataDir\$ModelName.traineddata" -ForegroundColor Green
    } catch {
        # Also copy to project directory as fallback
        $projectCopy = Join-Path $PSScriptRoot "$ModelName.traineddata"
        Copy-Item "$WorkDir\$ModelName.traineddata" $projectCopy -Force
        Write-Host "  [WARN] Cannot copy to tessdata (need Admin privileges)." -ForegroundColor DarkYellow
        Write-Host "  Model saved to: $projectCopy" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "  Please run this command as Administrator:" -ForegroundColor Yellow
        Write-Host "    Copy-Item '$projectCopy' '$TessdataDir\$ModelName.traineddata' -Force" -ForegroundColor White
    }
} else {
    Write-Error "Failed to create final traineddata"
    exit 1
}

# === Verify ===
Write-Host ""
Write-Host "=======================================" -ForegroundColor Cyan
Write-Host " Training Complete!" -ForegroundColor Green
Write-Host "=======================================" -ForegroundColor Cyan
Write-Host ""

# Quick verification test
Write-Host "Running verification test..." -ForegroundColor Yellow
$testResult = & "$TesseractDir\tesseract.exe" --list-langs 2>&1 | Select-String $ModelName
if ($testResult) {
    Write-Host "  [OK] Model '$ModelName' is available in Tesseract" -ForegroundColor Green
} else {
    Write-Host "  [WARN] Model may not be listed yet, check if copy was successful" -ForegroundColor DarkYellow
}

Write-Host ""
Write-Host "Usage in PHP:" -ForegroundColor Cyan
Write-Host '  $ocr->lang("ocra");' -ForegroundColor White
Write-Host '  $ocr->psm(7);  // single line' -ForegroundColor White
Write-Host '  $ocr->oem(1);  // LSTM only' -ForegroundColor White
Write-Host '  $ocr->allowlist("0123456789");' -ForegroundColor White
Write-Host ""
Write-Host "CLI test:" -ForegroundColor Cyan
Write-Host "  tesseract image.png stdout -l ocra --psm 7 -c tessedit_char_whitelist=0123456789" -ForegroundColor White
Write-Host ""
