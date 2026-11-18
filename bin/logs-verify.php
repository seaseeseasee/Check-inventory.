<?php
declare(strict_types=1);

/**
 * ตรวจ hash chain ของ audit log ทั้งสาย — รวมส่วนที่อยู่ในฐานข้อมูล (refer_logs.log_changes) และในไฟล์ archive
 *   - แต่ละแถว: prev_hash ต้องตรงกับ row_hash ของแถวก่อนหน้า และ row_hash ต้องคำนวณได้ตรงจากข้อมูลปัจจุบัน
 *   - ลำดับ chain_seq ต้องต่อเนื่อง: แถวที่หายจาก DB ต้องอยู่ในไฟล์ archive (ตรวจ SHA-256 ของไฟล์ก่อนใช้)
 *   - เจอจุดแรกที่ไม่ตรง = มีการแก้/ลบ/แทรกข้อมูลย้อนหลัง → รายงานแล้วจบด้วย exit code 1
 *
 * ใช้: docker compose exec -u www-data backend php bin/logs-verify.php
 *      (www-data เพื่อให้อ่านไฟล์ archive ได้; ถ้ายังไม่เคย archive จะรันด้วย user ใดก็ได้)
 */
require __DIR__ . '/_bootstrap.php';

use App\Services\Logging\ArchiveReader;
use App\Services\Logging\LogConnection;
use App\Services\Logging\LogService;

$pdo = LogConnection::get();
if ($pdo === null) {
    cli_err('เชื่อมต่อฐาน log ไม่ได้');
    exit(2);
}

// 1) แถวของสายที่ถูกย้ายไป archive แล้ว (เฉพาะหมวด audit) — โหลดเป็น map seq → row
$archived = [];
$files = $pdo->query("SELECT * FROM log_archives WHERE category = 'audit' AND status IN ('backed_up', 'purged') ORDER BY period")->fetchAll();
foreach ($files as $a) {
    try {
        foreach (ArchiveReader::chainRows($a) as $r) {
            $archived[(int)$r['chain_seq']] = $r;
        }
    } catch (\Throwable $e) {
        cli_err("FAIL ไฟล์ archive audit {$a['period']}: " . $e->getMessage());
        exit(1);
    }
}

// ไฟล์ audit ที่ทำลายตามอายุ (5 ปี) แล้ว — ต้นสายส่วนนั้นหายไปตามนโยบาย: ยอมให้สายเริ่มกลางทางได้เฉพาะ "ต้นสาย"
$destroyedAudit = (int)$pdo->query("SELECT COUNT(*) FROM log_archives WHERE category = 'audit' AND status = 'destroyed'")->fetchColumn();

$fromArchive = 0;
$fromDb = 0;
$prevHash = null;
$lastSeq = 0;

$check = function (array $r, string $where) use (&$prevHash, &$lastSeq, $destroyedAudit): void {
    $seq = (int)$r['chain_seq'];
    if ($prevHash === null && $seq !== 1) {
        if ($destroyedAudit === 0) {
            throw new \RuntimeException("สายเริ่มที่ seq {$seq} — แถว 1.." . ($seq - 1) . ' หายไป (ไม่อยู่ทั้งใน DB และ archive)');
        }
        cli_out("หมายเหตุ: seq 1.." . ($seq - 1) . " อยู่ในไฟล์ audit ที่ทำลายตามระยะเก็บแล้ว ({$destroyedAudit} ไฟล์) — ตรวจต่อจาก seq {$seq}");
    } elseif ($prevHash === null) {
        if ($r['prev_hash'] !== LogService::GENESIS_HASH) {
            throw new \RuntimeException('seq 1 ไม่ได้ต่อจาก genesis');
        }
    } elseif (!hash_equals($prevHash, (string)$r['prev_hash'])) {
        throw new \RuntimeException("seq {$seq} ({$where}, event {$r['event_id']}): prev_hash ไม่ตรงกับแถวก่อนหน้า");
    }
    $expected = LogService::chainHash((string)$r['prev_hash'], LogService::canonical($r, $r['old_value'], $r['new_value']));
    if (!hash_equals($expected, (string)$r['row_hash'])) {
        throw new \RuntimeException("seq {$seq} ({$where}, event {$r['event_id']}): ข้อมูลไม่ตรงกับ hash — ถูกแก้ไขหลังบันทึก");
    }
    $prevHash = (string)$r['row_hash'];
    $lastSeq = $seq;
};

