<?php
declare(strict_types=1);

namespace App\Services\Logging;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * ฝั่งอ่าน log สำหรับหน้า Log Management — ทุกคำสั่งเป็น prepared statement
 * ชื่อคอลัมน์ที่มาจากผู้ใช้ (sort) ผ่าน whitelist เท่านั้น
 *
 * เวลา: ฐานเก็บ UTC — ช่วงวันที่ที่ผู้ใช้เลือกเป็นวันตามเวลาไทย (Asia/Bangkok) แปลงเป็นช่วง UTC ก่อน query
 * ส่งออกเป็น ISO 8601 ลงท้าย Z ให้หน้าเว็บแสดงเป็นเวลาไทยเอง
 */
final class LogQueryService
{
    public const TZ = 'Asia/Bangkok';
    private const TZ_OFFSET = '+07:00';   // ประเทศไทยไม่มีเวลาออมแสง — CONVERT_TZ แบบตัวเลขไม่ต้องมีตาราง timezone
    public const SORTABLE = ['created_at', 'category', 'action', 'module', 'username', 'status', 'severity', 'ip_address'];
    public const PER_PAGE = [20, 50, 100];
    public const EXPORT_LIMIT = 50000;
    public const COUNT_CAP = 10000;

    private const LIST_COLUMNS = 'e.id, e.event_id, e.category, e.action, e.module, e.record_type, e.record_id, e.user_id, e.username,
        e.user_role, e.description, e.status, e.severity, e.ip_address, e.http_status, e.repeat_count, e.has_changes,
        e.created_at, e.last_seen_at';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = LogConnection::get() ?? throw new \RuntimeException('ระบบ log ไม่พร้อมใช้งาน (เชื่อมต่อฐาน log ไม่ได้)');
    }

    /**
     * @param array $f กรองแล้วโดย controller: q, category, user_id, module, status, severity, from, to, view, hide_security
     * @return array{rows: array, total: int, page: int, per_page: int}
     */
    public function search(array $f, int $page, int $perPage, string $sort, string $dir): array
    {
        $params = [];
        $where = $this->where($f, $params);
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        // นับแบบมีเพดาน: ผลลัพธ์ใหญ่มาก COUNT(*) ทั้งหมดช้า (วัดได้ 3–5 วิ ที่ 1 ล้านแถว) — เกินเพดานให้ผู้ใช้กรองให้แคบลง
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM log_events e {$where} LIMIT " . (self::COUNT_CAP + 1) . ") t");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $capped = $total > self::COUNT_CAP;
        $total = min($total, self::COUNT_CAP);
        $page = min($page, max(1, (int)ceil($total / $perPage)));

        $offset = ($page - 1) * $perPage;
        // id เป็นตัวตัดสินลำดับรอง — แถวที่เวลาเท่ากันไม่สลับที่ระหว่างหน้า
        $stmt = $this->pdo->prepare("SELECT " . self::LIST_COLUMNS . " FROM log_events e {$where}
            ORDER BY e.{$sort} {$dir}, e.id {$dir} LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);

        return ['rows' => array_map([$this, 'present'], $stmt->fetchAll()), 'total' => $total, 'total_capped' => $capped,
            'page' => $page, 'per_page' => $perPage];
    }

    public function find(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT " . self::LIST_COLUMNS . ", e.user_agent, e.http_method, e.path, e.context, e.request_id,
                c.old_value, c.new_value, c.chain_seq, c.row_hash
            FROM log_events e LEFT JOIN log_changes c ON c.event_id = e.event_id
            WHERE e.event_id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $out = $this->present($row);
        foreach (['user_agent', 'http_method', 'path', 'request_id'] as $k) {
            $out[$k] = $row[$k];
        }
        $out['context'] = $row['context'] !== null ? json_decode((string)$row['context'], true) : null;
        $out['old_value'] = $row['old_value'] !== null ? json_decode((string)$row['old_value'], true) : null;
        $out['new_value'] = $row['new_value'] !== null ? json_decode((string)$row['new_value'], true) : null;
        $out['chain_seq'] = $row['chain_seq'] !== null ? (int)$row['chain_seq'] : null;
        $out['row_hash'] = $row['row_hash'];
        return $out;
    }

    /** การ์ดสรุป + ข้อมูลกราฟ ของช่วง $days วันล่าสุด (นับวันตามเวลาไทย รวมวันนี้) */
    public function summary(int $days, bool $includeSecurity): array
    {
        $tz = new DateTimeZone(self::TZ);
        $todayStart = new DateTimeImmutable('today', $tz);
        $since = $todayStart->modify('-' . ($days - 1) . ' days');
        $sinceUtc = self::toUtc($since);
        $todayUtc = self::toUtc($todayStart);
        $sec = $includeSecurity ? '' : " AND category <> 'security'";

        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM log_events WHERE 1=1{$sec}")->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT
                COUNT(*) AS in_range,
                SUM(created_at >= ?) AS today,
                SUM(action = 'auth.login_success') AS login_success,
                SUM(action = 'auth.login_failed') AS login_failed,
                SUM(category = 'audit' AND status = 'SUCCESS' AND (action LIKE '%.create' OR action LIKE '%.submit' OR action LIKE '%.copy')) AS creates,
                SUM(category = 'audit' AND status = 'SUCCESS' AND action LIKE '%.delete') AS deletes,
                SUM(category = 'audit' AND status = 'SUCCESS') AS audits,
                SUM(category = 'system') AS system_errors,
                COALESCE(SUM(CASE WHEN category = 'security' THEN repeat_count END), 0) AS security_events
            FROM log_events WHERE created_at >= ?{$sec}");
        $stmt->execute([$todayUtc, $sinceUtc]);
        $c = array_map('intval', $stmt->fetch() ?: []);

        $stmt = $this->pdo->prepare("SELECT DATE(CONVERT_TZ(created_at, '+00:00', '" . self::TZ_OFFSET . "')) AS d,
                COUNT(*) AS total,
                SUM(category = 'system' OR severity IN ('ERROR', 'CRITICAL')) AS errors
            FROM log_events WHERE created_at >= ?{$sec} GROUP BY d ORDER BY d");
        $stmt->execute([$sinceUtc]);
        $byDay = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDay[(string)$r['d']] = ['total' => (int)$r['total'], 'errors' => (int)$r['errors']];
        }
        $daily = [];
        for ($d = $since; $d <= $todayStart; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $daily[] = ['date' => $key] + ($byDay[$key] ?? ['total' => 0, 'errors' => 0]);
        }

        $stmt = $this->pdo->prepare("SELECT category, COUNT(*) AS n FROM log_events WHERE created_at >= ?{$sec} GROUP BY category ORDER BY n DESC");
        $stmt->execute([$sinceUtc]);
        $categories = array_map(fn($r) => ['category' => $r['category'], 'count' => (int)$r['n']], $stmt->fetchAll());

        $stmt = $this->pdo->prepare("SELECT user_id, MAX(username) AS username, COUNT(*) AS n FROM log_events
            WHERE created_at >= ? AND user_id IS NOT NULL{$sec} GROUP BY user_id ORDER BY n DESC LIMIT 10");
        $stmt->execute([$sinceUtc]);
        $users = array_map(fn($r) => ['user_id' => $r['user_id'], 'username' => $r['username'], 'count' => (int)$r['n']], $stmt->fetchAll());

        return [
            'days' => $days,
            'cards' => [
                'total' => $total,
                'in_range' => $c['in_range'] ?? 0,
                'today' => $c['today'] ?? 0,
                'login_success' => $c['login_success'] ?? 0,
                'login_failed' => $c['login_failed'] ?? 0,
                'creates' => $c['creates'] ?? 0,
                'updates' => max(0, ($c['audits'] ?? 0) - ($c['creates'] ?? 0) - ($c['deletes'] ?? 0)),
                'deletes' => $c['deletes'] ?? 0,
                'system_errors' => $c['system_errors'] ?? 0,
                'security_events' => $includeSecurity ? ($c['security_events'] ?? 0) : null,
            ],
            'daily' => $daily,
            'categories' => $categories,
            'top_users' => $users,
            'archive_alert' => $this->archiveAlert(),
        ];
    }

    /** ตัวเลือกของ dropdown กรอง (จากข้อมูลจริงในฐาน) */
    public function filterOptions(bool $includeSecurity): array
    {
        // ใช้ index (module, created_at) / (user_id, created_at) แบบ loose scan — เดิม GROUP BY + MAX(username) อ่านทุกแถว (เกิน 60 วิ ที่ 1 ล้านแถว)
        // ตัวเลือกเป็นแค่ตัวช่วยกรอง: ไม่ตัดหมวด security ที่นี่ (ชื่อ module/ผู้ใช้ไม่ใช่ข้อมูลลับ — ผลกรองยังถูกจำกัดสิทธิ์ที่ search)
        $modules = $this->pdo->query("SELECT module FROM log_events GROUP BY module ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
        $ids = $this->pdo->query("SELECT MAX(id) FROM log_events WHERE user_id IS NOT NULL GROUP BY user_id")->fetchAll(PDO::FETCH_COLUMN);
        $users = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->pdo->prepare('SELECT user_id, username FROM log_events WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute($chunk);
            array_push($users, ...$stmt->fetchAll());
        }
        usort($users, fn($a, $b) => strcmp((string)$a['username'], (string)$b['username']));
        return ['modules' => $modules, 'users' => $users];
    }

    /** ส่งออกทีละชุด (keyset ตาม id) ไม่โหลดทั้งหมดเข้า memory */
    public function exportRows(array $f, int $limit): \Generator
    {
        $params = [];
        $where = $this->where($f, $params);
        $lastId = PHP_INT_MAX;
        $sent = 0;
        $glue = $where === '' ? 'WHERE' : 'AND';
        while ($sent < $limit) {
            $batch = min(2000, $limit - $sent);
            $stmt = $this->pdo->prepare("SELECT " . self::LIST_COLUMNS . " FROM log_events e {$where} {$glue} e.id < ?
                ORDER BY e.id DESC LIMIT {$batch}");
            $stmt->execute(array_merge($params, [$lastId]));
            $rows = $stmt->fetchAll();
            if (!$rows) {
                return;
            }
            foreach ($rows as $r) {
                $lastId = (int)$r['id'];
                $sent++;
                yield $this->present($r);
            }
            if (count($rows) < $batch) {
                return;
            }
        }
    }

    public function count(array $f): int
    {
        $params = [];
        $where = $this->where($f, $params);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM log_events e {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function archives(): array
    {
        $files = $this->pdo->query("SELECT id, category, period, file_path, row_count, from_at, to_at, size_bytes, sha256, status,
                error_message, run_id, updated_at FROM log_archives ORDER BY period DESC, category")->fetchAll();
        $runs = $this->pdo->query("SELECT id, status, triggered_by, requested_at, started_at, finished_at, summary
            FROM log_archive_runs ORDER BY id DESC LIMIT 20")->fetchAll();
        $oldest = $this->pdo->query("SELECT MIN(created_at) FROM log_events")->fetchColumn();
        $rows = (int)$this->pdo->query("SELECT COUNT(*) FROM log_events")->fetchColumn();
        $disk = [];
        foreach (['main' => ArchiveConfig::root(), 'backup' => ArchiveConfig::backupRoot()] as $k => $dir) {
            $probe = is_dir($dir) ? $dir : dirname($dir);
            $free = @disk_free_space($probe);
            $total = @disk_total_space($probe);
            $disk[$k] = ['free' => $free === false ? null : (int)$free, 'total' => $total === false ? null : (int)$total, 'exists' => is_dir($dir)];
        }
        return [
            'policy' => ['retention_days' => ArchiveConfig::retentionDays(), 'keep_years' => ArchiveConfig::keepYears()],
            'disk' => $disk,
            'archived_bytes' => (int)$this->pdo->query("SELECT COALESCE(SUM(size_bytes), 0) FROM log_archives WHERE status IN ('verified', 'backed_up', 'purged')")->fetchColumn(),
            'alert' => $this->archiveAlert(),
            'files' => array_map(fn($r) => array_merge($r, [
                'from_at' => self::iso($r['from_at']), 'to_at' => self::iso($r['to_at']), 'updated_at' => self::iso($r['updated_at']),
            ]), $files),
            'runs' => array_map(fn($r) => array_merge($r, [
                'requested_at' => self::iso($r['requested_at']), 'started_at' => self::iso($r['started_at']),
                'finished_at' => self::iso($r['finished_at']),
            ]), $runs),
            'db' => ['rows' => $rows, 'oldest_at' => self::iso($oldest ?: null)],
        ];
    }

    public function archiveById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM log_archives WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * แจ้งเตือนบนหน้าเว็บเมื่อ archive มีปัญหา: รอบล่าสุดล้มเหลว / มีไฟล์ค้างสถานะ failed
     * (ระบบไม่มีอีเมล — แสดงแถบเตือนในหน้า Log จนกว่าจะแก้ + เหตุการณ์ CRITICAL ในหมวดระบบ)
     */
    public function archiveAlert(): ?array
    {
        $last = $this->pdo->query("SELECT id, status, finished_at FROM log_archive_runs WHERE status <> 'requested' ORDER BY id DESC LIMIT 1")->fetch();
        $failedFiles = (int)$this->pdo->query("SELECT COUNT(*) FROM log_archives
            WHERE status = 'failed' OR (status = 'backed_up' AND error_message IS NOT NULL)")->fetchColumn();
        if ($failedFiles === 0 && (!$last || $last['status'] === 'success' || $last['status'] === 'running')) {
            return null;
        }
        return [
            'last_run_status' => $last['status'] ?? null,
            'last_run_at' => self::iso($last['finished_at'] ?? null),
            'failed_files' => $failedFiles,
        ];
    }

    // ---------------------------------------------------------------- ภายใน

    private function where(array $f, array &$params): string
    {
        $w = [];
        if (!empty($f['view']) && $f['view'] === 'security') {
            // มุมมองความปลอดภัย = เหตุการณ์ security ทั้งหมด + การยืนยันตัวตนที่ล้มเหลว + การเปลี่ยนสิทธิ์
            $w[] = "(e.category = 'security' OR (e.category = 'auth' AND e.status = 'FAILED') OR e.severity = 'CRITICAL')";
        }
        if (!empty($f['hide_security'])) {
            $w[] = "e.category <> 'security'";
        }
        foreach (['category', 'module', 'status', 'severity', 'user_id', 'action'] as $col) {
            if (isset($f[$col]) && $f[$col] !== '') {
                $w[] = "e.{$col} = ?";
                $params[] = $f[$col];
            }
        }
        if (!empty($f['from'])) {
            $w[] = 'e.created_at >= ?';
            $params[] = self::toUtc(new DateTimeImmutable($f['from'] . ' 00:00:00', new DateTimeZone(self::TZ)));
        }
        if (!empty($f['to'])) {
            $w[] = 'e.created_at < ?';
            $params[] = self::toUtc((new DateTimeImmutable($f['to'] . ' 00:00:00', new DateTimeZone(self::TZ)))->modify('+1 day'));
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $or = ['e.description LIKE ?', 'e.action LIKE ?', 'e.username LIKE ?', 'e.record_id LIKE ?'];
            array_push($params, $like, $like, $like, $like);
            // ip_address / event_id เป็นคอลัมน์ ascii — เทียบกับข้อความภาษาไทยแล้ว MariaDB error (collation ปนกัน)
            // ค่าทั้งสองไม่มีอักษรนอก ASCII อยู่แล้ว จึงค้นเฉพาะเมื่อคำค้นเป็น ASCII ล้วน
            if (preg_match('/^[\x20-\x7E]+$/', $f['q'])) {
                array_push($or, 'e.ip_address LIKE ?', 'e.event_id = ?');
                array_push($params, $like, $f['q']);
            }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        return $w ? 'WHERE ' . implode(' AND ', $w) : '';
    }

    private function present(array $r): array
    {
        return [
            'event_id' => $r['event_id'],
            'category' => $r['category'],
            'action' => $r['action'],
            'module' => $r['module'],
            'record_type' => $r['record_type'],
            'record_id' => $r['record_id'],
            'user_id' => $r['user_id'],
            'username' => $r['username'],
            'user_role' => $r['user_role'],
            'description' => $r['description'],
            'status' => $r['status'],
            'severity' => $r['severity'],
            'ip_address' => $r['ip_address'],
            'http_status' => $r['http_status'] !== null ? (int)$r['http_status'] : null,
            'repeat_count' => (int)$r['repeat_count'],
            'has_changes' => (bool)$r['has_changes'],
            'created_at' => self::iso($r['created_at']),
            'last_seen_at' => self::iso($r['last_seen_at']),
        ];
    }

    private static function toUtc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** '2026-11-12 02:59:19.098793' (UTC) → '2026-11-12T02:59:19.098Z' */
    public static function iso(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        return str_replace(' ', 'T', substr($utc, 0, 23)) . 'Z';
    }
}
