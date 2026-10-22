<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\RestockService;
use App\Services\AuthService;
use App\Services\VehicleService;
use App\Services\DepartmentService;
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
    private VehicleService $vehicleService;
    private DepartmentService $departmentService;
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
        $this->vehicleService = new VehicleService();
        $this->departmentService = new DepartmentService();
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
            $this->error('ชื่อผู้ใช้นี้มีอยู่แล้ว', 409);
        }
        $this->assertNurseNotLinked((string)$input['nurse_id'], null);

        try {
            $user = $this->userModel->createUser($input);
            unset($user['password_hash']);
            $this->success($user, 201);
        } catch (\Throwable $e) {
            // ไม่ส่งข้อความของ exception (อาจมี SQL) กลับไปที่หน้าจอ
            error_log('createUser failed: ' . $e->getMessage());
            $this->error('สร้างบัญชีไม่สำเร็จ กรุณาลองใหม่', 500);
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

        if (array_key_exists('nurse_id', $input)) {
            $this->assertNurseNotLinked((string)$input['nurse_id'], $id);
        }

        // กันระบบไม่มีผู้ดูแลเหลือ: ห้ามลดสิทธิ์/ปิดใช้งาน admin คนสุดท้าย และห้ามลดสิทธิ์ตัวเอง
        $demote = isset($input['role']) && $input['role'] !== 'admin' && $existing['role'] === 'admin';
        $deactivate = in_array($activeRaw, [false, 0, '0', 'false'], true) && !empty($existing['active']);
        if ($demote && ($this->roleMiddleware->getCurrentUser()['id'] ?? null) === $id) {
            $this->error('ไม่สามารถลดสิทธิ์ผู้ดูแลระบบของบัญชีตัวเองได้', 400);
        }
        if (($demote || $deactivate) && $existing['role'] === 'admin' && !empty($existing['active']) && $this->userModel->countActiveAdmins() <= 1) {
            $this->error('ต้องมีผู้ดูแลระบบที่ใช้งานอยู่อย่างน้อย 1 บัญชี', 409);
        }

        try {
            $this->userModel->updateUser($id, $input);
            if (isset($input['role']) && $input['role'] !== $existing['role']) {
                // ไม่มีตาราง audit log ในระบบ — บันทึกการเปลี่ยนบทบาทลง log ของเซิร์ฟเวอร์อย่างน้อย
                error_log(sprintf('AUDIT role change: user %s (%s) %s -> %s by %s',
                    $id, $existing['username'], $existing['role'], $input['role'],
                    $this->roleMiddleware->getCurrentUser()['username'] ?? '?'));
            }
            $this->success(['id' => $id]);
        } catch (\Throwable $e) {
            error_log('updateUser failed: ' . $e->getMessage());
            $this->error('บันทึกบัญชีไม่สำเร็จ กรุณาลองใหม่', 500);
        }
    }

    /** พยาบาล 1 คนมีบัญชีได้ 1 บัญชี — กันผูกพยาบาลที่มีบัญชีอยู่แล้วกับบัญชีที่สอง */
    private function assertNurseNotLinked(string $nurseId, ?string $exceptUserId): void
    {
        if ($nurseId === '') {
            return;
        }
        $linked = $this->userModel->findByNurseId($nurseId);
        if ($linked && (string)$linked['id'] !== (string)$exceptUserId) {
            $this->error('พยาบาลรายนี้มีบัญชีเข้าใช้งานอยู่แล้ว (' . $linked['username'] . ') — แก้ไขที่บัญชีเดิมแทนการสร้างใหม่', 409);
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
     * รถทุกคัน + ตัวเลขประกอบคำถามของหน้าจอ (จำนวนอุปกรณ์ / ประวัติตรวจ / งานเติมค้าง)
     * ตรรกะทั้งหมดอยู่ที่ VehicleService ซึ่งเป็นตัวเดียวกับที่ /api/vehicles ใช้
     * — แต่เดิมสอง route นี้เขียนซ้ำกันคนละแบบจึงพฤติกรรมไม่เท่ากัน
     */
    public function getVehicles(): void
    {
        $this->roleMiddleware->requireAdmin();
        $this->success($this->vehicleService->listVehicles());
    }

    /**
     * รถ 1 คันพร้อมอุปกรณ์ "ทุกแถว" (รวมที่พักใช้งาน) และผลประเมินว่าลบได้หรือไม่ได้
     */
    public function getVehicle(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        $this->success($this->vehicleService->getVehicleDetail($id));
    }

    /**
     * Create vehicle (Admin)
     * transaction เดียว: แถวรถ + ชุดอุปกรณ์มาตรฐาน (แม่แบบ/คัดลอกจากคันอื่น/ไม่สร้าง)
     */
    public function createVehicle(): void
    {
        $this->roleMiddleware->requireAdmin();
        $vehicle = $this->vehicleService->createVehicle($this->getInput(), $this->roleMiddleware->getCurrentUser());
        $this->success($vehicle, 201);
    }

    /**
     * Update vehicle (Admin) — name / plate / brand_model / status / odometer_initial / notes
     * ตรวจทะเบียนชนคันอื่น + ล๊อคแถว + ครอบ transaction
     */
    public function updateVehicle(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        $vehicle = $this->vehicleService->updateVehicle($id, $this->getInput(), $this->roleMiddleware->getCurrentUser());
        $this->success($vehicle);
    }

    /**
     * Delete vehicle (Admin)
     *   body { mode: 'auto' }     → ลบถาวรได้เฉพาะรถที่ไม่มีประวัติผูก (FK ของตารางประวัติเป็น RESTRICT)
     *   body { mode: 'disable' }  → ตั้งสถานะ "งดใช้งาน" แทน โดยเก็บอุปกรณ์/ประวัติไว้ครบ
     * ถ้ามีประวัติผูก service จะโยน 409 พร้อมจำนวนที่ผูกให้ UI เสนอทางเลือกกับผู้ใช้งาน
     */
    public function deleteVehicle(string $id): void
    {
        $this->roleMiddleware->requireAdmin();

        $input = $this->getInput();
        $mode = strtolower(trim((string)($input['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'disable', 'force'], true)) {
            $mode = 'auto';
        }

        $this->success($this->vehicleService->deleteVehicle($id, $this->roleMiddleware->getCurrentUser(), $mode));
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
            'phone' => $input['phone'] ?? '',
            'start_text' => $input['start_text'] ?? '',
            'end_text' => $input['end_text'] ?? '',
            'note' => $input['note'] ?? '',
            'active' => $input['active'] ?? true,
        ];

        // วอร์ดมาจากทะเบียน master (department_id หรือชื่อเดิมแบบ text) — ห้ามเชื่อ text จาก client
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $department = $this->departmentService->resolveForNurse($input);
            $data['department'] = $department['name'];
            $data['department_id'] = $department['id'];
            $this->nurseModel->create($data);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

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

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $department = $this->departmentService->resolveForNurse($input, $existing['department_id'] ?? null);
            $data['department'] = $department['name'];
            $data['department_id'] = $department['id'];
            $this->nurseModel->update($id, $data);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

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
     * คืนทุกแถว รวมรายการที่ "พักใช้งาน" ไว้แล้ว เพื่อให้เปิดกลับจากหน้าจอนี้ได้
     */
    public function getVehicleItems(string $vehicleId): void
    {
        $this->roleMiddleware->requireAdmin();
        $vehicle = $this->vehicleModel->find($vehicleId);
        if (!$vehicle) {
            $this->notFound('Vehicle not found');
        }

        $this->success($this->vehicleService->getItems($vehicleId, true));
    }

    /**
     * Add item to vehicle (Admin)
     *  name / standard / expiry_text / is_battery / onboard_qty (ยอดบนรถ = ตรวจนับ)
     *  และ current_stock + min_stock สำหรับคลังกลาง — เขียนพร้อมกันใน transaction เดียว
     */
    public function addVehicleItem(string $vehicleId): void
    {
        $this->roleMiddleware->requireAdmin();
        $item = $this->vehicleService->addItem($vehicleId, $this->getInput(), $this->roleMiddleware->getCurrentUser());
        $this->success($item, 201);
    }

    /**
     * Update item (Admin)
     * แก้ standard แล้ว target_qty/unit จะถูกคำนวณใหม่ทันที (หน้ารอเติมใช้ค่านี้คำนวณ "ต้องเติมกี่ชิ้น")
     */
    public function updateItem(string $itemId): void
    {
        $this->roleMiddleware->requireAdmin();
        $item = $this->vehicleService->updateItem($itemId, $this->getInput(), $this->roleMiddleware->getCurrentUser());
        $this->success($item);
    }

    /**
     * Delete item (Admin)
     *   body { mode: 'auto' }    → ลบถาวรเมื่อไม่มีแถวใดอ้างถึง มิฉะนั้น "พักใช้งาน" (active=0)
     *   body { mode: 'disable' } → พักใช้งานอย่างเดียว
     * ประวัติการตรวจเดิม (refer_check_items) ยังอ่านได้ครบเพราะเก็บชื่อ/จำนวนไว้ในตัวมันเอง
     */
    public function deleteItem(string $itemId): void
    {
        $this->roleMiddleware->requireAdmin();

        $input = $this->getInput();
        $mode = strtolower(trim((string)($input['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'disable'], true)) {
            $mode = 'auto';
        }

        $this->success($this->vehicleService->deleteItem($itemId, $this->roleMiddleware->getCurrentUser(), $mode));
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

}
