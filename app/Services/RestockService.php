<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Vehicle;
use App\Models\Nurse;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\RefillBatch;
use App\Models\RestockRequest;
use App\Models\RestockItem;
use App\Models\RestockAction;
use App\Models\StockMovement;
use App\Config\Database;
use App\Exceptions\ApiException;

/**
 * เติมอุปกรณ์เข้ารถ "โดยตรง" (ไม่มีคลังสต็อกกลาง / ไม่มีขั้นตอนมอบหมาย-อนุมัติ)
 *
 * - รายการรอเติม (refer_restock_requests) ยังถูกสร้างจากผลตรวจเหมือนเดิม ใช้เป็น "สิ่งที่ต้องเติม"
 * - การเติม 1 ครั้ง = 1 refill batch (client_token กันกดซ้ำ) → เพิ่ม Lot ตามวันหมดอายุ / เพิ่มเครื่องไฟฟ้า
 *   → ปิดยอดในรายการรอเติมของรถคันนั้นตามจำนวนที่เติมจริง (เก่าสุดก่อน) — ทั้งหมดใน transaction เดียว
 */
class RestockService
{
    /** จำนวน Lot สูงสุดต่อรายการใน 1 ครั้ง (กัน payload ขยะ) */
    private const MAX_LOTS_PER_LINE = 20;

    private Vehicle $vehicleModel;
    private Nurse $nurseModel;
    private Item $itemModel;
    private ItemUnit $unitModel;
    private RefillBatch $batchModel;
    private RestockRequest $requestModel;
    private RestockItem $itemModel2;
    private RestockAction $actionModel;
    private VehicleStockService $stock;

    public function __construct()
    {
        $this->vehicleModel = new Vehicle();
        $this->nurseModel = new Nurse();
        $this->itemModel = new Item();
        $this->unitModel = new ItemUnit();
        $this->batchModel = new RefillBatch();
        $this->requestModel = new RestockRequest();
        $this->itemModel2 = new RestockItem();
        $this->actionModel = new RestockAction();
        $this->stock = new VehicleStockService();
    }

    public function getVehicles(): array
    {
        return $this->vehicleModel->all();
    }

    public function getActiveNurses(): array
    {
        return $this->nurseModel->getActive();
    }

    public function getRestockRequestsForAdmin(): array
    {
        return $this->requestModel->getAllWithDetailsForAdmin();
    }

    public function getRestockRequestsForStaff(?string $userId = null): array
    {
        // ไม่มีการมอบหมายงานแล้ว — ผู้เติมของทุกคนเห็นรายการรอเติมของทุกรถ (ยกเว้นที่ยกเลิก)
        return $this->requestModel->getAllWithDetailsForStaff(null);
    }

    public function getRestockRequest(string $id): ?array
    {
        return $this->requestModel->getWithDetails($id);
    }

    public function getByVehicle(string $carId): array
    {
        return $this->requestModel->getByVehicle($carId);
    }

    public function getActiveByCheck(string $checkId): ?array
    {
        return $this->requestModel->getActiveByCheck($checkId);
    }

    public function getAssignedToUser(string $userId): array
    {
        return $this->requestModel->getByAssignedUser($userId);
    }

    public function getCreatedByUser(string $userId): array
    {
        return $this->requestModel->getByCreatedByUser($userId);
    }

    public function getStats(): array
    {
        return [
            'pending' => $this->requestModel->getPendingCount(),
            'in_progress' => $this->requestModel->getInProgressCount(),
            'partial' => $this->requestModel->getPartialCount(),
            'completed' => $this->requestModel->getCompletedCount(),
        ];
    }

    // ==================== หน้าเติมของ ====================

