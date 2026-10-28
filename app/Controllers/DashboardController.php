<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\RestockService;
use App\Middleware\RoleMiddleware;

class DashboardController extends Controller
{
    private InspectionService $inspectionService;
    private RestockService $restockService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->restockService = new RestockService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    public function index(): void
    {
        $this->roleMiddleware->requireStaff();
        $inspectionStats = $this->inspectionService->getDashboardStats();
        $restockStats = $this->restockService->getDashboardStats();
        $expiringItems = $this->restockService->getExpiringItems();

        $this->success([
            'vehicles' => $inspectionStats['vehicles'],
            'inspections' => $inspectionStats,
            'restock' => $restockStats,
            'expiring_items' => $expiringItems,
        ]);
    }

    public function stats(): void
    {
        $this->roleMiddleware->requireStaff();
        $inspectionStats = $this->inspectionService->getDashboardStats();
        $restockStats = $this->restockService->getDashboardStats();

        $this->success(array_merge($inspectionStats, $restockStats));
    }
}