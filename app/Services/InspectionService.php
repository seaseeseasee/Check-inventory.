<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Vehicle;
use App\Models\Item;
use App\Models\Nurse;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Draft;
use App\Models\DraftItem;
use App\Models\Metadata;
use App\Models\RestockRequest;
use App\Models\RestockItem;
use App\Models\RestockAction;
use App\Config\Database;

class InspectionService
{
    /** สถานะที่หน้าตรวจรถใช้ได้ (ตรงกับปุ่มใน inspections.js) */
    public const STATUS_PENDING = 'ยังไม่ตรวจ';   // ยังไม่ได้ตรวจนับ ห้ามบันทึก
    public const STATUS_ENOUGH  = 'Checked';      // "ครบ" = มีตามเป้าหมาย
    public const STATUS_USED    = 'ใช้ไป';         // ต้องกรอก "จำนวนคงเหลือจริง"
    public const STATUS_MISSING = 'ขาด';           // ต้องกรอก "จำนวนคงเหลือจริง"

    /** สถานะที่ถือว่าการตรวจนับถูกรีคอร์ดแล้ว */
    public const COUNTED_STATUSES = [self::STATUS_ENOUGH, self::STATUS_USED, self::STATUS_MISSING];

    /**เพดานที่ยอมรับได้ของจำนวนที่กรอก (กันกรอกผิดหลัก/ข้อมูลขยะ) */
    public const MAX_QTY = 1000000.0;

    private Vehicle $vehicleModel;
    private Item $itemModel;
    private Nurse $nurseModel;
    private Inspection $inspectionModel;
    private InspectionItem $inspectionItemModel;
    private Draft $draftModel;
    private DraftItem $draftItemModel;
    private Metadata $metadataModel;
    private RestockRequest $restockRequestModel;
    private RestockItem $restockItemModel;
    private RestockAction $restockActionModel;
    private StockService $stockService;

    public function __construct()
    {
        $this->vehicleModel = new Vehicle();
        $this->itemModel = new Item();
        $this->nurseModel = new Nurse();
        $this->inspectionModel = new Inspection();
        $this->inspectionItemModel = new InspectionItem();
        $this->draftModel = new Draft();
        $this->draftItemModel = new DraftItem();
        $this->metadataModel = new Metadata();
        $this->restockRequestModel = new RestockRequest();
        $this->restockItemModel = new RestockItem();
        $this->restockActionModel = new RestockAction();
        $this->stockService = new StockService();
    }

    public function getVehicles(): array
    {
        return $this->vehicleModel->all();
    }

    public function getVehicle(string $id): ?array
    {
        return $this->vehicleModel->find($id);
    }

    public function getItemsByVehicle(string $carId): array
    {
        return $this->itemModel->getActiveByVehicle($carId);
    }

    public function getAllItems(): array
    {
        return $this->itemModel->all();
    }

    public function getActiveNurses(): array
    {
        return $this->nurseModel->getActive();
    }

    public function getAllNurses(): array
    {
        return $this->nurseModel->all();
    }

    public function createDraft(array $data): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Delete existing draft for this vehicle
            $existingDraft = $this->draftModel->firstWhere('car_id', $data['car_id']);
            if ($existingDraft) {
                $this->draftItemModel->query(
                    "DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?",
                    [$existingDraft['id']]
                );
                $this->draftModel->delete($existingDraft['id']);
            }

            // Create new draft
            $draftId = $data['id'] ?? generate_id();
            $draftData = [
                'id' => $draftId,
                'car_id' => $data['car_id'],
                'nurse_id' => $data['nurse_id'],
                'car_name' => $data['car_name'],
                'nurse_name' => $data['nurse_name'],
                'shift_name' => $data['shift_name'],
                'started_at' => $data['started_at'],
            ];
            $this->draftModel->create($draftData);

            // Create draft items
            foreach ($data['results'] as $index => $item) {
                $this->draftItemModel->create([
                    'draft_id' => $draftId,
                    'item_id' => $item['id'],
                    'sort_order' => $index,
                    'name' => $item['name'],
                    'standard' => $item['standard'],
                    'expiry_text' => $item['expire'],
                    'is_battery' => $item['isBattery'] ? 1 : 0,
                    'status' => $item['status'],
                    'detail' => self::rawActualInput($item),
                    'battery_level' => $item['batteryLevel'] ?? null,
                ]);
            }

            $db->commit();
            return $this->draftModel->getWithItems($draftId) ?? $draftData;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function getDraft(string $carId): ?array
    {
        return $this->draftModel->getByVehicle($carId);
    }

    /**
     * Checklist ของรถพร้อมร่างที่ค้างอยู่ — อ่านยอดสต็อก "จากฐานข้อมูลตอนเรียกทุกครั้ง"
     * ไม่มี cache/session state (ข้อ 1.1 และข้อ 5.1)
     */
    public function getDraftResults(string $carId): array
    {
        $draft = $this->draftModel->getByVehicle($carId);
        if (!$draft) return [];

        $stored = [];
        foreach ($this->draftItemModel->getByDraft($draft['id']) as $row) {
            $stored[(string)$row['item_id']] = $row;
        }

        return $this->decorateResults($this->itemModel->getActiveByVehicle($carId), $stored);
    }

    /**
     * Checklist ใหม่ของรถ (ยังไม่ตรวจ) พร้อมยอดสต็อกจริงจากฐานข้อมูล
     */
    public function buildResultsFromVehicle(string $carId): array
    {
        return $this->decorateResults($this->itemModel->getActiveByVehicle($carId), []);
    }

