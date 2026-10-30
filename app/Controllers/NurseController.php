<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\DepartmentService;
use App\Config\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\RoleMiddleware;
use App\Models\AuditLog;
use App\Models\Nurse;
use App\Models\User;

class NurseController extends Controller
{
    private InspectionService $inspectionService;
    private DepartmentService $departmentService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->departmentService = new DepartmentService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    public function index(): void
    {
        $nurses = $this->inspectionService->getAllNurses();

        // Login usernames are admin-only info; staff use this list just to pick inspectors
        if ($this->roleMiddleware->isAdmin()) {
            $accounts = (new User())->getAccountsByNurseIds(array_column($nurses, 'id'));
            foreach ($nurses as &$nurse) {
                $account = $accounts[(string)$nurse['id']] ?? null;
                $nurse['login_username'] = $account['username'] ?? null;
                $nurse['account'] = $account;   // หน้าจัดการผู้ใช้: บทบาท/สถานะ/เข้าสู่ระบบล่าสุด
            }
            unset($nurse);
        } else {
            $nurses = self::forSelection($nurses);
        }

        $this->success($nurses);
    }

    /**
     * รายชื่อสำหรับเลือกผู้ตรวจ (staff) — ตัดเบอร์โทร/หมายเหตุออก
     * เบอร์โทร = ชื่อผู้ใช้ของบัญชีพยาบาล จึงไม่ควรเปิดให้ทุกคนดูรายชื่อเบอร์ทั้งหมดได้
     */
    public static function forSelection(array $nurses): array
    {
        return array_map(static function (array $nurse): array {
            unset($nurse['phone'], $nurse['note']);
            return $nurse;
        }, $nurses);
    }

    public function store(): void
    {
        // ตารางพยาบาลเป็นหน้า admin — ตรวจสิทธิ์ฝั่งเซิร์ฟเวอร์ก่อนแตะข้อมูล
        $this->roleMiddleware->requireAdmin();

        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'id' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'phone' => 'string|max:100',
            'start_text' => 'string|max:100',
            'end_text' => 'string|max:100',
            'note' => 'string',
            'active' => 'boolean',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        [$username, $password] = $this->accountCredentials($input, true);

        $nurseModel = new Nurse();

        // Check duplicate ID
        if ($nurseModel->find($input['id'])) {
            $this->error('Nurse ID already exists', 409);
        }

        $userModel = new User();
        if ($username !== '' && $userModel->findByUsername($username)) {
            $this->error('ชื่อผู้ใช้นี้ถูกใช้แล้ว', 409, ['code' => 'USERNAME_EXISTS']);
        }

        $data = [
            'id' => $input['id'],
            'name' => $input['name'],
            'phone' => $input['phone'] ?? '',
            'start_text' => $input['start_text'] ?? '',
            'end_text' => $input['end_text'] ?? '',
            'note' => $input['note'] ?? '',
            'active' => $input['active'] ?? true,
        ];

        // ผูกพยาบาลกับวอร์ดจากทะเบียน master (department_id ที่เลือก หรือชื่อข้อความเดิมตอนนำเข้า Excel)
        // ชื่อที่เก็บลง refer_nurses.department จะคัดจากแถว master เสมอ ไม่ใช้ text ที่ client ส่งมา
        // ทั้งสองแถว (สร้างวอร์ดใหม่ถ้าจำเป็น + สร้างพยาบาล) อยู่ใน transaction เดียวกัน
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $department = $this->departmentService->resolveForNurse($input);
            $data['department'] = $department['name'];
            $data['department_id'] = $department['id'];
            $nurseModel->create($data);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($username !== '') {
            try {
                $userModel->createUser([
                    'username' => $username,
                    'password' => $password,
                    'full_name' => $data['name'],
                    'role' => User::ROLE_STAFF,
                    'nurse_id' => $data['id'],
                    'active' => (bool)$data['active'],
                ]);
            } catch (\Throwable $e) {
                // ข้อความ exception อาจมี SQL/ชื่อตาราง — เก็บใน log ฝั่งเซิร์ฟเวอร์เท่านั้น
                error_log('nurse store createUser failed: ' . $e->getMessage());
                $this->error('บันทึกพยาบาลแล้ว แต่สร้างบัญชีเข้าใช้งานไม่สำเร็จ กรุณาลองใหม่', 500);
            }
        }

        $this->success(['id' => $data['id']], 201);
    }

