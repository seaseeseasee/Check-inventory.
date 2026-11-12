<?php
declare(strict_types=1);

namespace App\Services\Logging;

/**
 * ค่าตั้งของระบบ Archive (ใช้ร่วมกันระหว่างสคริปต์ CLI กับหน้าเว็บ)
 *
 *   LOG_RETENTION_DAYS          เก็บใน DB กี่วัน (ค่าเริ่มต้น 90) — ย้ายเฉพาะ "เดือนที่จบแล้วและเก่ากว่านี้ทั้งเดือน"
 *   LOG_ARCHIVE_RETENTION_YEARS เก็บไฟล์ archive กี่ปีแล้วทำลาย (ค่าเริ่มต้น 5 — ปรับตามนโยบายองค์กร)
 *   LOG_ARCHIVE_PATH            ที่เก็บไฟล์หลัก (ค่าเริ่มต้น storage/logs/archive)
 *   LOG_ARCHIVE_BACKUP_PATH     ที่เก็บสำเนา — ต้องเป็นคนละ storage กับไฟล์หลัก
 *                               (Docker: volume log_archive_backup ที่ /var/log-archive-backup; XAMPP: ตั้งเป็นดิสก์/โฟลเดอร์อื่น)
 */
final class ArchiveConfig
{
    public static function root(): string
    {
        return rtrim((string)(getenv('LOG_ARCHIVE_PATH') ?: __DIR__ . '/../../../storage/logs/archive'), '/\\');
    }

    public static function backupRoot(): string
    {
        $env = getenv('LOG_ARCHIVE_BACKUP_PATH');
        if ($env) {
            return rtrim((string)$env, '/\\');
        }
        return is_dir('/var/log-archive-backup') ? '/var/log-archive-backup' : __DIR__ . '/../../../storage/backups/log-archive';
    }

    public static function retentionDays(): int
    {
        return max(30, (int)(getenv('LOG_RETENTION_DAYS') ?: 90));
    }

    public static function keepYears(): int
    {
        return max(1, (int)(getenv('LOG_ARCHIVE_RETENTION_YEARS') ?: 5));
    }

    /** logs/archive/audit/2026/audit_2026_01.json.gz (relative) */
    public static function relativePath(string $category, string $period): string
    {
        [$y, $m] = explode('-', $period);
        return "{$category}/{$y}/{$category}_{$y}_{$m}.json.gz";
    }

    /** path จริงของไฟล์ใน root ที่กำหนด — กันชื่อไฟล์จากฐานข้อมูลพาออกนอกโฟลเดอร์ (../) */
    public static function resolve(string $root, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '..') || !preg_match('#^[a-z_]+/\d{4}/[a-z_]+_\d{4}_\d{2}\.json\.gz$#', $relative)) {
            return null;
        }
        return $root . '/' . $relative;
    }
}