    /**
     * ประกอบข้อมูลแต่ละบรรทัดของฟอร์มตรวจรถ: เป้าหมาย + หน่วย + ยอดบนรถล่าสุด + ยอดคลังกลาง
     * และคำนวณ used / ต้องเติม "ด้วยกติกาเดียวกับที่จะบันทึกจริง" เพื่อให้หน้าจอกับ DB ตรงกัน
     *
     * @param array $itemRows แถวจาก refer_items
     * @param array $storedMap item_id => แถว refer_draft_items (ค่าที่ผู้ใช้เลือกไว้ก่อนหน้า)
     */
    private function decorateResults(array $itemRows, array $storedMap): array
    {
        $warehouse = $this->stockService->getQtyByNameMap(array_column($itemRows, 'name'));

        $out = [];
        foreach ($itemRows as $item) {
            $itemId = (string)$item['id'];
            $name = (string)$item['name'];
            $stored = $storedMap[$itemId] ?? null;

            $target = self::itemTargetQty($item);
            $unit = self::itemUnit($item);
            $before = self::itemCountedQty($item);      // null = ยังไม่เคยตรวจนับ (ห้ามตีความเป็น 0)

            $status = (string)($stored['status'] ?? self::STATUS_PENDING);
            if (!in_array($status, self::COUNTED_STATUSES, true)) {
                $status = self::STATUS_PENDING;
            }
            $battery = (isset($stored['battery_level']) && $stored['battery_level'] !== null)
                ? (int)$stored['battery_level'] : null;

            $detail = (string)($stored['detail'] ?? '');
            $actual = null;
            if ($status !== self::STATUS_PENDING) {
                $actual = self::parseActualRemaining($status, $detail, $target)['qty'];
            }

            $calc = self::deriveNumbers($before, $actual, $target);
            $wh = $warehouse[$name] ?? null;

            $out[] = [
                'id' => $itemId,
                'name' => $name,
                'standard' => $item['standard'],
                'target_qty' => $target,
                'unit' => $unit,
                'expire' => $item['expiry_text'],
                'isBattery' => (bool)$item['is_battery'],
                'status' => $status,
                'missing' => $detail,                 // คง key เดิมไว้ให้ client/หน้าประวัติเดิมอ่านได้
                'actual_remaining' => $actual,         // ค่าที่ตรวจนับจริง (ชนิดตัวเลข)
                'batteryLevel' => $battery,
                'car_qty' => $before,                  // ยอดบนรถก่อนตรวจ (null = ยังไม่เคยตรวจนับ)
                'car_qty_at' => self::itemCountedAt($item),
                'used_qty' => $calc['used'],
                'qty_to_refill' => self::refillQty($calc, (bool)$item['is_battery'], $battery),
                'warehouse_qty' => $wh['qty'] ?? null,
                'warehouse_min' => $wh['min'] ?? null,
                'warehouse_status' => $wh['status'] ?? null,   // ok / low / out / unset (unset = ยังไม่ตั้งยอดคลัง)
            ];
        }

        return $out;
    }

    // ==================== กติกาตัวเลข (ใช้ซ้ำทั้งตอนแสดงและตอนบันทึก) ====================

    /**
     * เป้าหมายตัวเลขของอุปกรณ์ — อ่านจาก target_qty (migration 006) เป็นหลัก
     * ถ้าไม่มี (มาตรฐานไม่ตัวเลข) คืน null เพื่อให้ผู้เรียก "ไม่เดา"
     */
    private static function itemTargetQty(array $item): ?float
    {
        if (isset($item['target_qty']) && $item['target_qty'] !== '' && $item['target_qty'] !== null) {
            return round((float)$item['target_qty'], 2);
        }
        $parsed = self::parseStandardQuantity((string)($item['standard'] ?? ''));
        return $parsed > 0 ? round($parsed, 2) : null;
    }

