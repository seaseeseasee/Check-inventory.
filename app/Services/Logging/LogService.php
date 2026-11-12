<?php
declare(strict_types=1);

namespace App\Services\Logging;

use App\Config\Database;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;

/**
 * จุดเขียน log กลางของทั้งระบบ (ตาราง refer_logs.log_events / log_changes — migration 017)
 *
 * กฎเหล็ก: ห้ามโยน exception ออกไปเด็ดขาด — เขียน log ไม่ได้ต้องไม่ทำให้งานหลักล้ม
 *          เขียนลง DB ไม่ได้ → storage/logs/fallback-YYYY-MM-DD.log + error_log
 *
 * ตัวเลือก ($opt) ที่ทุกเมธอดรับ:
 *   description, record_type, record_id, status (SUCCESS|FAILED), severity (INFO|WARNING|ERROR|CRITICAL),
 *   context (array เล็ก ๆ), user (['id','username','role'] หรือ null = ไม่ระบุตัว — ไม่ส่ง = อ่านจาก session),
 *   module, created_at (DateTimeInterface), event_id, ip, no_request (true = ไม่แนบข้อมูล HTTP),
 *   tx_bound (true = ถ้างานหลักอยู่ใน transaction ให้รอผลตอนจบ request — audit ทำแบบนี้เสมอ),
 *   dedupe (true = เหตุการณ์เดียวกันจากผู้ใช้/IP/path เดิมภายใน 60 วิ นับเพิ่มในแถวเดิม — security ทำแบบนี้เสมอ)
 */
final class LogService
{
    public const CATEGORIES = ['activity', 'audit', 'auth', 'security', 'system', 'data_access'];
    private const STATUSES = ['SUCCESS', 'FAILED'];
    private const SEVERITIES = ['INFO', 'WARNING', 'ERROR', 'CRITICAL'];

    private const MAX_CONTEXT_BYTES = 4096;
    private const MAX_CHANGE_BYTES = 16384;
    private const DEDUPE_SECONDS = 60;
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** audit ที่เกิดระหว่าง transaction ของงานหลัก — รอผลตอนจบ request (ดู flushDeferred) */
    private static array $deferred = [];
    private static bool $shutdownHooked = false;
    private static bool $authOrSecurityLogged = false;
    private static bool $systemLogged = false;

    // ---------------------------------------------------------------- API

    public static function auth(string $action, string $status = 'SUCCESS', array $opt = []): void
    {
        $opt['status'] = $status;
        $opt['severity'] ??= $status === 'FAILED' ? 'WARNING' : 'INFO';
        self::record('auth', $action, $opt['module'] ?? 'auth', $opt);
    }

    public static function security(string $action, string $description, string $severity = 'WARNING', array $opt = []): void
    {
        $opt['description'] = $description;
        $opt['severity'] = $severity;
        $opt['status'] ??= 'FAILED';
        self::record('security', $action, $opt['module'] ?? 'security', $opt, true);
    }

    public static function system(string $action, string $description, string $severity = 'ERROR', array $opt = []): void
    {
        $opt['description'] = $description;
        $opt['severity'] = $severity;
        $opt['status'] ??= 'FAILED';
        self::record('system', $action, $opt['module'] ?? 'system', $opt);
    }

    public static function dataAccess(string $action, string $module, ?string $recordType = null, ?string $recordId = null, array $opt = []): void
    {
        $opt['record_type'] = $recordType;
        $opt['record_id'] = $recordId;
        self::record('data_access', $action, $module, $opt);
    }

    public static function activity(string $action, string $module, array $opt = []): void
    {
        self::record('activity', $action, $module, $opt);
    }

    /**
     * CREATE/UPDATE/DELETE/RESTORE/APPROVE/REJECT ของข้อมูล — old/new ควรมีเฉพาะช่องที่เกี่ยวข้อง
     * ถ้างานหลักยังอยู่ใน transaction จะรอผลตอนจบ request: สำเร็จ → SUCCESS, error → FAILED
     */
    public static function audit(string $action, string $module, ?string $recordType, ?string $recordId, ?array $old, ?array $new, array $opt = []): void
    {
        try {
            $row = self::buildRow('audit', $action, $module, $opt + ['record_type' => $recordType, 'record_id' => $recordId]);
            $oldJson = LogSanitizer::json($old, self::MAX_CHANGE_BYTES);
            $newJson = LogSanitizer::json($new, self::MAX_CHANGE_BYTES);
            if (self::$shutdownHooked && self::mainDbInTransaction()) {
                self::$deferred[] = ['audit', $row, $oldJson, $newJson];
                return;
            }
            self::writeAudit($row, $oldJson, $newJson);
        } catch (\Throwable $e) {
            self::fallback($row ?? ['category' => 'audit', 'action' => $action], $e, ['old' => $old, 'new' => $new]);
        }
    }

