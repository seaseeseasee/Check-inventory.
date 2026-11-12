<?php
declare(strict_types=1);

namespace App\Services\Logging;

/**
 * อ่าน/ค้นหาไฟล์ archive จากหน้าเว็บ — อ่านแบบ stream ทีละบรรทัด ไม่นำข้อมูลกลับเข้า MySQL
 * ตรวจ SHA-256 กับค่าที่บันทึกไว้ก่อนอ่านทุกครั้ง: ไฟล์ถูกแก้/เสีย = ไม่แสดงผล
 */
final class ArchiveReader
{
    public const MAX_MATCHES = 5000;

    /** ไฟล์หลักถ้า hash ตรง ไม่งั้นใช้สำเนา — คืน [path, 'main'|'backup'] หรือโยน error */
    public static function locate(array $archive): array
    {
        $tried = [];
        foreach (['main' => ArchiveConfig::root(), 'backup' => ArchiveConfig::backupRoot()] as $which => $root) {
            $f = ArchiveConfig::resolve($root, (string)$archive['file_path']);
            if ($f === null) {
                throw new \RuntimeException('ชื่อไฟล์ archive ไม่ถูกต้อง');
            }
            if (!is_file($f)) {
                $tried[] = "{$which}: ไม่พบไฟล์";
                continue;
            }
            if (hash_file('sha256', $f) !== $archive['sha256']) {
                $tried[] = "{$which}: hash ไม่ตรง";
                continue;
            }
            return [$f, $which];
        }
        throw new \RuntimeException('ไม่มีไฟล์ archive ที่ตรวจ hash ผ่าน (' . implode(', ', $tried) . ')');
    }

    /** ตรวจทั้งสองสำเนา — สำหรับปุ่ม "ตรวจ Hash" */
    public static function verifyBoth(array $archive): array
    {
        $out = [];
        foreach (['main' => ArchiveConfig::root(), 'backup' => ArchiveConfig::backupRoot()] as $which => $root) {
            $f = ArchiveConfig::resolve($root, (string)$archive['file_path']);
            if ($f === null || !is_file($f)) {
                $out[$which] = ['ok' => false, 'message' => 'ไม่พบไฟล์'];
                continue;
            }
            $sha = hash_file('sha256', $f);
            $out[$which] = ['ok' => $sha === $archive['sha256'], 'sha256' => $sha, 'size' => filesize($f),
                'message' => $sha === $archive['sha256'] ? 'ถูกต้อง' : 'hash ไม่ตรงกับที่บันทึกไว้'];
        }
        return $out;
    }

    /**
     * @param array $f q, user, action, status, severity
     * @return array{rows: array, total: int, truncated: bool, source: string}
     */
    public static function search(array $archive, array $f, int $offset, int $limit): array
    {
        [$path, $source] = self::locate($archive);
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('เปิดไฟล์ archive ไม่ได้');
        }
        $q = mb_strtolower((string)($f['q'] ?? ''), 'UTF-8');
        $matches = 0;
        $rows = [];
        $truncated = false;
        try {
            while (($line = gzgets($gz)) !== false) {
                $r = json_decode($line, true);
                if (!is_array($r)) {
                    continue;
                }
                if (($f['user'] ?? '') !== '' && (string)($r['username'] ?? '') !== $f['user'] && (string)($r['user_id'] ?? '') !== $f['user']) {
                    continue;
                }
                foreach (['action', 'status', 'severity'] as $k) {
                    if (($f[$k] ?? '') !== '' && (string)($r[$k] ?? '') !== $f[$k]) {
                        continue 2;
                    }
                }
                if ($q !== '') {
                    $hay = mb_strtolower(implode(' ', [$r['description'] ?? '', $r['action'] ?? '', $r['username'] ?? '',
                        $r['record_id'] ?? '', $r['ip_address'] ?? '', $r['event_id'] ?? '']), 'UTF-8');
                    if (!str_contains($hay, $q)) {
                        continue;
                    }
                }
                if ($matches >= $offset && count($rows) < $limit) {
                    $rows[] = $r;
                }
                if (++$matches >= self::MAX_MATCHES) {
                    $truncated = true;
                    break;
                }
            }
        } finally {
            gzclose($gz);
        }
        // ลำดับตามไฟล์ = เก่าไปใหม่ (id)
        return ['rows' => array_map([self::class, 'present'], $rows), 'total' => $matches, 'truncated' => $truncated, 'source' => $source];
    }

    /** ในไฟล์เก็บ JSON เป็นข้อความดิบ (ให้ hash chain ตรวจได้) — แปลงเป็นโครงสร้างตอนแสดงผล */
    private static function json(mixed $v): mixed
    {
        return is_string($v) ? json_decode($v, true) : $v;
    }

    /** อ่านแถว hash chain (หมวด audit ที่มี chain_seq) จากไฟล์ — ใช้โดย bin/logs-verify.php */
    public static function chainRows(array $archive): \Generator
    {
        [$path] = self::locate($archive);
        $gz = gzopen($path, 'rb');
        try {
            while (($line = gzgets($gz)) !== false) {
                $r = json_decode($line, true);
                if (is_array($r) && isset($r['chain_seq'])) {
                    yield $r;
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    private static function present(array $r): array
    {
        return [
            'event_id' => $r['event_id'] ?? null,
            'category' => $r['category'] ?? null,
            'action' => $r['action'] ?? null,
            'module' => $r['module'] ?? null,
            'record_type' => $r['record_type'] ?? null,
            'record_id' => $r['record_id'] ?? null,
            'username' => $r['username'] ?? null,
            'user_role' => $r['user_role'] ?? null,
            'description' => $r['description'] ?? null,
            'status' => $r['status'] ?? null,
            'severity' => $r['severity'] ?? null,
            'ip_address' => $r['ip_address'] ?? null,
            'user_agent' => $r['user_agent'] ?? null,
            'repeat_count' => (int)($r['repeat_count'] ?? 1),
            'created_at' => LogQueryService::iso($r['created_at'] ?? null),
            'old_value' => self::json($r['old_value'] ?? null),
            'new_value' => self::json($r['new_value'] ?? null),
            'context' => self::json($r['context'] ?? null),
            'has_changes' => !empty($r['has_changes']),
        ];
    }
}
