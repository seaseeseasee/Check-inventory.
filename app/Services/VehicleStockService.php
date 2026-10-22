<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Item;
use App\Models\ItemLot;
use App\Models\ItemUnit;
use App\Models\StockMovement;
use App\Exceptions\ApiException;

/**
 * แหล่งความจริงเดียวของ "ยอดอุปกรณ์บนรถ" (ไม่มีคลังสต็อกกลาง)
 *
 * แหล่งข้อมูลหลักตามประเภท:
 *   - นับเป็นจำนวน (box / piece / durable): refer_item_lots.qty_remaining → current_qty = SUM(lots)
 *   - รายเครื่อง/รายถัง (electric / oxygen):  refer_item_units (active = 1) → current_qty = จำนวนเครื่อง
 *   ทุกการเปลี่ยนยอดเขียน refer_stock_movements 1 แถวต่อ 1 Lot ที่ถูกแตะ (ledger เพิ่มอย่างเดียว)
 *
 * ทุก method ที่เขียนข้อมูล "ต้องถูกเรียกภายใน transaction ของผู้เรียก" และล็อกแถวก่อนอ่านยอด
 * (FOR UPDATE) — service นี้ไม่ begin/commit เอง เพื่อให้งานทั้งชุดสำเร็จหรือล้มพร้อมกัน
 */
class VehicleStockService
{
    /** ของเป็นกล่อง/ล็อต — 1 ล็อต = จำนวน + วันหมดอายุเดียวกัน */
    public const TYPE_BOX = 'box';
    /** ของเป็นชิ้น — 1 แถว refer_item_lots = 1 ชิ้น (qty 1) มีรหัสและวันหมดอายุของตัวเอง */
    public const TYPE_INDIVIDUAL = 'individual';
    /** ไม่มีวันหมดอายุ — รายเครื่อง (refer_item_units) + แบต (is_battery) / แรงดัน (has_pressure) */
    public const TYPE_NON_EXPIRING = 'non_expiring';
    public const ITEM_TYPES = [self::TYPE_BOX, self::TYPE_INDIVIDUAL, self::TYPE_NON_EXPIRING];
    /** นับเป็นจำนวนผ่านล็อต/ชิ้น และมีวันหมดอายุ */
    public const COUNTED_TYPES = [self::TYPE_BOX, self::TYPE_INDIVIDUAL];
    /** นับรายเครื่อง (ไม่มีวันหมดอายุ) */
    public const UNIT_TYPES = [self::TYPE_NON_EXPIRING];
    /** ชื่อประเภทเดิม (ก่อน migration 012) → ประเภทใหม่ — ใช้กับข้อมูลเก่าที่ยังส่งมา */
    public const LEGACY_TYPES = ['piece' => self::TYPE_INDIVIDUAL, 'durable' => self::TYPE_NON_EXPIRING, 'electric' => self::TYPE_NON_EXPIRING, 'oxygen' => self::TYPE_NON_EXPIRING];

    /** ค่าเริ่มต้นเมื่ออุปกรณ์ไม่ได้ตั้ง expiry_warn_days */
    public const EXPIRY_WARN_DAYS = 30;
    public const MAX_PSI = 10000;

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

    // ==================== ประเภท / เกณฑ์ ====================

    public static function itemType(array $item): string
    {
        $type = (string)($item['item_type'] ?? '');
        if (in_array($type, self::ITEM_TYPES, true)) {
            return $type;
        }
        if (isset(self::LEGACY_TYPES[$type])) {
            return self::LEGACY_TYPES[$type];
        }
        return !empty($item['is_battery']) ? self::TYPE_NON_EXPIRING : self::TYPE_INDIVIDUAL;
    }

    public static function hasBattery(array $item): bool
    {
        return self::usesUnits($item) && !empty($item['is_battery']);
    }

    public static function hasPressure(array $item): bool
    {
        return self::usesUnits($item) && !empty($item['has_pressure']);
    }

    /** นับเป็นจำนวนผ่าน Lot (ชื่อเดิมคงไว้ให้โค้ดเดิมเรียกได้) */
    public static function isConsumable(array $item): bool
    {
        return in_array(self::itemType($item), self::COUNTED_TYPES, true);
    }

