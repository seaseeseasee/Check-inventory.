<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\RoleMiddleware;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Logging\LogQueryService;
use App\Services\Logging\LogService;

/**
 * หน้า Log Management (/api/logs/*)
 *
 * สิทธิ์: ทุก endpoint ต้องเป็น admin + ถือสิทธิ์ย่อยใน refer_user_permissions (UserPermission::ALL)
 *   - logs.view          ดูภาพรวม / ตาราง / รายละเอียด
 *   - logs.audit_detail  เห็นค่าก่อน/หลังแก้ไข (ไม่มีสิทธิ์ = ได้รายละเอียดแต่ไม่มี old/new)
 *   - logs.security      เห็นหมวด security และมุมมองความปลอดภัย
 *   - logs.export        ส่งออก CSV
 *   - archives.*         หน้า Archive (Phase 4)
 * ทุกการเปิดดู/ค้นหา/ส่งออก ถูกบันทึกเป็น data_access (module = logs)
 * ไม่มี endpoint แก้ไขหรือลบ log — และบัญชีแอปก็ไม่มีสิทธิ์ทำที่ระดับฐานข้อมูล (migration 018)
 */
class LogController extends Controller
{
    private RoleMiddleware $roles;

    public function __construct()
    {
        $this->roles = new RoleMiddleware();
        // หน้า Log อ่าน session อย่างเดียว (middleware ตรวจ/ต่ออายุไปแล้ว) — ปล่อย lock ไฟล์ session ทันที
        // ไม่งั้น query หนัก (เช่นค้นข้อความทั้งตาราง) ทำให้ request อื่นของผู้ใช้คนเดียวกันต้องรอคิว
        // $_SESSION ยังอ่านได้ตามปกติหลังปิด
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** สิทธิ์ของผู้ใช้ปัจจุบัน — หน้าเว็บใช้ตัดสินว่าจะแสดงแท็บ/ปุ่มใด (admin ที่ไม่มีสิทธิ์ได้รายการว่าง) */
    public function me(): void
    {
        $this->roles->requireAdmin();
        $id = (string)$this->roles->getCurrentUserId();
        $this->success([
            'permissions' => (new UserPermission())->forUser($id),
            'labels' => UserPermission::ALL,
        ]);
    }

    public function summary(): void
    {
        $this->roles->requirePermission(UserPermission::LOGS_VIEW);
        $days = (int)($this->getQuery('days', 7)) === 30 ? 30 : 7;
        // cache 60 วิ: ภาพรวมรวมยอดทั้งช่วง (30 วันที่ 1 ล้านแถว ~7 วิ) — หลายคนเปิดพร้อมกันไม่ต้องคำนวณซ้ำ
        $sec = $this->canSecurity();
        $cacheFile = sys_get_temp_dir() . "/refer-logsum-{$days}-" . ($sec ? 's' : 'n') . '.json';
        $data = null;
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 60) {
            $data = json_decode((string)@file_get_contents($cacheFile), true);
        }
        if (!is_array($data)) {
            $data = (new LogQueryService())->summary($days, $sec);
            $data['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        LogService::dataAccess('logs.view_dashboard', 'logs', null, null, [
            'description' => "เปิดดูภาพรวม Log ({$days} วัน)", 'dedupe' => true,
        ]);
        $this->success($data);
    }

    public function index(): void
    {
        $this->roles->requirePermission(UserPermission::LOGS_VIEW);
        $f = $this->filters();
        $page = max(1, min(100000, (int)$this->getQuery('page', 1)));
        $perPage = (int)$this->getQuery('per_page', 50);
        $perPage = in_array($perPage, LogQueryService::PER_PAGE, true) ? $perPage : 50;
        $sort = (string)$this->getQuery('sort', 'created_at');
        $dir = (string)$this->getQuery('dir', 'desc');

        $result = (new LogQueryService())->search($f, $page, $perPage, $sort, $dir);
        LogService::dataAccess('logs.search', 'logs', null, null, [
            'description' => 'ค้นหา/กรองประวัติ Log' . (($f['view'] ?? '') === 'security' ? ' (มุมมองความปลอดภัย)' : ''),
            'context' => ['filters' => array_filter($f, fn($v) => $v !== '' && $v !== null && $v !== false), 'page' => $page],
            'dedupe' => true,
        ]);
        $this->success($result);
    }