    /**
     * คืน [old, new] เฉพาะช่องที่ค่าเปลี่ยนจริง — $changes คือข้อมูลที่จะเขียน (เช่น $data ที่ส่งเข้า update)
     * เทียบแบบหลวม: true/1, "5"/5.0 ถือว่าเท่ากัน; ไม่มีอะไรเปลี่ยน → [null, null]
     */
    public static function diff(?array $before, array $changes, array $skip = ['updated_at', 'created_at', 'password_hash']): array
    {
        $old = [];
        $new = [];
        foreach ($changes as $field => $value) {
            if (in_array($field, $skip, true)) {
                continue;
            }
            $prev = $before[$field] ?? null;
            if (self::normalize($prev) === self::normalize($value)) {
                continue;
            }
            $old[$field] = $prev;
            $new[$field] = $value;
        }
        return [$old ?: null, $new ?: null];
    }

    /** ตัดเฉพาะช่องที่ต้องการจากแถว (ใช้ทำ snapshot ตอน create/delete) */
    public static function pick(?array $row, array $fields): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = array_intersect_key($row, array_flip($fields));
        return $out ?: null;
    }

    private static function normalize(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
        }
        return (string)$v;
    }

    // ---------------------------------------------------------------- ใช้โดย RequestLogger

    public static function markShutdownHooked(): void
    {
        self::$shutdownHooked = true;
    }

    public static function wasAuthOrSecurityLogged(): bool
    {
        return self::$authOrSecurityLogged;
    }

    public static function wasSystemLogged(): bool
    {
        return self::$systemLogged;
    }

    public static function flushDeferred(int $httpStatus): void
    {
        $items = self::$deferred;
        self::$deferred = [];
        foreach ($items as [$type, $row, $oldJson, $newJson]) {
            if ($httpStatus >= 400) {
                $row['status'] = 'FAILED';
                $row['severity'] = 'WARNING';
                $row['description'] = LogSanitizer::text(
                    trim(($row['description'] ?? '') . " (ไม่มีผล: คำขอจบด้วย HTTP {$httpStatus})"), 500);
            }
            try {
                if ($type === 'audit') {
                    self::writeAudit($row, $oldJson, $newJson);
                } else {
                    $pdo = LogConnection::get() ?? throw new \RuntimeException('log database unavailable');
                    self::insertEvent($pdo, $row);
                }
            } catch (\Throwable $e) {
                self::fallback($row, $e, ['old' => $oldJson, 'new' => $newJson]);
            }
        }
    }

    // ---------------------------------------------------------------- hash chain (ใช้ร่วมกับ bin/logs-verify.php)

    /** ข้อความที่ใช้คำนวณ hash — ห้ามเปลี่ยนลำดับ/ชุดฟิลด์ ไม่งั้น verify แถวเก่าไม่ผ่าน */
    public static function canonical(array $row, ?string $oldJson, ?string $newJson): string
    {
        $fields = [];
        foreach (['event_id', 'category', 'action', 'module', 'record_type', 'record_id', 'user_id', 'username',
                     'description', 'status', 'severity', 'ip_address', 'created_at'] as $k) {
            $v = $row[$k] ?? null;
            $fields[$k] = $v === null ? null : (string)$v;
        }
        $fields['old_value'] = $oldJson;
        $fields['new_value'] = $newJson;
        return json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function chainHash(string $prevHash, string $canonical): string
    {
        return hash('sha256', $prevHash . "\n" . $canonical);
    }

    /** ULID: เวลา ms 48 บิต + สุ่ม 80 บิต (Crockford base32) — ส่ง $randomSeed เพื่อให้ได้ค่าเดิมทุกครั้ง (ใช้ตอนนำเข้าข้อมูลเก่า) */
    public static function ulid(?int $ms = null, ?string $randomSeed = null): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ms ??= (int)floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = $alphabet[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $bytes = $randomSeed !== null ? hash('sha256', $randomSeed, true) : random_bytes(16);
        $rand = '';
        for ($i = 0; $i < 16; $i++) {
            $rand .= $alphabet[ord($bytes[$i]) % 32];
        }
        return $time . $rand;
    }

    public static function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // ---------------------------------------------------------------- ภายใน

    private static function record(string $category, string $action, string $module, array $opt, bool $dedupe = false): void
    {
        $row = null;
        try {
            $row = self::buildRow($category, $action, $module, $opt);
            // tx_bound: เหตุการณ์ที่ "สำเร็จ" ได้ก็ต่อเมื่องานหลัก commit (เช่น เปลี่ยนบทบาท) → รอผลแบบเดียวกับ audit
            if (!empty($opt['tx_bound']) && self::$shutdownHooked && self::mainDbInTransaction()) {
                self::$deferred[] = ['event', $row, null, null];
                return;
            }
            $pdo = LogConnection::get();
            if ($pdo === null) {
                throw new \RuntimeException('log database unavailable');
            }
            if (($dedupe || !empty($opt['dedupe'])) && self::bumpDuplicate($pdo, $row)) {
                return;
            }
            self::insertEvent($pdo, $row);
        } catch (\Throwable $e) {
            self::fallback($row ?? ['category' => $category, 'action' => $action], $e);
        }
    }

    private static function buildRow(string $category, string $action, string $module, array $opt): array
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'system';
        }
        $user = array_key_exists('user', $opt) ? $opt['user'] : RequestContext::user();
        $withRequest = empty($opt['no_request']);
        $createdAt = ($opt['created_at'] ?? null) instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($opt['created_at'])->setTimezone(new DateTimeZone('UTC'))
            : self::utcNow();
        $status = strtoupper((string)($opt['status'] ?? 'SUCCESS'));
        $severity = strtoupper((string)($opt['severity'] ?? 'INFO'));

        if ($category === 'auth' || $category === 'security') {
            self::$authOrSecurityLogged = true;
        } elseif ($category === 'system') {
            self::$systemLogged = true;
        }

        return [
            'event_id' => (string)($opt['event_id'] ?? self::ulid((int)$createdAt->format('Uv'))),
            'request_id' => $withRequest && RequestContext::isHttp() ? RequestContext::requestId() : null,
            'category' => $category,
            'action' => LogSanitizer::text($action, 64) ?: 'unknown',
            'module' => LogSanitizer::text($module, 40) ?: 'unknown',
            'record_type' => LogSanitizer::text(isset($opt['record_type']) ? (string)$opt['record_type'] : null, 40),
            'record_id' => LogSanitizer::text(isset($opt['record_id']) ? (string)$opt['record_id'] : null, 100),
            'user_id' => isset($user['id']) ? LogSanitizer::text((string)$user['id'], 100) : null,
            'username' => isset($user['username']) ? LogSanitizer::text((string)$user['username'], 100) : null,
            'user_role' => isset($user['role']) ? LogSanitizer::text((string)$user['role'], 20) : null,
            'description' => LogSanitizer::text(isset($opt['description']) ? (string)$opt['description'] : null, 500),
            'status' => in_array($status, self::STATUSES, true) ? $status : 'SUCCESS',
            'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'INFO',
            'ip_address' => array_key_exists('ip', $opt) ? $opt['ip'] : ($withRequest ? RequestContext::ip() : null),
            'user_agent' => $withRequest ? RequestContext::userAgent() : null,
            'http_method' => $withRequest ? RequestContext::method() : null,
            'path' => $withRequest ? RequestContext::path() : null,
            'http_status' => isset($opt['http_status']) ? (int)$opt['http_status'] : null,
            'context' => LogSanitizer::json($opt['context'] ?? null, self::MAX_CONTEXT_BYTES),
            'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
        ];
    }

    private static function insertEvent(PDO $pdo, array $row, bool $hasChanges = false): void
    {
        $row['has_changes'] = $hasChanges ? 1 : 0;
        $row['last_seen_at'] = $row['created_at'];
        $cols = array_keys($row);
        $sql = 'INSERT INTO log_events (`' . implode('`, `', $cols) . '`) VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')';
        $pdo->prepare($sql)->execute(array_values($row));
    }

    /** เหตุการณ์ security เดียวกัน (action + IP + path + ผู้ใช้) ภายใน 60 วิ → นับเพิ่มในแถวเดิม */
    private static function bumpDuplicate(PDO $pdo, array $row): bool
    {
        $since = self::utcNow()->modify('-' . self::DEDUPE_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
        $stmt = $pdo->prepare(
            "SELECT id FROM log_events
             WHERE category = ? AND action = ? AND created_at >= ?
               AND ip_address <=> ? AND path <=> ? AND user_id <=> ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$row['category'], $row['action'], $since, $row['ip_address'], $row['path'], $row['user_id']]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }
        $pdo->prepare('UPDATE log_events SET repeat_count = repeat_count + 1, last_seen_at = ? WHERE id = ?')
            ->execute([$row['created_at'], $id]);
        return true;
    }

    private static function writeAudit(array $row, ?string $oldJson, ?string $newJson): void
    {
        $pdo = LogConnection::get();
        if ($pdo === null) {
            throw new \RuntimeException('log database unavailable');
        }
        if ((int)$pdo->query("SELECT GET_LOCK('refer_logs.audit_chain', 5)")->fetchColumn() !== 1) {
            throw new \RuntimeException('audit chain lock timeout');
        }
        try {
            // หัวสายอยู่ใน log_chain_head (019) — ไม่ใช้ MAX(chain_seq) เพราะแถวท้ายสายอาจถูกย้ายไป archive แล้ว
            // ไม่มีแถวหัวสาย (ฐานใหม่) → เริ่มจากแถวล่าสุดใน log_changes หรือ genesis
            $head = $pdo->query('SELECT last_seq, last_hash FROM log_chain_head WHERE id = 1')->fetch();
            $hasHeadRow = (bool)$head;
            if (!$head) {
                $tail = $pdo->query('SELECT chain_seq AS last_seq, row_hash AS last_hash FROM log_changes ORDER BY chain_seq DESC LIMIT 1')->fetch();
                $head = $tail ?: null;
            }
            $seq = $head ? (int)$head['last_seq'] + 1 : 1;
            $prev = $head ? (string)$head['last_hash'] : self::GENESIS_HASH;
            $hash = self::chainHash($prev, self::canonical($row, $oldJson, $newJson));

            $pdo->beginTransaction();
            self::insertEvent($pdo, $row, true);
            $pdo->prepare('INSERT INTO log_changes (event_id, old_value, new_value, chain_seq, prev_hash, row_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$row['event_id'], $oldJson, $newJson, $seq, $prev, $hash]);
            // บัญชีแอป UPDATE ได้แค่ 2 คอลัมน์ (019) — ON DUPLICATE KEY UPDATE ต้องการสิทธิ์คอลัมน์ id ด้วย จึงแยก INSERT/UPDATE
            $pdo->prepare($hasHeadRow
                ? 'UPDATE log_chain_head SET last_seq = ?, last_hash = ? WHERE id = 1'
                : 'INSERT INTO log_chain_head (last_seq, last_hash, id) VALUES (?, ?, 1)')
                ->execute([$seq, $hash]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            try {
                $pdo->query("SELECT RELEASE_LOCK('refer_logs.audit_chain')");
            } catch (\Throwable) {
                // lock หลุดเองเมื่อ connection ปิด
            }
        }
    }

    private static function mainDbInTransaction(): bool
    {
        try {
            return Database::getConnection()->inTransaction();
        } catch (\Throwable) {
            return false;
        }
    }

    /** เขียน DB ไม่ได้ → เก็บลงไฟล์ (JSON 1 บรรทัด) ให้ตามเก็บย้อนหลังได้ */
    private static function fallback(array $row, \Throwable $error, array $extra = []): void
    {
        try {
            error_log('LOG write failed (' . ($row['category'] ?? '?') . ' ' . ($row['action'] ?? '?') . '): ' . $error->getMessage());
            $dir = (string)(getenv('LOG_FALLBACK_PATH') ?: __DIR__ . '/../../../storage/logs');
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
            $line = json_encode(['row' => $row, 'extra' => LogSanitizer::redact($extra), 'error' => $error->getMessage()],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            @file_put_contents($dir . '/fallback-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // ไม่มีที่ไปแล้ว — ห้ามโยนต่อ
        }
    }
}
