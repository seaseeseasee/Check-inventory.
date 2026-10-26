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