    public function options(): void
    {
        $this->roles->requirePermission(UserPermission::LOGS_VIEW);
        $this->success((new LogQueryService())->filterOptions($this->canSecurity()));
    }

    public function show(string $eventId): void
    {
        $this->roles->requirePermission(UserPermission::LOGS_VIEW);
        if (!preg_match('/^[0-9A-Z]{26}$/', $eventId)) {
            $this->notFound('ไม่พบรายการ Log');
        }
        $row = (new LogQueryService())->find($eventId);
        if (!$row || ($row['category'] === 'security' && !$this->canSecurity())) {
            $this->notFound('ไม่พบรายการ Log');
        }
        $canDetail = $this->roles->hasPermission(UserPermission::LOGS_AUDIT_DETAIL);
        if (!$canDetail) {
            unset($row['old_value'], $row['new_value']);
        }
        $row['can_view_changes'] = $canDetail;
        LogService::dataAccess('logs.view_detail', 'logs', 'log_event', $eventId, [
            'description' => 'เปิดดูรายละเอียด Log: ' . $row['action'] . ($canDetail && $row['has_changes'] ? ' (รวมค่าก่อน/หลังแก้ไข)' : ''),
        ]);
        $this->success($row);
    }

    /** CSV (UTF-8 + BOM ให้ Excel อ่านภาษาไทยได้) — สูงสุด 50,000 แถวต่อครั้ง */
    public function export(): void
    {
        $this->roles->requirePermission(UserPermission::LOGS_EXPORT);
        $f = $this->filters();
        $svc = new LogQueryService();
        $total = $svc->count($f);
        if ($total > LogQueryService::EXPORT_LIMIT) {
            $this->error(sprintf('ข้อมูลมี %s แถว เกินกำหนด %s แถวต่อครั้ง กรุณาเลือกช่วงวันที่หรือตัวกรองให้แคบลง',
                number_format($total), number_format(LogQueryService::EXPORT_LIMIT)), 422);
        }

        LogService::dataAccess('logs.export', 'logs', null, null, [
            'description' => 'ส่งออก Log เป็น CSV (' . number_format($total) . ' แถว)',
            'context' => ['filters' => array_filter($f, fn($v) => $v !== '' && $v !== null && $v !== false), 'rows' => $total],
        ]);

        $tz = new \DateTimeZone(LogQueryService::TZ);
        $filename = 'logs-' . (new \DateTimeImmutable('now', $tz))->format('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['วันที่/เวลา (เวลาไทย)', 'ประเภท', 'กิจกรรม', 'Module', 'ผู้ใช้งาน', 'Record', 'รายละเอียด',
            'IP Address', 'สถานะ', 'ระดับ', 'จำนวนครั้ง', 'Event ID'], ',', '"', '');
        foreach ($svc->exportRows($f, LogQueryService::EXPORT_LIMIT) as $r) {
            $at = (new \DateTimeImmutable((string)$r['created_at']))->setTimezone($tz)->format('Y-m-d H:i:s');
            fputcsv($out, array_map([self::class, 'csvSafe'], [
                $at, $r['category'], $r['action'], $r['module'], $r['username'] ?? '',
                trim(($r['record_type'] ?? '') . ' ' . ($r['record_id'] ?? '')), $r['description'] ?? '',
                $r['ip_address'] ?? '', $r['status'], $r['severity'], (string)$r['repeat_count'], $r['event_id'],
            ]), ',', '"', '');
        }
        fclose($out);
        exit;
    }

    public function archives(): void
    {
        $this->roles->requireAdmin();
        if (!$this->roles->hasPermission(UserPermission::ARCHIVES_MANAGE) && !$this->roles->hasPermission(UserPermission::ARCHIVES_DOWNLOAD)) {
            Response::forbidden('Insufficient permissions');
        }
        LogService::dataAccess('logs.view_archives', 'logs', null, null, ['description' => 'เปิดดูรายการ Archive', 'dedupe' => true]);
        $this->success((new LogQueryService())->archives());
    }

    /** ค้นหา Log เก่าในไฟล์ archive 1 ไฟล์ (อ่านแบบ stream — ไม่นำกลับเข้าฐานข้อมูล) */
    public function archiveSearch(string $id): void
    {
        $archive = $this->archiveForRead((int)$id);
        if ($archive['category'] === 'security' && !$this->canSecurity()) {
            Response::forbidden('Insufficient permissions');
        }
        $str = fn(string $k, int $max) => mb_substr(trim((string)($this->getQuery($k, '') ?? '')), 0, $max);
        $f = [
            'q' => $str('q', 100), 'user' => $str('user', 100), 'action' => $str('action', 64),
            'status' => in_array($v = $str('status', 10), ['SUCCESS', 'FAILED'], true) ? $v : '',
            'severity' => in_array($v = $str('severity', 10), ['INFO', 'WARNING', 'ERROR', 'CRITICAL'], true) ? $v : '',
        ];
        $page = max(1, (int)$this->getQuery('page', 1));
        $perPage = 50;
        try {
            $result = \App\Services\Logging\ArchiveReader::search($archive, $f, ($page - 1) * $perPage, $perPage);
        } catch (\RuntimeException $e) {
            LogService::security('security.archive_integrity', "อ่าน archive {$archive['category']} {$archive['period']} ไม่ได้: " . $e->getMessage(),
                'CRITICAL', ['module' => 'logs', 'record_type' => 'log_archive', 'record_id' => (string)$archive['id']]);
            $this->error($e->getMessage(), 409);
        }
        if (!$this->roles->hasPermission(UserPermission::LOGS_AUDIT_DETAIL)) {
            foreach ($result['rows'] as &$r) {
                unset($r['old_value'], $r['new_value']);
            }
            unset($r);
        }
        LogService::dataAccess('logs.archive_search', 'logs', 'log_archive', (string)$archive['id'], [
            'description' => "ค้นหา Log ใน archive {$archive['category']} {$archive['period']}",
            'context' => ['filters' => array_filter($f), 'page' => $page], 'dedupe' => true,
        ]);
        $this->success($result + ['page' => $page, 'per_page' => $perPage, 'can_view_changes' => $this->roles->hasPermission(UserPermission::LOGS_AUDIT_DETAIL)]);
    }

    /** ดาวน์โหลดไฟล์ archive (ไฟล์ที่ตรวจ hash ผ่านเท่านั้น) */
    public function archiveDownload(string $id): void
    {
        $this->roles->requirePermission(UserPermission::ARCHIVES_DOWNLOAD);
        $archive = $this->archiveForRead((int)$id, false);
        try {
            [$path, $source] = \App\Services\Logging\ArchiveReader::locate($archive);
        } catch (\RuntimeException $e) {
            LogService::security('security.archive_integrity', "ดาวน์โหลด archive {$archive['category']} {$archive['period']} ไม่ได้: " . $e->getMessage(),
                'CRITICAL', ['module' => 'logs', 'record_type' => 'log_archive', 'record_id' => (string)$archive['id']]);
            $this->error($e->getMessage(), 409);
        }
        LogService::dataAccess('logs.archive_download', 'logs', 'log_archive', (string)$archive['id'], [
            'description' => "ดาวน์โหลดไฟล์ archive {$archive['category']} {$archive['period']} ({$archive['row_count']} แถว)",
            'severity' => 'WARNING',
            'context' => ['sha256' => $archive['sha256'], 'source' => $source],
        ]);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Archive-SHA256: ' . $archive['sha256']);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** ตรวจ SHA-256 ของไฟล์หลักและสำเนาเทียบกับค่าที่บันทึกไว้ */
    public function archiveVerify(string $id): void
    {
        $this->roles->requirePermission(UserPermission::ARCHIVES_MANAGE);
        $archive = $this->archiveForRead((int)$id, false);
        $result = \App\Services\Logging\ArchiveReader::verifyBoth($archive);
        $ok = $result['main']['ok'] && $result['backup']['ok'];
        LogService::dataAccess('logs.archive_verify', 'logs', 'log_archive', (string)$archive['id'], [
            'description' => "ตรวจ hash archive {$archive['category']} {$archive['period']}: " . ($ok ? 'ถูกต้องทั้งสองสำเนา' : 'พบปัญหา'),
            'status' => $ok ? 'SUCCESS' : 'FAILED', 'severity' => $ok ? 'INFO' : 'CRITICAL',
        ]);
        $this->success(['ok' => $ok, 'expected' => $archive['sha256'], 'copies' => $result]);
    }

    private function archiveForRead(int $id, bool $needView = true): array
    {
        if ($needView) {
            $this->roles->requirePermission(UserPermission::LOGS_VIEW);
        }
        if (!$this->roles->hasPermission(UserPermission::ARCHIVES_DOWNLOAD) && !$this->roles->hasPermission(UserPermission::ARCHIVES_MANAGE)) {
            Response::forbidden('Insufficient permissions');
        }
        $archive = (new LogQueryService())->archiveById($id);
        if (!$archive || !in_array($archive['status'], ['verified', 'backed_up', 'purged'], true) || !$archive['sha256']) {
            $this->notFound('ไม่พบไฟล์ archive ที่พร้อมใช้งาน');
        }
        return $archive;
    }

    /** รายชื่อผู้ดูแลระบบพร้อมสิทธิ์ดู Log — สำหรับแท็บ "สิทธิ์ผู้ดู Log" */
    public function permissions(): void
    {
        $this->roles->requirePermission(UserPermission::LOGS_VIEW);
        $grouped = (new UserPermission())->allGrouped();
        $admins = array_values(array_filter(
            (new User())->all(['id', 'username', 'full_name', 'role', 'active'], 'full_name'),
            fn($u) => $u['role'] === 'admin'
        ));
        foreach ($admins as &$u) {
            $u['active'] = (bool)$u['active'];
            $u['permissions'] = $grouped[(string)$u['id']] ?? [];
        }
        unset($u);
        $this->success([
            'users' => $admins,
            'labels' => UserPermission::ALL,
            'mine' => (new UserPermission())->forUser((string)$this->roles->getCurrentUserId()),
            'me' => (string)$this->roles->getCurrentUserId(),
        ]);
    }

    /**
     * ตั้งสิทธิ์ของ admin คนอื่น — ให้/ถอนได้เฉพาะสิทธิ์ที่ตัวเองถืออยู่ และแก้สิทธิ์ตัวเองไม่ได้
     * (กันการยกสิทธิ์ตัวเอง และกันถอนสิทธิ์ตัวเองจนไม่มีใครดู Log ได้)
     */
    public function updatePermissions(string $userId): void
    {
        $this->roles->requirePermission(UserPermission::LOGS_VIEW);
        $me = (string)$this->roles->getCurrentUserId();
        if ($userId === $me) {
            $this->error('แก้ไขสิทธิ์ของตัวเองไม่ได้ ให้ผู้ดูแลคนอื่นเป็นผู้ปรับให้', 409);
        }
        $target = (new User())->find($userId);
        if (!$target) {
            $this->notFound('ไม่พบผู้ใช้');
        }
        if ($target['role'] !== 'admin') {
            $this->error('ให้สิทธิ์ดู Log ได้เฉพาะบัญชีที่เป็นผู้ดูแลระบบ', 422);
        }
        $input = $this->getInput();
        $wanted = array_values(array_unique(array_filter((array)($input['permissions'] ?? []), 'is_string')));
        if (array_diff($wanted, array_keys(UserPermission::ALL))) {
            $this->validationError(['permissions' => 'มีสิทธิ์ที่ระบบไม่รู้จัก']);
        }
        if ($wanted && !in_array(UserPermission::LOGS_VIEW, $wanted, true)) {
            $this->validationError(['permissions' => 'ต้องมีสิทธิ์ "ดู Log" ก่อนจึงจะให้สิทธิ์อื่นได้']);
        }

        $model = new UserPermission();
        $mine = $model->forUser($me);
        $current = $model->forUser($userId);
        $touched = array_merge(array_diff($wanted, $current), array_diff($current, $wanted));
        $notMine = array_diff($touched, $mine);
        if ($notMine) {
            $labels = array_map(fn($p) => UserPermission::ALL[$p], $notMine);
            $this->error('ให้หรือถอนได้เฉพาะสิทธิ์ที่คุณถืออยู่เอง: ' . implode(', ', $labels), 403);
        }

        [$added, $removed] = $model->replace($userId, $wanted, $me);
        if ($added || $removed) {
            LogService::audit('permission.update', 'user', 'user', $userId,
                ['permissions' => $current], ['permissions' => $model->forUser($userId)],
                ['description' => 'ปรับสิทธิ์ดู Log ของ ' . $target['full_name'] . ' (' . $target['username'] . ')']);
            LogService::security('security.permission_change',
                'ปรับสิทธิ์ดู Log ของ ' . $target['username']
                . ($added ? ' — ให้: ' . implode(', ', $added) : '') . ($removed ? ' — ถอน: ' . implode(', ', $removed) : ''),
                'WARNING', ['module' => 'logs', 'record_type' => 'user', 'record_id' => $userId, 'status' => 'SUCCESS']);
        }
        $this->success(['user_id' => $userId, 'permissions' => $model->forUser($userId), 'added' => $added, 'removed' => $removed]);
    }

    // ---------------------------------------------------------------- ภายใน

    private function canSecurity(): bool
    {
        return $this->roles->hasPermission(UserPermission::LOGS_SECURITY);
    }

    /** อ่าน + ตรวจตัวกรองจาก query string (ค่าที่ไม่ถูกต้องถูกทิ้ง ไม่ส่งต่อถึง SQL) */
    private function filters(): array
    {
        $str = fn(string $k, int $max) => mb_substr(trim((string)($this->getQuery($k, '') ?? '')), 0, $max);
        $enum = fn(string $k, array $allowed) => in_array($v = $str($k, 20), $allowed, true) ? $v : '';
        $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v = $str($k, 10)) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : '';

        $canSecurity = $this->canSecurity();
        $view = $str('view', 20) === 'security' ? 'security' : '';
        if ($view === 'security' && !$canSecurity) {
            Response::forbidden('Insufficient permissions');
        }
        $category = $enum('category', LogService::CATEGORIES);
        if ($category === 'security' && !$canSecurity) {
            $category = '';
        }
        return [
            'q' => $str('q', 100),
            'category' => $category,
            'module' => $str('module', 40),
            'user_id' => $str('user_id', 100),
            'action' => $str('action', 64),
            'status' => $enum('status', ['SUCCESS', 'FAILED']),
            'severity' => $enum('severity', ['INFO', 'WARNING', 'ERROR', 'CRITICAL']),
            'from' => $date('from'),
            'to' => $date('to'),
            'view' => $view,
            'hide_security' => !$canSecurity,
        ];
    }

    /** กัน CSV/Formula injection — ค่าที่ขึ้นต้นด้วย = + - @ tab CR ให้ Excel อ่านเป็นข้อความ */
    private static function csvSafe(?string $v): string
    {
        $v = (string)$v;
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }
}
