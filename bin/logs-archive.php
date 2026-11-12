<?php
declare(strict_types=1);

/**
 * ย้าย log ที่เก่ากว่า LOG_RETENTION_DAYS (90 วัน) ออกจากฐานข้อมูลเป็นไฟล์ JSON.GZ + สำเนา (ดู ArchiveService)
 * และทำลายไฟล์ที่เก่ากว่า LOG_ARCHIVE_RETENTION_YEARS (5 ปี)
 *
 * ปกติเรียกผ่านตัวตั้งเวลาของเครื่อง host ด้วย bin/run-log-archive.ps1 (Windows) หรือ bin/run-log-archive.sh (Linux)
 * ซึ่งส่งรหัสบัญชี refer_log_archiver ผ่าน stdin — รหัสไม่อยู่ใน .env ที่เว็บอ่านได้
 *
 * เรียกเอง:  docker compose exec -u www-data backend php bin/logs-archive.php --dry-run
 *   --dry-run        แสดงว่าจะ archive อะไรบ้าง ไม่เขียน/ไม่ลบ
 *   --password-stdin อ่านรหัส refer_log_archiver จากบรรทัดแรกของ stdin
 * exit code: 0 สำเร็จ / 1 มีบางไฟล์ล้มเหลว (ข้อมูลต้นฉบับยังอยู่ครบ) / 2 เริ่มทำงานไม่ได้
 */
require __DIR__ . '/_bootstrap.php';

use App\Services\Logging\ArchiveService;
use App\Services\Logging\LogService;

$args = getopt('', ['dry-run', 'password-stdin']);
if (isset($args['password-stdin'])) {
    $pw = trim((string)fgets(STDIN));
    if ($pw === '') {
        cli_err('ไม่ได้รับรหัสผ่านทาง stdin');
        exit(2);
    }
    putenv('LOG_ARCHIVER_PASSWORD=' . $pw);
}

// กันรันซ้อนกัน (ตัวตั้งเวลายิงซ้ำ / มีคนสั่งมือพร้อมกัน)
$lockFile = sys_get_temp_dir() . '/refer-log-archive.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    cli_err('มี archive กำลังทำงานอยู่แล้ว');
    exit(2);
}

try {
    $service = new ArchiveService('cli_out');
    $result = $service->run(isset($args['dry-run']), null);
} catch (\Throwable $e) {
    cli_err('เริ่ม archive ไม่ได้: ' . $e->getMessage());
    LogService::system('system.archive_failed', 'เริ่มงาน archive ไม่ได้: ' . $e->getMessage(), 'CRITICAL',
        ['module' => 'archive', 'no_request' => true, 'user' => null]);
    exit(2);
}

cli_out('ผล: ' . $result['status']);
exit(in_array($result['status'], ['success', 'dry-run'], true) ? 0 : 1);
