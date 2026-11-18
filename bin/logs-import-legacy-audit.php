<?php
declare(strict_types=1);

/**
 * นำเข้า refer_system.refer_audit_log (ตารางเดิม) → refer_logs.log_events + log_changes (พร้อม hash chain)
 *
 *   - อ่านตารางเดิมอย่างเดียว ไม่แก้/ไม่ลบ
 *   - รันซ้ำได้: event_id สร้างจาก id เดิมแบบคงที่ → แถวที่นำเข้าแล้วจะถูกข้าม
 *   - เวลาเดิม (คอลัมน์ at) เป็นเวลาไทยที่ติดป้าย "Z" ไว้ผิด → ตีความเป็น Asia/Bangkok แล้วแปลงเป็น UTC จริง
 *     (เปลี่ยนได้ด้วย --source-tz=UTC ถ้าตรวจแล้วพบว่าข้อมูลเป็น UTC จริง)
 *
 * ใช้: docker compose exec backend php bin/logs-import-legacy-audit.php [--dry-run] [--source-tz=Asia/Bangkok]
 */
require __DIR__ . '/_bootstrap.php';

use App\Config\Database;
use App\Models\AuditLog;
use App\Services\Logging\LogConnection;
use App\Services\Logging\LogService;

$args = getopt('', ['dry-run', 'source-tz:']);
$dryRun = isset($args['dry-run']);
try {
    $sourceTz = new DateTimeZone((string)($args['source-tz'] ?? 'Asia/Bangkok'));
} catch (\Throwable) {
    cli_err('--source-tz ไม่ถูกต้อง');
    exit(2);
}

$logPdo = LogConnection::get();
if ($logPdo === null) {
    cli_err('เชื่อมต่อฐาน log ไม่ได้');
    exit(2);
}
$main = Database::getConnection();
$exists = $logPdo->prepare('SELECT 1 FROM log_events WHERE event_id = ?');

$rows = $main->query('SELECT * FROM refer_audit_log ORDER BY id')->fetchAll();
$imported = $skipped = $failed = 0;

foreach ($rows as $r) {
    $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', substr((string)$r['at'], 0, 26), $sourceTz)
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', substr((string)$r['at'], 0, 19), $sourceTz);
    if ($at === false) {
        cli_err("ข้าม #{$r['id']}: อ่านเวลา '{$r['at']}' ไม่ได้");
        $failed++;
        continue;
    }
    $at = $at->setTimezone(new DateTimeZone('UTC'));
    $eventId = LogService::ulid((int)$at->format('Uv'), 'legacy-audit|' . $r['id']);

    $exists->execute([$eventId]);
    if ($exists->fetchColumn()) {
        $skipped++;
        continue;
    }

    $details = $r['details'] !== null ? json_decode((string)$r['details'], true) : [];
    $details = is_array($details) ? $details : [];
    [$old, $new] = AuditLog::splitDetails((string)$r['action'], $details);

    if ($dryRun) {
        cli_out(sprintf('[dry-run] #%d %s %s:%s @ %s UTC', $r['id'], $r['action'], $r['target_type'], $r['target_id'], $at->format('Y-m-d H:i:s')));
        $imported++;
        continue;
    }

    LogService::audit((string)$r['action'], (string)$r['target_type'], (string)$r['target_type'], (string)$r['target_id'], $old, $new, [
        'event_id' => $eventId,
        'created_at' => $at,
        'no_request' => true,
        'ip' => $r['ip'] ?: null,
        'user' => $r['actor_id'] !== null ? ['id' => $r['actor_id'], 'username' => $r['actor_username'], 'role' => null] : null,
        'description' => AuditLog::describe((string)$r['action'], (string)$r['target_type'], array_keys($details))
            . " (นำเข้าจากบันทึกเดิม #{$r['id']})",
        'context' => ['legacy_audit_id' => (int)$r['id'], 'legacy_at' => (string)$r['at']],
    ]);

    $exists->execute([$eventId]);
    if ($exists->fetchColumn()) {
        $imported++;
    } else {
        cli_err("นำเข้า #{$r['id']} ไม่สำเร็จ (ดู storage/logs/fallback-*.log)");
        $failed++;
    }
}

cli_out(sprintf('%sทั้งหมด %d แถว: นำเข้า %d, มีอยู่แล้ว %d, ล้มเหลว %d',
    $dryRun ? '[dry-run] ' : '', count($rows), $imported, $skipped, $failed));
exit($failed > 0 ? 1 : 0);