    /**
     * ข้อมูลหน้าเติมของของรถ 1 คัน: ทุกรายการพร้อมยอดปัจจุบัน เป้าหมาย Lot/เครื่อง และยอดค้างในรายการรอเติม
     */
    public function getRefillSheet(string $carId): array
    {
        $vehicle = $this->vehicleModel->find($carId);
        if (!$vehicle) {
            throw new ApiException('ไม่พบรถ', 404);
        }
        $items = $this->itemModel->getActiveByVehicle($carId);
        $ids = array_map(fn($i) => (string)$i['id'], $items);
        $details = $this->stock->detailsForItems($ids);
        $outstanding = $this->itemModel2->getOutstandingForCar($carId, $ids);

        $rows = [];
        foreach ($items as $item) {
            $id = (string)$item['id'];
            $type = VehicleStockService::itemType($item);
            $target = $item['target_qty'] !== null && $item['target_qty'] !== '' ? round((float)$item['target_qty'], 2) : null;
            $current = Item::countedQty($item);
            $lots = $details['lots'][$id] ?? [];
            $rows[] = [
                'id' => $id,
                'name' => (string)$item['name'],
                'standard' => (string)$item['standard'],
                'unit' => (string)$item['unit'],
                'item_type' => $type,
                'target_qty' => $target,
                'current_qty' => $current,
                'counted' => $current !== null,
                'shortfall' => ($target !== null && $type !== VehicleStockService::TYPE_ELECTRIC)
                    ? round(max(0.0, $target - (float)($current ?? 0)), 2) : 0.0,
                'requested' => round((float)($outstanding[$id]['outstanding'] ?? 0), 2),
                'request_no' => $outstanding[$id]['no'] ?? null,
                'lots' => $lots,
                'expiry' => VehicleStockService::expirySummary($lots),
                'units' => $details['units'][$id] ?? [],
            ];
        }

        $open = array_values(array_filter(
            $this->requestModel->getByVehicle($carId),
            fn($r) => in_array($r['status'], [RestockRequest::STATUS_PENDING, RestockRequest::STATUS_IN_PROGRESS, RestockRequest::STATUS_PARTIAL], true)
        ));

        return [
            'vehicle' => $vehicle,
            'items' => $rows,
            'open_requests' => $open,
            'today' => VehicleStockService::today(),
            'expiry_warn_days' => VehicleStockService::EXPIRY_WARN_DAYS,
        ];
    }

