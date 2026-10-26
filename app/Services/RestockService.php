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
        $details = $this->stock->detailsForItems($items);
        $outstanding = $this->itemModel2->getOutstandingForCar($carId, $ids);

        $rows = [];
        foreach ($items as $item) {
            $id = (string)$item['id'];
            $type = VehicleStockService::itemType($item);
            $target = $item['target_qty'] !== null && $item['target_qty'] !== '' ? round((float)$item['target_qty'], 2) : null;
            $current = Item::countedQty($item);
            $lots = $details['lots'][$id] ?? [];
            $stock = $details['stock'][$id];
            $counted = in_array($type, VehicleStockService::COUNTED_TYPES, true);
            // ยอดพร้อมใช้ไม่รวมของหมดอายุ → ใช้คำนวณที่ต้องเติม; ยอดรวมยังแสดงตามจริง (ไม่ตัดยอดเกินทิ้ง)
            $units = $details['units'][$id] ?? [];
            // ไม่มีวันหมดอายุ: ยอด = จำนวนเครื่องบนรถ (ไม่พร้อมใช้ยังนับเป็นเครื่อง แต่ขึ้นเป็น "ตรวจสอบ/เปลี่ยน")
            $usable = $counted ? round($stock['available'] + $stock['unknown'], 2) : (float)count($units);
            $notReady = count(array_filter($units, fn($u) => ($u['status'] ?? '') !== ItemUnit::STATUS_READY));
            $rows[] = [
                'id' => $id,
                'name' => (string)$item['name'],
                'standard' => (string)$item['standard'],
                'unit' => (string)$item['unit'],
                'item_type' => $type,
                'target_qty' => $target,
                'current_qty' => $current,
                'counted' => $current !== null,
                'stock' => $stock,
                'requires_expiry' => VehicleStockService::requiresExpiry($item),
                'shortfall' => $target !== null ? round(max(0.0, $target - $usable), 2) : 0.0,
                'not_ready' => $notReady,
                'has_battery' => VehicleStockService::hasBattery($item),
                'has_pressure' => VehicleStockService::hasPressure($item),
                'over_target' => ($target !== null && $counted && (float)($current ?? 0) > $target),
                'requested' => round((float)($outstanding[$id]['outstanding'] ?? 0), 2),
                'request_no' => $outstanding[$id]['no'] ?? null,
                'lots' => $lots,
                'expiry' => VehicleStockService::expirySummary($lots),
                'units' => $units,
                'battery_max_bars' => VehicleStockService::batteryMax($item),
                'battery_low_bars' => $item['battery_low_bars'] !== null ? (int)$item['battery_low_bars'] : null,
                'o2_low_psi' => $item['o2_low_psi'] !== null ? (int)$item['o2_low_psi'] : null,
                'expiry_warn_days' => VehicleStockService::warnDays($item),
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
            'is_admin' => false,
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

                if (VehicleStockService::usesUnits($item)) {
                    $unitsBefore = (float)$this->unitModel->countActive($itemId);
                    $added = 0;
                    foreach (array_slice((array)($line['units'] ?? []), 0, self::MAX_LOTS_PER_LINE) as $u) {
                        $this->stock->addUnit($item, VehicleStockService::parseUnitInput((array)$u, $item, (string)($u['label'] ?? '')), $ctx);
                        $added++;
                    }
                    $active = [];
                    foreach ($this->unitModel->lockActiveByItem($itemId) as $u) {
                        $active[(string)$u['id']] = $u;
                    }
                    // อัปเดตสถานะ/แบต/แรงดันของเครื่องเดิม (เช่น ชาร์จแบตแล้ว, ซ่อมแล้วพร้อมใช้)
                    $updated = 0;
                    foreach (array_slice(array_values(array_filter((array)($line['unit_updates'] ?? []), 'is_array')), 0, self::MAX_LOTS_PER_LINE) as $u) {
                        $uid = (string)($u['id'] ?? '');
                        if (!isset($active[$uid])) {
                            throw new ApiException($name . ': ไม่พบเครื่องที่ต้องการอัปเดต', 422);
                        }
                        $parsed = VehicleStockService::parseUnitInput($u, $item, (string)$active[$uid]['label']);
                        $this->unitModel->update($uid, [
                            'status' => $parsed['status'], 'battery_level' => $parsed['battery_level'],
                            'pressure_psi' => $parsed['pressure_psi'], 'note' => $parsed['note'],
                            'updated_at' => $now, 'updated_by' => $userName,
                        ]);
                        $updated++;
                    }
                    $retired = 0;
                    $retireIds = array_map('strval', (array)($line['retire_unit_ids'] ?? []));
                    if ($retireIds) {
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
                    if ($updated > 0) {
                        $this->stock->syncItemTotal($item, $now);
                    }
                    if ($added === 0 && $retired === 0 && $updated === 0) {
                        throw new ApiException($name . ': ยังไม่ได้เพิ่ม ปลด หรืออัปเดตเครื่อง', 422);
                    }
                    $notReady = 0;
                    foreach ($this->unitModel->lockActiveByItem($itemId) as $u) {
                        $notReady += (string)$u['status'] !== ItemUnit::STATUS_READY ? 1 : 0;
                    }
                    $applied[] = [
                        'item_id' => $itemId, 'name' => $name, 'item_type' => $type,
                        'before' => $unitsBefore, 'added' => (float)$added, 'retired' => $retired, 'updated' => $updated,
                        'not_ready' => $notReady,
                        'after' => (float)$this->unitModel->countActive($itemId), 'lots' => [],
                    ];
                    continue;
                }

                $lots = array_values(array_filter((array)($line['lots'] ?? []), 'is_array'));
                $isPiece = $type === VehicleStockService::TYPE_INDIVIDUAL;
                if ($isPiece) {
                    // ของแบบชิ้น: pieces = วันหมดอายุทีละชิ้น (1 รายการ = 1 ชิ้น) — แปลงเป็นล็อตจำนวน 1
                    foreach ((array)($line['pieces'] ?? []) as $p) {
                        $lots[] = is_array($p) ? ['qty' => 1] + $p : ['qty' => 1, 'expiry_date' => (string)$p];
                    }
                    $pieceCount = 0;
                    foreach ($lots as $l) {
                        if (!empty($l['lot_id'])) {
                            throw new ApiException($name . ': ของแบบชิ้นเติมเข้าชิ้นเดิมไม่ได้ — เพิ่มเป็นชิ้นใหม่พร้อมวันหมดอายุ', 422);
                        }
                        $q = $l['qty'] ?? null;
                        if (!is_numeric($q) || (float)$q <= 0 || floor((float)$q) != (float)$q) {
                            throw new ApiException($name . ': จำนวนชิ้นต้องเป็นจำนวนเต็มมากกว่า 0', 422);
                        }
                        $pieceCount += (int)$q;
                    }
                    if (isset($line['qty']) && (int)$line['qty'] !== $pieceCount) {
                        throw new ApiException(sprintf('%s: เติม %d ชิ้น แต่ระบุวันหมดอายุ %d ชิ้น — จำนวนต้องตรงกัน', $name, (int)$line['qty'], $pieceCount), 422);
                    }
                }
                if (empty($lots)) {
                    throw new ApiException($name . ': ระบุจำนวน' . (VehicleStockService::requiresExpiry($item) ? 'และวันหมดอายุ' : '') . 'อย่างน้อย 1 รายการ', 422);
                }
                if (count($lots) > self::MAX_LOTS_PER_LINE) {
                    throw new ApiException($name . ': แยกได้ไม่เกิน ' . self::MAX_LOTS_PER_LINE . ' ล็อตต่อครั้ง', 422);
                }
                $addedLots = [];
                $total = 0.0;
                $reason = trim('เติมของเข้ารถ ' . (string)($input['note'] ?? ''));
                foreach ($lots as $i => $lot) {
                    $label = sprintf('%s (ล็อตที่ %d)', $name, $i + 1);
                    $qtyRaw = $lot['qty'] ?? null;
                    if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
                        throw new ApiException($label . ': จำนวนที่เติมต้องมากกว่า 0', 422);
                    }
                    $qty = round((float)$qtyRaw, 2);
                    if ($qty > InspectionService::MAX_QTY) {
                        throw new ApiException($label . ': จำนวนสูงเกินกำหนด', 422);
                    }

                    // เติมเข้าล็อตเดิม (ล็อตเดียวกัน วันหมดอายุเดียวกัน) — ไม่สร้างล็อตซ้ำ
                    $targetLot = trim((string)($lot['lot_id'] ?? ''));
                    if ($targetLot !== '') {
                        $addedLots[] = $this->stock->topUpLot($item, $targetLot, $qty, $ctx + ['reason' => $reason . ' (เติมเข้าล็อตเดิม)']);
                        $total = round($total + $qty, 2);
                        continue;
                    }

                    $expiry = trim((string)($lot['expiry_date'] ?? ''));
                    if ($expiry === '' && VehicleStockService::requiresExpiry($item)) {
                        throw new ApiException($label . ': กรุณาระบุวันหมดอายุ (ของสิ้นเปลืองต้องมีวันหมดอายุ)', 422);
                    }
                    if ($expiry !== '' && !VehicleStockService::validDate($expiry)) {
                        throw new ApiException($label . ': วันหมดอายุไม่ถูกต้อง', 422);
                    }
                    if ($expiry !== '' && $expiry < $today) {
                        throw new ApiException(sprintf('%s: วันหมดอายุ %s ผ่านไปแล้ว — ไม่อนุญาตให้เติมของหมดอายุ', $label, $expiry), 422);
                    }
                    if ($expiry !== '' && VehicleStockService::expiryState($expiry, $today, VehicleStockService::warnDays($item)) === 'soon') {
                        $warnings[] = sprintf('%s: ล็อตหมดอายุ %s (ภายใน %d วัน)', $name, $expiry, VehicleStockService::warnDays($item));
                    }
                    $received = VehicleStockService::optionalPastDate($lot['received_date'] ?? '', $label . ': วันที่รับเข้า') ?? $today;
                    $meta = ['lot_number' => $lot['lot_number'] ?? '', 'received_date' => $received, 'note' => $lot['note'] ?? ''];
                    if ($isPiece) {
                        // 1 แถวต่อ 1 ชิ้น — รหัสชิ้น = id ของแถว ใช้ตามรอยตอนตัด FEFO
                        array_push($addedLots, ...$this->stock->addPieces($item, array_fill(0, (int)$qty, $expiry), 'REFILL',
                            StockMovement::TYPE_REFILL, $ctx + ['reason' => $reason], $meta));
                    } else {
                        $addedLots[] = $this->stock->addLot($item, $qty, $expiry === '' ? null : $expiry, 'REFILL', StockMovement::TYPE_REFILL,
                            $ctx + ['reason' => $reason], $meta);
                    }
                    $total = round($total + $qty, 2);
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

    /**
     * จัดการล็อต 1 ล็อต — ทุกการเปลี่ยนยอดเป็น movement ที่ย้อนดูได้ ไม่มีการลบแถวล็อต
     *  edit   (admin): เลขล็อต / วันรับเข้า / หมายเหตุ / วันหมดอายุ (ต้องมีเหตุผล)
     *  adjust (admin): ปรับยอดล็อตเป็นจำนวนที่นับได้จริง (ต้องมีเหตุผล)
     *  void   (admin): ยกเลิกล็อต — ตัดยอดที่เหลือออก สถานะ void (ใช้แทนการลบ)
     *  expire (ทุกบทบาท): จำหน่ายล็อตที่หมดอายุแล้วออกจากรถ
     */
    public function manageLot(string $lotId, string $action, array $input, ?array $user): array
    {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        if (in_array($action, ['edit', 'adjust', 'void'], true) && !$isAdmin) {
            throw new ApiException('เฉพาะผู้ดูแลระบบเท่านั้นที่แก้ไข/ปรับยอด/ยกเลิกล็อตได้', 403);
        }
        if (!in_array($action, ['edit', 'adjust', 'void', 'expire'], true)) {
            throw new ApiException('ไม่รู้จักคำสั่ง', 400);
        }
        $ctx = [
            'by_user_id' => isset($user['id']) ? (string)$user['id'] : null,
            'by_name' => (string)($user['full_name'] ?? ''),
            'at' => VehicleStockService::now(),
        ];

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $lot = (new \App\Models\ItemLot())->find($lotId);
            if (!$lot) {
                throw new ApiException('ไม่พบล็อต', 404);
            }
            $item = $this->itemModel->lockById((string)$lot['item_id']);
            if (!$item || !VehicleStockService::isConsumable($item)) {
                throw new ApiException('ล็อตนี้ไม่อยู่ในรายการที่นับเป็นจำนวน', 422);
            }
            $reason = trim((string)($input['reason'] ?? ''));
            $result = ['action' => $action, 'lot_id' => $lotId, 'item_id' => (string)$item['id']];
            switch ($action) {
                case 'edit':
                    $result['changed'] = $this->stock->editLot($item, $lotId, $input, $ctx);
                    break;
                case 'adjust':
                    if (!isset($input['qty']) || !is_numeric($input['qty'])) {
                        throw new ApiException('กรุณาระบุจำนวนที่นับได้จริงของล็อต', 422);
                    }
                    $result['delta'] = $this->stock->setLotQty($item, $lotId, round((float)$input['qty'], 2), $reason, $ctx);
                    break;
                case 'void':
                    $this->stock->voidLot($item, $lotId, $reason, $ctx);
                    break;
                case 'expire':
                    $result['removed'] = $this->stock->expireLot($item, $lotId, $ctx);
                    break;
            }
            $issues = $this->stock->reconcile((string)$item['car_id']);
            if ($issues) {
                error_log('reconcile failed after lot ' . $action . ' ' . $lotId . ': ' . json_encode($issues, JSON_UNESCAPED_UNICODE));
                throw new \RuntimeException('ยอดคงเหลือไม่สอดคล้องกันหลังคำนวณ ระบบยกเลิกการบันทึกเพื่อป้องกันข้อมูลผิด');
            }
            $db->commit();
            $result['current_qty'] = (float)($this->itemModel->find((string)$item['id'])['current_qty'] ?? 0);
            return $result;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** ประวัติการเคลื่อนไหวของอุปกรณ์ 1 รายการ (ล่าสุดก่อน) */
    public function getItemMovements(string $itemId, int $limit = 100): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT m.*, l.lot_number, l.expiry_date FROM refer_stock_movements m
               LEFT JOIN refer_item_lots l ON l.id = m.lot_id
              WHERE m.item_id = ? ORDER BY m.at DESC, m.id DESC LIMIT " . max(1, min($limit, 500))
        );
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
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
        // ทุกประเภท: จำนวนที่เพิ่มจริงไปปิดบรรทัด "เติม" | ไม่มีวันหมดอายุ: ไม่มีเครื่องไม่พร้อมใช้เหลือ → ปิดบรรทัด "ตรวจสอบ/เปลี่ยน"
        $addedByItem = [];
        foreach ($applied as $a) {
            if ($a['added'] > 0 || (isset($a['not_ready']) && $a['not_ready'] === 0)) {
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
                if (!isset($addedByItem[$itemId])) {
                    continue;
                }
                $isReplace = ($line['line_type'] ?? 'refill') === 'replace';
                if ($isReplace ? ($addedByItem[$itemId]['not_ready'] ?? 1) !== 0 : ($left[$itemId] ?? 0) <= 0) {
                    continue;
                }
                $locked = $this->itemModel2->lockLine((string)$request['id'], $itemId);
                $requested = (float)$locked['qty_requested'];
                $filled = (float)$locked['qty_filled'];
                $need = round(max(0.0, $requested - $filled), 2);
                if ($need <= 0) {
                    continue;
                }
                if ($isReplace) {
                    $take = $need;   // ตรวจสอบ/เปลี่ยนเสร็จ (ทุกเครื่องพร้อมใช้) — ไม่ใช้จำนวนที่เพิ่ม
                } else {
                    $take = round(min($need, $left[$itemId]), 2);
                    $left[$itemId] = round($left[$itemId] - $take, 2);
                }
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