// เติมช่องว่างจาก archive จนถึง seq ที่ต้องการ (ไม่รวม)
$fill = function (int $untilSeq) use (&$archived, &$lastSeq, &$fromArchive, &$prevHash, $check, $destroyedAudit): void {
    $start = $lastSeq + 1;
    if ($prevHash === null && $destroyedAudit > 0 && $archived) {
        // ต้นสายถูกทำลาย: เริ่มจากแถวแรกที่ยังมีอยู่ (ใน archive หรือ DB อย่างใดน้อยกว่า)
        $start = min(min(array_keys($archived)), $untilSeq);
    } elseif ($prevHash === null && $destroyedAudit > 0) {
        $start = $untilSeq;
    }
    for ($s = $start; $s < $untilSeq; $s++) {
        if (!isset($archived[$s])) {
            throw new \RuntimeException("seq {$s} หายไป — ไม่อยู่ทั้งในฐานข้อมูลและในไฟล์ archive (มีแถวถูกลบ)");
        }
        $check($archived[$s], 'archive');
        unset($archived[$s]);
        $fromArchive++;
    }
};

$stmt = $pdo->prepare(
    'SELECT c.chain_seq, c.prev_hash, c.row_hash, c.old_value, c.new_value,
            e.event_id, e.category, e.action, e.module, e.record_type, e.record_id, e.user_id, e.username,
            e.description, e.status, e.severity, e.ip_address, e.created_at
     FROM log_changes c JOIN log_events e ON e.event_id = c.event_id
     WHERE c.chain_seq > ? ORDER BY c.chain_seq LIMIT 2000'
);

try {
    $cursor = 0;
    do {
        $stmt->execute([$cursor]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $seq = (int)$r['chain_seq'];
            $fill($seq);
            if (isset($archived[$seq])) {
                unset($archived[$seq]);   // อยู่ทั้ง DB และ archive (ยังไม่ถูกลบ) — ตรวจจาก DB
            }
            $check($r, 'DB');
            $fromDb++;
            $cursor = $seq;
        }
    } while (count($rows) === 2000);
    // ท้ายสายที่อยู่ใน archive อย่างเดียว
    if ($archived) {
        $fill(max(array_keys($archived)) + 1);
    }
    // ท้ายสายต้องตรงกับหัวสายที่บันทึกไว้ (019) — จับกรณีแถวล่าสุดถูกลบทิ้ง ซึ่งการไล่ prev_hash อย่างเดียวมองไม่เห็น
    try {
        $head = $pdo->query('SELECT last_seq, last_hash FROM log_chain_head WHERE id = 1')->fetch();
    } catch (\PDOException) {
        $head = false;   // ยังไม่ได้รัน migration 019
    }
    if ($head) {
        if ((int)$head['last_seq'] !== $lastSeq || !hash_equals((string)$head['last_hash'], (string)$prevHash)) {
            throw new \RuntimeException("ท้ายสายไม่ตรงกับหัวสายที่บันทึกไว้ (บันทึก seq {$head['last_seq']}, พบถึง seq {$lastSeq}) — แถวล่าสุดหายไป");
        }
    } elseif ($lastSeq > 0) {
        cli_out('หมายเหตุ: ยังไม่มีตาราง/แถว log_chain_head (migration 019) — ข้ามการตรวจท้ายสาย');
    }
} catch (\RuntimeException $e) {
    cli_err('FAIL ' . $e->getMessage());
    exit(1);
}

$total = $fromDb + $fromArchive;
cli_out("OK ตรวจแล้ว {$total} แถว (ฐานข้อมูล {$fromDb}, archive {$fromArchive}) — hash chain สมบูรณ์" . ($total ? " (seq สุดท้าย {$lastSeq})" : ''));
exit(0);