    /**
     * เติมของเข้ารถโดยตรง — สำเร็จทั้งหมดหรือไม่บันทึกเลย
     *
     * @param array $input {client_token, note, lines: [{item_id, lots: [{qty, expiry_date}], units: [{label,status,battery_level,note}], retire_unit_ids: []}]}
     */
    public function refill(string $carId, array $input, ?array $user): array
    {
        $token = trim((string)($input['client_token'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            throw new ApiException('คำขอไม่สมบูรณ์ (ไม่มีรหัสกันบันทึกซ้ำ) กรุณาโหลดหน้าใหม่', 422);
        }
        $existing = $this->batchModel->findByToken($token);
        if ($existing) {
            return $this->batchResult($existing, true);
        }

        $lines = array_values(array_filter((array)($input['lines'] ?? []), 'is_array'));
        if (empty($lines)) {
            throw new ApiException('ยังไม่มีรายการที่จะเติม', 422);
        }

        $userId = isset($user['id']) ? (string)$user['id'] : null;
        $userName = (string)($user['full_name'] ?? '');
        $now = VehicleStockService::now();
        $today = VehicleStockService::today();

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $vehicle = $this->vehicleModel->find($carId);
            if (!$vehicle) {
                throw new ApiException('ไม่พบรถ', 404);
            }

            $batchId = 'rf-' . generate_id();
            try {
                $this->batchModel->create([
                    'id' => $batchId,
                    'car_id' => $carId,
                    'client_token' => $token,
                    'by_user_id' => $userId,
                    'by_name' => $userName,
                    'note' => mb_substr(trim((string)($input['note'] ?? '')), 0, 500),
                    'at' => $now,
                ]);
            } catch (\PDOException $e) {
                // คำขอเดียวกันถูกส่งซ้อนเข้ามาพร้อมกัน → อีกคำขอบันทึกไปแล้ว คืนผลเดิม
                if (stripos($e->getMessage(), 'uk_refill_token') !== false) {
                    $db->rollBack();
                    $dup = $this->batchModel->findByToken($token);
                    if ($dup) {
                        return $this->batchResult($dup, true);
                    }
                }
                throw $e;
            }

            $wanted = array_map(fn($l) => (string)($l['item_id'] ?? ''), $lines);
            $items = [];
            foreach ($this->itemModel->lockByIds($wanted) as $row) {
                $items[(string)$row['id']] = $row;
            }

            $ctx = ['batch_id' => $batchId, 'by_user_id' => $userId, 'by_name' => $userName, 'at' => $now];
            $applied = [];
            $warnings = [];
            $seen = [];

            foreach ($lines as $line) {
                $itemId = (string)($line['item_id'] ?? '');
                $item = $items[$itemId] ?? null;
                if (!$item || (string)$item['car_id'] !== $carId || empty($item['active'])) {
                    throw new ApiException('มีรายการที่ไม่ได้อยู่ในรถ ' . $vehicle['name'] . ' (กรุณาโหลดหน้าใหม่)', 422);
                }
                if (isset($seen[$itemId])) {
                    throw new ApiException($item['name'] . ': ส่งรายการซ้ำ — รวมทุก Lot ไว้ในรายการเดียว', 422);
                }
                $seen[$itemId] = true;

                $name = (string)$item['name'];
                $type = VehicleStockService::itemType($item);
                $before = Item::countedQty($item);

                if ($type === VehicleStockService::TYPE_ELECTRIC) {
                    $unitsBefore = (float)$this->unitModel->countActive($itemId);
                    $added = 0;
                    foreach (array_slice((array)($line['units'] ?? []), 0, self::MAX_LOTS_PER_LINE) as $u) {
                        $this->stock->addUnit($item, VehicleStockService::parseUnitInput((array)$u, $name), $ctx);
                        $added++;
                    }
                    $retired = 0;
                    $retireIds = array_map('strval', (array)($line['retire_unit_ids'] ?? []));
                    if ($retireIds) {
                        $active = [];
                        foreach ($this->unitModel->lockActiveByItem($itemId) as $u) {
                            $active[(string)$u['id']] = $u;
                        }
                        foreach ($retireIds as $rid) {
                            if (!isset($active[$rid])) {
                                throw new ApiException($name . ': ไม่พบเครื่องที่ต้องการปลดออก', 422);
                            }
                            $this->unitModel->update($rid, ['active' => 0, 'updated_at' => $now, 'updated_by' => $userName,
                                'note' => mb_substr('ปลดออก/เปลี่ยนเครื่อง ' . $now . ' ' . (string)$active[$rid]['note'], 0, 500)]);
                            $retired++;
                        }
                        $this->stock->syncItemTotal($item, $now);
                    }
                    if ($added === 0 && $retired === 0) {
                        throw new ApiException($name . ': ยังไม่ได้เพิ่มหรือปลดเครื่อง', 422);
                    }
                    $applied[] = [
                        'item_id' => $itemId, 'name' => $name, 'item_type' => $type,
                        'before' => $unitsBefore, 'added' => (float)$added, 'retired' => $retired,
                        'after' => (float)$this->unitModel->countActive($itemId), 'lots' => [],
                    ];
                    continue;
                }

                $lots = array_values(array_filter((array)($line['lots'] ?? []), 'is_array'));
                if (empty($lots)) {
                    throw new ApiException($name . ': ระบุจำนวนและวันหมดอายุอย่างน้อย 1 รายการ', 422);
                }
                if (count($lots) > self::MAX_LOTS_PER_LINE) {
                    throw new ApiException($name . ': แยกได้ไม่เกิน ' . self::MAX_LOTS_PER_LINE . ' วันหมดอายุต่อครั้ง', 422);
                }
                $addedLots = [];
                $total = 0.0;
                foreach ($lots as $i => $lot) {
                    $label = sprintf('%s (รายการที่ %d)', $name, $i + 1);
                    $qtyRaw = $lot['qty'] ?? null;
                    if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
                        throw new ApiException($label . ': จำนวนที่เติมต้องมากกว่า 0', 422);
                    }
                    $qty = round((float)$qtyRaw, 2);
                    if ($qty > InspectionService::MAX_QTY) {
                        throw new ApiException($label . ': จำนวนสูงเกินกำหนด', 422);
                    }
                    $expiry = trim((string)($lot['expiry_date'] ?? ''));
                    if ($expiry === '' || !VehicleStockService::validDate($expiry)) {
                        throw new ApiException($label . ': กรุณาระบุวันหมดอายุให้ถูกต้อง (วว/ดด/ปปปป)', 422);
                    }
                    if ($expiry < $today) {
                        throw new ApiException(sprintf('%s: วันหมดอายุ %s ผ่านไปแล้ว — ไม่อนุญาตให้เติมของหมดอายุ', $label, $expiry), 422);
                    }
                    if (VehicleStockService::expiryState($expiry, $today) === 'soon') {
                        $warnings[] = sprintf('%s: Lot หมดอายุ %s (ภายใน %d วัน)', $name, $expiry, VehicleStockService::EXPIRY_WARN_DAYS);
                    }
                    $addedLots[] = $this->stock->addLot($item, $qty, $expiry, 'REFILL', StockMovement::TYPE_REFILL,
                        $ctx + ['reason' => trim('เติมของเข้ารถ ' . (string)($input['note'] ?? ''))]);
                    $total = round($total + $qty, 2);
                }
                if ($before === null) {
                    $warnings[] = $name . ': รายการนี้ยังไม่เคยตรวจนับ ยอดคงเหลือจึงเท่ากับจำนวนที่เติมครั้งนี้ — ควรตรวจนับในเวรถัดไป';
                }
                $applied[] = [
                    'item_id' => $itemId, 'name' => $name, 'item_type' => $type,
                    'before' => $before, 'added' => $total,
                    'after' => (float)$this->itemModel->lockById($itemId)['current_qty'],
                    'lots' => $addedLots,
                ];
            }

            $requests = $this->applyToOpenRequests($carId, $applied, $userId ?? '', $userName, $now);

            $issues = $this->stock->reconcile($carId);
            if ($issues) {
                error_log('reconcile failed after refill ' . $batchId . ': ' . json_encode($issues, JSON_UNESCAPED_UNICODE));
                throw new \RuntimeException('ยอดคงเหลือไม่สอดคล้องกันหลังคำนวณ ระบบยกเลิกการบันทึกเพื่อป้องกันข้อมูลผิด');
            }

            $db->commit();
            return [
                'batch_id' => $batchId,
                'duplicate' => false,
                'applied' => $applied,
                'requests' => $requests,
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** ผลของ batch ที่บันทึกไปแล้ว (คืนให้คำขอที่ส่งซ้ำ — ไม่เติมซ้ำ) */
    private function batchResult(array $batch, bool $duplicate): array
    {
        $applied = [];
        foreach ((new StockMovement())->getByBatch((string)$batch['id']) as $mv) {
            $key = (string)$mv['item_id'];
            $applied[$key] = $applied[$key] ?? ['item_id' => $key, 'added' => 0.0, 'after' => 0.0];
            $applied[$key]['added'] = round($applied[$key]['added'] + (float)$mv['qty'], 2);
            $applied[$key]['after'] = (float)$mv['balance_after'];
        }
        return ['batch_id' => (string)$batch['id'], 'duplicate' => $duplicate, 'applied' => array_values($applied), 'requests' => [], 'warnings' => []];
    }

    /**
     * ปิดยอดในรายการรอเติมที่เปิดอยู่ของรถคันนี้ตามจำนวนที่เติมจริง (คำขอเก่าสุดก่อน)
     * เติมเกินยอดที่ขอได้ (เช่น เติมให้เต็มเป้าหมาย) — ส่วนเกินไม่ถูกนับเข้าคำขอ
     */
    private function applyToOpenRequests(string $carId, array $applied, string $userId, string $userName, string $now): array
    {
        $addedByItem = [];
        foreach ($applied as $a) {
            if ($a['item_type'] !== VehicleStockService::TYPE_ELECTRIC && $a['added'] > 0) {
                $addedByItem[$a['item_id']] = $a;
            }
        }
        if (empty($addedByItem)) {
            return [];
        }

        $open = array_filter(
            $this->requestModel->getByVehicle($carId),
            fn($r) => in_array($r['status'], [RestockRequest::STATUS_PENDING, RestockRequest::STATUS_IN_PROGRESS, RestockRequest::STATUS_PARTIAL], true)
        );
        usort($open, fn($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']));

        $left = array_map(fn($a) => (float)$a['added'], $addedByItem);
        $touched = [];
        foreach ($open as $request) {
            $request = $this->requestModel->lockById((string)$request['id']);
            if (!$request) {
                continue;
            }
            $actionLines = [];
            foreach ($this->itemModel2->getByRequest((string)$request['id']) as $line) {
                $itemId = (string)$line['item_id'];
                if (($left[$itemId] ?? 0) <= 0) {
                    continue;
                }
                $locked = $this->itemModel2->lockLine((string)$request['id'], $itemId);
                $requested = (float)$locked['qty_requested'];
                $filled = (float)$locked['qty_filled'];
                $need = round(max(0.0, $requested - $filled), 2);
                if ($need <= 0) {
                    continue;
                }
                $take = round(min($need, $left[$itemId]), 2);
                $left[$itemId] = round($left[$itemId] - $take, 2);
                $newFilled = round($filled + $take, 2);
                $a = $addedByItem[$itemId];
                $this->itemModel2->writeQuantities((string)$request['id'], $itemId, $newFilled, $newFilled, $a['before'], $a['after']);
                $actionLines[] = ['itemId' => $itemId, 'name' => $a['name'], 'qty' => VehicleStockService::fmt($take),
                    'stockBefore' => $a['before'], 'stockAfter' => $a['after']];
            }
            if (empty($actionLines)) {
                continue;
            }
            $status = $this->calculateNewStatus((string)$request['id']);
            if ($status !== (string)$request['status']) {
                $this->requestModel->updateStatus((string)$request['id'], $status);
            }
            $this->actionModel->create([
                'request_id' => (string)$request['id'],
                'type' => 'restock',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => 'เติมเข้ารถโดยตรง ' . count($actionLines) . ' รายการ',
                'action_lines' => $actionLines,
            ]);
            $touched[] = ['id' => (string)$request['id'], 'no' => (string)$request['no'], 'status' => $status, 'lines' => count($actionLines)];
        }
        return $touched;
    }

    /**
     * ยกเลิกรายการรอเติม (ผู้ดูแลระบบ หรือ staff เมื่อรายการยังไม่ถูกมอบหมาย — ข้อมูลมอบหมายรุ่นเก่า)
     * ไม่มีการคืนของเข้าคลัง เพราะรายการรอเติมไม่เคยตัด/เพิ่มยอดใด ๆ
     */
    public function cancelRestock(string $id, string $userId, string $userName, string $reason, ?array $operator = null): ?array
    {
        if (empty(trim($reason))) {
            throw new \RuntimeException('กรุณาระบุเหตุผลการยกเลิก');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $request = $this->requestModel->lockById($id);
            if (!$request) {
                $db->rollBack();
                return null;
            }
            if (($operator['role'] ?? '') !== 'admin') {
                $assigned = trim((string)($request['assigned_to_user_id'] ?? ''));
                if ($assigned !== '' && $assigned !== (string)($operator['id'] ?? '')) {
                    throw new \RuntimeException('รายการนี้ถูกมอบหมายให้ผู้อื่นในระบบเดิม — ให้ผู้ดูแลระบบยกเลิก');
                }
            }
            if (in_array($request['status'], [RestockRequest::STATUS_COMPLETED, RestockRequest::STATUS_CANCELLED], true)) {
                throw new \RuntimeException(sprintf('สถานะ "%s" ไม่อนุญาตให้ยกเลิก', (string)$request['status']));
            }

            $this->requestModel->update($id, ['status' => RestockRequest::STATUS_CANCELLED]);
            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'cancel',
                'at' => VehicleStockService::now(),
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => $reason,
                'action_lines' => [],
            ]);

            $db->commit();
            return $this->requestModel->getWithDetails($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** PENDING (ยังไม่เติม) → PARTIAL (เติมบางส่วน) → COMPLETED (ครบทุกบรรทัด) */
    private function calculateNewStatus(string $requestId): string
    {
        $allCompleted = true;
        $anyFilled = false;
        foreach ($this->itemModel2->getByRequest($requestId) as $item) {
            $requested = (float)($item['qty_requested'] ?? 0);
            $filled = (float)($item['qty_filled'] ?? 0);
            if ($filled > 0) {
                $anyFilled = true;
            }
            if ($requested > 0 && $filled < $requested) {
                $allCompleted = false;
            }
        }
        if ($allCompleted) {
            return RestockRequest::STATUS_COMPLETED;
        }
        return $anyFilled ? RestockRequest::STATUS_PARTIAL : RestockRequest::STATUS_PENDING;
    }

    /** อุปกรณ์ที่หมดอายุแล้ว/ใกล้หมดอายุ — อ่านจาก Lot จริง (ไม่ใช่ข้อความ expiry_text เดิม) */
    public function getExpiringItems(int $days = 60): array
    {
        $db = Database::getConnection();
        $limit = (new \DateTimeImmutable(VehicleStockService::today()))->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
        $stmt = $db->prepare(
            "SELECT i.id, i.car_id, i.name, l.id AS lot_id, l.expiry_date, l.qty_remaining
               FROM refer_item_lots l JOIN refer_items i ON i.id = l.item_id
              WHERE i.active = 1 AND l.qty_remaining > 0 AND l.expiry_date IS NOT NULL AND l.expiry_date <= ?
              ORDER BY l.expiry_date"
        );
        $stmt->execute([$limit]);
        $today = new \DateTimeImmutable(VehicleStockService::today());
        return array_map(fn($r) => $r + [
            'days_left' => (int)$today->diff(new \DateTimeImmutable($r['expiry_date']))->format('%r%a'),
        ], $stmt->fetchAll());
    }
}
