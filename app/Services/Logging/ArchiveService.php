<?php
declare(strict_types=1);

namespace App\Services\Logging;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * ย้าย log เก่าจากฐานข้อมูลไปเป็นไฟล์ JSON.GZ (ใช้จาก bin/logs-archive.php เท่านั้น)
 *
 * 1 ไฟล์ = 1 ประเภท × 1 เดือน (ตามเวลา UTC) — ภายในเป็น NDJSON (1 บรรทัด = 1 log พร้อม old/new + hash chain)
 * ขั้นตอนต่อไฟล์ (สถานะใน log_archives):
 *   exporting → เขียน .tmp ทีละชุด → ตรวจ: อ่านกลับนับบรรทัด/JSON ถูกต้อง = จำนวนใน DB → SHA-256 → rename   = verified
 *   → สำเนาไป backup root → SHA-256 ต้องตรงกัน                                                          = backed_up
 *   → ตรวจ hash ทั้งสองไฟล์อีกครั้ง → ลบจาก DB ทีละ 2,000 แถว (transaction ต่อชุด) จนไม่เหลือ               = purged
 * ผิดพลาดขั้นใด → failed + แจ้งเตือน CRITICAL และ "ไม่ลบต้นฉบับ"; รันซ้ำได้: ทำต่อจากสถานะเดิม/ทำใหม่ทั้งไฟล์
 * ลบได้เฉพาะแถวที่อยู่ในช่วง id ที่ export แล้ว (min_id..max_id) — แถวใหม่ที่อาจเข้ามาไม่ถูกลบโดยไม่ได้ archive
 *
 * ใช้บัญชี refer_log_archiver (LOG_ARCHIVER_USER / LOG_ARCHIVER_PASSWORD) — บัญชีแอปไม่มีสิทธิ์ลบ log (migration 018)
 */
final class ArchiveService
{
    private const BATCH = 2000;

    private PDO $db;
    /** @var callable(string): void */
    private $out;

