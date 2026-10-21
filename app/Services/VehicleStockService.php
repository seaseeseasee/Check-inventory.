<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\ItemLot;
use App\Models\ItemUnit;
use App\Models\StockMovement;

/**
 * แหล่งความจริงเดียวของ "ยอดอุปกรณ์บนรถ" (แทนคลังสต็อกกลางที่ถูกยกเลิก)
 *
 * แหล่งข้อมูลหลัก:
 *   - ของสิ้นเปลือง (box / piece): refer_item_lots.qty_remaining  → current_qty = SUM(lots)
 *   - เครื่องไฟฟ้า (electric):       refer_item_units (active = 1) → current_qty = จำนวนเครื่อง
 *   ทุกการเปลี่ยนยอดเขียน refer_stock_movements 1 แถวต่อ 1 Lot ที่ถูกแตะ (ledger เพิ่มอย่างเดียว)
 *
 * ทุก method ที่เขียนข้อมูล "ต้องถูกเรียกภายใน transaction ของผู้เรียก" และล็อกแถวก่อนอ่านยอด
 * (FOR UPDATE) — service นี้ไม่ begin/commit เอง เพื่อให้การตรวจ/การเติมสำเร็จหรือล้มทั้งชุด
 */
class VehicleStockService
{
    public const TYPE_BOX = 'box';
    public const TYPE_PIECE = 'piece';
    public const TYPE_ELECTRIC = 'electric';
    public const ITEM_TYPES = [self::TYPE_BOX, self::TYPE_PIECE, self::TYPE_ELECTRIC];

    /** แจ้งเตือนใกล้หมดอายุเมื่อเหลือไม่เกินกี่วัน */
    public const EXPIRY_WARN_DAYS = 30;

    private Item $itemModel;
    private ItemLot $lotModel;
    private ItemUnit $unitModel;
    private StockMovement $movementModel;

    public function __construct()
    {
        $this->itemModel = new Item();
        $this->lotModel = new ItemLot();
        $this->unitModel = new ItemUnit();
        $this->movementModel = new StockMovement();
    }

    public static function isConsumable(array $item): bool
    {
        return self::itemType($item) !== self::TYPE_ELECTRIC;
    }

    public static function itemType(array $item): string
    {
        $type = (string)($item['item_type'] ?? '');
        if (in_array($type, self::ITEM_TYPES, true)) {
            return $type;
        }
        return !empty($item['is_battery']) ? self::TYPE_ELECTRIC : self::TYPE_PIECE;
    }

