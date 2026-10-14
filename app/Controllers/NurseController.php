<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\RoleMiddleware;
use App\Models\Nurse;
use App\Models\User;

class NurseController extends Controller
{
    private InspectionService $inspectionService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    public function index(): void
    {
        $nurses = $this->inspectionService->getAllNurses();

        // Login usernames are admin-only info; staff use this list just to pick inspectors
        if ($this->roleMiddleware->isAdmin()) {
            $logins = (new User())->getUsernamesByNurseIds(array_column($nurses, 'id'));
            foreach ($nurses as &$nurse) {
                $nurse['login_username'] = $logins[(string)$nurse['id']] ?? null;
            }
            unset($nurse);
        }

        $this->success($nurses);
    }

    public function store(): void
    {
        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'id' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'department' => 'required|string|max:255',
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
            'department' => $input['department'],
            'phone' => $input['phone'] ?? '',
            'start_text' => $input['start_text'] ?? '',
            'end_text' => $input['end_text'] ?? '',
            'note' => $input['note'] ?? '',
            'active' => $input['active'] ?? true,
        ];

        $nurseModel->create($data);

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
        $input = $this->getInput();
        
        $nurseModel = new Nurse();
        $existing = $nurseModel->find($id);
        if (!$existing) {
            $this->notFound('Nurse not found');
        }

        $validator = new Validator($input, [
            'name' => 'required|string|max:255',
            'department' => 'required|string|max:255',
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
            'department' => $input['department'],
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

        $nurseModel->update($id, $data);

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
        $nurseModel = new Nurse();
        $existing = $nurseModel->find($id);
        if (!$existing) {
            $this->notFound('Nurse not found');
        }

        // Soft delete - just mark as inactive
        $nurseModel->update($id, ['active' => false]);

        // A พักใช้งาน nurse must not be able to log in
        $userModel = new User();
        $linked = $userModel->findByNurseId($id);
        if ($linked !== null) {
            $userModel->updateUser($linked['id'], ['active' => false]);
        }

        $this->success(['id' => $id]);
    }
}