    public function __construct(?callable $out = null)
    {
        $this->out = $out ?? static function (string $m): void {};
        $config = require __DIR__ . '/../../../config/database.php';
        $c = $config['connections']['mysql'];
        $user = (string)(getenv('LOG_ARCHIVER_USER') ?: 'refer_log_archiver');
        $pass = getenv('LOG_ARCHIVER_PASSWORD');
        if ($pass === false || $pass === '') {
            throw new \RuntimeException('ไม่ได้ตั้ง LOG_ARCHIVER_PASSWORD (รหัสของบัญชี refer_log_archiver)');
        }
        $dbName = (string)(getenv('LOG_DB_DATABASE') ?: 'refer_logs');
        $this->db = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$dbName};charset={$c['charset']}", $user, $pass, $c['options'] ?? []);
    }

    /**
     * @return array{status: string, files: array}
     */
    public function run(bool $dryRun, ?string $triggeredBy = null): array
    {
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        // เดือนที่ "จบแล้วทั้งเดือน" และเก่ากว่ากำหนด: ตัดที่วันที่ 1 ของเดือนที่ (วันนี้ - N วัน) ตกอยู่
        $cutoff = $now->modify('-' . ArchiveConfig::retentionDays() . ' days')->modify('first day of this month')->setTime(0, 0);
        $this->say(sprintf('เก็บใน DB %d วัน → archive log ที่เก่ากว่า %s (UTC)', ArchiveConfig::retentionDays(), $cutoff->format('Y-m-d')));

        $stmt = $this->db->prepare("SELECT category, DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS n, MIN(id) AS min_id, MAX(id) AS max_id
            FROM log_events WHERE created_at < ? GROUP BY category, period ORDER BY period, category");
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);
        $candidates = $stmt->fetchAll();

        if ($dryRun) {
            foreach ($candidates as $c) {
                $this->say(sprintf('[dry-run] %s %s: %d แถว → %s', $c['period'], $c['category'], $c['n'],
                    ArchiveConfig::relativePath($c['category'], $c['period'])));
            }
            $this->say('[dry-run] ไม่มีการเขียนไฟล์หรือลบข้อมูล');
            return ['status' => 'dry-run', 'files' => $candidates];
        }

        $this->db->prepare("INSERT INTO log_archive_runs (status, triggered_by, requested_at, started_at) VALUES ('running', ?, ?, ?)")
            ->execute([$triggeredBy, $this->ts(), $this->ts()]);
        $runId = (int)$this->db->lastInsertId();

        $results = [];
        $failed = 0;
        foreach ($candidates as $c) {
            $key = "{$c['category']} {$c['period']}";
            try {
                $results[$key] = $this->archiveOne((string)$c['category'], (string)$c['period'], $runId);
                $this->say("✓ {$key}: {$results[$key]}");
            } catch (\Throwable $e) {
                $failed++;
                $results[$key] = 'failed: ' . $e->getMessage();
                $this->say("✗ {$key}: " . $e->getMessage());
                $this->markFailed((string)$c['category'], (string)$c['period'], $runId, $e->getMessage());
                LogService::system('system.archive_failed', "Archive {$key} ล้มเหลว — ข้อมูลต้นฉบับยังอยู่ในฐานข้อมูลครบ: " . $e->getMessage(), 'CRITICAL', [
                    'module' => 'archive', 'record_type' => 'log_archive', 'record_id' => $key, 'no_request' => true, 'user' => null,
                    'context' => ['run_id' => $runId],
                ]);
            }
        }

        try {
            $destroyed = $this->destroyExpired();
        } catch (\Throwable $e) {
            $failed++;
            $destroyed = [];
            $results['retention'] = 'failed: ' . $e->getMessage();
            LogService::system('system.archive_failed', 'ทำลายไฟล์ archive ที่ครบอายุไม่สำเร็จ: ' . $e->getMessage(), 'CRITICAL',
                ['module' => 'archive', 'no_request' => true, 'user' => null]);
        }

        $status = $failed === 0 ? 'success' : ($failed < count($candidates) ? 'partial' : 'failed');
        $this->db->prepare('UPDATE log_archive_runs SET status = ?, finished_at = ?, summary = ? WHERE id = ?')
            ->execute([$status, $this->ts(), json_encode(['files' => $results, 'destroyed' => $destroyed], JSON_UNESCAPED_UNICODE), $runId]);

        LogService::system('system.archive_run', sprintf('Archive รอบที่ %d: %s (%d ไฟล์, ล้มเหลว %d, ทำลายตามอายุ %d)',
            $runId, $status, count($candidates), $failed, count($destroyed)), $failed ? 'ERROR' : 'INFO', [
            'module' => 'archive', 'status' => $failed ? 'FAILED' : 'SUCCESS', 'no_request' => true, 'user' => null,
            'context' => ['run_id' => $runId, 'results' => $results],
        ]);
        return ['status' => $status, 'files' => $results];
    }

    private function archiveOne(string $category, string $period, int $runId): string
    {
        $from = new DateTimeImmutable($period . '-01 00:00:00', new DateTimeZone('UTC'));
        $to = $from->modify('+1 month');
        $range = [$category, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
        $rel = ArchiveConfig::relativePath($category, $period);
        $main = ArchiveConfig::root() . '/' . $rel;
        $backup = ArchiveConfig::backupRoot() . '/' . $rel;

        $row = $this->archiveRow($category, $period);
        if ($row && in_array($row['status'], ['purged', 'destroyed'], true)) {
            // มี log ย้อนหลังเข้ามาในเดือนที่ archive ไปแล้ว (เช่นนำเข้าข้อมูลเก่า) — ไม่เขียนทับไฟล์เดิม ให้คนตรวจ
            throw new \RuntimeException("เดือนนี้ archive แล้ว (สถานะ {$row['status']}) แต่มี log เพิ่มเข้ามาอีก — ต้องตรวจสอบด้วยมือ");
        }

        // สำรองแล้ว (backed_up) = อาจเริ่มลบจาก DB ไปบางส่วนแล้ว → ห้าม export ใหม่เด็ดขาด
        // (export ใหม่จะได้ไฟล์ที่ขาดแถวที่ลบไปแล้ว แล้วเขียนทับไฟล์ที่ครบ = ข้อมูลหายถาวร)
        // ทำต่อจากไฟล์เดิม: สำเนาใดเสียให้กู้จากอีกสำเนา; เสียทั้งคู่ = หยุดให้คนตรวจ
        $resume = $row && $row['status'] === 'backed_up' && $row['sha256'];
        if ($resume) {
            $okMain = is_file($main) && hash_file('sha256', $main) === $row['sha256'];
            $okBackup = is_file($backup) && hash_file('sha256', $backup) === $row['sha256'];
            if (!$okMain && !$okBackup) {
                throw new \RuntimeException('ไฟล์หลักและสำเนาเสียทั้งคู่ ทั้งที่อาจลบข้อมูลจาก DB ไปบางส่วนแล้ว — ห้ามรันซ้ำ ต้องตรวจสอบด้วยมือ');
            }
            if (!$okMain) {
                $this->say('ไฟล์หลักเสีย/หาย — กู้จากสำเนา');
                $this->copyVerified($backup, $main, $row['sha256']);
            }
            if (!$okBackup) {
                $this->say('สำเนาเสีย/หาย — สร้างใหม่จากไฟล์หลัก');
                $this->copyVerified($main, $backup, $row['sha256']);
            }
        }

        if (!$resume) {
            $this->upsert($category, $period, $runId, ['status' => 'exporting', 'file_path' => $rel, 'error_message' => null]);

            $stmt = $this->db->prepare('SELECT COUNT(*), MIN(id), MAX(id), MIN(created_at), MAX(created_at) FROM log_events
                WHERE category = ? AND created_at >= ? AND created_at < ?');
            $stmt->execute($range);
            [$count, $minId, $maxId, $minAt, $maxAt] = $stmt->fetch(PDO::FETCH_NUM);
            $count = (int)$count;
            if ($count === 0) {
                return 'ไม่มีข้อมูล';
            }

            $written = $this->export($main, $range, (int)$minId, (int)$maxId);
            if ($written !== $count) {
                throw new \RuntimeException("export ได้ {$written} แถว แต่ในฐานข้อมูลมี {$count} แถว");
            }
            [$lines, $sha] = $this->verifyFile($main);
            if ($lines !== $count) {
                throw new \RuntimeException("ตรวจไฟล์ได้ {$lines} บรรทัด ไม่เท่ากับ {$count}");
            }
            $this->upsert($category, $period, $runId, [
                'status' => 'verified', 'row_count' => $count, 'min_id' => $minId, 'max_id' => $maxId,
                'from_at' => $minAt, 'to_at' => $maxAt, 'size_bytes' => filesize($main), 'sha256' => $sha,
            ]);

            $this->copyVerified($main, $backup, $sha);
            $this->upsert($category, $period, $runId, ['status' => 'backed_up', 'backup_path' => $rel]);
            $row = $this->archiveRow($category, $period);
        }

        // ก่อนลบ: ทั้งสองสำเนายังต้องตรงกับ hash ที่บันทึกไว้
        foreach ([$main, $backup] as $f) {
            if (!is_file($f) || hash_file('sha256', $f) !== $row['sha256']) {
                throw new \RuntimeException('ไฟล์ archive ไม่ตรงกับ hash ก่อนลบ: ' . basename(dirname($f, 3)) . '/' . basename($f));
            }
        }
        $deleted = $this->purge($range, (int)$row['min_id'], (int)$row['max_id']);
        $this->upsert($category, $period, $runId, ['status' => 'purged', 'error_message' => null]);
        return sprintf('%s แถว → %s (%s, ลบจาก DB %d แถว)', number_format((int)$row['row_count']), $rel,
            $this->bytes((int)$row['size_bytes']), $deleted);
    }

    /** เขียน NDJSON.gz ลง .tmp ทีละชุด (keyset ตาม id) — คืนจำนวนบรรทัดที่เขียน */
    private function export(string $file, array $range, int $minId, int $maxId): int
    {
        $this->ensureDir(dirname($file));
        $tmp = $file . '.tmp';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('เปิดไฟล์เขียนไม่ได้: ' . $tmp);
        }
        $written = 0;
        $last = $minId - 1;
        $stmt = $this->db->prepare('SELECT e.*, c.old_value, c.new_value, c.chain_seq, c.prev_hash, c.row_hash
            FROM log_events e LEFT JOIN log_changes c ON c.event_id = e.event_id
            WHERE e.category = ? AND e.created_at >= ? AND e.created_at < ? AND e.id > ? AND e.id <= ?
            ORDER BY e.id LIMIT ' . self::BATCH);
        try {
            do {
                $stmt->execute(array_merge($range, [$last, $maxId]));
                $rows = $stmt->fetchAll();
                foreach ($rows as $r) {
                    $last = (int)$r['id'];
                    // context / old_value / new_value เก็บเป็นข้อความ JSON ดิบตามที่อยู่ใน DB ทุกตัวอักษร
                    // (ถ้า decode แล้ว encode ใหม่ ตัวเลข/escape อาจต่างไป → hash chain ตรวจจากไฟล์ไม่ผ่าน)
                    $line = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (gzwrite($gz, $line) !== strlen($line)) {
                        throw new \RuntimeException('เขียนไฟล์ไม่ครบ (พื้นที่เต็ม?)');
                    }
                    $written++;
                }
            } while (count($rows) === self::BATCH);
        } finally {
            gzclose($gz);
        }
        if (!rename($tmp, $file)) {
            throw new \RuntimeException('เปลี่ยนชื่อไฟล์ .tmp ไม่สำเร็จ');
        }
        return $written;
    }

    /** อ่านไฟล์กลับทั้งไฟล์: ทุกบรรทัดต้องเป็น JSON ที่มี event_id — คืน [จำนวนบรรทัด, sha256] */
    public function verifyFile(string $file): array
    {
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('เปิดไฟล์อ่านไม่ได้: ' . basename($file));
        }
        $lines = 0;
        try {
            while (($line = gzgets($gz)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $obj = json_decode($line, true);
                if (!is_array($obj) || empty($obj['event_id'])) {
                    throw new \RuntimeException('ไฟล์เสีย: บรรทัดที่ ' . ($lines + 1) . ' ไม่ใช่ JSON ที่ถูกต้อง');
                }
                $lines++;
            }
        } finally {
            gzclose($gz);
        }
        return [$lines, hash_file('sha256', $file)];
    }

    private function copyVerified(string $src, string $dst, string $sha): void
    {
        $this->ensureDir(dirname($dst));
        if (!copy($src, $dst . '.tmp')) {
            throw new \RuntimeException('สำเนาไฟล์ไปที่เก็บสำรองไม่สำเร็จ: ' . ArchiveConfig::backupRoot());
        }
        if (hash_file('sha256', $dst . '.tmp') !== $sha) {
            @unlink($dst . '.tmp');
            throw new \RuntimeException('ไฟล์สำรอง hash ไม่ตรงกับไฟล์หลัก');
        }
        if (!rename($dst . '.tmp', $dst)) {
            throw new \RuntimeException('เปลี่ยนชื่อไฟล์สำรองไม่สำเร็จ');
        }
    }

    /** ลบจาก DB ทีละชุด — แต่ละชุดเป็น transaction (old/new ก่อน แล้วจึง event) จนไม่เหลือในช่วง id ที่ archive แล้ว */
    private function purge(array $range, int $minId, int $maxId): int
    {
        $sel = $this->db->prepare('SELECT id, event_id FROM log_events
            WHERE category = ? AND created_at >= ? AND created_at < ? AND id BETWEEN ? AND ? ORDER BY id LIMIT ' . self::BATCH);
        $total = 0;
        while (true) {
            $sel->execute(array_merge($range, [$minId, $maxId]));
            $rows = $sel->fetchAll();
            if (!$rows) {
                break;
            }
            $ids = array_column($rows, 'id');
            $events = array_column($rows, 'event_id');
            $in = fn(array $a) => implode(',', array_fill(0, count($a), '?'));
            $this->db->beginTransaction();
            try {
                $this->db->prepare('DELETE FROM log_changes WHERE event_id IN (' . $in($events) . ')')->execute($events);
                $del = $this->db->prepare('DELETE FROM log_events WHERE id IN (' . $in($ids) . ')');
                $del->execute($ids);
                if ($del->rowCount() !== count($ids)) {
                    throw new \RuntimeException('จำนวนแถวที่ลบไม่ตรงกับที่เลือก');
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            $total += count($ids);
        }
        return $total;
    }

    /** ไฟล์ที่เก่ากว่าระยะเก็บ (ค่าเริ่มต้น 5 ปี) → ลบทั้งไฟล์หลักและสำเนา + บันทึกการทำลาย */
    private function destroyExpired(): array
    {
        $limit = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . ArchiveConfig::keepYears() . ' years')->format('Y-m');
        $stmt = $this->db->prepare("SELECT * FROM log_archives WHERE status = 'purged' AND period < ?");
        $stmt->execute([$limit]);
        $done = [];
        foreach ($stmt->fetchAll() as $a) {
            foreach ([ArchiveConfig::root(), ArchiveConfig::backupRoot()] as $root) {
                $f = ArchiveConfig::resolve($root, (string)$a['file_path']);
                if ($f && is_file($f) && !unlink($f)) {
                    throw new \RuntimeException('ลบไฟล์ครบอายุไม่ได้: ' . $a['file_path']);
                }
            }
            $this->db->prepare("UPDATE log_archives SET status = 'destroyed', updated_at = ? WHERE id = ?")->execute([$this->ts(), $a['id']]);
            LogService::system('system.archive_destroyed', "ทำลายไฟล์ archive ครบอายุ {$a['category']} {$a['period']} ({$a['row_count']} แถว, SHA-256 {$a['sha256']})",
                'WARNING', ['module' => 'archive', 'status' => 'SUCCESS', 'no_request' => true, 'user' => null,
                    'record_type' => 'log_archive', 'record_id' => (string)$a['id']]);
            $done[] = "{$a['category']} {$a['period']}";
        }
        return $done;
    }

    // ---------------------------------------------------------------- ภายใน

    private function archiveRow(string $category, string $period): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM log_archives WHERE category = ? AND period = ?');
        $stmt->execute([$category, $period]);
        return $stmt->fetch() ?: null;
    }

    private function upsert(string $category, string $period, int $runId, array $data): void
    {
        $data['run_id'] = $runId;
        $data['updated_at'] = $this->ts();
        if ($this->archiveRow($category, $period)) {
            $sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
            $this->db->prepare("UPDATE log_archives SET {$sets} WHERE category = ? AND period = ?")
                ->execute(array_merge(array_values($data), [$category, $period]));
            return;
        }
        $data += ['category' => $category, 'period' => $period, 'created_at' => $this->ts(), 'file_path' => ArchiveConfig::relativePath($category, $period)];
        $cols = array_keys($data);
        $this->db->prepare('INSERT INTO log_archives (`' . implode('`, `', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($data));
    }

    private function markFailed(string $category, string $period, int $runId, string $message): void
    {
        try {
            $row = $this->archiveRow($category, $period);
            if ($row && in_array($row['status'], ['purged', 'destroyed'], true)) {
                return;   // ไม่ลดสถานะไฟล์ที่เสร็จแล้ว (กรณีมี log เพิ่มเข้ามาในเดือนเดิม)
            }
            if ($row && $row['status'] === 'backed_up') {
                // คงสถานะไว้ให้รอบหน้าทำต่อจากไฟล์เดิม (ห้าม export ใหม่) — บันทึกแค่สาเหตุ
                $this->upsert($category, $period, $runId, ['error_message' => mb_substr($message, 0, 500)]);
                return;
            }
            $this->upsert($category, $period, $runId, ['status' => 'failed', 'error_message' => mb_substr($message, 0, 500)]);
        } catch (\Throwable $e) {
            $this->say('บันทึกสถานะ failed ไม่สำเร็จ: ' . $e->getMessage());
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('สร้างโฟลเดอร์ไม่ได้: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('โฟลเดอร์เขียนไม่ได้: ' . $dir);
        }
    }

    private function ts(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function bytes(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : ($n >= 1024 ? round($n / 1024, 1) . ' KB' : $n . ' B');
    }

    private function say(string $m): void
    {
        ($this->out)($m);
    }
}
