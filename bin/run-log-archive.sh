#!/usr/bin/env sh
# ตัวเรียก archive สำหรับ cron บนเครื่อง Linux ที่รัน Docker (เหมือน run-log-archive.ps1)
#   crontab: 0 2 1 * * /path/to/emer/bin/run-log-archive.sh >> /path/to/emer/storage/logs/archive-cron.txt 2>&1
#   เพิ่ม --dry-run เพื่อดูอย่างเดียว
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
SECRET="$ROOT/.env.archiver"
[ -f "$SECRET" ] || { echo "ไม่พบ $SECRET (บรรทัด LOG_ARCHIVER_PASSWORD=...)" >&2; exit 2; }
PW=$(sed -n 's/^[[:space:]]*LOG_ARCHIVER_PASSWORD[[:space:]]*=[[:space:]]*//p' "$SECRET" | head -n1 | tr -d '"\r')
[ -n "$PW" ] || { echo "LOG_ARCHIVER_PASSWORD ว่าง" >&2; exit 2; }
cd "$ROOT"
printf '%s\n' "$PW" | docker compose exec -T -u www-data backend php bin/logs-archive.php --password-stdin "$@"
