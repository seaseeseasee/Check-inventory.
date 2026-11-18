# ตัวเรียก archive สำหรับ Windows Task Scheduler (เครื่อง host ที่รัน Docker)
#   - อ่านรหัสบัญชี refer_log_archiver จาก .env.archiver (ไฟล์บน host เท่านั้น — ไม่ถูก copy เข้า image, git ไม่ track)
#   - ส่งรหัสเข้า container ทาง stdin (ไม่อยู่ใน command line / env ของเว็บ)
#   - รันในนาม www-data เพื่อให้ไฟล์ archive เป็นของผู้ใช้เดียวกับเว็บ (หน้าเว็บอ่าน/ดาวน์โหลดได้)
# ตั้งเวลา (ตัวอย่าง วันที่ 1 ของทุกเดือน 02:00):
#   schtasks /Create /TN "emer-log-archive" /SC MONTHLY /D 1 /ST 02:00 /RL HIGHEST ^
#     /TR "powershell -NoProfile -ExecutionPolicy Bypass -File C:\path\to\emer\bin\run-log-archive.ps1"
param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$secretFile = Join-Path $root '.env.archiver'
if (-not (Test-Path $secretFile)) { Write-Error "ไม่พบ $secretFile (บรรทัด LOG_ARCHIVER_PASSWORD=...)"; exit 2 }
$line = Get-Content $secretFile | Where-Object { $_ -match '^\s*LOG_ARCHIVER_PASSWORD\s*=' } | Select-Object -First 1
$password = ($line -replace '^\s*LOG_ARCHIVER_PASSWORD\s*=\s*', '').Trim().Trim('"')
if (-not $password) { Write-Error 'LOG_ARCHIVER_PASSWORD ว่าง'; exit 2 }

$logDir = Join-Path $root 'storage\logs'
New-Item -ItemType Directory -Force $logDir | Out-Null
$logFile = Join-Path $logDir ('archive-run-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt')

$extra = @('--password-stdin')
if ($DryRun) { $extra += '--dry-run' }
Push-Location $root
try {
    $password | docker compose exec -T -u www-data backend php bin/logs-archive.php @extra *>&1 | Tee-Object -FilePath $logFile
    $code = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $code