    /** หน่วยนับ: ใช้คอลัมน์ unit ก่อน ถ้าว่างให้ลองแกะจากข้อความ standard */
    private static function itemUnit(array $item): string
    {
        $unit = trim((string)($item['unit'] ?? ''));
        if ($unit !== '') {
            return $unit;
        }
        if (preg_match('/^[^0-9]*\d+(?:[.]\d+)?\s+(\S.*)$/u', (string)($item['standard'] ?? ''), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * ยอดคงเหลือ "บนรถ" ที่ตรวจนับแล้วเท่านั้น — กฎเก็บอยู่ใน Item::countedQty()
     * (ทั้งหน้าตรวจรถ หน้าเติมของ และประวัติ ใช้กฎเดียวกัน ตัวเลขจึงไม่ขัดกัน)
     */
    private static function itemCountedQty(array $item): ?float
    {
        return Item::countedQty($item);
    }

    private static function itemCountedAt(array $item): ?string
    {
        return Item::countedAt($item);
    }

    /**
     * อ่าน "จำนวนคงเหลือจริง" ที่ผู้ใช้กรอก (ข้อ 1.4: ระบบรับเฉพาะแบบคงเหลือจริง ไม่ปน "ใช้ไป")
     * - ครบ  → คงเหลือจริง = เป้าหมาย
     * - ใช้ไป/ขาด → ต้องเป็นตัวเลขไม่ติดลบเท่านั้น (ค่าว่าง/ข้อความ/ติดลบ = error)
     *
     * @return array{qty: ?float, error: ?string}
     */
    private static function parseActualRemaining(string $status, string $raw, ?float $target): array
    {
        if ($status === self::STATUS_ENOUGH) {
            return ['qty' => $target, 'error' => null];
        }

        $text = trim($raw);
        if ($text === '') {
            return ['qty' => null, 'error' => 'ต้องกรอกจำนวนคงเหลือจริง (ถ้าหมดรถกรอก 0 ได้)'];
        }
        if (!preg_match('/^\d+(?:[.]\d+)?$/', $text)) {
            return ['qty' => null, 'error' => 'จำนวนคงเหลือจริงต้องเป็นตัวเลขไม่ติดลบ เช่น 7 หรือ 7.5'];
        }
        $qty = round((float)$text, 2);
        if ($qty > self::MAX_QTY) {
            return ['qty' => null, 'error' => 'จำนวนคงเหลือจริงสูงเกินกำหนด (ไม่เกิน ' . rtrim(rtrim(number_format(self::MAX_QTY, 2, '.', ''), '0'), '.') . ')'];
        }
        return ['qty' => $qty, 'error' => null];
    }

    /** ค่าที่ client ส่งมาในช่อง "คงเหลือจริง" (รับทั้ง key ใหม่และ key เดิม) */
    private static function rawActualInput(array $line): string
    {
        if (isset($line['actual_remaining']) && $line['actual_remaining'] !== '' && $line['actual_remaining'] !== null) {
            return trim((string)$line['actual_remaining']);
        }
        return trim((string)($line['missing'] ?? ''));
    }

    /**
     * คำนวณ "ใช้ไป" ตามข้อ 2 ของสเปก (Case B เท่านั้น เพราะระบบรับคงเหลือจริง)
     *  - ไม่มียอดก่อนตรวจ → used = null (ไม่เดา ไม่ใช่ 0)
     *  - นับได้มากกว่ายอดเดิม → ไม่ใช่ "ใช้ไปติดลบ" แต่ถือเป็นการปรับยอด/นับคลาดเคลื่อน
     *    จึงใช้ used = 0 และตั้ง flag over_count ให้ไปแสดง/ทบทวน (ข้อ 2.5 ห้ามสร้างติดลบอัตโนมัติ)
     */
    private static function deriveNumbers(?float $before, ?float $actual, ?float $target): array
    {
        $used = null;
        $overCount = false;

        if ($before !== null && $actual !== null) {
            if ($actual > $before) {
                $used = 0.0;
                $overCount = true;
            } else {
                $used = round($before - $actual, 2);
            }
        }

        $refill = ($target !== null && $actual !== null)
            ? round(max(0.0, $target - $actual), 2)   // ข้อ 3.4: ต้องเติม = MAX(0, เป้าหมาย − คงเหลือจริง)
            : null;

        return ['used' => $used, 'over_count' => $overCount, 'refill' => $refill];
    }

    /**
     * จำนวนที่ต้องเติมเข้ารายการรอเติม
     * อุปกรณ์ที่ใช้แบตเตอรี่: ถ้ายอดแบตต่ำกว่า 4 ขีด ถือว่าต้องเปลี่ยน/ชาร์จ 1 หน่วย
     * (ของยังอยู่บนรถ ยอดคงเหลือจึงไม่ได้บอกปัญหา — ถ้าไม่บังคับตรงนี้ รายการแบตจะไม่ถูกรอเติมเลย)
     */
    private static function refillQty(array $calc, bool $isBattery, ?int $batteryLevel): float
    {
        $qty = (float)($calc['refill'] ?? 0.0);
        if ($isBattery && $batteryLevel !== null && $batteryLevel < 4) {
            $qty = max($qty, 1.0);
        }
        return round($qty, 2);
    }

    /** ข้อความรายละเอียดที่เก็บในคอลัมน์ detail (เดิมเป็น free-text) — ตอนนี้เขียนเป็น "คงเหลือ N" */
    private static function detailText(?float $actual, string $unit, ?int $batteryLevel, bool $isBattery, string $status): string
    {
        if ($isBattery && $batteryLevel !== null) {
            return $batteryLevel . '/4 ขีด';
        }
        if ($status === self::STATUS_ENOUGH || $actual === null) {
            return '';
        }
        return 'คงเหลือ ' . self::fmtQty($actual) . ($unit !== '' ? ' ' . $unit : '');
    }

    private static function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }


    public function updateDraft(string $carId, array $results): bool
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // ล็อกแถวร่างก่อน (car_id เป็น UNIQUE) → สองคนบันทึกร่างเดียวกันไม่ทับกันหายไปครึ่งหนึ่ง
            $draft = $this->draftModel->lockByVehicle($carId);
            if (!$draft) {
                $db->rollBack();
                return false;
            }

            $this->draftItemModel->query(
                "DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?",
                [$draft['id']]
            );

            // Insert new items
            foreach ($results as $index => $item) {
                $this->draftItemModel->create([
                    'draft_id' => $draft['id'],
                    'item_id' => $item['id'],
                    'sort_order' => $index,
                    'name' => $item['name'],
                    'standard' => $item['standard'],
                    'expiry_text' => $item['expire'],
                    'is_battery' => $item['isBattery'] ? 1 : 0,
                    'status' => $item['status'],
                    'detail' => self::rawActualInput($item),
                    'battery_level' => $item['batteryLevel'] ?? null,
                ]);
            }

            // Update item expiry in main items table
            foreach ($results as $item) {
                $this->itemModel->updateExpiry($item['id'], $item['expire']);
            }

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * ตรวจความถูกต้องของรายการที่จะบันทึก "ก่อน" เข้า transaction จริง
     * คืน map ข้อผิดพลาด (คีย์เป็น index ของรายการ) เพื่อให้ controller ตอบ 422 แทนที่จะพังเป็น 500
     * ยังมีการตรวจซ้ำชั้นใน saveInspection (ตอนล็อกแถวแล้ว) เป็นชั้นที่สอง
     *
     * @return array<string,string> เช่น ['results[3]' => '...']
     */
    public function validateSubmission(string $carId, array $results): array
    {
        $errors = [];
        if (empty($results)) {
            return ['results' => 'ไม่มีรายการตรวจให้บันทึก'];
        }

        $itemsById = [];
        foreach ($this->itemModel->getActiveByVehicle($carId) as $row) {
            $itemsById[(string)$row['id']] = $row;
        }

        foreach (array_values($results) as $index => $raw) {
            $itemId = (string)($raw['id'] ?? '');
            $item = $itemsById[$itemId] ?? null;
            if (!$item) {
                $errors['results[' . $index . ']'] = 'ไม่พบอุปกรณ์รายการนี้ในรถคันนี้ (กรุณาโหลดหน้าใหม่)';
                continue;
            }

            $status = (string)($raw['status'] ?? self::STATUS_PENDING);
            if (!in_array($status, self::COUNTED_STATUSES, true)) {
                $errors['results[' . $index . ']'] = 'ยังตรวจไม่ครบทุกรายการ: ' . $item['name'];
                continue;
            }

            if ((bool)$item['is_battery']) {
                $level = $raw['batteryLevel'] ?? null;
                if ($level === null || $level === '' || !is_numeric($level) || (int)$level != $level || (int)$level < 0 || (int)$level > 4) {
                    $errors['results[' . $index . ']'] = 'ระดับแบตเตอรี่ของ ' . $item['name'] . ' ต้องเป็นจำนวนเต็ม 0-4';
                    continue;
                }
            }

            $check = self::parseActualRemaining($status, self::rawActualInput($raw), self::itemTargetQty($item));
            if ($check['error'] !== null) {
                $errors['results[' . $index . ']'] = $item['name'] . ': ' . $check['error'];
            }
        }

        return $errors;
    }

    /**
     * บันทึกรอบตรวจรถ 1 รอบแบบ all-or-nothing (ข้อ 2.6)
     *  ตรวจข้อมูล → ล็อกแถวอุปกรณ์ → คำนวณด้วยกติกาเดียวกับที่แสดงบนหน้าจอ
     *  → เขียนยอดคงเหลือบนรถ + เก็บรอยเท้าตัวเลขต่อรายการ → สร้าง/อัปเดตรายการรอเติม → ลบร่าง
     * ขั้นใดขั้นหนึ่งพลาดจะ rollback ทั้งชุด จึงไม่มีสถานะ "รถถูกตัดยอด แต่ไม่มีรายการเติม"
     */
    public function saveInspection(array $data, ?string $currentUserId = null, string $currentUserName = ''): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $checkId = (string)($data['id'] ?? '');
            $carId = (string)($data['car_id'] ?? '');
            if ($checkId === '' || $carId === '') {
                throw new \RuntimeException('บันทึกไม่ได้: ไม่พบรหัสรอบตรวจหรือรหัสรถ');
            }

            // กันการกดซ้ำ/ส่งร่างเดิมซ้ำ: รหัสรอบตรวจมาจาก ร่างที่ค้างอยู่เสมอ
            if ($this->inspectionModel->find($checkId)) {
                throw new \RuntimeException('รอบตรวจนี้ถูกบันทึกไปแล้ว กรุณาเริ่มการตรวจใหม่');
            }

            $vehicle = $this->vehicleModel->find($carId);
            if (!$vehicle) {
                throw new \RuntimeException('ไม่พบรถในฐานข้อมูล');
            }

            $results = array_values($data['results'] ?? []);
            if (empty($results)) {
                throw new \RuntimeException('ไม่มีรายการตรวจให้บันทึก');
            }

            // ล็อกแถวอุปกรณ์ (เรียงตาม id เพื่อกัน deadlock) แล้วค่อยอ่านยอด "จาก DB" เท่านั้น
            $itemByKey = [];
            foreach ($this->itemModel->lockByIds(array_map(fn($r) => (string)($r['id'] ?? ''), $results)) as $row) {
                $itemByKey[(string)$row['id']] = $row;
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
            $lines = [];
            $adjustments = [];

            foreach ($results as $index => $raw) {
                $itemId = (string)($raw['id'] ?? '');
                $item = $itemByKey[$itemId] ?? null;
                if (!$item) {
                    throw new \RuntimeException(sprintf(
                        'ไม่พบอุปกรณ์ "%s" ในฐานข้อมูล (รายการอาจถูกลบ/ย้ายคันไปแล้ว) กรุณาโหลดหน้าใหม่',
                        (string)($raw['name'] ?? $itemId)
                    ));
                }
                if ((string)$item['car_id'] !== $carId) {
                    throw new \RuntimeException(sprintf(
                        'อุปกรณ์ "%s" ไม่ได้อยู่ในรถ %s กรุณาโหลดหน้าใหม่',
                        (string)$item['name'],
                        (string)$vehicle['name']
                    ));
                }

                $status = (string)($raw['status'] ?? self::STATUS_PENDING);
                if (!in_array($status, self::COUNTED_STATUSES, true)) {
                    throw new \RuntimeException(sprintf('ยังตรวจไม่ครบทุกรายการ (พบสถานะ "%s")', $status));
                }

                $isBattery = (bool)$item['is_battery'];
                $batteryLevel = null;
                if ($isBattery) {
                    $rawLevel = $raw['batteryLevel'] ?? null;
                    if ($rawLevel === null || $rawLevel === '') {
                        throw new \RuntimeException('กรุณาระบุระดับแบตเตอรี่ของ ' . $item['name']);
                    }
                    if (!is_numeric($rawLevel) || (float)$rawLevel !== (float)(int)$rawLevel || (int)$rawLevel < 0 || (int)$rawLevel > 4) {
                        throw new \RuntimeException('ระดับแบตเตอรี่ของ ' . $item['name'] . ' ต้องเป็นจำนวนเต็ม 0-4');
                    }
                    $batteryLevel = (int)$rawLevel;
                }

                $target = self::itemTargetQty($item);
                $unit = self::itemUnit($item);
                $before = self::itemCountedQty($item);   // null = ยังไม่เคยตรวจนับ → ไม่เดา "ใช้ไป"

                $parsed = self::parseActualRemaining($status, self::rawActualInput($raw), $target);
                if ($parsed['error'] !== null) {
                    throw new \RuntimeException($item['name'] . ': ' . $parsed['error']);
                }
                $actual = $parsed['qty'];

                $calc = self::deriveNumbers($before, $actual, $target);
                if ($calc['over_count']) {
                    // นับได้มากกว่ายอดเดิม = ปรับยอด/นับคลาดเคลื่อน ห้ามกลายเป็น "ใช้ไป" ติดลบ (ข้อ 2.5)
                    $adjustments[] = [
                        'item_id' => $itemId,
                        'name' => (string)$item['name'],
                        'qty_before' => $before,
                        'qty_actual' => $actual,
                        'message' => sprintf(
                            'ตรวจนับได้ %s ซึ่งมากกว่ายอดเดิม %s → บันทึกเป็นยอดคงเหลือใหม่ (ไม่ได้หักใช้ไปติดลบ) โปรดทบทวนว่ามีการเติมของที่ยังไม่ได้บันทึกหรือไม่',
                            self::fmtQty((float)$actual),
                            self::fmtQty((float)$before)
                        ),
                    ];
                }

                $lines[] = [
                    'index' => $index,
                    'item' => $item,
                    'status' => $status,
                    'unit' => $unit,
                    'target' => $target,
                    'before' => $before,
                    'actual' => $actual,
                    'used' => $calc['used'],
                    'refill' => self::refillQty($calc, $isBattery, $batteryLevel),
                    'battery' => $batteryLevel,
                    'is_battery' => $isBattery,
                    'expire' => (string)($raw['expire'] ?? ($item['expiry_text'] ?? '')),
                ];
            }

            // 1) หัวรอบตรวจ
            $this->inspectionModel->create([
                'id' => $checkId,
                'car_id' => $carId,
                'nurse_id' => $data['nurse_id'] ?? null,
                'car_name' => (string)$vehicle['name'],
                'nurse_name' => $data['nurse_name'] ?? '',
                'shift_name' => $data['shift_name'] ?? '',
                'started_at' => $data['started_at'] ?? $now,
                'completed_at' => $data['completed_at'] ?? $now,
            ]);

            // 2) บรรทัดตรวจ + รอยเท้าตัวเลข (ข้อ 6: ก่อน/หลัง/ใช้ไป/เป้าหมาย/หน่วย)
            foreach ($lines as $line) {
                $item = $line['item'];
                $this->inspectionItemModel->create([
                    'check_id' => $checkId,
                    'item_id' => $item['id'],
                    'sort_order' => $line['index'],
                    'name' => $item['name'],
                    'standard' => $item['standard'],
                    'expiry_text' => $line['expire'],
                    'is_battery' => $line['is_battery'] ? 1 : 0,
                    'status' => $line['status'],
                    'detail' => self::detailText($line['actual'], $line['unit'], $line['battery'], $line['is_battery'], $line['status']),
                    'battery_level' => $line['battery'],
                    'qty_before' => $line['before'],
                    'actual_qty' => $line['actual'],
                    'used_qty' => $line['used'],
                    'qty_target' => $line['target'],
                    'unit' => $line['unit'],
                ]);
            }

            // 3) เขียนยอดคงเหลือบนรถ (เฉพาะบรรทัดที่ตรวจนับได้จริง) + วันหมดอายุ
            foreach ($lines as $line) {
                $itemId = (string)$line['item']['id'];
                $this->itemModel->updateExpiry($itemId, $line['expire']);
                if ($line['actual'] !== null) {
                    $this->itemModel->updateStock($itemId, $line['actual'], $now);
                }
            }

            // 4) ลบร่าง (ล็อกแถวร่างก่อน กันสองคนบันทึกร่างเดียวกันพร้อมกัน)
            $draft = $this->draftModel->lockByVehicle($carId);
            if ($draft) {
                $this->draftItemModel->query(
                    "DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?",
                    [$draft['id']]
                );
                $this->draftModel->delete($draft['id']);
            }

            // 5) สร้างรายการ "รอเติมอุปกรณ์" อัตโนมัติตามยอดที่ต้องเติมจริง (ข้อ 3)
            $refill = $this->createRestockRequestsFromInspection($data, $lines, $currentUserId, $now);

            $db->commit();

            $result = $this->inspectionModel->getWithItems($checkId) ?? $data;
            $result['refill'] = $refill;
            $result['adjustments'] = $adjustments;
            // หมายเหตุ: การตัดคลังกลางไม่เกิดที่นี่ แต่เกิดตอน "เติมขึ้นรถจริง" (RestockService::recordRestock)
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * สร้าง/ปรับปรุงรายการ "รอเติมอุปกรณ์" จากรอบตรวจที่เพิ่งบันทึก (ข้อ 3)
     *
     * สูตรจำนวนที่ต้องเติม (ข้อ 3.4): ต้องเติม = MAX(0, เป้าหมาย − คงเหลือจริงที่นับได้)
     * ซึ่ง "ไม่ใช่จำนวนที่ใช้ไป" เสมอไป (รอบก่อนเติมไม่ครบ / มีของค้างบนรถ)
     *
     * นโยบายกันซ้ำ (ข้อ 3.6): ถ้ายอดที่รถต้องมีตอนนี้ถูกครอบคลุมอยู่แล้วในคำขอเปิด
     * ของรถคันเดียวกัน (PENDING/IN_PROGRESS/PARTIAL) จะไม่สร้างบรรทัดใหม่เกินจำเป็น
     * และไม่สร้างบรรทัดที่จำนวน = 0 (ข้อ 7.6)
     *
     * @return array{created:bool,request_id:?string,request_no:?string,line_count:int,skipped:array}
     */
    private function createRestockRequestsFromInspection(array $inspectionData, array $lines, ?string $currentUserId, string $now): array
    {
        $carId = (string)($inspectionData['car_id'] ?? '');
        $checkId = (string)($inspectionData['id'] ?? '');

        $need = array_values(array_filter($lines, fn($l) => $l['refill'] > 0));
        if (empty($need)) {
            return ['created' => false, 'request_id' => null, 'request_no' => null, 'line_count' => 0, 'skipped' => []];
        }

        $itemIds = array_map(fn($l) => (string)$l['item']['id'], $need);
        $outstanding = $this->restockItemModel->getOutstandingForCar($carId, $itemIds);

        $newLines = [];
        $skipped = [];
        foreach ($need as $line) {
            $itemId = (string)$line['item']['id'];
            $pendingQty = (float)($outstanding[$itemId]['outstanding'] ?? 0.0);
            $add = round(max(0.0, (float)$line['refill'] - $pendingQty), 2);

            if ($add <= 0) {
                $skipped[] = [
                    'item_id' => $itemId,
                    'name' => (string)$line['item']['name'],
                    'reason' => sprintf(
                        'ต้องเติม %s แต่มีคำขอ %s ค้างอยู่ %s อยู่แล้ว จึงไม่สร้างซ้ำ',
                        self::fmtQty((float)$line['refill']),
                        (string)($outstanding[$itemId]['no'] ?? ''),
                        self::fmtQty($pendingQty)
                    ),
                ];
                continue;
            }

            $line['qty_to_add'] = $add;
            $newLines[] = $line;
        }

        if (empty($newLines)) {
            return ['created' => false, 'request_id' => null, 'request_no' => null, 'line_count' => 0, 'skipped' => $skipped];
        }

        $existing = $checkId !== '' ? $this->restockRequestModel->getActiveByCheck($checkId) : null;
        $request = $existing
            ? $this->updateExistingRestockRequest($existing, $newLines, $now)
            : $this->createNewRestockRequest($inspectionData, $newLines, $currentUserId, $now);

        return [
            'created' => true,
            'request_id' => (string)$request['id'],
            'request_no' => (string)$request['no'],
            'line_count' => count($newLines),
            'skipped' => $skipped,
        ];
    }

    private function createNewRestockRequest(array $inspectionData, array $newLines, ?string $currentUserId, string $now): array
    {
        $requestId = generate_id();
        $createdBy = (string)($inspectionData['nurse_id'] ?? '');
        $createdName = (string)($inspectionData['nurse_name'] ?? '');

        $requestData = [
            'id' => $requestId,
            'no' => '', // ถูกกำหนดโดย generateRequestNo() ข้างล่าง (ใช้ MAX(no) ไม่ใช่ COUNT+1)
            'car_id' => (string)($inspectionData['car_id'] ?? ''),
            'car_name' => (string)($inspectionData['car_name'] ?? ''),
            'check_id' => (string)($inspectionData['id'] ?? ''),
            'created_by' => $createdBy,
            'created_name' => $createdName,
            'created_by_user_id' => $currentUserId,
            'reason' => 'สร้างอัตโนมัติจากผลตรวจรถ: พบอุปกรณ์ขาด/ไม่ครบตามมาตรฐาน',
            'note' => '',
            'status' => RestockRequest::STATUS_PENDING,
            'created_at' => $now,
        ];

        $this->insertRequestWithUniqueNo($requestData);

        foreach ($newLines as $index => $line) {
            $item = $line['item'];
            $this->restockItemModel->create([
                'request_id' => $requestId,
                'item_id' => (string)$item['id'],
                'sort_order' => $index,
                'name' => (string)$item['name'],
                'standard' => (string)$item['standard'],
                'actual_qty' => $line['actual'] !== null ? self::fmtQty((float)$line['actual']) : '',
                'qty_requested' => self::fmtQty((float)$line['qty_to_add']),
                'qty_issued' => '',
                'qty_filled' => '',
                // รอยเท้ายอดบนรถ ณ ตอนสร้างคำขอ (ข้อ 3.2 / ข้อ 6)
                'vehicle_stock_before' => $line['before'],
                'vehicle_stock_after' => $line['actual'],
            ]);
        }

        $this->restockActionModel->create([
            'request_id' => $requestId,
            'type' => 'create',
            'at' => $now,
            'by_id' => $currentUserId ?? $createdBy,
            'by_name' => $currentUserId !== null ? 'ระบบ (จากรอบตรวจรถ)' : $createdName,
            'note' => 'สร้างอัตโนมัติจากผลตรวจรถ • ' . count($newLines) . ' รายการที่ต้องเติม',
            'action_lines' => array_map(fn($l) => [
                'itemId' => (string)$l['item']['id'],
                'qty' => self::fmtQty((float)$l['qty_to_add']),
            ], $newLines),
        ]);

        return $this->restockRequestModel->find($requestId) ?? $requestData;
    }

    /**
     * เติมบรรทัดใหม่/ขยายยอดบรรทัดเดิมของคำขอที่เปิดอยู่สำหรับรอบตรวจเดียวกัน
     * (เส้นทางนี้เป็นการป้องกันกรณีบันทึกซ้ำ — ปกติรอบตรวจใหม่จะยังไม่มีคำขอผูกอยู่)
     */
    private function updateExistingRestockRequest(array $existingRequest, array $newLines, string $now): array
    {
        $requestId = (string)$existingRequest['id'];
        $existingItems = $this->restockItemModel->getByRequest($requestId);
        $byItem = [];
        foreach ($existingItems as $row) {
            $byItem[(string)$row['item_id']] = $row;
        }

        $lines = [];
        $sortOrder = count($existingItems);
        foreach ($newLines as $line) {
            $itemId = (string)$line['item']['id'];
            $add = (float)$line['qty_to_add'];
            $row = $byItem[$itemId] ?? null;

            if ($row) {
                // ขยายยอดที่ต้องเติม ไม่เคยลด และ ไม่แตะจำนวนที่เติมจริงไปแล้ว (ข้อ 6.5)
                $newRequested = round((float)($row['qty_requested'] ?? 0) + $add, 2);
                $this->restockItemModel->query(
                    "UPDATE " . $this->restockItemModel->getTable()
                    . " SET qty_requested = ?, actual_qty = ? WHERE request_id = ? AND item_id = ?",
                    [self::fmtQty($newRequested), $line['actual'] !== null ? self::fmtQty((float)$line['actual']) : '', $requestId, $itemId]
                );
            } else {
                $item = $line['item'];
                $this->restockItemModel->create([
                    'request_id' => $requestId,
                    'item_id' => $itemId,
                    'sort_order' => $sortOrder++,
                    'name' => (string)$item['name'],
                    'standard' => (string)$item['standard'],
                    'actual_qty' => $line['actual'] !== null ? self::fmtQty((float)$line['actual']) : '',
                    'qty_requested' => self::fmtQty($add),
                    'qty_issued' => '',
                    'qty_filled' => '',
                    'vehicle_stock_before' => $line['before'],
                    'vehicle_stock_after' => $line['actual'],
                ]);
            }

            $lines[] = ['itemId' => $itemId, 'qty' => self::fmtQty($add)];
        }

        $this->restockActionModel->create([
            'request_id' => $requestId,
            'type' => 'create',
            'at' => $now,
            'by_id' => $existingRequest['created_by_user_id'] ?? $existingRequest['created_by'],
            'by_name' => (string)($existingRequest['created_name'] ?? ''),
            'note' => 'ปรับปรุงรายการที่ต้องเติมจากรอบตรวจล่าสุด',
            'action_lines' => $lines,
        ]);

        return $this->restockRequestModel->find($requestId) ?? $existingRequest;
    }

    /**
     * สร้างเลขที่คำขอโดยไม่ชน UNIQUE uk_no: อ่าน MAX(no) ของวันนั้นแล้ว +1
     * (เดิมใช้ COUNT(*)+1 ซึ่งชนทันทีเมื่อมีคำขอถูกยกเลิก/บันทึกพร้อมกัน ทำให้ saveInspection ทั้งรอบ rollback)
     * ถ้ายังแพ้การแข่งอยู่ จะได้ duplicate key แล้วลองใหม่สูงสุด 5 ครั้ง
     */
    private function insertRequestWithUniqueNo(array $requestData): void
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $requestData['no'] = $this->generateRequestNo();
            try {
                $this->restockRequestModel->create($requestData);
                return;
            } catch (\PDOException $e) {
                if (!$this->isDuplicateKeyError($e)) {
                    throw $e;
                }
                $lastError = $e;
                usleep(50000); // 50ms แล้วอ่าน MAX(no) ใหม่
            }
        }

        throw new \RuntimeException('ออกเลขที่คำขอเติมอุปกรณ์ไม่ได้ กรุณาลองอีกครั้ง', 0, $lastError);
    }

    private function isDuplicateKeyError(\PDOException $e): bool
    {
        return in_array((string)$e->getCode(), ['23000', '23505'], true)
            || stripos($e->getMessage(), 'duplicate entry') !== false;
    }

    private function generateRequestNo(): string
    {
        $prefix = 'RS-' . (new \DateTime())->format('Ymd');
        $max = $this->restockRequestModel->maxRequestNoForPrefix($prefix);

        $seq = 1;
        if ($max !== null && preg_match('/(\d+)$/', $max, $m)) {
            $seq = (int)$m[1] + 1;
        }

        return $prefix . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    public function getInspections(): array
    {
        return $this->inspectionModel->all(['*'], 'completed_at DESC');
    }

    public function getItemSummary(array $checkIds): array
    {
        $rows = (new InspectionItem())->getSummaryByChecks($checkIds);
        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['check_id']] = [
                'item_count' => (int)$row['item_count'],
                'attention_count' => (int)$row['attention_count'],
            ];
        }
        return $summary;
    }

