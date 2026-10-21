<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Vehicle;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\Nurse;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Draft;
use App\Models\DraftItem;
use App\Models\CheckHistory;
use App\Models\Metadata;
use App\Models\RestockRequest;
use App\Models\RestockItem;
use App\Models\RestockAction;
use App\Models\StockMovement;
use App\Config\Database;
use App\Exceptions\ApiException;

/**
 * การตรวจเช็คอุปกรณ์ประจำรถตามเวร (1 รอบต่อ รถ + วันที่ + เวร — UNIQUE uk_check_car_date_shift)
 *
 * ของสิ้นเปลือง (box / piece):
 *   ผู้ตรวจกรอก "ใช้ไป" (0 ได้) → ระบบคาดการณ์ "คงเหลือ = ก่อนตรวจ − ใช้ไป"
 *   → ผู้ตรวจยืนยัน "ครบถ้วน" (นับได้ตรงที่คาด) หรือกรอก "นับได้จริง" ถ้าไม่ตรง (= ผิดปกติ ต้องมีหมายเหตุ)
 *   ตัดยอดแบบ FEFO ผ่าน VehicleStockService และบันทึก ledger ทุก Lot ที่ถูกแตะ
 * เครื่องไฟฟ้า (electric): บันทึกสถานะ/แบต 1-4 ขีด รายเครื่อง — ไม่ตัดสต๊อคเด็ดขาด
 *
 * แก้รอบเดิม: เก็บสำเนาก่อนแก้ลง refer_check_history → ย้อนผลรอบเดิมจาก ledger → คำนวณใหม่
 * ทั้งหมดใน transaction เดียว จึงไม่หักซ้ำไม่ว่าจะแก้กี่ครั้ง
 */
class InspectionService
{
    // ---- สถานะผลตรวจรายรายการ (refer_check_items.check_status) ----
    public const RESULT_COMPLETE = 'complete';        // ตรวจสอบแล้ว ครบถ้วน
    public const RESULT_REFILL = 'needs_refill';      // ตรวจสอบแล้ว ต้องเติม (คงเหลือ < เป้าหมาย)
    public const RESULT_ANOMALY = 'anomaly';          // ตรวจสอบแล้ว พบความผิดปกติ
    public const RESULT_PENDING = 'pending';          // ยังไม่ได้ตรวจสอบ

    /** สถานะรุ่นเก่า (คอลัมน์ status) — ยังเขียนคู่กันเพื่อให้หน้าประวัติ/รายงานเดิมอ่านได้ */
    public const STATUS_PENDING = 'ยังไม่ตรวจ';
    public const STATUS_ENOUGH  = 'Checked';
    public const STATUS_USED    = 'ใช้ไป';
    public const STATUS_MISSING = 'ขาด';

    public const SHIFTS = [
        'morning' => ['name' => 'เช้า', 'label' => 'เวรเช้า (08:00-16:00)'],
        'afternoon' => ['name' => 'บ่าย', 'label' => 'เวรบ่าย (16:00-24:00)'],
        'night' => ['name' => 'ดึก', 'label' => 'เวรดึก (00:00-08:00)'],
    ];

    /** เพดานที่ยอมรับได้ของจำนวนที่กรอก (กันกรอกผิดหลัก/ข้อมูลขยะ) */
    public const MAX_QTY = 100000.0;

    private Vehicle $vehicleModel;
    private Item $itemModel;
    private ItemUnit $unitModel;
    private Nurse $nurseModel;
    private Inspection $inspectionModel;
    private InspectionItem $inspectionItemModel;
    private Draft $draftModel;
    private DraftItem $draftItemModel;
    private CheckHistory $historyModel;
    private Metadata $metadataModel;
    private RestockRequest $restockRequestModel;
    private RestockItem $restockItemModel;
    private RestockAction $restockActionModel;
    private VehicleStockService $stock;

    public function __construct()
    {
        $this->vehicleModel = new Vehicle();
        $this->itemModel = new Item();
        $this->unitModel = new ItemUnit();
        $this->nurseModel = new Nurse();
        $this->inspectionModel = new Inspection();
        $this->inspectionItemModel = new InspectionItem();
        $this->draftModel = new Draft();
        $this->draftItemModel = new DraftItem();
        $this->historyModel = new CheckHistory();
        $this->metadataModel = new Metadata();
        $this->restockRequestModel = new RestockRequest();
        $this->restockItemModel = new RestockItem();
        $this->restockActionModel = new RestockAction();
        $this->stock = new VehicleStockService();
    }