    public static function usesUnits(array $item): bool
    {
        return in_array(self::itemType($item), self::UNIT_TYPES, true);
    }

    /** บังคับวันหมดอายุตอนเติม — กล่องและรายชิ้น (ไม่มีวันหมดอายุไม่มีช่องนี้) */
    public static function requiresExpiry(array $item): bool
    {
        return in_array(self::itemType($item), self::COUNTED_TYPES, true);
    }

    public static function warnDays(array $item): int
    {
        $days = $item['expiry_warn_days'] ?? null;
        return ($days === null || $days === '') ? self::EXPIRY_WARN_DAYS : max(0, (int)$days);
    }

    public static function batteryMax(array $item): int
    {
        $max = (int)($item['battery_max_bars'] ?? 4);
        return $max >= 1 && $max <= 10 ? $max : 4;
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

    /**
     * สถานะวันหมดอายุของ 1 ล็อต: expired | soon | ok | unknown (ไม่ทราบ — รอตรวจสอบ) | na (ครุภัณฑ์ไม่มีวันหมดอายุ)
     */
    public static function expiryState(?string $expiryDate, ?string $today = null, int $warnDays = self::EXPIRY_WARN_DAYS, bool $noExpiryType = false): string
    {
        if ($expiryDate === null || $expiryDate === '') {
            return $noExpiryType ? 'na' : 'unknown';
        }
        $today = $today ?? self::today();
        if ($expiryDate < $today) {
            return 'expired';
        }
        $limit = (new \DateTimeImmutable($today))->modify('+' . $warnDays . ' days')->format('Y-m-d');
        return $expiryDate <= $limit ? 'soon' : 'ok';
    }

    /** ตรวจรูปแบบวันที่ YYYY-MM-DD และเป็นวันที่มีจริง */
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
     * Lot / เครื่อง / ยอดแยกสถานะ ของอุปกรณ์หลายรายการ สำหรับแสดงผล
     * @param array $items แถว refer_items
     * @return array{lots: array, units: array, stock: array<string, array{available: float, expired: float, unknown: float}>}
     */
    public function detailsForItems(array $items): array
    {
        $today = self::today();
        $byId = [];
        foreach ($items as $item) {
            $byId[(string)$item['id']] = $item;
        }
        $lots = [];
        $stock = [];
        foreach ($this->lotModel->getOpenByItems(array_keys($byId), $today) as $itemId => $rows) {
            foreach ($rows as $row) {
                $lot = self::presentLot($row, $today, $byId[$itemId] ?? []);
                $lots[$itemId][] = $lot;
                $bucket = $lot['state'] === 'expired' ? 'expired' : ($lot['state'] === 'unknown' ? 'unknown' : 'available');
                $stock[$itemId][$bucket] = round(($stock[$itemId][$bucket] ?? 0.0) + $lot['qty'], 2);
            }
        }
        foreach (array_keys($byId) as $id) {
            // ค่าที่คำนวณได้ต้องอยู่ซ้าย: array union เก็บ key ของฝั่งซ้ายไว้
            $stock[$id] = ($stock[$id] ?? []) + ['available' => 0.0, 'expired' => 0.0, 'unknown' => 0.0];
        }
        return ['lots' => $lots, 'units' => $this->unitModel->getActiveByItems(array_keys($byId)), 'stock' => $stock];
    }

    public static function presentLot(array $row, ?string $today = null, array $item = []): array
    {
        $expiry = $row['expiry_date'] ?? null;
        return [
            'id' => (string)$row['id'],
            'lot_number' => (string)($row['lot_number'] ?? ''),
            'expiry_date' => $expiry,
            'received_date' => $row['received_date'] ?? null,
            'qty' => round((float)$row['qty_remaining'], 2),
            'note' => (string)($row['note'] ?? ''),
            'state' => self::expiryState($expiry, $today, self::warnDays($item), false),
        ];
    }

    /** สรุปวันหมดอายุของรายการจาก Lot: วันใกล้สุด + สถานะแย่สุด */
    public static function expirySummary(array $lots): array
    {
        $rank = ['expired' => 4, 'soon' => 3, 'unknown' => 2, 'ok' => 1, 'na' => 0];
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
     * ตัดของออกจากรถแบบ FEFO (ยังไม่หมดอายุก่อน → ไม่ทราบวันหมดอายุ → หมดอายุแล้ว)
     * ถ้าของในทุก Lot รวมกันไม่พอ จะ throw (ห้ามยอดติดลบ)
     */
    public function consume(array $item, float $qty, string $type, array $ctx): array
    {
        $qty = round($qty, 2);
        if ($qty <= 0) {
            return [];
        }
        $itemId = (string)$item['id'];
        $lots = $this->lotModel->lockOpenByItem($itemId, self::today());
        $available = round(array_sum(array_map(fn($l) => (float)$l['qty_remaining'], $lots)), 2);
        if ($qty > $available) {
            throw new ApiException(sprintf('%s: ต้องการตัด %s แต่บนรถมีเพียง %s', (string)$item['name'], self::fmt($qty), self::fmt($available)), 422);
        }

        $taken = [];
        $left = $qty;
        foreach ($lots as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = round(min($left, (float)$lot['qty_remaining']), 2);
            $this->changeLot($item, $lot, -$take, $type, $ctx);
            $taken[] = ['lot_id' => (string)$lot['id'], 'expiry_date' => $lot['expiry_date'], 'qty' => $take];
            $left = round($left - $take, 2);
        }
        $this->syncItemTotal($item, $ctx['at'] ?? self::now());
        return $taken;
    }

    /**
     * เปลี่ยนยอดของ "ล็อตที่ระบุ" (+ เพิ่ม / − ตัด) — ใช้เมื่อผู้ใช้เลือกล็อตเอง / นับรายล็อต / ปรับยอด
     * ล็อตต้องเป็นของอุปกรณ์นี้และยังไม่ถูกยกเลิก; ห้ามติดลบ
     */
    public function changeLotById(array $item, string $lotId, float $delta, string $type, array $ctx): void
    {
        $delta = round($delta, 2);
        if ($delta == 0.0) {
            return;
        }
        $lot = $this->lockLotOf($item, $lotId);
        if ((string)$lot['lot_status'] !== ItemLot::STATUS_ACTIVE) {
            throw new ApiException((string)$item['name'] . ': ล็อตนี้ถูกยกเลิกแล้ว', 422);
        }
        if ((float)$lot['qty_remaining'] + $delta < 0) {
            throw new ApiException(sprintf('%s: ล็อต%s มีเพียง %s ตัด %s ไม่ได้',
                (string)$item['name'], self::lotLabel($lot), self::fmt((float)$lot['qty_remaining']), self::fmt(-$delta)), 422);
        }
        $this->changeLot($item, $lot, $delta, $type, $ctx);
        $this->syncItemTotal($item, $ctx['at'] ?? self::now());
    }

    /** เพิ่ม Lot ใหม่บนรถ (เติมของ / ปรับยอดขึ้น / ตั้งยอดครั้งแรก) */
    public function addLot(array $item, float $qty, ?string $expiryDate, string $source, string $type, array $ctx, array $meta = []): array
    {
        $qty = round($qty, 2);
        if ($qty <= 0) {
            throw new ApiException('จำนวนที่เพิ่มต้องมากกว่า 0', 422);
        }
        $at = $ctx['at'] ?? self::now();
        $lotId = 'lot-' . generate_id();
        $this->lotModel->create([
            'id' => $lotId,
            'item_id' => (string)$item['id'],
            'lot_number' => mb_substr(trim((string)($meta['lot_number'] ?? '')), 0, 100),
            'expiry_date' => $expiryDate,
            'received_date' => $meta['received_date'] ?? null,
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'lot_status' => ItemLot::STATUS_ACTIVE,
            'note' => mb_substr(trim((string)($meta['note'] ?? '')), 0, 500),
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
     * เพิ่มของแบบชิ้น: 1 วันหมดอายุ = 1 ชิ้น = 1 แถว (qty 1, id ของแถว = รหัสประจำชิ้น)
     * จำนวนชิ้น = จำนวนวันหมดอายุที่ส่งมาเสมอ — ทุกชิ้นวันหมดอายุต่างกันได้
     * @param string[] $expiries วันหมดอายุของแต่ละชิ้น (YYYY-MM-DD) — ตรวจรูปแบบ/หมดอายุแล้วโดยผู้เรียก
     * @return array รายการชิ้นที่สร้าง
     */
    public function addPieces(array $item, array $expiries, string $source, string $type, array $ctx, array $meta = []): array
    {
        if (self::itemType($item) !== self::TYPE_INDIVIDUAL) {
            throw new ApiException((string)$item['name'] . ': ไม่ใช่ของแบบชิ้น', 422);
        }
        $out = [];
        foreach ($expiries as $expiry) {
            $out[] = $this->addLot($item, 1, $expiry, $source, $type, $ctx, $meta);
        }
        return $out;
    }

    /** เติมเข้าล็อตเดิม (ล็อตเดียวกัน วันหมดอายุเดียวกัน) — ห้ามเติมเข้าล็อตที่หมดอายุ/ถูกยกเลิก */
    public function topUpLot(array $item, string $lotId, float $qty, array $ctx): array
    {
        if (self::itemType($item) === self::TYPE_INDIVIDUAL) {
            throw new ApiException((string)$item['name'] . ': ของแบบชิ้นต้องเพิ่มเป็นชิ้นใหม่พร้อมวันหมดอายุรายชิ้น', 422);
        }
        $qty = round($qty, 2);
        if ($qty <= 0) {
            throw new ApiException('จำนวนที่เติมต้องมากกว่า 0', 422);
        }
        $lot = $this->lockLotOf($item, $lotId);
        if ((string)$lot['lot_status'] !== ItemLot::STATUS_ACTIVE) {
            throw new ApiException((string)$item['name'] . ': ล็อตนี้ถูกยกเลิกแล้ว เติมเพิ่มไม่ได้', 422);
        }
        if ($lot['expiry_date'] !== null && $lot['expiry_date'] < self::today()) {
            throw new ApiException((string)$item['name'] . ': ล็อตนี้หมดอายุแล้ว — ไม่อนุญาตให้เติมเพิ่ม', 422);
        }
        $this->lotModel->query("UPDATE " . $this->lotModel->getTable() . " SET qty_received = qty_received + ? WHERE id = ?", [$qty, $lotId]);
        $this->changeLot($item, $lot, $qty, StockMovement::TYPE_REFILL, $ctx);
        $this->syncItemTotal($item, $ctx['at'] ?? self::now());
        return ['lot_id' => $lotId, 'expiry_date' => $lot['expiry_date'], 'qty' => $qty, 'top_up' => true];
    }

    /** แก้ข้อมูลล็อต (เลขล็อต/วันรับ/หมายเหตุ/วันหมดอายุ) — บันทึกการแก้เป็น movement qty 0 เพื่อย้อนดูได้ */
    public function editLot(array $item, string $lotId, array $input, array $ctx): array
    {
        $lot = $this->lockLotOf($item, $lotId);
        $data = [];
        $changes = [];
        if (array_key_exists('lot_number', $input)) {
            $data['lot_number'] = mb_substr(trim((string)$input['lot_number']), 0, 100);
        }
        if (array_key_exists('note', $input)) {
            $data['note'] = mb_substr(trim((string)$input['note']), 0, 500);
        }
        if (array_key_exists('received_date', $input)) {
            $data['received_date'] = self::optionalPastDate($input['received_date'], 'วันที่รับเข้า');
        }
        if (array_key_exists('expiry_date', $input)) {
            $raw = trim((string)($input['expiry_date'] ?? ''));
            if ($raw === '' && self::requiresExpiry($item)) {
                throw new ApiException('ของสิ้นเปลืองต้องมีวันหมดอายุ', 422);
            }
            if ($raw !== '' && !self::validDate($raw)) {
                throw new ApiException('วันหมดอายุไม่ถูกต้อง', 422);
            }
            $data['expiry_date'] = $raw === '' ? null : $raw;
        }
        foreach ($data as $key => $value) {
            if ((string)($lot[$key] ?? '') !== (string)($value ?? '')) {
                $changes[] = sprintf('%s: "%s" → "%s"', $key, (string)($lot[$key] ?? ''), (string)($value ?? ''));
            }
        }
        if (!$changes) {
            throw new ApiException('ไม่มีข้อมูลที่เปลี่ยนแปลง', 422);
        }
        $reason = trim((string)($input['reason'] ?? ''));
        if (array_key_exists('expiry_date', $data) && (string)$lot['expiry_date'] !== (string)$data['expiry_date'] && $reason === '') {
            throw new ApiException('การแก้วันหมดอายุต้องระบุเหตุผล', 422);
        }
        $this->lotModel->update($lotId, $data);
        $this->writeMovement($item, $lotId, 'LOT_EDIT', 0.0, $this->lotModel->sumRemaining((string)$item['id']),
            $ctx + ['reason' => mb_substr(trim('แก้ข้อมูลล็อต ' . implode('; ', $changes) . ($reason ? ' — ' . $reason : '')), 0, 500)]);
        $this->syncItemTotal($item, $ctx['at'] ?? self::now());
        return $data;
    }

    /** ปรับยอดล็อตเป็นจำนวนที่นับได้จริง (ADJUST ตามส่วนต่าง) */
    public function setLotQty(array $item, string $lotId, float $newQty, string $reason, array $ctx): float
    {
        if (self::itemType($item) === self::TYPE_INDIVIDUAL && !in_array($newQty, [0.0, 1.0], true)) {
            throw new ApiException((string)$item['name'] . ': ของแบบชิ้น 1 แถว = 1 ชิ้น — ปรับได้เป็น 0 หรือ 1 เท่านั้น', 422);
        }
        if ($newQty < 0) {
            throw new ApiException('จำนวนต้องไม่ติดลบ', 422);
        }
        if (trim($reason) === '') {
            throw new ApiException('การปรับยอดต้องระบุเหตุผล', 422);
        }
        $lot = $this->lockLotOf($item, $lotId);
        $delta = round($newQty - (float)$lot['qty_remaining'], 2);
        if ($delta == 0.0) {
            throw new ApiException('ยอดเท่าเดิม ไม่มีการปรับ', 422);
        }
        $this->changeLotById($item, $lotId, $delta, StockMovement::TYPE_ADJUST, $ctx + ['reason' => 'ปรับยอดล็อต: ' . $reason]);
        return $delta;
    }

    /**
     * ยกเลิกล็อต (บันทึกผิด/ของเสีย) — ไม่ลบแถว: ตัดยอดที่เหลือออกด้วย movement VOID และเปลี่ยนสถานะ
     * ประวัติการรับเข้า/ใช้ของล็อตนี้ยังอยู่ครบ
     */
    public function voidLot(array $item, string $lotId, string $reason, array $ctx): void
    {
        if (trim($reason) === '') {
            throw new ApiException('การยกเลิกล็อตต้องระบุเหตุผล', 422);
        }
        $lot = $this->lockLotOf($item, $lotId);
        if ((string)$lot['lot_status'] === ItemLot::STATUS_VOID) {
            throw new ApiException('ล็อตนี้ถูกยกเลิกไปแล้ว', 409);
        }
        if ((float)$lot['qty_remaining'] > 0) {
            $this->changeLot($item, $lot, -(float)$lot['qty_remaining'], 'VOID', $ctx + ['reason' => 'ยกเลิกล็อต: ' . $reason]);
        }
        $this->lotModel->update($lotId, ['lot_status' => ItemLot::STATUS_VOID]);
        $this->syncItemTotal($item, $ctx['at'] ?? self::now());
    }

    /** จำหน่ายล็อตที่หมดอายุออกจากรถ (EXPIRE) — ทำได้เฉพาะล็อตที่หมดอายุแล้วจริง */
    public function expireLot(array $item, string $lotId, array $ctx): float
    {
        $lot = $this->lockLotOf($item, $lotId);
        if ($lot['expiry_date'] === null || $lot['expiry_date'] >= self::today()) {
            throw new ApiException('ล็อตนี้ยังไม่หมดอายุ — ถ้าต้องการนำออกด้วยเหตุผลอื่นให้ใช้ "ยกเลิกล็อต"', 422);
        }
        $qty = (float)$lot['qty_remaining'];
        if ($qty <= 0) {
            throw new ApiException('ล็อตนี้ไม่มีของเหลืออยู่แล้ว', 409);
        }
        $this->changeLot($item, $lot, -$qty, 'EXPIRE', $ctx + ['reason' => 'จำหน่ายของหมดอายุ (' . $lot['expiry_date'] . ')']);
        $this->syncItemTotal($item, $ctx['at'] ?? self::now());
        return $qty;
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
            $ctxCheck = $ctx + ['check_id' => $checkId];

            $undo = round(-(float)$row['net'], 2);
            $newRemaining = round((float)$lot['qty_remaining'] + $undo, 2);
            if ($newRemaining < 0) {
                // ของที่รอบนี้เคยเพิ่มเข้ามาถูกใช้ไปแล้วโดยรายการอื่น → ย้อนได้เท่าที่เหลือ ส่วนเกินตัดจาก Lot อื่นแบบ FEFO
                $this->changeLot($item, $lot, -(float)$lot['qty_remaining'], StockMovement::TYPE_REVERSAL, $ctxCheck);
                $this->consume($item, -$newRemaining, StockMovement::TYPE_REVERSAL, $ctxCheck);
                continue;
            }
            $this->changeLot($item, $lot, $undo, StockMovement::TYPE_REVERSAL, $ctxCheck);
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
            $open = $this->lotModel->getOpenByItems([$itemId], self::today())[$itemId] ?? [];
            $dates = array_filter(array_column($open, 'expiry_date'));
            $this->itemModel->updateExpiry($itemId, $dates ? min($dates) : '');
        } else {
            $total = (float)$this->unitModel->countActive($itemId);
        }
        $this->itemModel->updateStock($itemId, $total, $at);
        return $total;
    }

    /** บันทึกเครื่อง/ถังใหม่ 1 ตัว */
    public function addUnit(array $item, array $unit, array $ctx): array
    {
        $at = $ctx['at'] ?? self::now();
        $id = 'unit-' . generate_id();
        $this->unitModel->create([
            'id' => $id,
            'item_id' => (string)$item['id'],
            'label' => mb_substr(trim((string)($unit['label'] ?? '')), 0, 100),
            'status' => $unit['status'],
            'battery_level' => $unit['battery_level'] ?? null,
            'pressure_psi' => $unit['pressure_psi'] ?? null,
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
     * ตรวจข้อมูลเครื่อง/ถัง 1 ตัวจาก client ตามประเภท
     *  non_expiring: สถานะ + แบต 0..battery_max_bars (ถ้ามีแบต) + แรงดัน PSI (ถ้าวัดแรงดัน)
     *  ไม่พร้อมใช้ / เปิดไม่ติด ต้องระบุสาเหตุ
     */
    public static function parseUnitInput(array $raw, array $item, string $label = ''): array
    {
        $name = trim((string)$item['name'] . ' ' . $label);
        $battery = !empty($item['is_battery']);   // ผู้เรียกส่งเฉพาะอุปกรณ์ประเภทไม่มีวันหมดอายุ
        $status = (string)($raw['status'] ?? '');
        $allowed = $battery
            ? [ItemUnit::STATUS_READY, ItemUnit::STATUS_NOT_READY, 'no_power']
            : [ItemUnit::STATUS_READY, ItemUnit::STATUS_NOT_READY];
        if (!in_array($status, $allowed, true)) {
            throw new ApiException($name . ': กรุณาเลือกสถานะ' . ($battery ? ' พร้อมใช้ / ไม่พร้อมใช้ / เปิดไม่ติด' : ' พร้อมใช้ / ไม่พร้อมใช้'), 422);
        }
        $out = ['status' => $status, 'battery_level' => null, 'pressure_psi' => null];
        if ($battery && $status !== 'no_power') {
            $max = self::batteryMax($item);
            $level = $raw['battery_level'] ?? null;
            if (!is_numeric($level) || (int)$level != $level || (int)$level < 0 || (int)$level > $max) {
                throw new ApiException(sprintf('%s: ระดับแบตเตอรี่ต้องเป็น 0-%d ขีด', $name, $max), 422);
            }
            $out['battery_level'] = (int)$level;
        }
        if (!empty($item['has_pressure'])) {
            $psi = $raw['pressure_psi'] ?? null;
            if (!is_numeric($psi) || (float)$psi < 0 || (float)$psi > self::MAX_PSI) {
                throw new ApiException(sprintf('%s: แรงดันต้องเป็นตัวเลข 0-%d PSI', $name, self::MAX_PSI), 422);
            }
            $out['pressure_psi'] = (int)round((float)$psi);
        }
        $note = trim((string)($raw['note'] ?? ''));
        if ($status !== ItemUnit::STATUS_READY && $note === '') {
            throw new ApiException($name . ': ไม่พร้อมใช้งาน/เปิดไม่ติด ต้องระบุสาเหตุ (เช่น แบตเสื่อม ชำรุด ส่งซ่อม)', 422);
        }
        $out['note'] = mb_substr($note, 0, 500);
        $out['label'] = mb_substr(trim((string)($raw['label'] ?? '')), 0, 100);
        return $out;
    }

    /** วันที่ในอดีต/วันนี้ ที่ไม่บังคับ ('' → NULL) */
    public static function optionalPastDate(mixed $raw, string $label): ?string
    {
        $value = trim((string)($raw ?? ''));
        if ($value === '') {
            return null;
        }
        if (!self::validDate($value)) {
            throw new ApiException($label . 'ไม่ถูกต้อง', 422);
        }
        if ($value > self::today()) {
            throw new ApiException($label . 'ต้องไม่เป็นวันในอนาคต', 422);
        }
        return $value;
    }

    /**
     * กระทบยอด: หาอุปกรณ์ที่ current_qty ≠ ยอดจากแหล่งข้อมูลหลัก หรือ ledger ≠ Lot
     * (ถูกเรียกก่อน commit ทุกครั้ง — ไม่แก้ข้อมูลเอง)
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
            $lots = round((float)$row['lots_qty'], 2);
            $ledger = round((float)$row['ledger_qty'], 2);
            if (in_array($row['item_type'], self::UNIT_TYPES, true)) {
                if ($current !== round((float)$row['unit_count'], 2) || $ledger !== $lots) {
                    $issues[] = $row + ['problem' => 'current_qty ≠ จำนวนเครื่อง หรือ ledger ≠ Lot'];
                }
                continue;
            }
            if ($current !== $lots || $ledger !== $lots) {
                $issues[] = $row + ['problem' => 'current_qty / Lot / ledger ไม่ตรงกัน'];
            }
        }
        return $issues;
    }

    // ==================== ภายใน ====================

    private function lockLotOf(array $item, string $lotId): array
    {
        $lot = $this->lotModel->lockById($lotId);
        if (!$lot || (string)$lot['item_id'] !== (string)$item['id']) {
            throw new ApiException((string)$item['name'] . ': ไม่พบล็อตนี้ในอุปกรณ์รายการนี้ (กรุณาโหลดหน้าใหม่)', 422);
        }
        return $lot;
    }

    /** เปลี่ยนยอดล็อตที่ล็อกไว้แล้ว + เขียน ledger (ไม่ sync ยอดรวม — ผู้เรียกทำ) */
    private function changeLot(array $item, array $lot, float $delta, string $type, array $ctx): void
    {
        $delta = round($delta, 2);
        $this->lotModel->setRemaining((string)$lot['id'], (float)$lot['qty_remaining'] + $delta);
        $balance = $this->lotModel->sumRemaining((string)$item['id']);
        $this->writeMovement($item, (string)$lot['id'], $type, $delta, $balance, $ctx);
    }

    public static function lotLabel(array $lot): string
    {
        $parts = [];
        if (!empty($lot['lot_number'])) {
            $parts[] = (string)$lot['lot_number'];
        }
        $parts[] = $lot['expiry_date'] ? 'หมดอายุ ' . $lot['expiry_date'] : 'ไม่มีวันหมดอายุ';
        return ' (' . implode(' ', $parts) . ')';
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