    public function getInspectionWithItems(string $id): ?array
    {
        return $this->inspectionModel->getWithItems($id);
    }

    public function getDashboardStats(): array
    {
        $vehicles = $this->getVehicles();
        $inspections = $this->getInspections();
        $today = (new \DateTime())->format('Y-m-d');

        $lastByCar = [];
        $draftByCar = [];
        foreach ($inspections as $inspection) {
            if (!isset($lastByCar[$inspection['car_id']])) {
                $lastByCar[$inspection['car_id']] = $inspection;
            }
        }
        foreach ($vehicles as $vehicle) {
            $draft = $this->getDraft($vehicle['id']);
            if ($draft) {
                $draftByCar[$vehicle['id']] = $draft;
            }
        }

        $checkedToday = 0;
        $attnTotal = 0;
        foreach ($vehicles as $vehicle) {
            $last = $lastByCar[$vehicle['id']] ?? null;
            if ($last) {
                $inspectionDate = (new \DateTime($last['completed_at']))->format('Y-m-d');
                if ($inspectionDate === $today) {
                    $checkedToday++;
                }
                $attnItems = array_filter($last['results'] ?? [], function($item) {
                    return in_array($item['status'], ['ขาด', 'ใช้ไป']) || 
                           ($item['is_battery'] && ($item['battery_level'] ?? 4) < 4);
                });
                $attnTotal += count($attnItems);
            }
        }

        return [
            'total_vehicles' => count($vehicles),
            'checked_today' => $checkedToday,
            'pending_drafts' => count($draftByCar),
            'attention_items' => $attnTotal,
            'vehicles' => $vehicles,
            'last_inspections' => $lastByCar,
            'drafts' => $draftByCar,
        ];
    }

