<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InspectionService;
use App\Middleware\RoleMiddleware;

/**
 * Legacy /api/inspections/* — อ่านอย่างเดียว
 *
 * เส้นทางเริ่ม/ร่าง/ส่งรอบตรวจของ client รุ่นเก่าถูกชี้ไปที่ StaffController แล้ว (routes/web.php)
 * เพื่อให้ทุกช่องทางใช้กติกาเวร/ยอดคงเหลือ/สิทธิ์ชุดเดียวกัน
 */
class InspectionController extends Controller
{
    private InspectionService $inspectionService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->inspectionService = new InspectionService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    public function index(): void
    {
        $this->roleMiddleware->requireStaff();

        $inspections = $this->inspectionService->getInspections();
        $this->success($inspections);
    }

    public function show(string $id): void
    {
        $this->roleMiddleware->requireStaff();

        $inspection = $this->inspectionService->getInspectionWithItems($id);
        if (!$inspection) {
            $this->notFound('Inspection not found');
        }
        $this->success($inspection);
    }
}
