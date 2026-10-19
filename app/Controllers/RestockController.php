<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\RestockService;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\RoleMiddleware;

class RestockController extends Controller
{
    private RestockService $restockService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->restockService = new RestockService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    /**
     * Get all restock requests (Admin view - all requests)
     */
    public function index(): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $requests = $this->restockService->getRestockRequestsForAdmin();
        $this->success($requests);
    }

    /**
     * Get restock requests for staff (assigned + unassigned pending)
     */
    public function staffIndex(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $userId = $this->roleMiddleware->getCurrentUserId();
        $requests = $this->restockService->getRestockRequestsForStaff($userId);
        $this->success($requests);
    }

    /**
     * Get single restock request with details
     */
    public function show(string $id): void
    {
        $this->roleMiddleware->requireStaff();
        
        $request = $this->restockService->getRestockRequest($id);
        if (!$request) {
            $this->notFound('Restock request not found');
        }
        $this->success($request);
    }

    /**
     * Start restocking (Staff - change status to IN_PROGRESS)
     */
    public function start(string $id): void
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
     * Record restock quantity (Staff)
     */
    public function restock(string $id): void
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

        // Validate each item has item_id and qty (ข้อ 7.4/7.5)
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
     * Complete restock (Staff - mark as COMPLETED)
     */
    public function complete(string $id): void
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
     * Cancel restock request (Admin/Staff)
     */
    public function cancel(string $id): void
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
     * Assign restock request to staff (Admin only)
     */
    public function assign(string $id): void
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
     * Unassign restock request (Admin only)
     */
    public function unassign(string $id): void
    {
        $this->roleMiddleware->requireAdmin();
        
        $result = $this->restockService->unassign($id);
        
        if (!$result) {
            $this->error('Cannot unassign: invalid request');
        }
        $this->success($result);
    }

    /**
     * Get restock requests for a specific vehicle
     */
    public function getForVehicle(string $carId): void
    {
        $this->roleMiddleware->requireStaff();
        
        $requests = $this->restockService->getByVehicle($carId);
        $this->success($requests);
    }

    /**
     * Get active restock request for a check
     */
    public function getActiveByCheck(string $checkId): void
    {
        $this->roleMiddleware->requireStaff();
        
        $request = $this->restockService->getActiveByCheck($checkId);
        $this->success($request ?? []);
    }

    /**
     * Get restock stats (for dashboard)
     */
    public function stats(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $stats = $this->restockService->getStats();
        $this->success($stats);
    }

    /**
     * Get restock requests assigned to current user
     */
    public function myRequests(): void
    {
        $this->roleMiddleware->requireStaff();
        
        $userId = $this->roleMiddleware->getCurrentUserId();
        $requests = $this->restockService->getAssignedToUser($userId);
        $this->success($requests);
    }
}