<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\DepartmentService;
use App\Config\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\RoleMiddleware;
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
        }

        $this->success($nurses);
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

        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $this->validationError(['username' => 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z A-Z 0-9 . _ - (3-100 ตัวอักษร)']);
        }
        if ($username !== '' && strlen($password) < 6) {
            $this->validationError(['password' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร']);
        }

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
                $this->error('บันทึกพยาบาลแล้ว แต่สร้างบัญชีเข้าใช้งานไม่สำเร็จ: ' . $e->getMessage(), 500);
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

        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($username !== '' && !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $this->validationError(['username' => 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z A-Z 0-9 . _ - (3-100 ตัวอักษร)']);
        }
        if ($password !== '' && strlen($password) < 6) {
            $this->validationError(['password' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร']);
        }

        $userModel = new User();
        $linked = $userModel->findByNurseId($id);

        // All credential checks must pass before touching the nurse record
        if ($username !== '') {
            if ($linked === null && strlen($password) < 6) {
                $this->validationError(['password' => 'บัญชีใหม่ต้องมีรหัสผ่านอย่างน้อย 6 ตัวอักษร']);
            }
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
                    $this->error('บันทึกพยาบาลแล้ว แต่สร้างบัญชีเข้าใช้งานไม่สำเร็จ: ' . $e->getMessage(), 500);
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
}
