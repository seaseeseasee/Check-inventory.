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
     * Start inspection - resume existing draft or create a new one
     */
    public function startInspection(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $input = $this->getInput();

        // Auto-assign: staff always inspect under the nurse linked to their account
        $currentUser = $this->roleMiddleware->getCurrentUser();
        if (($currentUser['role'] ?? '') === 'staff') {
            $linkedNurseId = trim((string)($currentUser['nurse_id'] ?? ''));
            if ($linkedNurseId === '') {
                $this->error(
                    'บัญชีนี้ยังไม่ได้เชื่อมโยงรายชื่อพยาบาล กรุณาติดต่อผู้ดูแลระบบ',
                    403,
                    ['code' => 'NO_LINKED_NURSE']
                );
            }
            $input['nurse_id'] = $linkedNurseId; // ignore whatever the client sent
        }
        
        $validator = new Validator($input, [
            'car_id' => 'required|string|max:100',
            'nurse_id' => 'required|string|max:100',
            'shift' => 'required|string|in:เช้า,บ่าย,ดึก',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $vehicle = $this->vehicleModel->find($input['car_id']);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }
        VehicleService::assertInspectable($vehicle);

        $nurses = $this->nurseModel->getActive();
        $nurse = array_filter($nurses, fn($n) => $n['id'] === $input['nurse_id']);
        $nurse = array_values($nurse);
        if (empty($nurse)) {
            if (($currentUser['role'] ?? '') === 'staff') {
                $this->error(
                    'รายชื่อพยาบาลที่เชื่อมโยงกับบัญชีนี้ถูกพักใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
                    403,
                    ['code' => 'LINKED_NURSE_INACTIVE']
                );
            }
            $this->notFound('Nurse not found');
        }
        $nurse = $nurse[0];

        // Check for existing draft
        $draft = $this->inspectionService->getDraft($input['car_id']);
        $sameOwner = $draft
            && $draft['nurse_id'] === $input['nurse_id']
            && $draft['shift_name'] === $input['shift'];

        if ($draft && $sameOwner) {
            // Same nurse/shift - resume the existing draft with its saved statuses
            $items = $this->inspectionService->getDraftResults($input['car_id']);
            if (empty($items)) {
                $items = $this->inspectionService->buildResultsFromVehicle($input['car_id']);
            }

            $this->success([
                'id' => $draft['id'],
                'car_id' => $draft['car_id'],
                'nurse_id' => $draft['nurse_id'],
                'car_name' => $draft['car_name'],
                'nurse_name' => $draft['nurse_name'],
                'shift_name' => $draft['shift_name'],
                'started_at' => $draft['started_at'],
                'results' => $items,
                'resumed' => true,
            ]);
        }

        if ($draft && empty($input['replace'])) {
            // Another nurse/shift owns the draft - let the client confirm replacing it
            $this->error(
                'มีร่างการตรวจของ ' . $draft['nurse_name'] . ' (เวร' . $draft['shift_name'] . ') อยู่แล้ว',
                409,
                [
                    'code' => 'DRAFT_EXISTS',
                    'draft' => [
                        'nurse_id' => $draft['nurse_id'],
                        'nurse_name' => $draft['nurse_name'],
                        'shift_name' => $draft['shift_name'],
                        'started_at' => $draft['started_at'],
                    ],
                ]
            );
        }

        // New inspection: no draft yet, or the client confirmed replacing the old one
        $checkId = generate_id();
        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

        $draftData = [
            'id' => $checkId,
            'car_id' => $input['car_id'],
            'nurse_id' => $input['nurse_id'],
            'car_name' => $vehicle['name'],
            'nurse_name' => $nurse['name'],
            'shift_name' => $input['shift'],
            'started_at' => $now,
            'results' => $this->inspectionService->buildResultsFromVehicle($input['car_id']),
        ];

        $this->inspectionService->createDraft($draftData);
        $this->success($draftData + ['resumed' => false]);
    }

    /**
     * Inspect the current draft of a vehicle (read-only).
     * Lets the client ask about replacing another nurse's draft
     * before starting, instead of firing a failing start request.
     */
    public function getDraftInfo(): void
    {
        $this->roleMiddleware->requireStaff();

        $carId = (string)($this->getQuery('car') ?? '');
        if ($carId === '') {
            $this->validationError(['car' => 'car query parameter required']);
        }

        $draft = $this->inspectionService->getDraft($carId);
        if (!$draft) {
            $this->success(['draft' => null]);
        }

        $this->success([
            'draft' => [
                'car_id' => $draft['car_id'],
                'nurse_id' => $draft['nurse_id'],
                'nurse_name' => $draft['nurse_name'],
                'shift_name' => $draft['shift_name'],
                'started_at' => $draft['started_at'],
            ],
        ]);
    }

    /**
     * Save draft during inspection
     */
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

        $draft = $this->inspectionService->getDraft($input['car_id']);
        if (!$draft) {
            $this->notFound('No active draft for this vehicle');
        }

        $currentUser = $this->roleMiddleware->getCurrentUser();
        if (($currentUser['role'] ?? '') === 'staff' && ($currentUser['nurse_id'] ?? '') !== $draft['nurse_id']) {
            $this->error(
                'ร่างการตรวจนี้เป็นของ ' . $draft['nurse_name'] . ' กรุณาเริ่มการตรวจใหม่ในชื่อของท่าน',
                403
            );
        }

        $this->inspectionService->updateDraft($input['car_id'], $input['results']);
        $this->success(['ok' => true]);
    }

    /**
     * Submit inspection - auto creates restock requests for missing items
     */
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

        // ตรวจกติกาฝั่งเซิร์ฟเวอร์ด้วยสูตรเดียวกับที่แสดงบนหน้าจอ (ข้อ 2.4 / 7.4)
        // → ตอบ 422 พร้อมข้อความไทยรายรายการ แทนที่จะปล่อยให้เกิด 500 กลาง transaction
        $errors = $this->inspectionService->validateSubmission((string)$input['car_id'], $input['results']);
        if (!empty($errors)) {
            $this->validationError($errors);
        }

        $draft = $this->inspectionService->getDraft($input['car_id']);
        if (!$draft) {
            $this->notFound('No active draft for this vehicle');
        }

        $currentUser = $this->roleMiddleware->getCurrentUser();

        // สิทธิ์เจ้าของร่าง: บัญชีอื่นไม่สามารถส่งรอบตรวจแทนเจ้าของร่างได้
        // (ข้อ 7.8 — การซ่อนปุ่มอย่างเดียวห้ามถือเป็นมาตรการ)
        if (($currentUser['role'] ?? '') === 'staff' && ($currentUser['nurse_id'] ?? '') !== $draft['nurse_id']) {
            $this->error(
                'ร่างการตรวจนี้เป็นของ ' . $draft['nurse_name'] . ' กรุณาเริ่มการตรวจใหม่ในชื่อของท่าน',
                403
            );
        }

        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $inspectionData = array_merge($draft, [
            'completed_at' => $now,
            'results' => $input['results'],
        ]);

        // Pass current user ID/name for restock request tracking + stock transaction log
        $result = $this->inspectionService->saveInspection(
            $inspectionData,
            $currentUser['id'] ?? null,
            (string)($currentUser['full_name'] ?? '')
        );
        $this->success($result);
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

    /**
     * Start restocking (change status to IN_PROGRESS)
     */
    public function startRestock(string $id): void
    {
        $this->roleMiddleware->requireStaff();
        
        $user = $this->roleMiddleware->getCurrentUser();
        $result = $this->restockService->startRestock($id, (string)($user['id'] ?? ''), (string)($user['full_name'] ?? ''), $user);
        
        if (!$result) {
            $this->error('ไม่พบคำขอเติมอุปกรณ์นี้');
        }
        $this->success($result);
    }

    /**
     * Record restock quantity
     */
    public function recordRestock(string $id): void
    {
        $this->roleMiddleware->requireStaff();
        
        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'items' => 'required|array',
            'note' => 'string|max:2000',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        // Validate items (ข้อ 7.4/7.5 — ตัวเลขมากกว่า 0 เท่านั้น)
        foreach ($input['items'] as $index => $item) {
            if (!isset($item['item_id']) || !isset($item['qty'])) {
                $this->validationError(['items' => 'ทุกรายการต้องมีรหัสอุปกรณ์ (item_id) และจำนวน (qty)']);
            }
            if (!is_numeric($item['qty']) || (float)$item['qty'] <= 0) {
                $this->validationError(['items' => 'จำนวนที่เติมต้องเป็นตัวเลขมากกว่า 0 (รายการลำดับที่ ' . ((int)$index + 1) . ')']);
            }
        }

        $user = $this->roleMiddleware->getCurrentUser();
        $result = $this->restockService->recordRestock(
            $id,
            (string)($user['id'] ?? ''),
            (string)($user['full_name'] ?? ''),
            $input['items'],
            $input['note'] ?? '',
            $user
        );
        
        if (!$result) {
            $this->error('ไม่พบคำขอเติมอุปกรณ์นี้');
        }
        $this->success($result);
    }

    /**
     * Complete restock
     */
    public function completeRestock(string $id): void
    {
        $this->roleMiddleware->requireStaff();
        
        $input = $this->getInput();
        $note = $input['note'] ?? '';
        
        $user = $this->roleMiddleware->getCurrentUser();
        $result = $this->restockService->completeRestock($id, (string)($user['id'] ?? ''), (string)($user['full_name'] ?? ''), $note, $user);
        
        if (!$result) {
            $this->error('ไม่พบคำขอเติมอุปกรณ์นี้');
        }
        $this->success($result);
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
        $this->success($nurses);
    }
}