    public function update(string $id): void
    {
        $this->roleMiddleware->requireAdmin();

        $input = $this->getInput();
        
        $nurseModel = new Nurse();
        $existing = $nurseModel->find($id);
        if (!$existing) {
            $this->notFound('Nurse not found');
        }

        $validator = new Validator($input, [
            'name' => 'required|string|max:255',
            'phone' => 'string|max:100',
            'start_text' => 'string|max:100',
            'end_text' => 'string|max:100',
            'note' => 'string',
            'active' => 'boolean',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $data = [
            'name' => $input['name'],
            'phone' => $input['phone'] ?? '',
            'start_text' => $input['start_text'] ?? '',
            'end_text' => $input['end_text'] ?? '',
            'note' => $input['note'] ?? '',
            'active' => $input['active'] ?? $existing['active'],
        ];

        $userModel = new User();
        $linked = $userModel->findByNurseId($id);
        [$username, $password] = $this->accountCredentials($input, $linked === null);

        // All credential checks must pass before touching the nurse record
        if ($username !== '') {
            $dup = $userModel->findByUsername($username);
            if ($dup && ($linked === null || $dup['id'] !== $linked['id'])) {
                $this->error('ชื่อผู้ใช้นี้ถูกใช้แล้ว', 409, ['code' => 'USERNAME_EXISTS']);
            }
        }

        // อ่าน nurse เดิม + เขียนยอดใหม่ + (กรณีกรอกชื่อใหม่) สร้างแถวในทะเบียนวอร์ด
        // ต้องสำเร็จพร้อมกัน จึงครอบด้วย transaction เดียว
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $department = $this->departmentService->resolveForNurse($input, $existing['department_id'] ?? null);
            $data['department'] = $department['name'];
            $data['department_id'] = $department['id'];
            $nurseModel->update($id, $data);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($username !== '') {
            if ($linked === null) {
                try {
                    $userModel->createUser([
                        'username' => $username,
                        'password' => $password,
                        'full_name' => $data['name'],
                        'role' => User::ROLE_STAFF,
                        'nurse_id' => $id,
                        'active' => (bool)$data['active'],
                    ]);
                } catch (\Throwable $e) {
                    error_log('nurse update createUser failed: ' . $e->getMessage());
                    $this->error('บันทึกพยาบาลแล้ว แต่สร้างบัญชีเข้าใช้งานไม่สำเร็จ กรุณาลองใหม่', 500);
                }
            } else {
                $updates = [
                    'username' => $username,
                    'full_name' => $data['name'],
                    'nurse_id' => $id,
                    'active' => (bool)$data['active'],
                ];
                if ($password !== '') {
                    $updates['password'] = $password;
                }
                $userModel->updateUser($linked['id'], $updates);
            }
        } elseif ($linked !== null) {
            // Keep the linked account in sync with the nurse profile (name/active)
            $userModel->updateUser($linked['id'], [
                'full_name' => $data['name'],
                'active' => (bool)$data['active'],
            ]);
        }

        $this->success(['id' => $id]);
    }

    /**
     * ชื่อผู้ใช้/รหัสผ่านของบัญชีพยาบาลจากฟอร์ม
     *  ชื่อผู้ใช้ที่เป็นเบอร์โทร → เก็บเป็นตัวเลขล้วน (081-234-5678 → 0812345678)
     *  บัญชีใหม่ที่ไม่กรอกรหัสผ่าน → รหัสเริ่มต้น = เบอร์โทร (ใช้ได้เฉพาะชื่อผู้ใช้ที่เป็นเบอร์โทร)
     *  ทุกรหัสที่ตั้งจากที่นี่เป็นรหัสชั่วคราว — ผู้ใช้ถูกบังคับเปลี่ยนตอนเข้าสู่ระบบครั้งแรก (User::createUser/updateUser)
     * @return array{0: string, 1: string} [username, password] — password '' = ไม่เปลี่ยน
     */
    private function accountCredentials(array $input, bool $isNewAccount): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($username === '') {
            return ['', $password];
        }
        $phone = User::normalizePhone($username);
        if ($phone !== null) {
            $username = $phone;
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $this->validationError(['username' => 'ชื่อผู้ใช้ต้องเป็นเบอร์โทรศัพท์ (เช่น 0812345678) หรือ a-z A-Z 0-9 . _ - (3-100 ตัวอักษร)']);
        }
        if ($isNewAccount && $password === '') {
            if ($phone === null) {
                $this->validationError(['password' => 'ชื่อผู้ใช้ไม่ใช่เบอร์โทรศัพท์ — ต้องตั้งรหัสผ่านเริ่มต้นเอง (อย่างน้อย 6 ตัวอักษร)']);
            }
            $password = $phone;
        }
        if ($password !== '' && strlen($password) < 6) {
            $this->validationError(['password' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร']);
        }
        return [$username, $password];
    }

    /**
     * สวิตช์ "เปิดใช้งานพยาบาลคนนี้" — body: { active: bool }
     * เปิด/ปิดพยาบาลกับบัญชีที่ผูกกันพร้อมกันเสมอ: ปิด = ไม่แสดงตอนเลือกผู้ตรวจ + login ไม่ได้ / เปิด = กลับมาเหมือนเดิม
     */
    public function setActive(string $id): void
    {
        $this->roleMiddleware->requireAdmin();

        $nurseModel = new Nurse();
        $nurse = $nurseModel->find($id);
        if (!$nurse) {
            $this->notFound('Nurse not found');
        }
        $input = $this->getInput();
        if (!array_key_exists('active', $input) || !is_bool($input['active'])) {
            $this->validationError(['active' => 'ต้องเป็น true หรือ false']);
        }
        $active = $input['active'];

        $userModel = new User();
        $linked = $userModel->findByNurseId($id);
        if (!$active && $linked !== null && !empty($linked['active'])) {
            if ((string)$linked['id'] === (string)($this->roleMiddleware->getCurrentUser()['id'] ?? '')) {
                $this->error('ปิดใช้งานบัญชีที่คุณกำลังใช้อยู่ไม่ได้', 409);
            }
            if ($linked['role'] === User::ROLE_ADMIN && $userModel->countActiveAdmins() <= 1) {
                $this->error('ต้องมีผู้ดูแลระบบที่ใช้งานอยู่อย่างน้อย 1 บัญชี', 409);
            }
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $nurseModel->update($id, ['active' => $active ? 1 : 0]);
            if ($linked !== null && (bool)$linked['active'] !== $active) {
                $userModel->updateUser((string)$linked['id'], ['active' => $active]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $this->success(['id' => $id, 'active' => $active, 'account' => $linked !== null]);
    }

    /**
     * body: { mode: 'disable' | 'delete' } (ไม่ส่ง = disable เหมือนเดิม)
     * disable = พักใช้งานพยาบาล + ปิดบัญชีที่ผูกกัน
     * delete  = ลบถาวร (พร้อมบัญชีที่ผูกกัน) ได้เฉพาะคนที่ยังไม่มีประวัติในระบบ
     *           ถ้ามีจะตอบ 409 พร้อมจำนวน และ UI เสนอ "พักใช้งานแทน" — ประวัติตรวจรถ/คำขอเติมต้องอยู่ครบ
     */
    public function destroy(string $id): void
    {
        // พักใช้งานพยาบาล + ปิดบัญชีที่ผูกกัน ต้องสำเร็จทั้งคู่ ไม่งั้นข้อมูลจะขัดกัน
        $this->roleMiddleware->requireAdmin();

        $nurseModel = new Nurse();
        $existing = $nurseModel->find($id);
        if (!$existing) {
            $this->notFound('Nurse not found');
        }

        $userModel = new User();
        $linked = $userModel->findByNurseId($id);

        $db = Database::getConnection();
        if (($this->getInput()['mode'] ?? '') === 'delete') {
            $this->deletePermanently($db, $existing, $linked);
            return;
        }


        $db->beginTransaction();
        try {
            // Soft delete - just mark as inactive
            $nurseModel->update($id, ['active' => false]);

            // A พักใช้งาน nurse must not be able to log in
            if ($linked !== null) {
                $userModel->updateUser($linked['id'], ['active' => false]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $this->success(['id' => $id]);
    }

    private function deletePermanently(\PDO $db, array $nurse, ?array $linked): void
    {
        $id = (string)$nurse['id'];
        $currentUser = $this->roleMiddleware->getCurrentUser();
        if ($linked !== null && (string)$linked['id'] === (string)($currentUser['id'] ?? '')) {
            $this->error('ลบไม่ได้ เพราะเป็นบัญชีที่คุณกำลังใช้งานอยู่', 409, ['reason' => 'self']);
        }

        $count = function (string $sql, string $value) use ($db): int {
            $stmt = $db->prepare($sql);
            $stmt->execute([$value]);
            return (int)$stmt->fetchColumn();
        };
        $usage = [
            'checks' => $count('SELECT COUNT(*) FROM refer_checks WHERE nurse_id = ?', $id),
            'drafts' => $count('SELECT COUNT(*) FROM refer_drafts WHERE nurse_id = ?', $id),
            'restock' => $count('SELECT COUNT(*) FROM refer_restock_requests WHERE created_by = ?', $id),
            'restock_actions' => $linked !== null
                ? $count('SELECT COUNT(*) FROM refer_restock_actions WHERE by_id = ?', (string)$linked['id'])
                : 0,
        ];
        $inUse = function () use ($nurse, $usage): void {
            $this->error(
                'ลบ "' . $nurse['name'] . '" ถาวรไม่ได้ เพราะมีประวัติในระบบแล้ว (ตรวจรถ ' . $usage['checks']
                . ' ครั้ง, คำขอเติม ' . ($usage['restock'] + $usage['restock_actions']) . ' รายการ'
                . ($usage['drafts'] ? ', แบบร่างค้าง ' . $usage['drafts'] . ' รายการ' : '') . ')',
                409,
                ['reason' => 'nurse_in_use', 'usage' => $usage, 'suggestion' => 'disable', 'name' => $nurse['name']]
            );
        };
        if (array_sum($usage) > 0) {
            $inUse();
        }

        $db->beginTransaction();
        try {
            if ($linked !== null) {
                $db->prepare('DELETE FROM refer_users WHERE id = ?')->execute([(string)$linked['id']]);
            }
            $db->prepare('DELETE FROM refer_nurses WHERE id = ?')->execute([$id]);
            $db->commit();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // ฐานข้อมูลเก่าบางแห่งมี FK อื่นชี้มาที่พยาบาล/บัญชี (เช่น restock_actions → nurses) — ถือว่ามีประวัติ
            if ($e->getCode() === '23000') {
                $inUse();
            }
            throw $e;
        }

        if ($linked !== null) {
            AuditLog::record('user.delete', 'user', (string)$linked['id'], [
                'username' => $linked['username'],
                'nurse_id' => $id,
            ]);
        }
        AuditLog::record('nurse.delete', 'nurse', $id, ['name' => $nurse['name']]);

        $this->success(['id' => $id, 'mode' => 'deleted', 'account_deleted' => $linked !== null]);
    }
}
