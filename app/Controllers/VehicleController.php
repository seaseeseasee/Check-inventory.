<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\VehicleService;
use App\Middleware\RoleMiddleware;

/**
 * ทะเบียนรถและอุปกรณ์ประจำรถ (Tab Stock)
 *
 * อ่าน:  index/show เปิดให้ผู้ใช้งานที่ login แล้ว (หน้าตรวจรถ/หน้ารอเติมใช้ร่วมกัน)
 * เขียน: ทุกคำสั่ง (สร้าง/แก้/ลบ รถและอุปกรณ์) ต้องเป็น role "admin" เท่านั้น
 *        — ซ่อนปุ่มอย่างเดียวไม่ได้ ถือเป็นการตรวจสิทธิ์ฝั่ง client เท่านั้น
 *        ตรรกะทั้งหมดอยู่ที่ VehicleService (route /api/admin/* เรียกเข้า service เดียวกัน)
 */
class VehicleController extends Controller
{
    private InspectionService $inspectionService;
    private VehicleService $vehicleService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->vehicleService = new VehicleService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    public function index(): void
    {
        $vehicles = $this->inspectionService->getVehicles();
        $this->success($vehicles);
    }

    public function show(string $id): void
    {
        $vehicle = $this->inspectionService->getVehicle($id);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $items = $this->inspectionService->getItemsByVehicle($id);
        $vehicle['items'] = $items;
        $this->success($vehicle);
    }

    // ==================== รถ: CRUD ====================

    /** POST /api/vehicles — สร้างรถใหม่ + สร้างชุดอุปกรณ์มาตรฐานให้ใน transaction เดียว */
    public function store(): void
    {
        $this->roleMiddleware->requireAdmin();
        $vehicle = $this->vehicleService->createVehicle($this->getInput(), $this->actor());
        $this->success($vehicle, 201);
    }

    /** PUT /api/vehicles/{id} — แก้ชื่อ/ทะเบียน/ยี่ห้อ-รุ่น/สถานะ/เลขไมล์/หมายเหตุ */
    public function update(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        $vehicle = $this->vehicleService->updateVehicle($id, $this->getInput(), $this->actor());
        $this->success($vehicle);
    }

    /**
     * DELETE /api/vehicles/{id}
     * body: { mode: 'auto' | 'disable' } — auto ลบได้เฉพาะรถที่ไม่มีประวัติผูก
     *       ถ้ามีประวัติจะตอบ 409 พร้อมจำนวนที่ผูก และ UI เสนอ "งดใช้งานแทน" (mode=disable)
     */
    public function destroy(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        $input = $this->getInput();
        $mode = strtolower(trim((string)($input['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'disable', 'force'], true)) {
            $mode = 'auto';
        }
        $result = $this->vehicleService->deleteVehicle($id, $this->actor(), $mode);
        $this->success($result);
    }

    // ==================== อุปกรณ์: CRUD ====================

    /** POST /api/vehicles/{carId}/items */
    public function addItem(string $vehicleId): void
    {
        $this->roleMiddleware->requireAdmin();
        $item = $this->vehicleService->addItem($vehicleId, $this->getInput(), $this->actor());
        $this->success($item, 201);
    }

    /** PUT /api/items/{itemId} */
    public function updateItem(string $itemId): void
    {
        $this->roleMiddleware->requireAdmin();
        $item = $this->vehicleService->updateItem($itemId, $this->getInput(), $this->actor());
        $this->success($item);
    }

    /** DELETE /api/items/{itemId} — body: { mode: 'auto' | 'disable' } */
    public function deleteItem(string $itemId): void
    {
        $this->roleMiddleware->requireAdmin();
        $input = $this->getInput();
        $mode = strtolower(trim((string)($input['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'disable'], true)) {
            $mode = 'auto';
        }
        $result = $this->vehicleService->deleteItem($itemId, $this->actor(), $mode);
        $this->success($result);
    }

    /**
     * POST /api/vehicles/{id}/items/copy — คัดลอกอุปกรณ์เป็นชุดเข้ารถปลายทาง
     *
     * หน้าจอร่างรายการให้ตรวจทานก่อน แล้วส่งค่าสุดท้าย (ชื่อ/จำนวนมาตรฐาน/วันหมดอายุ/
     * แบตเตอรี่/สถานะ/ยอดคงเหลือบนรถ) เข้ามา — ตรรกะตรวจสอบ+INSERT อยู่ที่
     * VehicleService::copyItemsToVehicle () ซึ่งเป็นชุดเดียวกับ "เพิ่มรถใหม่พร้อมคัดลอก"
     */
    public function copyItems(string $vehicleId): void
    {
        $this->roleMiddleware->requireAdmin();

        $input = $this->getInput();
        $items = $input['items'] ?? null;

        $result = $this->vehicleService->copyItemsToVehicle(
            $vehicleId,
            is_array($items) ? $items : [],
            $this->actor()
        );
        $this->success($result, 201);
    }

    /** ผู้ใช้งานปัจจุบันสำหรับอ้างอิงในบันทึก (payload ที่ส่งเข้า service) */
    private function actor(): ?array
    {
        return $this->roleMiddleware->getCurrentUser();
    }
}
