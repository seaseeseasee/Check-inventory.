<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\RoleMiddleware;
use App\Services\DepartmentService;

/**
 * ทะเบียนวอร์ด/หน่วยงาน (Department Management)
 *
 * อ่าน:  GET /api/departments เปิดให้ผู้ใช้งานที่ login แล้ว (หน้าพยาบาลใช้ dropdown ร่วมกัน)
 * เขียน: POST/PUT/DELETE ต้องเป็น role "admin" เท่านั้น — การซ่อนปุ่มฝั่ง client
 *        ไม่นับเป็นการตรวจสิทธิ์ จึงบังคับที่ middleware ทุกคำสั่งเขียน
 *        ตรรกะทั้งหมด (ตรวจชื่อซ้ำ, cascade เปลี่ยนชื่อ, เงื่อนไขห้ามลบ) อยู่ใน DepartmentService
 */
class DepartmentController extends Controller
{
    private DepartmentService $departmentService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->departmentService = new DepartmentService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    /** GET /api/departments — วอร์ดทุกแถวพร้อมจำนวนพยาบาลที่สังกัด */
    public function index(): void
    {
        $this->success($this->departmentService->listDepartments());
    }

    /** POST /api/departments — body: { name, notes?, active? } */
    public function store(): void
    {
        $this->roleMiddleware->requireAdmin();
        $department = $this->departmentService->createDepartment($this->getInput(), $this->actor());
        $this->success($department, 201);
    }

    /** PUT /api/departments/{id} — body: { name?, notes?, active? } (แก้ชื่อจะอัปเดตรายชื่อพยาบาลด้วย) */
    public function update(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        $department = $this->departmentService->updateDepartment($id, $this->getInput(), $this->actor());
        $this->success($department);
    }

    /**
     * DELETE /api/departments/{id}
     * body: { mode: 'auto' | 'disable' } — auto ลบได้เฉพาะวอร์ดที่ไม่มีพยาบาลสังกัด
     *       ถ้ามีอยู่จะตอบ 409 พร้อมจำนวน และ UI เสนอ "ปิดใช้งานแทน" (mode=disable)
     */
    public function destroy(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        $input = $this->getInput();
        $mode = strtolower(trim((string)($input['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'disable'], true)) {
            $mode = 'auto';
        }
        $this->success($this->departmentService->deleteDepartment($id, $this->actor(), $mode));
    }

    /** ผู้ใช้งานปัจจุบันสำหรับอ้างอิงในบันทึก */
    private function actor(): ?array
    {
        return $this->roleMiddleware->getCurrentUser();
    }
}