    public function getChecklistConfig(): array
    {
        return [
            ['name' => 'หน้ากากอนามัย (Mask)', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Tourniquet', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ชามรูปไต', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่องวัดความดัน (ติดผนัง)', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่องวัดความดัน (พกพา)', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Stethoscope', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Laryngoscope + Blade', 'standard' => '1 ชุด', 'is_battery' => false],
            ['name' => 'Micropore', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Transpore', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่อง Suction', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Macgril forcep', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เสาน้ำเกลือ', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถังขยะติดเชื้อ', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถังขยะทั่วไป', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่อง AED (ระบุจำนวนขีดแบต)', 'standard' => 1, 'is_battery' => true],
            ['name' => 'เครื่องวัด O2 Sat mobile', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Slide Board', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Alcohol jelly', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถุงมือเบอร์ M', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถุงมือเบอร์ S', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Aquapak 340 ml', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Lubricgel', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Betadine', 'standard' => 1, 'is_battery' => false],
            ['name' => '0.9% NSS 100 cc', 'standard' => 1, 'is_battery' => false],
            ['name' => '0.9% NSS 1000 cc', 'standard' => 1, 'is_battery' => false],
            ['name' => '5% DN/2 1000 cc', 'standard' => 1, 'is_battery' => false],
            ['name' => '70% Alcohol', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Set IV', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Set Infusion', 'standard' => 2, 'is_battery' => false],
            ['name' => '3-way', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Extension', 'standard' => 2, 'is_battery' => false],
            ['name' => 'สาย Suction No.14', 'standard' => 3, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 20 ml', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 10 ml', 'standard' => 5, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 5 ml', 'standard' => 5, 'is_battery' => false],
            ['name' => 'Syringe ขนาด 3 ml', 'standard' => 5, 'is_battery' => false],
            ['name' => 'เข็มเบอร์ 18', 'standard' => 5, 'is_battery' => false],
            ['name' => 'เข็มเบอร์ 21', 'standard' => 5, 'is_battery' => false],
            ['name' => 'เข็มเบอร์ 24', 'standard' => 5, 'is_battery' => false],
            ['name' => 'IV catheter No.20', 'standard' => 3, 'is_battery' => false],
            ['name' => 'IV catheter No.22', 'standard' => 3, 'is_battery' => false],
            ['name' => 'IV catheter No.24', 'standard' => 3, 'is_battery' => false],
            ['name' => 'Bacteria Filter', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 6.5', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 7', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 7.5', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Endotracheal tube ID 8', 'standard' => 2, 'is_battery' => false],
            ['name' => 'Stylet', 'standard' => 1, 'is_battery' => false],
            ['name' => 'O2 Mask with Bag', 'standard' => 1, 'is_battery' => false],
            ['name' => 'O2 cannula', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ชุด Ambu Bag + สายต่อ O2', 'standard' => 1, 'is_battery' => false],
            ['name' => 'Oral Airway (4 ชิ้น No.3,4)', 'standard' => '1 ชุด', 'is_battery' => false],
            ['name' => 'Nasal Airway No.7.5', 'standard' => 1, 'is_battery' => false],
            ['name' => 'ถุงมือ Sterile No.6.5', 'standard' => 3, 'is_battery' => false],
            ['name' => 'ถุงมือ Sterile No.7', 'standard' => 3, 'is_battery' => false],
            ['name' => 'สำลี 5 ก้อน', 'standard' => 4, 'is_battery' => false],
            ['name' => 'Gauze Bandage', 'standard' => 2, 'is_battery' => false],
            ['name' => 'ไม้พันสำลี', 'standard' => 4, 'is_battery' => false],
            ['name' => 'ก๊อส 3x3', 'standard' => 4, 'is_battery' => false],
            ['name' => 'ไฟฉาย', 'standard' => 1, 'is_battery' => false],
            ['name' => 'เครื่องเจาะ DTX', 'standard' => 1, 'is_battery' => false],
            ['name' => 'O2 ถังใหญ่ (หน่วย PSI)', 'standard' => 2, 'is_battery' => false],
            ['name' => 'O2 ถังเล็ก (หน่วย PSI)', 'standard' => 1, 'is_battery' => false],
        ];
    }
}