    public function getVehicles(): array
    {
        return $this->vehicleModel->all(['*'], 'name');
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

    public function getDraft(string $carId): ?array
    {
        return $this->draftModel->getByVehicle($carId);
    }

    // ==================== เวร ====================

    /** เวรปัจจุบันตามเวลาไทย: 08-16 เช้า / 16-24 บ่าย / 00-08 ดึก (วันที่เดียวกัน) */
    public static function currentShift(?\DateTimeImmutable $at = null): array
    {
        $at = ($at ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Asia/Bangkok'));
        $hour = (int)$at->format('G');
        $code = $hour < 8 ? 'night' : ($hour < 16 ? 'morning' : 'afternoon');
        return ['check_date' => $at->format('Y-m-d'), 'shift_code' => $code];
    }

    public static function shiftName(?string $code): string
    {
        return self::SHIFTS[$code ?? '']['name'] ?? '';
    }

    /** ตรวจ/ปรับวันที่+เวรจาก client — ห้ามวันในอนาคต และย้อนหลังได้ไม่เกิน 7 วัน */
    public static function normalizeShift(array $input): array
    {
        $code = (string)($input['shift_code'] ?? '');
        if ($code === '' && isset($input['shift'])) {
            // client รุ่นเก่าส่งชื่อเวรภาษาไทย
            foreach (self::SHIFTS as $key => $shift) {
                if ($shift['name'] === (string)$input['shift']) {
                    $code = $key;
                }
            }
        }
        if (!isset(self::SHIFTS[$code])) {
            throw new ApiException('กรุณาเลือกเวร (เช้า / บ่าย / ดึก)', 422);
        }
        $date = (string)($input['check_date'] ?? '');
        if ($date === '') {
            $date = self::currentShift()['check_date'];
        }
        if (!VehicleStockService::validDate($date)) {
            throw new ApiException('วันที่ตรวจไม่ถูกต้อง', 422);
        }
        $today = VehicleStockService::today();
        if ($date > $today) {
            throw new ApiException('ไม่สามารถบันทึกการตรวจของวันในอนาคตได้', 422);
        }
        $oldest = (new \DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
        if ($date < $oldest) {
            throw new ApiException('ย้อนหลังได้ไม่เกิน 7 วัน', 422);
        }
        return ['check_date' => $date, 'shift_code' => $code];
    }

    public function findCheckByShift(string $carId, string $date, string $shiftCode): ?array
    {
        $stmt = $this->inspectionModel->query(
            "SELECT * FROM " . $this->inspectionModel->getTable() . " WHERE car_id = ? AND check_date = ? AND shift_code = ? LIMIT 1",
            [$carId, $date, $shiftCode]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** รอบตรวจของรถในวันหนึ่ง: shift_code => {id, nurse_name, completed_at} — ใช้แสดงว่าเวรไหนตรวจแล้ว */
    public function shiftsDoneOn(string $date): array
    {
        $stmt = $this->inspectionModel->query(
            "SELECT id, car_id, shift_code, nurse_name, completed_at FROM " . $this->inspectionModel->getTable() . " WHERE check_date = ?",
            [$date]
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['car_id']][(string)$row['shift_code']] = $row;
        }
        return $out;
    }

    // ==================== เริ่ม/ร่าง ====================

    /**
     * เริ่มตรวจ: ถ้าเวรนี้ของรถนี้บันทึกไปแล้ว → เปิดรอบเดิมเพื่อแก้ไข (mode=edit)
     * ถ้ามีร่างของคน/เวรเดียวกัน → ทำต่อ (mode=draft) / ร่างของคนอื่น → 409 ให้ยืนยันแทนที่
     */
    public function start(array $vehicle, array $nurse, array $shift, bool $replace, ?array $user): array
    {
        $carId = (string)$vehicle['id'];
        $existing = $this->findCheckByShift($carId, $shift['check_date'], $shift['shift_code']);
        if ($existing) {
            $this->assertCanEditCheck($existing, $user);
            return $this->sessionPayload('edit', $existing, $this->buildForm($carId, null, (string)$existing['id']));
        }

        $draft = $this->draftModel->getByVehicle($carId);
        $sameOwner = $draft
            && (string)$draft['nurse_id'] === (string)$nurse['id']
            && (string)($draft['check_date'] ?? '') === $shift['check_date']
            && (string)($draft['shift_code'] ?? '') === $shift['shift_code'];
        if ($draft && $sameOwner) {
            $payload = json_decode((string)($draft['payload'] ?? ''), true);
            return $this->sessionPayload('draft', $draft, $this->buildForm($carId, is_array($payload) ? $payload : null, null));
        }
        if ($draft && !$replace) {
            throw new ApiException(
                sprintf('มีร่างการตรวจของ %s (%s %s) ค้างอยู่', $draft['nurse_name'], $draft['check_date'] ?? '', self::shiftName($draft['shift_code'] ?? null) ?: $draft['shift_name']),
                409,
                ['code' => 'DRAFT_EXISTS', 'draft' => [
                    'nurse_id' => $draft['nurse_id'],
                    'nurse_name' => $draft['nurse_name'],
                    'shift_name' => self::shiftName($draft['shift_code'] ?? null) ?: $draft['shift_name'],
                    'check_date' => $draft['check_date'] ?? null,
                    'started_at' => $draft['started_at'],
                ]]
            );
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $old = $this->draftModel->lockByVehicle($carId);
            if ($old) {
                $this->draftItemModel->query("DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?", [$old['id']]);
                $this->draftModel->delete((string)$old['id']);
            }
            $draft = [
                'id' => generate_id(),
                'car_id' => $carId,
                'nurse_id' => (string)$nurse['id'],
                'car_name' => (string)$vehicle['name'],
                'nurse_name' => (string)$nurse['name'],
                'shift_name' => self::shiftName($shift['shift_code']),
                'check_date' => $shift['check_date'],
                'shift_code' => $shift['shift_code'],
                'started_at' => VehicleStockService::now(),
                'payload' => null,
            ];
            $this->draftModel->create($draft);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $this->sessionPayload('new', $draft, $this->buildForm($carId, null, null));
    }

    private function sessionPayload(string $mode, array $row, array $results): array
    {
        return [
            'mode' => $mode,
            'id' => (string)$row['id'],
            'car_id' => (string)$row['car_id'],
            'nurse_id' => (string)$row['nurse_id'],
            'car_name' => (string)$row['car_name'],
            'nurse_name' => (string)$row['nurse_name'],
            'check_date' => $row['check_date'] ?? null,
            'shift_code' => $row['shift_code'] ?? null,
            'shift_name' => self::shiftName($row['shift_code'] ?? null) ?: (string)($row['shift_name'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'revision' => isset($row['revision']) ? (int)$row['revision'] : null,
            'results' => $results,
        ];
    }

    /** ร่าง: เก็บสถานะฟอร์มทั้งชุดเป็น JSON (ไม่แตะยอดสต๊อค) */
    public function saveDraft(string $carId, array $lines, ?array $user): void
    {
        $draft = $this->draftModel->getByVehicle($carId);
        if (!$draft) {
            throw new ApiException('ไม่พบร่างการตรวจของรถคันนี้ (อาจถูกบันทึกหรือแทนที่ไปแล้ว)', 404);
        }
        $this->assertDraftOwner($draft, $user);
        $clean = [];
        foreach (array_slice($lines, 0, 500) as $line) {
            if (!is_array($line) || !isset($line['id'])) {
                continue;
            }
            $clean[] = [
                'id' => (string)$line['id'],
                'used' => $line['used'] ?? null,
                'confirmed' => !empty($line['confirmed']),
                'actual' => $line['actual'] ?? null,
                'note' => mb_substr((string)($line['note'] ?? ''), 0, 500),
                'units' => is_array($line['units'] ?? null) ? array_slice($line['units'], 0, 50) : [],
            ];
        }
        $this->draftModel->update((string)$draft['id'], ['payload' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
    }

    private function assertDraftOwner(array $draft, ?array $user): void
    {
        if (($user['role'] ?? '') === 'staff' && (string)($user['nurse_id'] ?? '') !== (string)$draft['nurse_id']) {
            throw new ApiException('ร่างการตรวจนี้เป็นของ ' . $draft['nurse_name'] . ' กรุณาเริ่มการตรวจในชื่อของท่าน', 403);
        }
    }

    /** แก้รอบตรวจที่บันทึกแล้ว: admin ทุกรอบ / staff เฉพาะรอบที่ตนเป็นผู้ตรวจ */
    private function assertCanEditCheck(array $check, ?array $user): void
    {
        if (($user['role'] ?? '') === 'admin') {
            return;
        }
        if ((string)($user['nurse_id'] ?? '') !== (string)$check['nurse_id']) {
            throw new ApiException(
                sprintf('เวรนี้ถูกตรวจและบันทึกแล้วโดย %s — แก้ไขได้เฉพาะผู้ตรวจเดิมหรือผู้ดูแลระบบ', $check['nurse_name']),
                409,
                ['code' => 'SHIFT_ALREADY_CHECKED']
            );
        }
    }

    // ==================== สร้างข้อมูลฟอร์ม ====================

    /**
     * รายการฟอร์มตรวจของรถ 1 คัน — ยอดจาก DB ตอนเรียกทุกครั้ง
     * @param array|null $payload สถานะฟอร์มจากร่าง
     * @param string|null $checkId รอบตรวจเดิม (โหมดแก้ไข) — ยอดก่อนตรวจ = ยอดปัจจุบันที่ย้อนผลของรอบนี้ออก
     */
    public function buildForm(string $carId, ?array $payload, ?string $checkId): array
    {
        $items = $this->itemModel->getActiveByVehicle($carId);
        $ids = array_map(fn($i) => (string)$i['id'], $items);
        $details = $this->stock->detailsForItems($ids);

        $state = [];
        foreach ($payload ?? [] as $line) {
            $state[(string)($line['id'] ?? '')] = $line;
        }
        $netByItem = [];
        if ($checkId !== null) {
            foreach ($this->inspectionItemModel->where('check_id', $checkId) as $row) {
                $snapshotUnits = json_decode((string)($row['units_snapshot'] ?? ''), true);
                $state[(string)$row['item_id']] = [
                    'used' => $row['used_qty'] !== null ? (float)$row['used_qty'] : null,
                    'confirmed' => $row['discrepancy'] !== null && (float)$row['discrepancy'] == 0.0 && $row['used_qty'] !== null,
                    'actual' => $row['actual_qty'] !== null ? (float)$row['actual_qty'] : null,
                    'note' => (string)($row['note'] ?? ''),
                    'units' => is_array($snapshotUnits) ? $snapshotUnits : [],
                ];
            }
            foreach ((new StockMovement())->netByLotForCheck($checkId) as $mv) {
                $netByItem[(string)$mv['item_id']] = ($netByItem[(string)$mv['item_id']] ?? 0.0) + (float)$mv['net'];
            }
        }

        $out = [];
        foreach ($items as $item) {
            $itemId = (string)$item['id'];
            $type = VehicleStockService::itemType($item);
            $lots = $details['lots'][$itemId] ?? [];
            $units = $details['units'][$itemId] ?? [];
            $before = Item::countedQty($item);
            if ($before !== null && isset($netByItem[$itemId])) {
                $before = round($before - $netByItem[$itemId], 2);
            }
            $s = $state[$itemId] ?? [];
            $unitState = [];
            foreach ($s['units'] ?? [] as $u) {
                if (isset($u['id'])) {
                    $unitState[(string)$u['id']] = $u;
                }
            }

            $out[] = [
                'id' => $itemId,
                'name' => (string)$item['name'],
                'standard' => (string)$item['standard'],
                'unit' => self::itemUnit($item),
                'item_type' => $type,
                'target_qty' => self::itemTargetQty($item),
                'before' => $type === VehicleStockService::TYPE_ELECTRIC ? (float)count($units) : $before,
                'counted' => Item::countedQty($item) !== null,
                'lots' => $lots,
                'expiry' => VehicleStockService::expirySummary($lots),
                'units' => array_map(fn($u) => [
                    'id' => (string)$u['id'],
                    'label' => (string)$u['label'],
                    'status' => (string)($unitState[(string)$u['id']]['status'] ?? $u['status']),
                    'battery_level' => isset($unitState[(string)$u['id']]['battery_level'])
                        ? (int)$unitState[(string)$u['id']]['battery_level']
                        : ($u['battery_level'] !== null ? (int)$u['battery_level'] : null),
                    'note' => (string)($unitState[(string)$u['id']]['note'] ?? $u['note']),
                    // true = ค่าเป็นผลตรวจของรอบนี้ (จากร่าง/รอบที่บันทึก) ไม่ใช่สถานะค้างจากรอบก่อน
                    'checked' => isset($unitState[(string)$u['id']]),
                ], $units),
                'used' => $s['used'] ?? null,
                'confirmed' => !empty($s['confirmed']),
                'actual' => $s['actual'] ?? null,
                'note' => (string)($s['note'] ?? ''),
            ];
        }
        return $out;
    }

    // ==================== กติกาตัวเลข ====================

    private static function itemTargetQty(array $item): ?float
    {
        if (isset($item['target_qty']) && $item['target_qty'] !== '' && $item['target_qty'] !== null) {
            return round((float)$item['target_qty'], 2);
        }
        if (preg_match('/\d+(?:[.]\d+)?/', (string)($item['standard'] ?? ''), $m) && (float)$m[0] > 0) {
            return round((float)$m[0], 2);
        }
        return null;
    }

    private static function itemUnit(array $item): string
    {
        $unit = trim((string)($item['unit'] ?? ''));
        if ($unit !== '') {
            return $unit;
        }
        if (preg_match('/^[^0-9]*\d+(?:[.]\d+)?\s+(\S.*)$/u', (string)($item['standard'] ?? ''), $m)) {
            return trim($m[1]);
        }
        return VehicleStockService::itemType($item) === VehicleStockService::TYPE_BOX ? 'กล่อง' : '';
    }

    /** ตัวเลขไม่ติดลบจาก client; '' / null = ไม่ได้กรอก */
    private static function parseQty(mixed $raw, string $label): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float)$raw < 0) {
            throw new ApiException($label . ' ต้องเป็นตัวเลขไม่ติดลบ', 422);
        }
        $qty = round((float)$raw, 2);
        if ($qty > self::MAX_QTY) {
            throw new ApiException($label . ' สูงเกินกำหนด', 422);
        }
        return $qty;
    }

    /**
     * คำนวณผลของ 1 รายการจากค่าที่ผู้ตรวจกรอก (ใช้ทั้งตอนบันทึก — กติกาเดียวกับหน้าจอ)
     * @return array ผลลัพธ์ + 'result' (complete/needs_refill/anomaly/pending)
     */
    public static function evaluateLine(array $form, array $raw): array
    {
        $name = (string)$form['name'];
        $target = $form['target_qty'];

        if ($form['item_type'] === VehicleStockService::TYPE_ELECTRIC) {
            $units = [];
            $byId = [];
            foreach ((array)($raw['units'] ?? []) as $u) {
                if (is_array($u) && isset($u['id'])) {
                    $byId[(string)$u['id']] = $u;
                }
            }
            $anyNotReady = false;
            foreach ($form['units'] as $unit) {
                $input = $byId[$unit['id']] ?? null;
                if ($input === null) {
                    return ['result' => self::RESULT_PENDING, 'error' => $name . ': ยังไม่ได้ระบุสถานะเครื่อง ' . ($unit['label'] ?: '')];
                }
                $parsed = VehicleStockService::parseUnitInput($input, trim($name . ' ' . $unit['label']));
                $units[] = ['id' => $unit['id'], 'label' => $unit['label']] + $parsed;
                $anyNotReady = $anyNotReady || $parsed['status'] === ItemUnit::STATUS_NOT_READY;
            }
            $note = trim((string)($raw['note'] ?? ''));
            if (empty($form['units'])) {
                if ($note === '') {
                    return ['result' => self::RESULT_PENDING, 'error' => $name . ': ยังไม่มีเครื่องลงทะเบียนบนรถ — ระบุหมายเหตุ หรือเพิ่มเครื่องที่หน้าเติมของ'];
                }
                return ['result' => self::RESULT_ANOMALY, 'units' => [], 'note' => $note, 'before' => 0.0, 'used' => null, 'expected' => null, 'actual' => 0.0, 'discrepancy' => null, 'refill' => 0.0];
            }
            return [
                'result' => $anyNotReady ? self::RESULT_ANOMALY : self::RESULT_COMPLETE,
                'units' => $units,
                'note' => mb_substr($note, 0, 500),
                'before' => (float)count($form['units']),
                'used' => null,
                'expected' => null,
                'actual' => (float)count($form['units']),
                'discrepancy' => null,
                'refill' => 0.0,
            ];
        }

        $note = mb_substr(trim((string)($raw['note'] ?? '')), 0, 500);
        $before = $form['before'];

        // ยังไม่เคยตรวจนับ → ต้องนับจริงเพื่อตั้งยอดครั้งแรก (ไม่มี "ใช้ไป" ให้คำนวณ)
        if ($before === null) {
            $actual = self::parseQty($raw['actual'] ?? null, $name . ': จำนวนนับได้จริง');
            if ($actual === null) {
                return ['result' => self::RESULT_PENDING, 'error' => $name . ': ยังไม่เคยตรวจนับ กรุณากรอกจำนวนที่นับได้จริงเพื่อตั้งยอดครั้งแรก'];
            }
            return [
                'result' => ($target !== null && $actual < $target) ? self::RESULT_REFILL : self::RESULT_COMPLETE,
                'initial' => true, 'note' => $note,
                'before' => null, 'used' => null, 'expected' => null, 'actual' => $actual, 'discrepancy' => null,
                'refill' => $target !== null ? round(max(0.0, $target - $actual), 2) : 0.0,
            ];
        }

        $used = self::parseQty($raw['used'] ?? 0, $name . ': จำนวนที่ใช้ไป') ?? 0.0;
        if ($used > $before) {
            throw new ApiException(sprintf(
                '%s: ใช้ไป %s มากกว่ายอดบนรถ %s — ถ้านับของจริงได้ไม่ตรง ให้กรอก "นับได้จริง" พร้อมหมายเหตุแทน',
                $name, VehicleStockService::fmt($used), VehicleStockService::fmt($before)
            ), 422);
        }
        $expected = round($before - $used, 2);

        $actual = null;
        if (!empty($raw['confirmed'])) {
            $actual = $expected;
        } else {
            $actual = self::parseQty($raw['actual'] ?? null, $name . ': จำนวนนับได้จริง');
        }
        if ($actual === null) {
            return ['result' => self::RESULT_PENDING, 'error' => $name . ': ยังไม่ยืนยันผลตรวจนับ (กด "ครบถ้วน" หรือกรอกจำนวนนับได้จริง)'];
        }
        $discrepancy = round($actual - $expected, 2);
        if ($discrepancy != 0.0 && $note === '') {
            throw new ApiException(sprintf(
                '%s: นับได้ %s ไม่ตรงกับที่ระบบคาดการณ์ %s — กรุณาระบุหมายเหตุ',
                $name, VehicleStockService::fmt($actual), VehicleStockService::fmt($expected)
            ), 422);
        }

        $result = $discrepancy != 0.0
            ? self::RESULT_ANOMALY
            : (($target !== null && $actual < $target) ? self::RESULT_REFILL : self::RESULT_COMPLETE);

        return [
            'result' => $result, 'note' => $note,
            'before' => $before, 'used' => $used, 'expected' => $expected, 'actual' => $actual, 'discrepancy' => $discrepancy,
            'refill' => $target !== null ? round(max(0.0, $target - $actual), 2) : 0.0,
        ];
    }

    // ==================== บันทึก ====================

    /**
     * บันทึกรอบตรวจ (ใหม่หรือแก้รอบเดิม) แบบ all-or-nothing
     * @param array $input {car_id, check_id, mode: new|edit, results: [...]}
     */
    public function submit(array $input, ?array $user): array
    {
        $carId = (string)($input['car_id'] ?? '');
        $checkId = (string)($input['check_id'] ?? $input['id'] ?? '');
        $mode = (string)($input['mode'] ?? 'new');
        $rawLines = [];
        foreach ((array)($input['results'] ?? []) as $line) {
            if (is_array($line) && isset($line['id'])) {
                $rawLines[(string)$line['id']] = $line;
            }
        }
        if ($carId === '' || $checkId === '') {
            throw new ApiException('ข้อมูลไม่ครบ: ไม่พบรหัสรถหรือรหัสรอบตรวจ', 422);
        }

        $userId = isset($user['id']) ? (string)$user['id'] : null;
        $userName = (string)($user['full_name'] ?? '');
        $now = VehicleStockService::now();

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $vehicle = $this->vehicleModel->find($carId);
            if (!$vehicle) {
                throw new ApiException('ไม่พบรถในฐานข้อมูล', 404);
            }

            if ($mode === 'edit') {
                $stmt = $this->inspectionModel->query("SELECT * FROM " . $this->inspectionModel->getTable() . " WHERE id = ? FOR UPDATE", [$checkId]);
                $header = $stmt->fetch() ?: null;
                if (!$header || (string)$header['car_id'] !== $carId) {
                    throw new ApiException('ไม่พบรอบตรวจที่ต้องการแก้ไข', 404);
                }
                $this->assertCanEditCheck($header, $user);
                $expectedRevision = isset($input['revision']) ? (int)$input['revision'] : null;
                if ($expectedRevision !== null && $expectedRevision !== (int)$header['revision']) {
                    throw new ApiException('รอบตรวจนี้ถูกแก้ไขโดยผู้อื่นระหว่างที่ท่านกำลังแก้ กรุณาเปิดใหม่เพื่อดูข้อมูลล่าสุด', 409, ['code' => 'STALE_REVISION']);
                }
                $ctx = ['check_id' => $checkId, 'by_user_id' => $userId, 'by_name' => $userName, 'at' => $now];

                // 1) เก็บสำเนาก่อนแก้ 2) ย้อนผลต่อยอดของรอบเดิม 3) ล้างบรรทัดเดิม
                $this->historyModel->create([
                    'check_id' => $checkId,
                    'revision' => (int)$header['revision'],
                    'snapshot' => json_encode([
                        'header' => $header,
                        'lines' => $this->inspectionItemModel->where('check_id', $checkId),
                    ], JSON_UNESCAPED_UNICODE),
                    'by_name' => $userName,
                    'at' => $now,
                ]);
                $this->stock->reverseCheck($checkId, $ctx + ['reason' => 'ย้อนผลรอบตรวจเดิมก่อนบันทึกการแก้ไข']);
                $this->inspectionItemModel->query("DELETE FROM " . $this->inspectionItemModel->getTable() . " WHERE check_id = ?", [$checkId]);
                $this->inspectionModel->update($checkId, [
                    'revision' => (int)$header['revision'] + 1,
                    'updated_at' => $now,
                    'updated_by' => $userName,
                    'completed_at' => $now,
                ]);
                $draftLike = $header;
            } else {
                $draft = $this->draftModel->lockByVehicle($carId);
                if (!$draft || (string)$draft['id'] !== $checkId) {
                    if ($this->inspectionModel->find($checkId)) {
                        throw new ApiException('รอบตรวจนี้ถูกบันทึกไปแล้ว (กดซ้ำ?) — เปิดเวรเดิมเพื่อแก้ไขได้', 409, ['code' => 'ALREADY_SUBMITTED']);
                    }
                    throw new ApiException('ไม่พบร่างการตรวจนี้ (อาจถูกแทนที่) กรุณาเริ่มตรวจใหม่', 404);
                }
                $this->assertDraftOwner($draft, $user);
                try {
                    $this->inspectionModel->create([
                        'id' => $checkId,
                        'car_id' => $carId,
                        'nurse_id' => (string)$draft['nurse_id'],
                        'car_name' => (string)$vehicle['name'],
                        'nurse_name' => (string)$draft['nurse_name'],
                        'check_date' => $draft['check_date'],
                        'shift_code' => $draft['shift_code'],
                        'shift_name' => self::shiftName($draft['shift_code']) ?: (string)$draft['shift_name'],
                        'started_at' => (string)$draft['started_at'],
                        'completed_at' => $now,
                        'revision' => 1,
                    ]);
                } catch (\PDOException $e) {
                    if (stripos($e->getMessage(), 'uk_check_car_date_shift') !== false) {
                        throw new ApiException('เวรนี้ของรถคันนี้ถูกบันทึกไปแล้วโดยผู้อื่น — เปิดเวรเดิมเพื่อตรวจสอบ/แก้ไข', 409, ['code' => 'SHIFT_ALREADY_CHECKED']);
                    }
                    throw $e;
                }
                $draftLike = $draft;
                $ctx = ['check_id' => $checkId, 'by_user_id' => $userId, 'by_name' => $userName, 'at' => $now];
            }

            // ล็อกอุปกรณ์ทั้งหมดของรถ (เรียง id กัน deadlock) แล้วสร้างฟอร์มจากยอด "หลังย้อนผล" ใน DB
            $activeIds = array_map(fn($i) => (string)$i['id'], $this->itemModel->getActiveByVehicle($carId));
            $locked = [];
            foreach ($this->itemModel->lockByIds($activeIds) as $row) {
                $locked[(string)$row['id']] = $row;
            }
            $forms = $this->buildForm($carId, null, null);

            $lines = [];
            $errors = [];
            foreach ($forms as $index => $form) {
                $raw = $rawLines[$form['id']] ?? null;
                if ($raw === null) {
                    $errors[] = $form['name'] . ': ไม่มีผลตรวจ (กรุณาโหลดหน้าใหม่)';
                    continue;
                }
                $eval = self::evaluateLine($form, $raw);
                if ($eval['result'] === self::RESULT_PENDING) {
                    $errors[] = $eval['error'];
                    continue;
                }
                $lines[] = ['index' => $index, 'form' => $form, 'item' => $locked[$form['id']]] + $eval;
            }
            if ($errors) {
                throw new ApiException('ยังตรวจไม่ครบ ' . count($errors) . ' รายการ', 422, ['errors' => array_slice($errors, 0, 20)]);
            }

            // ปรับยอดบนรถ (ของสิ้นเปลืองเท่านั้น) + บันทึกสถานะเครื่องไฟฟ้า
            $adjustments = [];
            foreach ($lines as $line) {
                $item = $line['item'];
                if ($line['form']['item_type'] === VehicleStockService::TYPE_ELECTRIC) {
                    foreach ($line['units'] as $u) {
                        $this->unitModel->update($u['id'], [
                            'status' => $u['status'],
                            'battery_level' => $u['battery_level'],
                            'note' => $u['note'],
                            'updated_at' => $now,
                            'updated_by' => $userName,
                        ]);
                    }
                    $this->stock->syncItemTotal($item, $now);
                    continue;
                }
                if (!empty($line['initial'])) {
                    if ($line['actual'] > 0) {
                        $this->stock->addLot($item, $line['actual'], null, 'ADJUST', StockMovement::TYPE_INIT,
                            $ctx + ['reason' => 'ตั้งยอดครั้งแรกจากการตรวจนับ (ยังไม่ทราบวันหมดอายุ)']);
                    } else {
                        $this->stock->syncItemTotal($item, $now);
                    }
                    continue;
                }
                if ($line['used'] > 0) {
                    $this->stock->consume($item, $line['used'], StockMovement::TYPE_USE, $ctx + ['reason' => 'ใช้ไประหว่างเวร']);
                }
                if ($line['discrepancy'] < 0) {
                    $this->stock->consume($item, -$line['discrepancy'], StockMovement::TYPE_ADJUST, $ctx + ['reason' => 'นับได้น้อยกว่าที่คาด: ' . $line['note']]);
                } elseif ($line['discrepancy'] > 0) {
                    $this->stock->addLot($item, $line['discrepancy'], null, 'ADJUST', StockMovement::TYPE_ADJUST,
                        $ctx + ['reason' => 'นับได้มากกว่าที่คาด: ' . $line['note']]);
                } elseif ($line['used'] <= 0) {
                    $this->stock->syncItemTotal($item, $now);   // ประทับเวลาตรวจนับล่าสุด
                }
                if ($line['discrepancy'] != 0.0) {
                    $adjustments[] = ['item_id' => $item['id'], 'name' => $item['name'], 'expected' => $line['expected'], 'actual' => $line['actual'], 'note' => $line['note']];
                }
            }

            foreach ($lines as $line) {
                $form = $line['form'];
                $legacy = match ($line['result']) {
                    self::RESULT_COMPLETE => self::STATUS_ENOUGH,
                    default => ($line['used'] ?? 0) > 0 ? self::STATUS_USED : self::STATUS_MISSING,
                };
                $batteries = array_column($line['units'] ?? [], 'battery_level');
                $this->inspectionItemModel->create([
                    'check_id' => $checkId,
                    'item_id' => $form['id'],
                    'sort_order' => $line['index'],
                    'name' => $form['name'],
                    'standard' => $form['standard'],
                    'expiry_text' => (string)($form['expiry']['nearest'] ?? ''),
                    'is_battery' => $form['item_type'] === VehicleStockService::TYPE_ELECTRIC ? 1 : 0,
                    'status' => $legacy,
                    'check_status' => $line['result'],
                    'item_type' => $form['item_type'],
                    'detail' => self::detailText($line, $form),
                    'note' => $line['note'] ?? '',
                    'battery_level' => $batteries ? min($batteries) : null,
                    'qty_before' => $line['before'],
                    'actual_qty' => $line['actual'],
                    'used_qty' => $line['used'],
                    'expected_qty' => $line['expected'],
                    'discrepancy' => $line['discrepancy'],
                    'qty_target' => $form['target_qty'],
                    'unit' => $form['unit'],
                    'lots_snapshot' => $form['lots'] ? json_encode($form['lots'], JSON_UNESCAPED_UNICODE) : null,
                    'units_snapshot' => !empty($line['units']) ? json_encode($line['units'], JSON_UNESCAPED_UNICODE) : null,
                ]);
            }

            if ($mode !== 'edit') {
                $this->draftItemModel->query("DELETE FROM " . $this->draftItemModel->getTable() . " WHERE draft_id = ?", [$checkId]);
                $this->draftModel->delete($checkId);
            }

            // รายการรอเติม: เฉพาะของสิ้นเปลืองที่คงเหลือต่ำกว่าเป้าหมาย (ไม่รวมเครื่องไฟฟ้า)
            $refillLines = [];
            foreach ($lines as $line) {
                if ($line['form']['item_type'] !== VehicleStockService::TYPE_ELECTRIC && $line['refill'] > 0) {
                    $refillLines[] = ['item' => $line['item'], 'refill' => $line['refill'], 'actual' => $line['actual'], 'before' => $line['before']];
                }
            }
            $refill = $this->createRestockRequestsFromInspection([
                'id' => $checkId,
                'car_id' => $carId,
                'car_name' => (string)$vehicle['name'],
                'nurse_id' => (string)$draftLike['nurse_id'],
                'nurse_name' => (string)$draftLike['nurse_name'],
            ], $refillLines, $userId, $now);

            $issues = $this->stock->reconcile($carId);
            if ($issues) {
                // ไม่ควรเกิด — ถ้าเกิดให้ยกเลิกทั้งรอบดีกว่าบันทึกยอดที่ขัดกัน
                error_log('reconcile failed after inspection ' . $checkId . ': ' . json_encode($issues, JSON_UNESCAPED_UNICODE));
                throw new \RuntimeException('ยอดคงเหลือไม่สอดคล้องกันหลังคำนวณ ระบบยกเลิกการบันทึกเพื่อป้องกันข้อมูลผิด');
            }

            $db->commit();

            $result = $this->inspectionModel->getWithItems($checkId) ?? [];
            $result['mode'] = $mode;
            $result['refill'] = $refill;
            $result['adjustments'] = $adjustments;
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private static function detailText(array $line, array $form): string
    {
        if ($form['item_type'] === VehicleStockService::TYPE_ELECTRIC) {
            $parts = [];
            foreach ($line['units'] ?? [] as $u) {
                $parts[] = trim(($u['label'] ?: 'เครื่อง') . ' ' . ($u['status'] === ItemUnit::STATUS_READY ? 'พร้อมใช้' : 'ไม่พร้อมใช้') . ' แบต ' . $u['battery_level'] . '/4');
            }
            return implode(' • ', $parts) ?: (string)($line['note'] ?? '');
        }
        $unit = $form['unit'] !== '' ? ' ' . $form['unit'] : '';
        if (!empty($line['initial'])) {
            return 'ตั้งยอดครั้งแรก ' . VehicleStockService::fmt((float)$line['actual']) . $unit;
        }
        $text = 'ใช้ไป ' . VehicleStockService::fmt((float)$line['used']) . ' • คงเหลือ ' . VehicleStockService::fmt((float)$line['actual']) . $unit;
        if ($line['discrepancy'] != 0.0) {
            $text .= ' (คาด ' . VehicleStockService::fmt((float)$line['expected']) . ')';
        }
        return $text;
    }

    private static function fmtQty(float $qty): string
    {
        return VehicleStockService::fmt($qty);
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
        $today = VehicleStockService::today();

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
                $inspectionDate = $last['check_date'] ?? (new \DateTime($last['completed_at']))->format('Y-m-d');
                if ($inspectionDate === $today) {
                    $checkedToday++;
                }
                $attnItems = array_filter($last['results'] ?? [], function($item) {
                    if (!empty($item['check_status'])) {
                        return in_array($item['check_status'], [self::RESULT_REFILL, self::RESULT_ANOMALY], true);
                    }
                    return in_array($item['status'], [self::STATUS_MISSING, self::STATUS_USED], true) ||
                           ($item['is_battery'] && ($item['battery_level'] ?? 4) < 4);   // รอบตรวจรุ่นเก่า
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
            'today' => $today,
            'current_shift' => self::currentShift()['shift_code'],
            'shifts_today' => $this->shiftsDoneOn($today),   // car_id => shift_code => รอบตรวจ
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