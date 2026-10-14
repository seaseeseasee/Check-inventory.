<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\RestockService;

class DashboardController extends Controller
{
    private InspectionService $inspectionService;
    private RestockService $restockService;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->restockService = new RestockService();
    }

    public function index(): void
    {
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
        $inspectionStats = $this->inspectionService->getDashboardStats();
        $restockStats = $this->restockService->getDashboardStats();

        $this->success(array_merge($inspectionStats, $restockStats));
    }
}