<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\RestockService;
use App\Services\AuthService;
use App\Services\StockService;
use App\Config\Database;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Nurse;
use App\Models\Item;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\RoleMiddleware;

class AdminController extends Controller
{
    private InspectionService $inspectionService;
    private RestockService $restockService;
    private AuthService $authService;
    private StockService $stockService;
    private User $userModel;
    private Vehicle $vehicleModel;
    private Nurse $nurseModel;
    private Item $itemModel;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->restockService = new RestockService();
        $this->authService = new AuthService();
        $this->stockService = new StockService();
        $this->userModel = new User();
        $this->vehicleModel = new Vehicle();
        $this->nurseModel = new Nurse();
        $this->itemModel = new Item();
        $this->roleMiddleware = new RoleMiddleware();
    }

    /**
     * Admin Dashboard - Overview stats
     */
    public function dashboard(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $inspectionStats = $this->inspectionService->getDashboardStats();
        $restockStats = $this->restockService->getStats();
        $expiringItems = $this->restockService->getExpiringItems();

        $this->success([
            'vehicles' => $inspectionStats['vehicles'],
            'inspections' => $inspectionStats,
            'restock' => $restockStats,
            'expiring_items' => $expiringItems,
        ]);
    }

    /**
     * Get all users (Admin only)
     */
    public function getUsers(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        // Include inactive users so admins can re-activate them (the page renders
        // a "ปิดใช้งาน" chip + "เปิดใช้งาน" button). Explicit columns — never
        // expose password_hash in API responses.
        $users = $this->userModel->all(
            ['id', 'username', 'full_name', 'role', 'nurse_id', 'active', 'last_login_at', 'created_at', 'updated_at'],
            'active DESC, full_name'
        );
        $this->success($users);
    }

    /**
     * Create new user (Admin only)
     */
    public function createUser(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();
        // The users form sends null for "not linked" — normalize so the string rule passes
        if (!isset($input['nurse_id'])) {
            $input['nurse_id'] = '';
        }
        
        $validator = new Validator($input, [
            'username' => 'required|string|max:100',
            'password' => 'required|string|min:6|max:100',
            'full_name' => 'required|string|max:255',
            'role' => 'required|in:admin,staff',
            'nurse_id' => 'string|max:100',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        // Check duplicate username
        $existing = $this->userModel->findByUsername($input['username']);
        if ($existing) {
            $this->error('Username already exists', 409);
        }

        try {
            $user = $this->userModel->createUser($input);
            unset($user['password_hash']);
            $this->success($user, 201);
        } catch (\Throwable $e) {
            $this->error('Failed to create user: ' . $e->getMessage());
        }
    }

    /**
     * Update user (Admin only)
     */
    public function updateUser(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();
        // Same normalization as createUser(): null means "not linked"
        if (array_key_exists('nurse_id', $input) && $input['nurse_id'] === null) {
            $input['nurse_id'] = '';
        }
        
        $existing = $this->userModel->find($id);
        if (!$existing) {
            $this->notFound('User not found');
        }

        // Prevent self-deactivation (parallel to deleteUser's self-delete guard)
        $activeRaw = $input['active'] ?? true;
        if (in_array($activeRaw, [false, 0, '0', 'false'], true)) {
            $currentUser = $this->roleMiddleware->getCurrentUser();
            if (($currentUser['id'] ?? null) === $id) {
                $this->error('ไม่สามารถปิดใช้งานบัญชีของตัวเองได้', 400);
            }
        }

        $validator = new Validator($input, [
            'full_name' => 'string|max:255',
            'role' => 'in:admin,staff',
            'nurse_id' => 'string|max:100',
            'active' => 'boolean',
            'password' => 'string|min:6|max:100',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        try {
            $this->userModel->updateUser($id, $input);
            $this->success(['id' => $id]);
        } catch (\Throwable $e) {
            $this->error('Failed to update user: ' . $e->getMessage());
        }
    }

    /**
     * Delete/Deactivate user (Admin only)
     */
    public function deleteUser(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $existing = $this->userModel->find($id);
        if (!$existing) {
            $this->notFound('User not found');
        }

        // Prevent self-deletion
        $currentUser = $this->roleMiddleware->getCurrentUser();
        if ($currentUser['id'] === $id) {
            $this->error('Cannot delete your own account');
        }

        $this->userModel->updateUser($id, ['active' => false]);
        $this->success(['id' => $id]);
    }

    /**
     * Get all vehicles with items (Admin)
     */
    public function getVehicles(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $vehicles = $this->vehicleModel->all();
        $this->success($vehicles);
    }

    /**
     * Get vehicle with items (Admin)
     */
    public function getVehicle(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $vehicle = $this->vehicleModel->find($id);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $items = $this->itemModel->getByVehicle($id);
        $vehicle['items'] = $items;
        $this->success($vehicle);
    }

    /**
     * Create vehicle (Admin)
     * Optionally copies the active equipment list from an existing vehicle
     * in the same transaction — vehicle and items are written together or not at all.
     */
    public function createVehicle(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();

        // Validator::string rejects missing keys, so optional fields must exist as ''.
        $input += [
            'name' => '',
            'brand_model' => '',
            'status' => '',
            'odometer_initial' => '',
            'notes' => '',
            'copy_source_vehicle_id' => '',
        ];
        
        $validator = new Validator($input, [
            'plate' => 'required|string|max:50',
            'name' => 'string|max:255',
            'brand_model' => 'string|max:255',
            'status' => 'string',
            'notes' => 'string|max:500',
            'copy_source_vehicle_id' => 'string|max:100',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $plate = trim((string)$input['plate']);
        if ($plate === '') {
            $this->validationError(['plate' => 'กรุณากรอกทะเบียนรถ']);
        }

        $status = trim((string)$input['status']);
        if ($status === '') {
            $status = 'available';
        }
        if (!in_array($status, ['available', 'maintenance', 'disabled'], true)) {
            $this->validationError(['status' => 'สถานะรถไม่ถูกต้อง']);
        }

        $odometerRaw = $input['odometer_initial'];
        $odometer = null;
        if ($odometerRaw !== '' && $odometerRaw !== null) {
            if (!is_numeric($odometerRaw) || (int)$odometerRaw != $odometerRaw || (int)$odometerRaw < 0) {
                $this->validationError(['odometer_initial' => 'เลขไมล์ต้องเป็นจำนวนเต็มไม่ติดลบ']);
            }
            $odometer = (int)$odometerRaw;
        }

        $duplicate = $this->vehicleModel->firstWhere('plate', $plate);
        if ($duplicate) {
            $this->error('ทะเบียนรถนี้มีอยู่ในระบบแล้ว: ' . ($duplicate['name'] ?? $plate), 409);
        }

        do {
            $id = 'car-' . substr(generate_id(), 0, 12);
        } while ($this->vehicleModel->find($id));

        // Dropdowns show `name` only, so keep the plate visible there —
        // matches the "รถ Refer (3ชฏ3250)" convention of existing rows.
        $name = trim((string)$input['name']);
        if ($name === '') {
            $name = $plate;
        } elseif (!str_contains($name, $plate)) {
            $name .= ' (' . $plate . ')';
        }

        $copySource = trim((string)$input['copy_source_vehicle_id']);
        $sourceItems = [];
        if ($copySource !== '') {
            if (!$this->vehicleModel->find($copySource)) {
                $this->validationError(['copy_source_vehicle_id' => 'ไม่พบรถต้นทางที่เลือก']);
            }
            $sourceItems = $this->itemModel->getActiveByVehicle($copySource);
            if (count($sourceItems) > 300) {
                $this->validationError(['copy_source_vehicle_id' => 'รถต้นทางมีอุปกรณ์เกิน 300 รายการ — คัดลอกได้ครั้งละไม่เกิน 300']);
            }
        }

        $copied = 0;
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $this->vehicleModel->create([
                'id' => $id,
                'name' => $name,
                'plate' => $plate,
                'brand_model' => trim((string)$input['brand_model']) ?: null,
                'status' => $status,
                'odometer_initial' => $odometer,
                'notes' => trim((string)$input['notes']) ?: null,
            ]);

            if ($sourceItems) {
                $rows = array_map(fn(array $item) => [
                    generate_id(),
                    $id,
                    $item['name'],
                    $item['standard'],
                    $item['expiry_text'] ?? '',
                    !empty($item['is_battery']) ? 1 : 0,
                    1,
                ], $sourceItems);
                $table = $this->itemModel->getTable();
                $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?)'));
                $stmt = $db->prepare("INSERT INTO {$table} (id, car_id, name, standard, expiry_text, is_battery, active) VALUES {$placeholders}");
                $stmt->execute(array_merge(...$rows));
                $copied = count($rows);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->error('เพิ่มรถไม่สำเร็จ: ' . $e->getMessage());
        }

        $this->success(['id' => $id, 'name' => $name, 'copied_items' => $copied], 201);
    }

    /**
     * Update vehicle (Admin)
     */
    public function updateVehicle(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();
        
        $existing = $this->vehicleModel->find($id);
        if (!$existing) {
            $this->notFound('Vehicle not found');
        }

        $validator = new Validator($input, [
            'name' => 'required|string|max:255',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $this->vehicleModel->update($id, ['name' => $input['name']]);
        $this->success(['id' => $id]);
    }

    /**
     * Delete vehicle (Admin)
     */
    public function deleteVehicle(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $existing = $this->vehicleModel->find($id);
        if (!$existing) {
            $this->notFound('Vehicle not found');
        }

        $this->vehicleModel->delete($id);
        $this->success(['id' => $id]);
    }

    /**
     * Get all nurses (Admin)
     */
    public function getNurses(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $nurses = $this->nurseModel->all();
        $this->success($nurses);
    }

    /**
     * Create nurse (Admin)
     */
    public function createNurse(): void
    {
        $this->roleMiddleware->requireAdmin();
        
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

        $existing = $this->nurseModel->find($input['id']);
        if ($existing) {
            $this->error('Nurse ID already exists', 409);
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

        $this->nurseModel->create($data);
        $this->success(['id' => $data['id']], 201);
    }

    /**
     * Update nurse (Admin)
     */
    public function updateNurse(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();
        
        $existing = $this->nurseModel->find($id);
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

        $this->nurseModel->update($id, $data);
        $this->success(['id' => $id]);
    }

    /**
     * Delete/Deactivate nurse (Admin)
     */
    public function deleteNurse(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $existing = $this->nurseModel->find($id);
        if (!$existing) {
            $this->notFound('Nurse not found');
        }

        $this->nurseModel->update($id, ['active' => false]);
        $this->success(['id' => $id]);
    }

    /**
     * Get items for a vehicle (Admin)
     */
    public function getVehicleItems(string $vehicleId): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $vehicle = $this->vehicleModel->find($vehicleId);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $items = $this->itemModel->getByVehicle($vehicleId);
        $this->success($items);
    }

    /**
     * Add item to vehicle (Admin)
     */
    public function addVehicleItem(string $vehicleId): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();
        
        $vehicle = $this->vehicleModel->find($vehicleId);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $validator = new Validator($input, [
            'name' => 'required|string|max:255',
            'standard' => 'required|string|max:100',
            'expiry_text' => 'string|max:100',
            'is_battery' => 'boolean',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $itemId = generate_id();
        $this->itemModel->create([
            'id' => $itemId,
            'car_id' => $vehicleId,
            'name' => $input['name'],
            'standard' => $input['standard'],
            'expiry_text' => $input['expiry_text'] ?? '',
            'is_battery' => $input['is_battery'] ?? false,
            'active' => true,
        ]);

        // Optional: ตั้งสต็อกกลางจากฟอร์มเพิ่มอุปกรณ์ (สต็อกปัจจุบัน + ขั้นต่ำ)
        $stockInfo = null;
        $currentRaw = $input['current_stock'] ?? null;
        $minRaw = $input['min_stock'] ?? null;
        $hasCurrent = $currentRaw !== null && $currentRaw !== '';
        $hasMin = $minRaw !== null && $minRaw !== '';

        if ($hasCurrent || $hasMin) {
            if ($hasCurrent && (!is_numeric($currentRaw) || (float)$currentRaw < 0 || (float)$currentRaw > 1000000)) {
                $this->validationError(['current_stock' => 'สต็อกปัจจุบันต้องเป็นตัวเลข 0 - 1,000,000']);
            }
            if ($hasMin && (!is_numeric($minRaw) || (float)$minRaw < 0 || (float)$minRaw > 1000000)) {
                $this->validationError(['min_stock' => 'จุดเตือนขั้นต่ำต้องเป็นตัวเลข 0 - 1,000,000']);
            }

            $actor = $this->roleMiddleware->getCurrentUser();
            $vehicleLabel = trim((string)($vehicle['name'] ?? '') . ' ' . (string)($vehicle['plate'] ?? ''));

            try {
                $item = $this->stockService->setStockForItemName(
                    (string)$input['name'],
                    $hasCurrent ? (float)$currentRaw : null,
                    $hasMin ? (float)$minRaw : null,
                    $actor['id'] ?? null,
                    $actor['full_name'] ?? '',
                    $vehicleLabel !== '' ? 'รถ ' . $vehicleLabel : ''
                );
                if ($item !== null) {
                    $stockInfo = [
                        'name' => $item['name'],
                        'current_qty' => $item['current_qty'],
                        'min_qty' => $item['min_qty'],
                        'status' => $item['status'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                // อุปกรณ์ถูกเพิ่มแล้ว — รายงานปัญหาสต็อกเป็นคำเตือนโดยไม่ทำให้คำขอล้มทั้งรายการ
                $stockInfo = ['error' => $e->getMessage()];
            }
        }

        $created = $this->itemModel->find($itemId) ?? [];
        $created['stock'] = $stockInfo;
        $this->success($created, 201);
    }

    /**
     * Update item (Admin)
     */
    public function updateItem(string $itemId): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();
        
        $existing = $this->itemModel->find($itemId);
        if (!$existing) {
            $this->notFound('Item not found');
        }

        $data = [];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['standard'])) $data['standard'] = $input['standard'];
        if (isset($input['expiry_text'])) $data['expiry_text'] = $input['expiry_text'];
        if (isset($input['is_battery'])) $data['is_battery'] = $input['is_battery'];
        if (isset($input['active'])) $data['active'] = $input['active'];

        if (empty($data)) {
            $this->error('No data to update');
        }

        $this->itemModel->update($itemId, $data);
        $this->success($this->itemModel->find($itemId));
    }

    /**
     * Delete/Deactivate item (Admin)
     */
    public function deleteItem(string $itemId): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $existing = $this->itemModel->find($itemId);
        if (!$existing) {
            $this->notFound('Item not found');
        }

        $this->itemModel->update($itemId, ['active' => false]);
        $this->success(['id' => $itemId]);
    }

    /**
     * Get all restock requests with details (Admin)
     */
    public function getRestockRequests(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $requests = $this->restockService->getRestockRequestsForAdmin();
        $this->success($requests);
    }

    /**
     * Get restock stats for admin dashboard
     */
    public function getRestockStats(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $stats = $this->restockService->getStats();
        $this->success($stats);
    }

    /**
     * Assign restock request to staff (Admin)
     */
    public function assignRestock(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $input = $this->getInput();
        
        $validator = new Validator($input, [
            'assigned_user_id' => 'required|string|max:100',
        ]);

        if (!$validator->validate()) {
            $this->validationError($validator->errors());
        }

        $result = $this->restockService->assignToStaff($id, $input['assigned_user_id']);
        
        if (!$result) {
            $this->error('Cannot assign: invalid request or status');
        }
        $this->success($result);
    }

    /**
     * Unassign restock request (Admin)
     */
    public function unassignRestock(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $result = $this->restockService->unassign($id);
        
        if (!$result) {
            $this->error('Cannot unassign: invalid request');
        }
        $this->success($result);
    }
}