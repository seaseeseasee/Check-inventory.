<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\StockService;
use App\Middleware\RoleMiddleware;

class StockController extends Controller
{
    private StockService $stockService;
    private RoleMiddleware $roleMiddleware;

    public function __construct()
    {
        $this->stockService = new StockService();
        $this->roleMiddleware = new RoleMiddleware();
    }

    /**
     * GET /api/stock — ภาพรวมคลังสต็อกกลาง (รายการ + สรุปสถานะ)
     */
    public function index(): void
    {
        $this->roleMiddleware->requireStaff();
        $this->success($this->stockService->getOverview());
    }

    /**
     * GET /api/stock/transactions — ประวัติการเคลื่อนไหวสต็อก (ล่าสุดก่อน)
     */
    public function transactions(): void
    {
        $this->roleMiddleware->requireStaff();

        $limit = (int)$this->getQuery('limit', 200);
        $itemId = $this->getQuery('item_id');
        $itemId = is_string($itemId) && $itemId !== '' ? $itemId : null;

        $this->success($this->stockService->getTransactions($limit, $itemId));
    }

    /**
     * POST /api/stock/{id}/receive — รับอุปกรณ์เข้าคลัง
     */
    public function receive(string $id): void
    {
        $this->roleMiddleware->requireStaff();

        if ($this->stockService->getItemWithStatus($id) === null) {
            $this->notFound('ไม่พบรายการสต็อกนี้');
        }

        $input = $this->getInput();

        $qtyRaw = $input['qty'] ?? null;
        if (!is_numeric($qtyRaw)) {
            $this->validationError(['qty' => 'จำนวนรับเข้าต้องเป็นตัวเลข']);
        }
        $qty = (float)$qtyRaw;
        if ($qty <= 0) {
            $this->validationError(['qty' => 'จำนวนรับเข้าต้องมากกว่า 0']);
        }
        if ($qty > 1000000) {
            $this->validationError(['qty' => 'จำนวนรับเข้าสูงเกินกำหนด (ไม่เกิน 1,000,000)']);
        }

        $note = trim((string)($input['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            $this->validationError(['note' => 'หมายเหตุยาวเกิน 500 ตัวอักษร']);
        }

        $user = $this->roleMiddleware->getCurrentUser();

        try {
            $item = $this->stockService->receive(
                $id,
                $qty,
                $note,
                $user['id'] ?? null,
                $user['full_name'] ?? ''
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }

        $this->success(['item' => $item]);
    }

    /**
     * POST /api/stock/{id}/adjust — ปรับยอดสต็อก/ขั้นต่ำ (ผู้ดูแลเท่านั้น)
     */
    public function adjust(string $id): void
    {
        $this->roleMiddleware->requireAdmin();

        if ($this->stockService->getItemWithStatus($id) === null) {
            $this->notFound('ไม่พบรายการสต็อกนี้');
        }

        $input = $this->getInput();

        $currentQty = null;
        if (isset($input['current_qty']) && $input['current_qty'] !== '' && $input['current_qty'] !== null) {
            if (!is_numeric($input['current_qty'])) {
                $this->validationError(['current_qty' => 'สต็อกปัจจุบันต้องเป็นตัวเลข']);
            }
            $currentQty = (float)$input['current_qty'];
            if ($currentQty < 0) {
                $this->validationError(['current_qty' => 'สต็อกปัจจุบันต้องไม่ติดลบ']);
            }
            if ($currentQty > 1000000) {
                $this->validationError(['current_qty' => 'สต็อกปัจจุบันสูงเกินกำหนด (ไม่เกิน 1,000,000)']);
            }
        }

        $minQty = null;
        if (isset($input['min_qty']) && $input['min_qty'] !== '' && $input['min_qty'] !== null) {
            if (!is_numeric($input['min_qty'])) {
                $this->validationError(['min_qty' => 'ขั้นต่ำต้องเป็นตัวเลข']);
            }
            $minQty = (float)$input['min_qty'];
            if ($minQty < 0) {
                $this->validationError(['min_qty' => 'ขั้นต่ำต้องไม่ติดลบ']);
            }
            if ($minQty > 1000000) {
                $this->validationError(['min_qty' => 'ขั้นต่ำสูงเกินกำหนด (ไม่เกิน 1,000,000)']);
            }
        }

        if ($currentQty === null && $minQty === null) {
            $this->validationError(['current_qty' => 'กรอกอย่างน้อยหนึ่งช่อง: สต็อกปัจจุบัน หรือ ขั้นต่ำ']);
        }

        $note = trim((string)($input['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            $this->validationError(['note' => 'หมายเหตุยาวเกิน 500 ตัวอักษร']);
        }

        $user = $this->roleMiddleware->getCurrentUser();

        try {
            $item = $this->stockService->adjust(
                $id,
                $currentQty,
                $minQty,
                $note,
                $user['id'] ?? null,
                $user['full_name'] ?? ''
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }

        $this->success(['item' => $item]);
    }
}
