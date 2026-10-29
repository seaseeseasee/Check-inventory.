<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\RestockService;
use App\Services\AuthService;
use App\Services\VehicleService;
use App\Models\Vehicle;
use App\Models\Nurse;
use App\Models\Item;
use App\Models\Inspection;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\RoleMiddleware;

class StaffController extends Controller
{
    private InspectionService $inspectionService;
    private RestockService $restockService;
    private AuthService $authService;
    private Vehicle $vehicleModel;
    private Nurse $nurseModel;
    private Item $itemModel;
    private Inspection $inspectionModel;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->restockService = new RestockService();
        $this->authService = new AuthService();
        $this->vehicleModel = new Vehicle();
        $this->nurseModel = new Nurse();
        $this->itemModel = new Item();
        $this->inspectionModel = new Inspection();
        $this->roleMiddleware = new RoleMiddleware();
    }

    /**
     * Staff Dashboard - Overview for assigned tasks
     */
    public function dashboard(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $userId = $this->roleMiddleware->getCurrentUserId();
        
        // Get vehicles
        $vehicles = $this->vehicleModel->all();
        
        // Get inspections
        $inspections = $this->inspectionModel->all(['*'], 'completed_at DESC');
        $today = (new \DateTime())->format('Y-m-d');

        $lastByCar = [];
        foreach ($inspections as $inspection) {
            if (!isset($lastByCar[$inspection['car_id']])) {
                $lastByCar[$inspection['car_id']] = $inspection;
            }
        }

        // Get restock stats for staff
        $myRequests = $this->restockService->getAssignedToUser($userId);
        $pendingRequests = $this->restockService->getRestockRequestsForStaff($userId);
        
        $pendingCount = 0;
        $inProgressCount = 0;
        $partialCount = 0;
        
        foreach ($pendingRequests as $req) {
            switch ($req['status']) {
                case 'PENDING': $pendingCount++; break;
                case 'IN_PROGRESS': $inProgressCount++; break;
                case 'PARTIAL': $partialCount++; break;
            }
        }

        $this->success([
            'vehicles' => $vehicles,
            'last_inspections' => $lastByCar,
            'restock' => [
                'my_requests' => count($myRequests),
                'pending' => $pendingCount,
                'in_progress' => $inProgressCount,
                'partial' => $partialCount,
            ],
            'pending_requests' => array_slice($pendingRequests, 0, 10), // Top 10 for quick view
            // ของหมดอายุ/ใกล้หมดอายุ — ผู้ใช้งานทั่วไปต้องเห็นเหมือนแอดมิน (คนตรวจ/เติมของคือพนักงาน)
            'expiring_items' => $this->restockService->getExpiringItems(),
            'shifts_today' => $this->inspectionService->shiftsDoneOn(InspectionService::currentShift()['check_date']),
        ]);
    }

    /**
     * Get vehicles for inspection
     */
    public function getVehicles(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $vehicles = $this->vehicleModel->all();
        $this->success($vehicles);
    }

    /**
     * Get vehicle with items for inspection
     */
    public function getVehicle(string $id): void
    {
        $this->roleMiddleware->requireStaff();
        
        $vehicle = $this->vehicleModel->find($id);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $items = $this->itemModel->getActiveByVehicle($id);
        $vehicle['items'] = $items;
        $this->success($vehicle);
    }

    /**
     * เริ่มตรวจรถตามเวร: {car_id, nurse_id, shift_code, check_date, replace?}
     * mode = new (ร่างใหม่) | draft (ทำต่อ) | edit (เวรนี้บันทึกแล้ว เปิดแก้รอบเดิม)
     */
    public function startInspection(): void
    {
        $this->roleMiddleware->requireStaff();
        $input = $this->getInput();
        $currentUser = $this->roleMiddleware->getCurrentUser();

        // staff ตรวจในชื่อพยาบาลที่ผูกกับบัญชีเสมอ (ไม่เชื่อค่าที่ client ส่งมา)
        if (($currentUser['role'] ?? '') === 'staff') {
            $linkedNurseId = trim((string)($currentUser['nurse_id'] ?? ''));
            if ($linkedNurseId === '') {
                $this->error('บัญชีนี้ยังไม่ได้เชื่อมโยงรายชื่อพยาบาล กรุณาติดต่อผู้ดูแลระบบ', 403, ['code' => 'NO_LINKED_NURSE']);
            }
            $input['nurse_id'] = $linkedNurseId;
        }

        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'nurse_id' => 'required|string|max:100',
        ]);
        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }
        $shift = InspectionService::normalizeShift($input);

        $vehicle = $this->vehicleModel->find((string)$input['car_id']);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }
        VehicleService::assertInspectable($vehicle);

        $nurse = null;
        foreach ($this->nurseModel->getActive() as $n) {
            if ((string)$n['id'] === (string)$input['nurse_id']) {
                $nurse = $n;
            }
        }
        if ($nurse === null) {
            if (($currentUser['role'] ?? '') === 'staff') {
                $this->error('รายชื่อพยาบาลที่เชื่อมโยงกับบัญชีนี้ถูกพักใช้งาน กรุณาติดต่อผู้ดูแลระบบ', 403, ['code' => 'LINKED_NURSE_INACTIVE']);
            }
            $this->notFound('Nurse not found');
        }

        $this->success($this->inspectionService->start($vehicle, $nurse, $shift, !empty($input['replace']), $currentUser));
    }

    /** เวรปัจจุบัน + เวรที่ตรวจแล้วของวันนั้น (ให้หน้าเริ่มตรวจแสดง/เลือกค่าเริ่มต้น) */
    public function getShiftStatus(): void
    {
        $this->roleMiddleware->requireStaff();
        $current = InspectionService::currentShift();
        $date = (string)($this->getQuery('date') ?? $current['check_date']);
        if (!\App\Services\VehicleStockService::validDate($date)) {
            $this->validationError(['date' => 'วันที่ไม่ถูกต้อง']);
        }
        $this->success([
            'current' => $current,
            'shifts' => InspectionService::SHIFTS,
            'date' => $date,
            'done' => $this->inspectionService->shiftsDoneOn($date),
        ]);
    }

    /** ร่างของรถ (อ่านอย่างเดียว) — ให้ถามก่อนแทนที่ร่างของคนอื่น */
    public function getDraftInfo(): void
    {
        $this->roleMiddleware->requireStaff();
        $carId = (string)($this->getQuery('car') ?? '');
        if ($carId === '') {
            $this->validationError(['car' => 'car query parameter required']);
        }
        $draft = $this->inspectionService->getDraft($carId);
        $this->success(['draft' => $draft ? [
            'car_id' => $draft['car_id'],
            'nurse_id' => $draft['nurse_id'],
            'nurse_name' => $draft['nurse_name'],
            'shift_name' => InspectionService::shiftName($draft['shift_code'] ?? null) ?: $draft['shift_name'],
            'shift_code' => $draft['shift_code'] ?? null,
            'check_date' => $draft['check_date'] ?? null,
            'started_at' => $draft['started_at'],
        ] : null]);
    }

    /** บันทึกร่าง (ไม่แตะยอดสต๊อค) */
    public function saveDraft(): void
    {
        $this->roleMiddleware->requireStaff();
        $input = $this->getInput();
        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'results' => 'required|array',
        ]);
        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }
        $this->inspectionService->saveDraft((string)$input['car_id'], $input['results'], $this->roleMiddleware->getCurrentUser());
        $this->success(['ok' => true]);
    }

    /** ส่งผลตรวจ (ใหม่หรือแก้รอบเดิม): {car_id, check_id, mode, revision?, results} */
    public function submitInspection(): void
    {
        $this->roleMiddleware->requireStaff();
        $input = $this->getInput();
        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'results' => 'required|array',
        ]);
        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }
        $this->success($this->inspectionService->submit($input, $this->roleMiddleware->getCurrentUser()));
    }

    /**
     * Get restock requests for staff (assigned + unassigned pending)
     */
    public function getRestockRequests(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $userId = $this->roleMiddleware->getCurrentUserId();
        $requests = $this->restockService->getRestockRequestsForStaff($userId);
        $this->success($requests);
    }

    /**
     * Get restock request details
     */
    public function getRestockRequest(string $id): void
    {
        $this->roleMiddleware->requireStaff();
        
        $request = $this->restockService->getRestockRequest($id);
        if (!$request) {
            $this->notFound('Restock request not found');
        }
        $this->success($request);
    }

    /** หน้าเติมของของรถ 1 คัน: ยอดปัจจุบัน / เป้าหมาย / Lot / เครื่อง / ยอดค้างในรายการรอเติม */
    public function getRefillSheet(string $carId): void
    {
        $this->roleMiddleware->requireStaff();
        $sheet = $this->restockService->getRefillSheet($carId);
        $sheet['is_admin'] = ($this->roleMiddleware->getCurrentUser()['role'] ?? '') === 'admin';
        $this->success($sheet);
    }

    /** จัดการล็อต: PUT แก้ข้อมูล / POST .../adjust | void | expire (สิทธิ์ตรวจใน RestockService::manageLot) */
    public function manageLot(string $lotId, string $action = 'edit'): void
    {
        $this->roleMiddleware->requireStaff();
        $this->success($this->restockService->manageLot($lotId, $action, $this->getInput(), $this->roleMiddleware->getCurrentUser()));
    }

    /** ประวัติการเคลื่อนไหว (รับเข้า/ใช้/ปรับ/หมดอายุ/ยกเลิก) ของอุปกรณ์ 1 รายการ */
    public function getItemMovements(string $itemId): void
    {
        $this->roleMiddleware->requireStaff();
        $this->success($this->restockService->getItemMovements($itemId));
    }

    /** เติมของเข้ารถโดยตรง (ไม่มีคลังกลาง/ไม่ต้องมอบหมาย) — client_token กันกดซ้ำ */
    public function refillVehicle(string $carId): void
    {
        $this->roleMiddleware->requireStaff();
        $input = $this->getInput();
        $validator = new Validator($input, [
            'client_token' => 'required|string|max:64',
            'lines' => 'required|array',
        ]);
        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }
        $this->success($this->restockService->refill($carId, $input, $this->roleMiddleware->getCurrentUser()));
    }

    /**
     * Cancel restock request
     */
    public function cancelRestock(string $id): void
    {
        $this->roleMiddleware->requireStaff();
        
        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'reason' => 'required|string|max:2000',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $user = $this->roleMiddleware->getCurrentUser();
        $result = $this->restockService->cancelRestock($id, (string)($user['id'] ?? ''), (string)($user['full_name'] ?? ''), $input['reason'], $user);
        
        if (!$result) {
            $this->error('ไม่พบคำขอเติมอุปกรณ์นี้');
        }
        $this->success($result);
    }

    /**
     * Get restock requests assigned to current user
     */
    public function getMyRestockRequests(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $userId = $this->roleMiddleware->getCurrentUserId();
        $requests = $this->restockService->getAssignedToUser($userId);
        $this->success($requests);
    }

    /**
     * Get inspection history
     */
    public function getInspections(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $inspections = $this->inspectionModel->all(['*'], 'completed_at DESC');
        
        // Apply filters
        $carId = $this->getQuery('car_id');
        $from = $this->getQuery('from');
        $to = $this->getQuery('to');
        $search = $this->getQuery('search');

        $filtered = array_filter($inspections, function($inspection) use ($carId, $from, $to, $search) {
            if ($carId && $inspection['car_id'] !== $carId) return false;
            if ($from && $inspection['completed_at'] < $from) return false;
            if ($to && $inspection['completed_at'] > $to) return false;
            if ($search) {
                $search = strtolower($search);
                $haystack = strtolower(json_encode($inspection));
                if (strpos($haystack, $search) === false) return false;
            }
            return true;
        });

        $this->success(array_values($filtered));
    }

    /**
     * Get restock history
     */
    public function getRestockHistory(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $requests = $this->restockService->getRestockRequestsForStaff(
            $this->roleMiddleware->getCurrentUserId()
        );
        
        // Apply filters
        $carId = $this->getQuery('car_id');
        $status = $this->getQuery('status');
        $from = $this->getQuery('from');
        $to = $this->getQuery('to');
        $search = $this->getQuery('search');

        $filtered = array_filter($requests, function($request) use ($carId, $status, $from, $to, $search) {
            if ($carId && $request['car_id'] !== $carId) return false;
            if ($status && $request['status'] !== $status) return false;
            if ($from && $request['created_at'] < $from) return false;
            if ($to && $request['created_at'] > $to) return false;
            if ($search) {
                $search = strtolower($search);
                $haystack = strtolower(json_encode($request));
                if (strpos($haystack, $search) === false) return false;
            }
            return true;
        });

        $this->success(array_values($filtered));
    }

    /**
     * Get nurses for selection
     */
    public function getNurses(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $nurses = $this->nurseModel->getActive();
        $this->success($this->roleMiddleware->isAdmin() ? $nurses : NurseController::forSelection($nurses));
    }
}