    /** วันที่ปัจจุบันตามเวลาไทย (Y-m-d) */
    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
    }

    public static function now(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** expired | soon | ok | unknown */
    public static function expiryState(?string $expiryDate, ?string $today = null): string
    {
        if ($expiryDate === null || $expiryDate === '') {
            return 'unknown';
        }
        $today = $today ?? self::today();
        if ($expiryDate < $today) {
            return 'expired';
        }
        $limit = (new \DateTimeImmutable($today))->modify('+' . self::EXPIRY_WARN_DAYS . ' days')->format('Y-m-d');
        return $expiryDate <= $limit ? 'soon' : 'ok';
    }

    /** ตรวจรูปแบบวันหมดอายุ YYYY-MM-DD และเป็นวันที่มีจริง */
    public static function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    // ==================== อ่าน (ไม่ล็อก) ====================

    /**
     * Lot และเครื่องของอุปกรณ์หลายรายการ สำหรับแสดงผล
     * @return array{lots: array<string, array>, units: array<string, array>}
     */
    public function detailsForItems(array $itemIds): array
    {
        $today = self::today();
        $lots = [];
        foreach ($this->lotModel->getOpenByItems($itemIds) as $itemId => $rows) {
            foreach ($rows as $row) {
                $lots[$itemId][] = self::presentLot($row, $today);
            }
        }
        return ['lots' => $lots, 'units' => $this->unitModel->getActiveByItems($itemIds)];
    }

    public static function presentLot(array $row, ?string $today = null): array
    {
        $expiry = $row['expiry_date'] ?? null;
        return [
            'id' => (string)$row['id'],
            'expiry_date' => $expiry,
            'qty' => round((float)$row['qty_remaining'], 2),
            'state' => self::expiryState($expiry, $today),
        ];
    }

    /** สรุปวันหมดอายุของรายการจาก Lot: วันใกล้สุด + สถานะแย่สุด */
    public static function expirySummary(array $lots): array
    {
        $rank = ['expired' => 3, 'soon' => 2, 'unknown' => 1, 'ok' => 0];
        $nearest = null;
        $worst = null;
        foreach ($lots as $lot) {
            if ($lot['expiry_date'] !== null && ($nearest === null || $lot['expiry_date'] < $nearest)) {
                $nearest = $lot['expiry_date'];
            }
            if ($worst === null || $rank[$lot['state']] > $rank[$worst]) {
                $worst = $lot['state'];
            }
        }
        return ['nearest' => $nearest, 'state' => $worst];
    }

    // ==================== เขียน (ต้องอยู่ใน transaction ของผู้เรียก) ====================

    /**
     * ตัดของสิ้นเปลืองออกจากรถแบบ FEFO (หมดอายุก่อนออกก่อน; Lot ไม่ทราบวันหมดอายุออกท้ายสุด)
     * คืนรายการ Lot ที่ถูกตัด ถ้าของในทุก Lot รวมกันไม่พอ จะ throw (ห้ามยอดติดลบ)
     */
    public function consume(array $item, float $qty, string $type, array $ctx): array
    {
        $qty = round($qty, 2);
        if ($qty <= 0) {
            return [];
        }
        $itemId = (string)$item['id'];
        $lots = $this->lotModel->lockOpenByItem($itemId);
        $available = round(array_sum(array_map(fn($l) => (float)$l['qty_remaining'], $lots)), 2);
        if ($qty > $available) {
            throw new \RuntimeException(sprintf(
                '%s: ต้องการตัด %s แต่บนรถมีเพียง %s',
                (string)$item['name'],
                self::fmt($qty),
                self::fmt($available)
            ));
        }

        $balance = $this->lotModel->sumRemaining($itemId);
        $taken = [];
        $left = $qty;
        foreach ($lots as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = round(min($left, (float)$lot['qty_remaining']), 2);
            $this->lotModel->setRemaining((string)$lot['id'], (float)$lot['qty_remaining'] - $take);
            $balance = round($balance - $take, 2);
            $this->writeMovement($item, (string)$lot['id'], $type, -$take, $balance, $ctx);
            $taken[] = ['lot_id' => (string)$lot['id'], 'expiry_date' => $lot['expiry_date'], 'qty' => $take];
            $left = round($left - $take, 2);
        }

        $this->syncItemTotal($item, $ctx['at'] ?? self::now());
        return $taken;
    }

    /** เพิ่ม Lot ใหม่บนรถ (เติมของ / ปรับยอดขึ้น) */
    public function addLot(array $item, float $qty, ?string $expiryDate, string $source, string $type, array $ctx): array
    {
        $qty = round($qty, 2);
        if ($qty <= 0) {
            throw new \RuntimeException('จำนวนที่เพิ่มต้องมากกว่า 0');
        }
        $at = $ctx['at'] ?? self::now();
        $lotId = 'lot-' . generate_id();
        $this->lotModel->create([
            'id' => $lotId,
            'item_id' => (string)$item['id'],
            'expiry_date' => $expiryDate,
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'source' => $source,
            'created_at' => $at,
            'created_by' => (string)($ctx['by_name'] ?? ''),
        ]);
        $balance = $this->lotModel->sumRemaining((string)$item['id']);
        $this->writeMovement($item, $lotId, $type, $qty, $balance, $ctx);
        $this->syncItemTotal($item, $at);
        return ['lot_id' => $lotId, 'expiry_date' => $expiryDate, 'qty' => $qty];
    }

    /**
     * ย้อนผลทั้งหมดที่รอบตรวจหนึ่งทำกับ Lot (ใช้ก่อนบันทึกการแก้ไขรอบเดิม)
     * คำนวณจากยอดสุทธิใน ledger จึงย้อนซ้ำกี่ครั้งก็ไม่เกิน (หลังย้อนแล้วยอดสุทธิ = 0)
     */
    public function reverseCheck(string $checkId, array $ctx): void
    {
        $touched = [];
        foreach ($this->movementModel->netByLotForCheck($checkId) as $row) {
            $lot = $this->lotModel->lockById((string)$row['lot_id']);
            if (!$lot) {
                continue;
            }
            $item = $touched[(string)$row['item_id']] ?? $this->itemModel->lockById((string)$row['item_id']);
            if (!$item) {
                continue;
            }
            $touched[(string)$item['id']] = $item;

            $undo = round(-(float)$row['net'], 2);
            $newRemaining = round((float)$lot['qty_remaining'] + $undo, 2);
            if ($newRemaining < 0) {
                // ของที่รอบนี้เคยเพิ่มเข้ามาถูกใช้ไปแล้วโดยรายการอื่น → ย้อนได้เท่าที่เหลือ ส่วนเกินตัดจาก Lot อื่นแบบ FEFO
                $this->lotModel->setRemaining((string)$lot['id'], 0);
                $balance = $this->lotModel->sumRemaining((string)$item['id']);
                $this->writeMovement($item, (string)$lot['id'], StockMovement::TYPE_REVERSAL, -(float)$lot['qty_remaining'], $balance, $ctx + ['check_id' => $checkId]);
                $this->consume($item, -$newRemaining, StockMovement::TYPE_REVERSAL, $ctx + ['check_id' => $checkId]);
                continue;
            }
            $this->lotModel->setRemaining((string)$lot['id'], $newRemaining);
            $balance = $this->lotModel->sumRemaining((string)$item['id']);
            $this->writeMovement($item, (string)$lot['id'], StockMovement::TYPE_REVERSAL, $undo, $balance, $ctx + ['check_id' => $checkId]);
        }
        foreach ($touched as $item) {
            $this->syncItemTotal($item, $ctx['at'] ?? self::now());
        }
    }

    /** เขียน current_qty ให้ตรงกับแหล่งข้อมูลหลัก (Lot หรือจำนวนเครื่อง) + ประทับว่าตรวจนับแล้ว */
    public function syncItemTotal(array $item, string $at): float
    {
        $itemId = (string)$item['id'];
        if (self::isConsumable($item)) {
            $total = $this->lotModel->sumRemaining($itemId);
            // expiry_text เดิมยังถูกอ่านโดยหน้าประวัติ/รายงานรุ่นเก่า → เก็บวันหมดอายุที่ใกล้สุดของ Lot ที่ยังมีของ
            $open = $this->lotModel->getOpenByItems([$itemId])[$itemId] ?? [];
            $dates = array_filter(array_column($open, 'expiry_date'));
            $this->itemModel->updateExpiry($itemId, $dates ? min($dates) : '');
        } else {
            $total = (float)$this->unitModel->countActive($itemId);
        }
        $this->itemModel->updateStock($itemId, $total, $at);
        return $total;
    }

    /** บันทึกเครื่องไฟฟ้าใหม่ 1 เครื่อง */
    public function addUnit(array $item, array $unit, array $ctx): array
    {
        $at = $ctx['at'] ?? self::now();
        $id = 'unit-' . generate_id();
        $this->unitModel->create([
            'id' => $id,
            'item_id' => (string)$item['id'],
            'label' => mb_substr(trim((string)($unit['label'] ?? '')), 0, 100),
            'status' => $unit['status'],
            'battery_level' => $unit['battery_level'],
            'note' => mb_substr(trim((string)($unit['note'] ?? '')), 0, 500),
            'active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
            'updated_by' => (string)($ctx['by_name'] ?? ''),
        ]);
        $this->syncItemTotal($item, $at);
        return ['id' => $id] + $unit;
    }

    /**
     * ตรวจความถูกต้องของข้อมูลเครื่องไฟฟ้า 1 เครื่องจาก client
     * @return array{status:string,battery_level:int,note:string,label:string}
     */
    public static function parseUnitInput(array $raw, string $itemName): array
    {
        $status = (string)($raw['status'] ?? '');
        if (!in_array($status, [ItemUnit::STATUS_READY, ItemUnit::STATUS_NOT_READY], true)) {
            throw new \RuntimeException($itemName . ': กรุณาเลือกสถานะ พร้อมใช้งาน / ไม่พร้อมใช้งาน');
        }
        $level = $raw['battery_level'] ?? null;
        if (!is_numeric($level) || (int)$level != $level || (int)$level < 1 || (int)$level > 4) {
            throw new \RuntimeException($itemName . ': ระดับแบตเตอรี่ต้องเป็น 1-4 ขีด');
        }
        $note = trim((string)($raw['note'] ?? ''));
        if ($status === ItemUnit::STATUS_NOT_READY && $note === '') {
            throw new \RuntimeException($itemName . ': เครื่องไม่พร้อมใช้งาน ต้องระบุสาเหตุ (เช่น แบตเสื่อม ชำรุด ส่งซ่อม)');
        }
        return [
            'status' => $status,
            'battery_level' => (int)$level,
            'note' => mb_substr($note, 0, 500),
            'label' => mb_substr(trim((string)($raw['label'] ?? '')), 0, 100),
        ];
    }

    /**
     * กระทบยอด: หาอุปกรณ์ที่ current_qty ≠ ยอดจากแหล่งข้อมูลหลัก หรือ ledger ≠ Lot
     * (ใช้ตรวจสุขภาพข้อมูล / ทดสอบ — ไม่แก้ข้อมูลเอง)
     */
    public function reconcile(?string $carId = null): array
    {
        $db = \App\Config\Database::getConnection();
        $sql = "SELECT i.id, i.car_id, i.name, i.item_type, i.current_qty,
                       (SELECT COALESCE(SUM(l.qty_remaining),0) FROM refer_item_lots l WHERE l.item_id = i.id) AS lots_qty,
                       (SELECT COALESCE(SUM(m.qty),0) FROM refer_stock_movements m WHERE m.item_id = i.id AND m.lot_id IS NOT NULL) AS ledger_qty,
                       (SELECT COUNT(*) FROM refer_item_units u WHERE u.item_id = i.id AND u.active = 1) AS unit_count
                  FROM refer_items i
                 WHERE i.stock_updated_at IS NOT NULL" . ($carId !== null ? " AND i.car_id = ?" : "");
        $stmt = $db->prepare($sql);
        $stmt->execute($carId !== null ? [$carId] : []);
        $issues = [];
        foreach ($stmt->fetchAll() as $row) {
            $current = round((float)$row['current_qty'], 2);
            if ($row['item_type'] === self::TYPE_ELECTRIC) {
                if ($current !== round((float)$row['unit_count'], 2)) {
                    $issues[] = $row + ['problem' => 'current_qty ≠ จำนวนเครื่อง'];
                }
                continue;
            }
            $lots = round((float)$row['lots_qty'], 2);
            $ledger = round((float)$row['ledger_qty'], 2);
            if ($current !== $lots || $ledger !== $lots) {
                $issues[] = $row + ['problem' => 'current_qty / Lot / ledger ไม่ตรงกัน'];
            }
        }
        return $issues;
    }

    private function writeMovement(array $item, ?string $lotId, string $type, float $qty, float $balanceAfter, array $ctx): void
    {
        $this->movementModel->create([
            'id' => 'mv-' . generate_id(),
            'item_id' => (string)$item['id'],
            'car_id' => (string)$item['car_id'],
            'lot_id' => $lotId,
            'type' => $type,
            'qty' => round($qty, 2),
            'balance_after' => round($balanceAfter, 2),
            'check_id' => $ctx['check_id'] ?? null,
            'batch_id' => $ctx['batch_id'] ?? null,
            'by_user_id' => $ctx['by_user_id'] ?? null,
            'by_name' => mb_substr((string)($ctx['by_name'] ?? ''), 0, 255),
            'reason' => mb_substr((string)($ctx['reason'] ?? ''), 0, 500),
            'at' => $ctx['at'] ?? self::now(),
        ]);
    }

    public static function fmt(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }
}
