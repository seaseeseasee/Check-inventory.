<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Services\RestockService;
use App\Helpers\Response;

class HistoryController extends Controller
{
    private InspectionService $inspectionService;
    private RestockService $restockService;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->restockService = new RestockService();
    }

    public function inspections(): void
    {
        $inspections = $this->inspectionService->getInspections();

        $summary = $this->inspectionService->getItemSummary(array_column($inspections, 'id'));
        foreach ($inspections as &$inspection) {
            $counts = $summary[$inspection['id']] ?? ['item_count' => 0, 'attention_count' => 0];
            $inspection['item_count'] = $counts['item_count'];
            $inspection['attention_count'] = $counts['attention_count'];
        }
        unset($inspection);

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

    public function inspectionDetail(string $id): void
    {
        $inspection = $this->inspectionService->getInspectionWithItems($id);
        if (!$inspection) {
            $this->notFound('ไม่พบรอบการตรวจ');
            return;
        }
        $this->success($inspection);
    }

    public function restock(): void
    {
        $requests = $this->restockService->getRestockRequestsForAdmin();
        
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

    public function clearInspections(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        // This would clear all inspections - use with caution
        // In production, you might want to add confirmation or soft delete
        $this->success(['ok' => true, 'message' => 'Use with caution - not implemented for safety']);
    }
}