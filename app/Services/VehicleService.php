<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Exceptions\ApiException;
use App\Models\Item;
use App\Models\Metadata;
use App\Models\StockMovement;
use App\Models\Vehicle;

/**
 * ทะเบียนรถและอุปกรณ์ประจำรถ (Vehicle & Item Management) — แหล่งตรรกะเดียวของทุกหน้าจอ
 *
 * เหตุผลที่มีคลาสนี้: เดิมการเพิ่ม/แก้/ลบรถกับอุปกรณ์กระจายอยู่สอง controller
 * (/api/admin/* ใช้ VehicleService-like code ของตัวเอง, /api/vehicles/* เป็นอีกชุด)
 * ทำให้พฤติกรรมต่างกันและบางเส้นทาง "ไม่มี" ตรวจสิทธิ์แอดมิน — ตอนนี้ทุกเส้นทาง
 * (legacy + admin) เรียกผ่าน service นี้จึงได้การตรวจค่า/Transaction/เงื่อนไขปลอดภัย เท่ากัน
 *
 * ข้อตกลงของระบบที่คลาสนี้ต้องรักษา:
 *  1) "ยังไม่เคยตรวจนับ" = refer_items.stock_updated_at IS NULL (ห้ามตีความเป็น 0)
 *     — การแก้ "ยอดบนรถ" จากหน้าจอ admin เป็นการตรวจนับชนิดหนึ่ง จึงต้องเขียน stamp ไปด้วย
 *     — การคัดลอกรายการ (รถใหม่/คัดลอกเป็นชุด) ต้องยก current_qty ของต้นทางมาด้วย
 *       แต่เฉพาะแถวที่มี stamp จริง เท่านั้น ส่วนแถวที่ยังไม่เคยนับให้คงค่าว่างไว้
 *  2) refer_items.current_qty ต้อง >= 0 (มี CHECK constraint) — ตรวจก่อนส่งถึงจะได้
 *     ข้อความภาษาไทยแทน SQL error
 *  3) จำนวนมาตรฐาน (standard) เป็นต้นทางของ target_qty/unit ที่ใช้คำนวณ "ต้องเติมกี่ชิ้น"
 *     — แก้ standard แล้วต้องคำนวณ target/unit ใหม่เสมอ ไม่งั้นหน้ารอเติมจะเพี้ยน
 *  4) ห้ามลบรถ/อุปกรณ์ที่ "มีประวัติ" ทับทิ้ง (refer_checks / refer_drafts /
 *     refer_restock_requests ที่ยังค้าง) — ให้ปฏิเสธแล้วเสนอ "งดใช้งาน" (status=disabled)
 *     หรือ "พักใช้งาน" (active=0) แทน เพราะประวัติการตรวจต้องอ่านย้อนหลังได้
 *  5) ทุกคำสั่งเขียน (create/update/delete) อยู่ใน Database Transaction เดียว
 *     และล๊อคแถวลูกค้าด้วย SELECT ... FOR UPDATE ก่อนแก้ กันการแก้ชนกันสองหน้าจอ
 *  6) สิทธิ์ต้องตรวจฝั่งเซิร์ฟเวอร์ทุกครั้ง (requireAdmin ที่ controller) — ไม่พึ่งการซ่อนปุ่ม
 */
class VehicleService
{
    public const STATUSES = ['available', 'maintenance', 'disabled'];

    /** สถานะงานเติมที่ยัง "เดินอยู่" — ห้ามลบรถทิ้งเด็ดขาด */
    public const OPEN_RESTOCK_STATUSES = ['PENDING', 'IN_PROGRESS', 'PARTIAL'];

    /** แม่แบบชุดอุปกรณ์มาตรฐานใน refer_metadata (สร้างโดย migration 007) */
    public const DEFAULT_ITEMS_META_ID = 'default_items';

    private Vehicle $vehicleModel;
    private Item $itemModel;
    private Metadata $metadata;

    /** สีเริ่มต้นเมื่อรถยังไม่กำหนดสี (ฝั่งหน้าจอใช้ค่าเดียวกันใน core/vehicle.js) */
    public const DEFAULT_COLOR = '#475569';

    public function __construct()
    {
        $this->vehicleModel = new Vehicle();
        $this->itemModel = new Item();
        $this->metadata = new Metadata();
    }

    /** สีรถ: '#RRGGBB' เท่านั้น ('' / null = ล้างกลับเป็นสีเริ่มต้น) */
    private function color(mixed $raw): ?string
    {
        if ($raw === null || trim((string)$raw) === '') {
            return null;
        }
        $value = strtoupper(trim((string)$raw));
        if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
            throw new ApiException('สีรถต้องอยู่ในรูปแบบ #RRGGBB เช่น #2563EB', 422,
                ['errors' => ['vehicle_color' => ['สีรถต้องอยู่ในรูปแบบ #RRGGBB']]]);
        }
        return $value;
    }

    /** ประเภทอุปกรณ์ box / individual / non_expiring (ชื่อเดิมก่อน migration 012 แปลงให้อัตโนมัติ) */
    private function itemTypeInput(mixed $raw, bool $isBatteryFallback): string
    {
        if ($raw === null || $raw === '') {
            return $isBatteryFallback ? VehicleStockService::TYPE_NON_EXPIRING : VehicleStockService::TYPE_INDIVIDUAL;
        }
        $type = VehicleStockService::LEGACY_TYPES[(string)$raw] ?? (string)$raw;
        if (!in_array($type, VehicleStockService::ITEM_TYPES, true)) {
            throw new ApiException('ประเภทอุปกรณ์ต้องเป็น กล่อง / ชิ้น / ไม่มีวันหมดอายุ', 422,
                ['errors' => ['item_type' => ['ประเภทอุปกรณ์ไม่ถูกต้อง']]]);
        }
        return $type;
    }

    /** ยอดบนรถเปลี่ยนได้ผ่านการตรวจเช็ค/การเติมของเท่านั้น (ต้องมี Lot + ledger รองรับ) */
    private function rejectDirectStock(array $input): void
    {
        // ช่องของคลังกลางเดิม (ยกเลิกแล้ว) — ส่วนยอดบนรถ (onboard_qty) ตั้งได้ผ่าน applyOnboardQty ซึ่งลง ledger
        foreach (['current_stock', 'min_stock'] as $key) {
            if (array_key_exists($key, $input) && trim((string)$input[$key]) !== '') {
                throw new ApiException('คลังสต็อกกลางถูกยกเลิกแล้ว', 422, ['errors' => [$key => ['คลังกลางถูกยกเลิกแล้ว']]]);
            }
        }
    }

    /**
     * ตั้ง "ยอดบนรถตอนนี้" จากหน้าตั้งค่ารถ (admin) — ไม่เขียน current_qty ตรง ๆ แต่ผ่านล็อต + ledger:
     *  เพิ่ม → ล็อตใหม่ (วันหมดอายุถ้ามี; ไม่ใส่ = "ไม่ทราบวันหมดอายุ" ให้ทบทวน) | ลด → ตัดแบบ FEFO
     * ทุกครั้งเป็นรายการ ADJUST ที่ย้อนดูได้ (ต้องอยู่ใน transaction ของผู้เรียก — แถวอุปกรณ์ถูกล็อกแล้ว)
     * @return ?array{before: ?float, after: float} null = ไม่ได้ส่งยอดมา
     */
    private function applyOnboardQty(array $item, array $input, ?array $actor): ?array
    {
        if (!array_key_exists('onboard_qty', $input) || trim((string)$input['onboard_qty']) === '') {
            return null;
        }
        if (VehicleStockService::usesUnits($item)) {
            throw new ApiException('อุปกรณ์ไม่มีวันหมดอายุนับเป็นรายเครื่อง — เพิ่มหรือปลดเครื่องที่ปุ่ม "เติมของ"', 422,
                ['errors' => ['onboard_qty' => ['ใช้ปุ่มเติมของ']]]);
        }
        $raw = $input['onboard_qty'];
        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > InspectionService::MAX_QTY) {
            throw new ApiException('ยอดบนรถต้องเป็นตัวเลขไม่ติดลบ', 422, ['errors' => ['onboard_qty' => ['ต้องเป็นตัวเลขไม่ติดลบ']]]);
        }
        $new = round((float)$raw, 2);
        $stock = new VehicleStockService();
        $before = Item::countedQty($item);
        $current = $before ?? 0.0;
        $delta = round($new - $current, 2);
        $ctx = [
            'by_user_id' => $actor['id'] ?? null,
            'by_name' => (string)($actor['full_name'] ?? ''),
            'at' => $this->now(),
            'reason' => 'ตั้งยอดจากหน้าตั้งค่ารถ',
        ];
        if ($delta > 0) {
            $isPiece = VehicleStockService::itemType($item) === VehicleStockService::TYPE_INDIVIDUAL;
            if ($isPiece && floor($delta) != $delta) {
                throw new ApiException('ของแบบชิ้นต้องเป็นจำนวนเต็ม', 422, ['errors' => ['onboard_qty' => ['ต้องเป็นจำนวนเต็ม']]]);
            }
            // ของแบบชิ้น: วันหมดอายุรายชิ้น (onboard_expiries) จำนวนต้องเท่ากับที่เพิ่ม; หรือวันเดียวใช้กับทุกชิ้น
            $list = $input['onboard_expiries'] ?? null;
            if ($isPiece && is_array($list) && $list) {
                $expiries = array_map(fn($e) => trim((string)$e), array_values($list));
                if (count($expiries) !== (int)$delta) {
                    throw new ApiException(sprintf('เพิ่ม %d ชิ้น แต่ระบุวันหมดอายุ %d ชิ้น — จำนวนต้องตรงกัน', (int)$delta, count($expiries)), 422,
                        ['errors' => ['onboard_expiries' => ['จำนวนวันหมดอายุไม่ตรงกับจำนวนชิ้น']]]);
                }
            } else {
                $expiries = array_fill(0, $isPiece ? (int)$delta : 1, trim((string)($input['onboard_expiry'] ?? '')));
            }
            foreach ($expiries as $expiry) {
                if ($expiry === '') {
                    throw new ApiException('กรุณาระบุวันหมดอายุของที่เพิ่ม', 422, ['errors' => ['onboard_expiry' => ['ต้องระบุวันหมดอายุ']]]);
                }
                if (!VehicleStockService::validDate($expiry)) {
                    throw new ApiException('วันหมดอายุไม่ถูกต้อง', 422, ['errors' => ['onboard_expiry' => ['วันหมดอายุไม่ถูกต้อง']]]);
                }
                if ($expiry < VehicleStockService::today()) {
                    throw new ApiException('วันหมดอายุผ่านไปแล้ว — ไม่อนุญาตให้เพิ่มของหมดอายุ', 422, ['errors' => ['onboard_expiry' => ['หมดอายุแล้ว']]]);
                }
            }
            $meta = ['received_date' => VehicleStockService::today()];
            if ($isPiece) {
                $stock->addPieces($item, $expiries, 'ADJUST', StockMovement::TYPE_ADJUST, $ctx, $meta);
            } else {
                $stock->addLot($item, $delta, $expiries[0], 'ADJUST', StockMovement::TYPE_ADJUST, $ctx, $meta);
            }
        } elseif ($delta < 0) {
            $stock->consume($item, -$delta, StockMovement::TYPE_ADJUST, $ctx);
        } else {
            $stock->syncItemTotal($item, $ctx['at']);   // ยอดเท่าเดิม: ประทับว่าตรวจนับแล้ว
        }
        $issues = $stock->reconcile((string)$item['car_id']);
        if ($issues) {
            error_log('reconcile failed after onboard set ' . $item['id'] . ': ' . json_encode($issues, JSON_UNESCAPED_UNICODE));
            throw new \RuntimeException('ยอดคงเหลือไม่สอดคล้องกันหลังคำนวณ ระบบยกเลิกการบันทึกเพื่อป้องกันข้อมูลผิด');
        }
        return ['before' => $before, 'after' => $new];
    }

    /**
     * รถที่ "งดใช้งาน" (status=disabled) ต้องไม่เริ่มรอบตรวจใหม่ — history/ทะเบียนยังอ่านได้
     * controller ทั้งสองเส้นทาง (/api/staff/inspections/start และ legacy /api/inspections/start)
     * เรียกใช้ร่วมกัน เพราะการซ่อนปุ่มฝั่ง client เพียงอย่างเดียวยืนยันสิทธิ์ไม่ได้
     */
    public static function assertInspectable(?array $vehicle): void
    {
        if (($vehicle['status'] ?? '') === 'disabled') {
            throw new ApiException(
                'รถคันนี้ถูกงดใช้งานแล้ว — เปิดกลับที่แท็บ "ทะเบียนรถ/อุปกรณ์" ก่อนเริ่มตรวจเช็ค',
                409,
                ['code' => 'VEHICLE_DISABLED']
            );
        }
    }

    // ==================== อ่านข้อมูล ====================

    /**
     * รถทุกคัน + ตัวเลขที่ใช้ตัดสินใจ (จำนวนอุปกรณ์/ประวัติตรวจ/งานเติมค้าง)
     * ใช้ทั้ง dropdown "เลือกรถ" และแสดงสถานะในแท็บทะเบียนรถ
     */
    public function listVehicles(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::OPEN_RESTOCK_STATUSES), '?'));
        $openList = '(' . $placeholders . ')';

        $sql = "SELECT v.*,
                       (SELECT COUNT(*) FROM refer_items i WHERE i.car_id = v.id) AS item_count,
                       (SELECT COUNT(*) FROM refer_items i WHERE i.car_id = v.id AND i.active = 1) AS active_item_count,
                       (SELECT COUNT(*) FROM refer_checks c WHERE c.car_id = v.id) AS check_count,
                       (SELECT COUNT(*) FROM refer_drafts d WHERE d.car_id = v.id) AS draft_count,
                       (SELECT COUNT(*) FROM refer_restock_requests r WHERE r.car_id = v.id
                          AND r.status IN {$openList}) AS open_restock_count,
                       (SELECT COUNT(*) FROM refer_restock_requests r WHERE r.car_id = v.id) AS restock_count
                  FROM refer_vehicles v
                 ORDER BY v.id";

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute(self::OPEN_RESTOCK_STATUSES);
        return $stmt->fetchAll();
    }

    /** รถ 1 คันพร้อมอุปกรณ์ "ทุกแถว" (รวมที่พักใช้งาน) — หน้า admin ต้องเห็นของที่พักเพื่อเปิดกลับได้ */
    public function getVehicleDetail(string $id): array
    {
        $vehicle = $this->vehicleModel->find($id);
        if (!$vehicle) {
            throw new ApiException('ไม่พบรถคันนี้ในระบบ: ' . $id, 404);
        }
        $vehicle['items'] = $this->getItems($id, true);
        $vehicle['blockers'] = $this->assessDeletion($id);
        return $vehicle;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(string $carId, bool $includeInactive = false): array
    {
        $rows = $includeInactive
            ? $this->itemModel->getByVehicle($carId)
            : $this->itemModel->getActiveByVehicle($carId);

        // เรียง "ที่ใช้ก่อน" แล้วตามชื่อ เพื่อให้หน้าจอคงลำดับเดิมทุกครั้ง
        usort($rows, function (array $a, array $b): int {
            return [(int)!empty($b['active']), mb_strtolower((string)$a['name'], 'UTF-8')]
                <=> [(int)!empty($a['active']), mb_strtolower((string)$b['name'], 'UTF-8')];
        });
        return $rows;
    }

    /**
     * สถิติที่ผูกกับรถ — ใช้เป็นเงื่อนไข "ลบได้/ไม่ได้" และแสดงต่อผู้ใช้
     */
    public function assessDeletion(string $carId): array
    {
        $db = Database::getConnection();
        $count = function (string $sql, array $params) use ($db): int {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        };

        $checks = $count("SELECT COUNT(*) FROM refer_checks WHERE car_id = ?", [$carId]);
        $drafts = $count("SELECT COUNT(*) FROM refer_drafts WHERE car_id = ?", [$carId]);
        $restockTotal = $count("SELECT COUNT(*) FROM refer_restock_requests WHERE car_id = ?", [$carId]);
        $restockOpen = $count(
            "SELECT COUNT(*) FROM refer_restock_requests WHERE car_id = ? AND status IN ("
            . implode(',', array_fill(0, count(self::OPEN_RESTOCK_STATUSES), '?')) . ")",
            array_merge([$carId], self::OPEN_RESTOCK_STATUSES)
        );
        $items = $count("SELECT COUNT(*) FROM refer_items WHERE car_id = ?", [$carId]);

        // FK ของ refer_checks/refer_drafts/refer_restock_requests → refer_vehicles เป็น RESTRICT
        // ถ้ามีแถวเหล่านี้ การลบถาวรจะพังเป็น SQL errorเสมอ — จึงต้องปฏิเสธก่อนแตะฐานข้อมูล
        $blocking = $checks > 0 || $drafts > 0 || $restockOpen > 0 || $restockTotal > 0;

        return [
            'checks' => $checks,
            'drafts' => $drafts,
            'restock_total' => $restockTotal,
            'restock_open' => $restockOpen,
            'items' => $items,
            'can_hard_delete' => !$blocking,
            'blocking' => $blocking,
        ];
    }

    // ==================== รถ: สร้าง ====================

    /**
     * เพิ่มรถใหม่ + สร้างชุดอุปกรณ์มาตรฐานให้ใน transaction เดียว
     *
     * แหล่งรายการอุปกรณ์ (item_source):
     *   'none' (ค่าเริ่มต้น)    → รถว่าง ไม่สร้างรายการ — เพิ่มอุปกรณ์เองแล้วจัดของขึ้นรถที่หน้าเติมของ
     *   'default'               → แม่แบบ refer_metadata['default_items'] → ถ้าไม่มีใช้รถที่มีรายการอุปกรณ์มากที่สุดในระบบ
     *   'copy'                  → จากรถที่ระบุใน copy_source_vehicle_id
     */
    public function createVehicle(array $input, ?array $actor): array
    {
        $plate = $this->text($input, 'plate', 50, 'ทะเบียนรถ', true);
        $name = $this->text($input, 'name', 255, 'ชื่อเรียกประจำรถ');
        $brand = $this->text($input, 'brand_model', 255, 'ยี่ห้อ/รุ่น');
        $notes = $this->text($input, 'notes', 500, 'หมายเหตุ');
        $status = $this->status($input['status'] ?? '');
        $odometer = $this->odometer($input['odometer_initial'] ?? null);
        $color = $this->color($input['vehicle_color'] ?? null);

        if ($plate === '') {
            throw new ApiException('กรุณากรอกทะเบียนรถ', 422, ['errors' => ['plate' => ['กรุณากรอกทะเบียนรถ']]]);
        }

        // dropdown แสดงเฉพาะ name จึงกันทะเบียนซ้ำ และคงวิธีกา "(ทะเบียน)" ต่อท้ายให้เหมือนแถวเดิม
        if ($this->vehicleModel->firstWhere('plate', $plate)) {
            throw new ApiException('ทะเบียนรถนี้มีอยู่ในระบบแล้ว: ' . $plate, 409);
        }

        if ($name === '') {
            $name = $plate;
        } elseif (!str_contains($name, $plate)) {
            $name .= ' (' . $plate . ')';
        }

        do {
            $id = 'car-' . substr(generate_id(), 0, 12);
        } while ($this->vehicleModel->find($id));

        $seed = $this->resolveSeedItems($input);

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $this->vehicleModel->create([
                'id' => $id,
                'name' => $name,
                'vehicle_color' => $color,
                'plate' => $plate,
                'brand_model' => $brand !== '' ? $brand : null,
                'status' => $status,
                'odometer_initial' => $odometer,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $inserted = $this->insertItems($id, $seed['items']);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $vehicle = $this->vehicleModel->find($id) ?? [];
        $vehicle['items_created'] = $inserted;
        $vehicle['item_source'] = $seed['source'];
        $vehicle['item_source_label'] = $seed['label'];
        $vehicle['copied_items'] = $inserted; // เข้ากับ client เดิม (adminApi.createVehicle)

        // รายงานให้หน้าจอรู้ว่า "ยอดบนรถ" ถูกยกมาเท่าไร (ไม่รวมแถวที่ยังไม่เคยตรวจนับ)
        $stockCopied = 0;
        $inactiveCopied = 0;
        foreach ($seed['items'] as $row) {
            if (self::parseQty($row['current_qty'] ?? null) !== null) {
                $stockCopied++;
            }
            if (array_key_exists('active', $row) && empty($row['active'])) {
                $inactiveCopied++;
            }
        }
        $vehicle['stock_copied'] = $stockCopied;
        $vehicle['items_inactive'] = $inactiveCopied;
        return $vehicle;
    }

    /**
     * เลือก "ชุดมาตรฐาน" ให้รถคันใหม่ — คืน rows ดิบ (name/standard/expiry_text/is_battery)
     */
    private function resolveSeedItems(array $input): array
    {
        $source = strtolower(trim((string)($input['item_source'] ?? 'none')));
        if (!in_array($source, ['default', 'copy', 'none'], true)) {
            $source = 'default';
        }

        if ($source === 'none') {
            return ['items' => [], 'source' => 'none', 'label' => 'ไม่สร้างรายการเริ่มต้น'];
        }

        $copyId = trim((string)($input['copy_source_vehicle_id'] ?? ''));
        if ($source === 'copy' || $copyId !== '') {
            if ($copyId === '') {
                throw new ApiException('ต้องเลือกรถต้นทางเมื่อระบุแหล่งรายการเป็น "คัดลอกจากคันอื่น"', 422,
                    ['errors' => ['copy_source_vehicle_id' => ['กรุณาเลือกรถต้นทาง']]]);
            }
            if (!$this->vehicleModel->find($copyId)) {
                throw new ApiException('ไม่พบรถต้นทางที่เลือก: ' . $copyId, 422,
                    ['errors' => ['copy_source_vehicle_id' => ['ไม่พบรถต้นทาง']]]);
            }
            // "คัดลอกจากคันอื่น" = ยกโครงสร้างของคันต้นทางมาทั้งชุด รวมแถวที่พักใช้งาน
            // เพื่อให้สถานะ active ติดมาด้วย (ผู้ใช้ออกตรวจนับ/เปิดใช้ได้เองภายหลัง)
            $rows = $this->getItems($copyId, true);
            if (count($rows) > 300) {
                throw new ApiException('รถต้นทางมีอุปกรณ์เกิน 300 รายการ — คัดลอกได้ครั้งละไม่เกิน 300', 422);
            }
            $label = $this->vehicleLabel($copyId);
            return [
                'items' => $this->toSeedRows($rows),
                'source' => 'copy',
                'label' => 'คัดลอกจาก ' . $label,
            ];
        }

        // โหมด default: ใช้แม่แบบที่ผู้ดูแลดูแลอยู่ก่อน ถ้ายังไม่มีให้ สำรองจากรถที่มีข้อมูลจริงในระบบ
        $template = $this->defaultItemsTemplate();
        if ($template !== null && !empty($template['items'])) {
            $origin = trim((string)($template['source_label'] ?? ''));
            return [
                'items' => $this->toSeedRows($template['items']),
                'source' => 'template',
                'label' => 'ชุดอุปกรณ์มาตรฐาน' . ($origin !== '' ? ' (ต้นฉบับ: ' . $origin . ')' : ''),
            ];
        }

        $fallback = $this->referenceVehicleId();
        if ($fallback !== null) {
            $rows = $this->getItems($fallback);
            return [
                'items' => $this->toSeedRows($rows),
                'source' => 'fallback',
                'label' => 'ยังไม่มีชุดมาตรฐานบันทึกไว้ — ใช้รายการของ ' . $this->vehicleLabel($fallback),
            ];
        }

        return ['items' => [], 'source' => 'empty', 'label' => 'ยังไม่มีแหล่งรายการเริ่มต้น (ระบบยังไม่มีรถที่มีอุปกรณ์)'];
    }

    /** แม่แบบ refer_metadata['default_items'] (payload เก็บเป็น JSON text) */
    public function defaultItemsTemplate(): ?array
    {
        $row = $this->metadata->find(self::DEFAULT_ITEMS_META_ID);
        if (!$row) {
            return null;
        }
        $payload = $row['payload'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            return null;
        }
        return $payload;
    }

    /** รถที่มีรายการอุปกรณ์ใช้งานอยู่เยอะที่สุด — ใช้เป็น "ชุดมาตรฐานชั่วคราว" ตอนยังไม่มีแม่แบบ */
    private function referenceVehicleId(): ?string
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT car_id FROM refer_items WHERE active = 1
              GROUP BY car_id ORDER BY COUNT(*) DESC, car_id ASC LIMIT 1"
        );
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id ? (string)$id : null;
    }

    private function vehicleLabel(string $carId): string
    {
        $v = $this->vehicleModel->find($carId);
        if (!$v) {
            return $carId;
        }
        $name = trim((string)($v['name'] ?? ''));
        $plate = trim((string)($v['plate'] ?? ''));
        return $name !== '' ? ($plate !== '' && !str_contains($name, $plate) ? $name . ' (' . $plate . ')' : $name) : ($plate ?: $carId);
    }

    /**
     * แปลงแถวแหล่งที่มา → โครงที่ insertItems รับ
     *
     * "ยอดคงเหลือบนรถ" ติดมากับชุดคัดลอกด้วย (current_qty + stamp) แต่เฉพาะแถวที่
     * ต้นทางเคยตรวจนับจริง (stock_updated_at มีค่า) — แถวที่ยังไม่เคยนับต้องคงสถานะ
     * "ยังไม่เคยตรวจนับ" ไว้ ไม่แต่งยอด 0 ขึ้นมาใหม่ (ข้อตกลงข้อ 1)
     */
    private function toSeedRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $standard = trim((string)($row['standard'] ?? ''));
            if ($name === '' || $standard === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'standard' => $standard,
                'expiry_text' => trim((string)($row['expiry_text'] ?? '')),
                'is_battery' => !empty($row['is_battery']),
                'item_type' => $row['item_type'] ?? null,
                'has_pressure' => !empty($row['has_pressure']),
                // แม่แบบใน refer_metadata ไม่มีคอลัมน์ active → ถือว่าใช้อยู่
                'active' => array_key_exists('active', $row) ? !empty($row['active']) : true,
                'current_qty' => self::copiedQty($row),
            ];
        }
        return $out;
    }

    /**
     * ยอดคงเหลือที่ "คัดลอกต่อ" ได้จากแถวต้นทาง — คืน NULL เมื่อไม่มียอดจริง
     * (NULL = ให้แถวใหม่เป็น "ยังไม่เคยตรวจนับ" ส่วน 0 คือ "นับแล้ว 0 ชิ้น" ซึ่งต่างกัน)
     */
    private static function copiedQty(array $row): ?float
    {
        $hasReading = trim((string)($row['stock_updated_at'] ?? '')) !== '';
        if (!$hasReading) {
            return null;
        }
        return self::parseQty($row['current_qty'] ?? null);
    }

    /** ค่าตัวเลขที่ปลอดภัยสำหรับ current_qty (ว่าง/ไม่ใช่ตัวเลข/ติดลบ → NULL) */
    private static function parseQty(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $qty = (float)$raw;
        return $qty >= 0 ? $qty : null;
    }

    /**
     * เขียน refer_items หลายแถวใน transaction ของผู้เรียก
     * target_qty/unit สกัดจาก standard ใหม่ทุกครั้ง (ข้อตกลงข้อ 3)
     * current_qty/stock_updated_at ติดมาจากแหล่งคัดลอกเฉพาะแถวที่มียอดจริง (ข้อตกลงข้อ 1)
     */
    private function insertItems(string $carId, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $db = Database::getConnection();
        $table = $this->itemModel->getTable();
        $stamp = $this->now();
        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $target = self::deriveTarget($row['standard']);
            $active = array_key_exists('active', $row) ? !empty($row['active']) : true;
            $type = VehicleStockService::itemType(['item_type' => $row['item_type'] ?? '', 'is_battery' => $row['is_battery'] ?? 0]);
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)';
            $params[] = 'itm-' . substr(generate_id(), 0, 12);
            $params[] = $carId;
            $params[] = $row['name'];
            $params[] = $row['standard'];
            $params[] = $type;
            $params[] = ($type === VehicleStockService::TYPE_NON_EXPIRING && !empty($row['is_battery'])) ? 1 : 0;
            $params[] = ($type === VehicleStockService::TYPE_NON_EXPIRING && !empty($row['has_pressure'])) ? 1 : 0;
            $params[] = $target['qty'];
            $params[] = $target['unit'];
            $params[] = $active ? 1 : 0;
            $params[] = '';
            // ไม่คัดลอกยอดคงเหลือข้ามรถ: ของจริงไม่ได้ย้ายมาด้วย — รถใหม่ "ยังไม่เคยตรวจนับ"
            // จนกว่าจะเติมของ (มี Lot + วันหมดอายุ) หรือตรวจนับในเวรแรก
        }
        unset($stamp);

        $stmt = $db->prepare(
            "INSERT INTO {$table} (id, car_id, name, standard, item_type, is_battery, has_pressure, target_qty, unit, active, expiry_text, current_qty, stock_updated_at)
             VALUES " . implode(', ', $values)
        );
        $stmt->execute($params);
        return count($rows);
    }

    /**
     * คัดลอกรายการอุปกรณ์เป็นชุดเข้ารถปลายทาง (หน้า ทะเบียนรถ/อุปกรณ์)
     *
     * CLIENT เป็นผู้ตรวจทานรายการก่อนส่ง จึงรับค่า "สุดท้าย" ที่ผู้ใช้อาจแก้มาแล้ว
     * ทุกรายการอยู่ใน INSERT เดียว ภายใน transaction เดียว (ข้อตกลงข้อ 5)
     *
     *  row: name*, standard*, expiry_text, is_battery, active,
     *       current_qty (ว่าง/null = ไม่เคยตรวจนับ | ตัวเลข = ยอดที่คัดลอกต่อ)
     *
     * @return array{created:int,stock_copied:int,active_copied:int,inactive_copied:int,vehicle_id:string}
     */
    public function copyItemsToVehicle(string $carId, array $items, ?array $actor): array
    {
        $vehicle = $this->vehicleModel->find($carId);
        if (!$vehicle) {
            throw new ApiException('ไม่พบรถคันนี้ในระบบ: ' . $carId, 404);
        }
        if (empty($items)) {
            throw new ApiException('ไม่มีรายการอุปกรณ์ที่จะคัดลอก', 422,
                ['errors' => ['items' => ['ไม่มีรายการอุปกรณ์ที่จะคัดลอก']]]);
        }
        if (count($items) > 300) {
            throw new ApiException('คัดลอกได้ครั้งละไม่เกิน 300 รายการ', 422,
                ['errors' => ['items' => ['คัดลอกได้ครั้งละไม่เกิน 300 รายการ']]]);
        }

        $rows = [];
        $stockCopied = 0;
        $inactiveCopied = 0;
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                throw new ApiException('รายการที่ ' . ($index + 1) . ': รูปแบบไม่ถูกต้อง', 422,
                    ['errors' => ['items' => ['รายการที่ ' . ($index + 1) . ': รูปแบบไม่ถูกต้อง']]]);
            }

            $name = trim((string)($item['name'] ?? ''));
            $standard = trim((string)($item['standard'] ?? ''));
            $expiry = trim((string)($item['expiry_text'] ?? ''));

            if ($name === '') {
                throw new ApiException('รายการที่ ' . ($index + 1) . ': ต้องมีชื่ออุปกรณ์', 422,
                    ['errors' => ['items' => ['รายการที่ ' . ($index + 1) . ': ต้องมีชื่ออุปกรณ์']]]);
            }
            if ($standard === '') {
                throw new ApiException('รายการที่ ' . ($index + 1) . ': ต้องมีจำนวนมาตรฐาน', 422,
                    ['errors' => ['items' => ['รายการที่ ' . ($index + 1) . ': ต้องมีจำนวนมาตรฐาน']]]);
            }
            if (mb_strlen($name) > 255 || mb_strlen($standard) > 100 || mb_strlen($expiry) > 100) {
                throw new ApiException('รายการที่ ' . ($index + 1) . ': ข้อความยาวเกินกำหนด', 422,
                    ['errors' => ['items' => ['รายการที่ ' . ($index + 1) . ': ข้อความยาวเกินกำหนด']]]);
            }

            // ค่าว่าง = "ยังไม่เคยตรวจนับ" ส่วนตัวเลข = ยอดบนรถที่คัดลอก/กรอกต่อ
            $qtyRaw = $item['current_qty'] ?? null;
            $qtyText = ($qtyRaw === null) ? '' : trim((string)$qtyRaw);
            if ($qtyText !== '' && !is_numeric($qtyText)) {
                throw new ApiException('รายการที่ ' . ($index + 1) . ': ยอดคงเหลือบนรถต้องเป็นตัวเลข', 422,
                    ['errors' => ['items' => ['รายการที่ ' . ($index + 1) . ': ยอดคงเหลือบนรถต้องเป็นตัวเลข']]]);
            }
            $qty = self::parseQty($qtyText);
            if ($qtyText !== '' && $qty === null) {
                throw new ApiException('รายการที่ ' . ($index + 1) . ': ยอดคงเหลือบนรถต้องมากกว่าหรือเท่ากับ 0', 422,
                    ['errors' => ['items' => ['รายการที่ ' . ($index + 1) . ': ยอดคงเหลือบนรถต้องมากกว่าหรือเท่ากับ 0']]]);
            }

            $active = array_key_exists('active', $item) ? !empty($item['active']) : true;
            if ($qty !== null) {
                $stockCopied++;
            }
            if (!$active) {
                $inactiveCopied++;
            }

            $rows[] = [
                'name' => $name,
                'standard' => $standard,
                'expiry_text' => $expiry,
                'is_battery' => !empty($item['is_battery']),
                'item_type' => $item['item_type'] ?? null,
                'has_pressure' => !empty($item['has_pressure']),
                'active' => $active,
                'current_qty' => $qty,
            ];
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $created = $this->insertItems($carId, $rows);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'created' => $created,
            'stock_copied' => $stockCopied,
            'active_copied' => $created - $inactiveCopied,
            'inactive_copied' => $inactiveCopied,
            'vehicle_id' => $carId,
        ];
    }

    // ==================== รถ: แก้ไข ====================

    /**
     * แก้ชื่อ/ทะเบียน/ยี่ห้อ-รุ่น/สถานะ/เลขไมล์/หมายเหตุ (แก้บางส่วนได้ ส่งเฉพาะฟิลด์ที่ต้องการ)
     * ครอบ transaction + ล๊อคแถวรถก่อนเขียน
     */
    public function updateVehicle(string $id, array $input, ?array $actor): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $existing = $this->vehicleModel->lockById($id);
            if (!$existing) {
                throw new ApiException('ไม่พบรถคันนี้ในระบบ: ' . $id, 404);
            }

            $data = [];

            if (array_key_exists('plate', $input)) {
                $plate = $this->text($input, 'plate', 50, 'ทะเบียนรถ', true);
                if ($plate === '') {
                    throw new ApiException('กรุณากรอกทะเบียนรถ', 422, ['errors' => ['plate' => ['กรุณากรอกทะเบียนรถ']]]);
                }
                $dup = $this->vehicleModel->firstWhere('plate', $plate);
                if ($dup && (string)$dup['id'] !== $id) {
                    throw new ApiException('ทะเบียนรถนี้ถูกใช้โดยรถคันอื่นแล้ว: ' . $this->vehicleLabel((string)$dup['id']), 409);
                }
                $data['plate'] = $plate;
            }

            if (array_key_exists('name', $input)) {
                $name = $this->text($input, 'name', 255, 'ชื่อเรียกประจำรถ', true);
                if ($name === '') {
                    throw new ApiException('กรุณากรอกชื่อเรียกประจำรถ', 422, ['errors' => ['name' => ['กรุณากรอกชื่อเรียกประจำรถ']]]);
                }
                $data['name'] = $name;
            }

            if (array_key_exists('brand_model', $input)) {
                $brand = $this->text($input, 'brand_model', 255, 'ยี่ห้อ/รุ่น');
                $data['brand_model'] = $brand !== '' ? $brand : null;
            }

            if (array_key_exists('status', $input)) {
                $data['status'] = $this->status($input['status']);
            }

            if (array_key_exists('odometer_initial', $input)) {
                $data['odometer_initial'] = $this->odometer($input['odometer_initial']);
            }

            if (array_key_exists('notes', $input)) {
                $notes = $this->text($input, 'notes', 500, 'หมายเหตุ');
                $data['notes'] = $notes !== '' ? $notes : null;
            }

            if (array_key_exists('vehicle_color', $input)) {
                $data['vehicle_color'] = $this->color($input['vehicle_color']);
            }

            if (empty($data)) {
                throw new ApiException('ไม่มีข้อมูลที่ต้องแก้ไข', 422);
            }

            // ชื่อเดิมมักต่อท้าย "(ทะเบียน)" — ถ้าแก้ทะเบียนให้ใหม่ ให้ป้ายชื่อตามไปด้วย
            // เพื่อไม่ให้ dropdown แสดงชื่อที่อ้างทะเบียนเก่าซึ่งไม่มีอยู่ในระบบแล้ว
            if (isset($data['plate']) && !array_key_exists('name', $input)) {
                $oldPlate = trim((string)($existing['plate'] ?? ''));
                $newPlate = (string)$data['plate'];
                $name = (string)$existing['name'];
                if ($oldPlate !== '' && $oldPlate !== $newPlate && str_contains($name, '(' . $oldPlate . ')')) {
                    $data['name'] = str_replace('(' . $oldPlate . ')', '(' . $newPlate . ')', $name);
                }
            }

            $this->vehicleModel->update($id, $data);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $vehicle = $this->vehicleModel->find($id) ?? [];
        $vehicle['updated_fields'] = array_keys($data);
        return $vehicle;
    }

    // ==================== รถ: ลบ / งดใช้งาน ====================

    /**
     * mode = 'auto'    → ลบได้เท่านั้นที่ไม่มีประวัติผูก (ถ้ามีประวัติ → 409 พร้อมตัวเลข)
     *        'disable' → งดใช้งาน (status=disabled) เก็บประวัติ/อุปกรณ์ไว้ครบ — ทางที่ปลอดภัย
     *        'force'   → ลบถาวรเมื่อไม่มีตัว chặn (เทียบเท่า auto แต่สื่อว่าผู้ใช้ยืนยันแล้ว)
     */
    public function deleteVehicle(string $id, ?array $actor, string $mode = 'auto'): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $vehicle = $this->vehicleModel->lockById($id);
            if (!$vehicle) {
                throw new ApiException('ไม่พบรถคันนี้ในระบบ: ' . $id, 404);
            }

            $blockers = $this->assessDeletion($id);
            $label = $this->vehicleLabel($id);

            if ($mode === 'disable') {
                if ((string)($vehicle['status'] ?? '') === 'disabled') {
                    $db->rollBack();
                    return ['mode' => 'already_disabled', 'id' => $id, 'name' => $label, 'blockers' => $blockers];
                }
                $this->vehicleModel->update($id, ['status' => 'disabled']);
                $db->commit();
                return ['mode' => 'disabled', 'id' => $id, 'name' => $label, 'blockers' => $blockers];
            }

            if (!$blockers['can_hard_delete']) {
                throw new ApiException($this->deletionMessage($blockers), 409, [
                    'reason' => 'vehicle_has_history',
                    'blockers' => $blockers,
                    'suggestion' => 'disable',
                    'name' => $label,
                ]);
            }

            // ไม่มีประวัติผูก: ลบอุปกรณ์ของคันนี้ก่อนแล้วจึงลบรถ
            // (FK ของ refer_items เป็น ON DELETE CASCADE อยู่แล้ว — ลบชัด ๆ เพื่อให้
            //  นับจำนวนแถวที่หายไปได้และป้องกันกรณี schema เปลี่ยนในอนาคต)
            $stmt = $db->prepare("DELETE FROM refer_items WHERE car_id = ?");
            $stmt->execute([$id]);
            $removedItems = $stmt->rowCount();

            $stmt = $db->prepare("DELETE FROM refer_vehicles WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() < 1) {
                throw new ApiException('ลบรถไม่สำเร็จ: ไม่พบแถวที่ต้องลบ');
            }

            $db->commit();
            return [
                'mode' => 'deleted',
                'id' => $id,
                'name' => $label,
                'items_removed' => $removedItems,
                'blockers' => $blockers,
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function deletionMessage(array $blockers): string
    {
        $parts = [];
        if ($blockers['checks'] > 0) {
            $parts[] = 'ประวัติการตรวจ ' . $blockers['checks'] . ' ครั้ง';
        }
        if ($blockers['drafts'] > 0) {
            $parts[] = 'ร่างที่ค้างอยู่ ' . $blockers['drafts'] . ' รายการ';
        }
        if ($blockers['restock_open'] > 0) {
            $parts[] = 'งานรอเติมที่ยังไม่ปิด ' . $blockers['restock_open'] . ' คำขอ';
        }
        if ($blockers['restock_total'] > $blockers['restock_open']) {
            $parts[] = 'ประวัติการเติมของ ' . ($blockers['restock_total'] - $blockers['restock_open']) . ' คำขอ';
        }
        return 'ลบถาวรไม่ได้เพราะรถคันนี้มี' . ($parts ? ' — ' . implode(' • ', $parts) : 'ข้อมูลผูกอยู่')
            . ' — แนะนำให้ตั้งสถานะเป็น "งดใช้งาน" แทน เพื่อให้รายงานย้อนหลังอ่านได้ครบ';
    }

    // ==================== อุปกรณ์: เพิ่ม / แก้ / ลบ ====================

    /**
     * เกณฑ์เตือนรายอุปกรณ์ (หน่วยงานเป็นผู้กำหนด — ค่าว่าง = ไม่เตือน/ใช้ค่าระบบ) — คืนเฉพาะฟิลด์ที่ส่งมา
     * $item = แถวเดิม (ใช้หาจำนวนขีดแบตเต็มเมื่อไม่ได้ส่ง battery_max_bars มาด้วย)
     */
    private function thresholdData(array $input, array $item): array
    {
        $int = function (string $key, int $min, int $max, string $label, bool $nullable = true) use ($input): ?int {
            $raw = trim((string)($input[$key] ?? ''));
            if ($raw === '') {
                if (!$nullable) {
                    throw new ApiException($label . ' ห้ามว่าง', 422, ['errors' => [$key => [$label . ' ห้ามว่าง']]]);
                }
                return null;
            }
            if (!preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max) {
                throw new ApiException(sprintf('%s ต้องเป็นจำนวนเต็ม %d-%d', $label, $min, $max), 422, ['errors' => [$key => [$label . ' ไม่ถูกต้อง']]]);
            }
            return (int)$raw;
        };
        $data = [];
        if (array_key_exists('battery_max_bars', $input)) {
            $data['battery_max_bars'] = $int('battery_max_bars', 1, 10, 'จำนวนขีดแบตเมื่อเต็ม', false);
        }
        if (array_key_exists('battery_low_bars', $input)) {
            $max = $data['battery_max_bars'] ?? VehicleStockService::batteryMax($item);
            $data['battery_low_bars'] = $int('battery_low_bars', 0, $max, 'เกณฑ์แบตต่ำ');
        }
        if (array_key_exists('o2_low_psi', $input)) {
            $data['o2_low_psi'] = $int('o2_low_psi', 0, VehicleStockService::MAX_PSI, 'เกณฑ์แรงดัน O2 ต่ำ (PSI)');
        }
        if (array_key_exists('expiry_warn_days', $input)) {
            $data['expiry_warn_days'] = $int('expiry_warn_days', 0, 3650, 'จำนวนวันเตือนใกล้หมดอายุ');
        }
        return $data;
    }

    /**
     * เพิ่มอุปกรณ์ประจำรถ — กรอกรายละเอียดตามประเภทได้ในครั้งเดียว (ไม่ส่ง = เพิ่มแค่รายการ ยังไม่มีของบนรถ)
     *  input: name*, standard*, item_type, is_battery, has_pressure,
     *         เกณฑ์เตือน: expiry_warn_days | battery_max_bars, battery_low_bars, o2_low_psi
     *         กล่อง/รายชิ้น: onboard_qty + onboard_expiry (กล่อง) / onboard_expiries[] (รายชิ้น 1 วันต่อ 1 ชิ้น)
     *         ไม่มีวันหมดอายุ: units[] = [{label, status, battery_level, pressure_psi, note}]
     *  ทั้งหมดอยู่ใน transaction เดียว — ข้อมูลส่วนไหนผิด จะไม่สร้างอุปกรณ์เลย
     */
    public function addItem(string $carId, array $input, ?array $actor): array
    {
        $vehicle = $this->vehicleModel->find($carId);
        if (!$vehicle) {
            throw new ApiException('ไม่พบรถคันนี้ในระบบ: ' . $carId, 404);
        }

        $name = $this->text($input, 'name', 255, 'ชื่ออุปกรณ์', true);
        $standard = $this->text($input, 'standard', 100, 'จำนวนมาตรฐาน', true);
        if ($name === '') {
            throw new ApiException('กรุณากรอกชื่ออุปกรณ์', 422, ['errors' => ['name' => ['กรุณากรอกชื่ออุปกรณ์']]]);
        }
        if ($standard === '') {
            throw new ApiException('กรุณากรอกจำนวนมาตรฐาน เช่น 10 หรือ 1 ชุด', 422,
                ['errors' => ['standard' => ['กรุณากรอกจำนวนมาตรฐาน']]]);
        }
        $this->rejectDirectStock($input);
        $itemType = $this->itemTypeInput($input['item_type'] ?? null, !empty($input['is_battery']));

        // ชื่ออุปกรณ์ซ้ำในรถคันเดียวกัน (แถวที่ยังใช้งาน) → ปฏิเสธก่อนเขียน
        $dupStmt = Database::getConnection()->prepare(
            "SELECT id, name FROM refer_items WHERE car_id = ? AND active = 1 AND name = ? LIMIT 1"
        );
        $dupStmt->execute([$carId, $name]);
        if ($dupStmt->fetch()) {
            throw new ApiException('อุปกรณ์ชื่อ "' . $name . '" มีอยู่ในรถคันนี้แล้ว', 409);
        }

        $isUnitType = $itemType === VehicleStockService::TYPE_NON_EXPIRING;
        $flags = [
            'is_battery' => ($isUnitType && !empty($input['is_battery'])) ? 1 : 0,
            'has_pressure' => ($isUnitType && !empty($input['has_pressure'])) ? 1 : 0,
        ];
        // เกณฑ์เตือนรับเฉพาะที่ตรงประเภท (เช่น กล่องไม่มีแบต) — ค่าที่ไม่เกี่ยวถูกตัดทิ้ง
        $allowed = $isUnitType
            ? array_merge($flags['is_battery'] ? ['battery_max_bars', 'battery_low_bars'] : [], $flags['has_pressure'] ? ['o2_low_psi'] : [])
            : ['expiry_warn_days'];
        $thresholds = $this->thresholdData(array_intersect_key($input, array_flip($allowed)), $flags);
        $units = $isUnitType ? array_values(array_filter((array)($input['units'] ?? []), 'is_array')) : [];
        if (count($units) > 50) {
            throw new ApiException('เพิ่มเครื่อง/ถังได้ครั้งละไม่เกิน 50', 422);
        }

        $itemId = 'itm-' . substr(generate_id(), 0, 12);
        $target = self::deriveTarget($standard);
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $this->itemModel->create($thresholds + [
                'id' => $itemId,
                'car_id' => $carId,
                'name' => $name,
                'standard' => $standard,
                'target_qty' => $target['qty'],
                'unit' => $target['unit'],
                'expiry_text' => '',
                'item_type' => $itemType,
                'is_battery' => $flags['is_battery'],
                'has_pressure' => $flags['has_pressure'],
                'active' => true,
            ]);
            // ไม่ได้กรอกของบนรถ = "ยังไม่เคยตรวจนับ" — ยอดเกิดจากการเติมของ (Lot + วันหมดอายุ) หรือการตรวจนับเวรแรก
            if ($isUnitType && $units) {
                $item = $this->itemModel->lockById($itemId);
                $ctx = ['by_user_id' => $actor['id'] ?? null, 'by_name' => (string)($actor['full_name'] ?? ''), 'at' => $this->now()];
                $stock = new VehicleStockService();
                foreach ($units as $u) {
                    $stock->addUnit($item, VehicleStockService::parseUnitInput($u, $item, (string)($u['label'] ?? '')), $ctx);
                }
            } elseif (!$isUnitType) {
                $this->applyOnboardQty($this->itemModel->lockById($itemId), $input, $actor);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $this->itemModel->find($itemId) ?? [];
    }

    /**
     * แก้ไขอุปกรณ์ 1 แถว (ส่งเฉพาะฟิลด์ที่ต้องการแก้)
     *  ฟิลด์: name, standard, expiry_text, is_battery, active, onboard_qty,
     *         current_stock, min_stock
     */
    public function updateItem(string $itemId, array $input, ?array $actor): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $existing = $this->itemModel->lockById($itemId);
            if (!$existing) {
                throw new ApiException('ไม่พบอุปกรณ์นี้ในระบบ: ' . $itemId, 404);
            }

            $data = [];
            $renamed = false;
            $oldName = (string)$existing['name'];

            if (array_key_exists('name', $input)) {
                $name = $this->text($input, 'name', 255, 'ชื่ออุปกรณ์', true);
                if ($name === '') {
                    throw new ApiException('กรุณากรอกชื่ออุปกรณ์', 422, ['errors' => ['name' => ['กรุณากรอกชื่ออุปกรณ์']]]);
                }
                if ($name !== $oldName) {
                    $stmt = $db->prepare(
                        "SELECT id FROM refer_items WHERE car_id = ? AND active = 1 AND name = ? AND id <> ? LIMIT 1"
                    );
                    $stmt->execute([$existing['car_id'], $name, $itemId]);
                    if ($stmt->fetch()) {
                        throw new ApiException('อุปกรณ์ชื่อ "' . $name . '" มีอยู่ในรถคันนี้แล้ว', 409);
                    }
                    $renamed = true;
                }
                $data['name'] = $name;
            }

            if (array_key_exists('standard', $input)) {
                $standard = $this->text($input, 'standard', 100, 'จำนวนมาตรฐาน', true);
                if ($standard === '') {
                    throw new ApiException('กรุณากรอกจำนวนมาตรฐาน เช่น 10 หรือ 1 ชุด', 422,
                        ['errors' => ['standard' => ['กรุณากรอกจำนวนมาตรฐาน']]]);
                }
                $data['standard'] = $standard;
                // target/unit เป็นตัวคำนวณ "ต้องเติมกี่ชิ้น" ของหน้ารอเติม — ต้องตาม standard ใหม่ทันที
                $target = self::deriveTarget($standard);
                $data['target_qty'] = $target['qty'];
                $data['unit'] = $target['unit'];
            }

            // วันหมดอายุมาจาก Lot แล้ว (expiry_text เป็นค่าที่ระบบเขียนเอง) จึงไม่รับจากหน้าตั้งค่า
            $this->rejectDirectStock($input);

            if (array_key_exists('item_type', $input)) {
                $newType = $this->itemTypeInput($input['item_type'], false);
                $oldType = VehicleStockService::itemType($existing);
                if ($newType !== $oldType) {
                    // กล่อง (ล็อตมีจำนวน) / รายชิ้น (1 แถว = 1 ชิ้น) / รายเครื่อง เก็บข้อมูลคนละแบบ — เปลี่ยนได้เฉพาะตอนยังไม่มีของบนรถ
                    $stmt = $db->prepare(
                        "SELECT (SELECT COUNT(*) FROM refer_item_lots WHERE item_id = ? AND qty_remaining > 0)
                              + (SELECT COUNT(*) FROM refer_item_units WHERE item_id = ? AND active = 1)"
                    );
                    $stmt->execute([$itemId, $itemId]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new ApiException('เปลี่ยนประเภทไม่ได้ขณะที่ยังมีของ/เครื่องบนรถ — นำของออกก่อน หรือสร้างรายการใหม่', 409);
                    }
                }
                $data['item_type'] = $newType;
            }
            // ธงของประเภทไม่มีวันหมดอายุ: มีแบตเตอรี่ / วัดแรงดัน (PSI)
            $finalType = $data['item_type'] ?? VehicleStockService::itemType($existing);
            foreach (['is_battery', 'has_pressure'] as $flag) {
                if (array_key_exists($flag, $input)) {
                    $data[$flag] = (!empty($input[$flag]) && $finalType === VehicleStockService::TYPE_NON_EXPIRING) ? 1 : 0;
                } elseif (isset($data['item_type']) && $finalType !== VehicleStockService::TYPE_NON_EXPIRING) {
                    $data[$flag] = 0;
                }
            }

            if (array_key_exists('active', $input)) {
                $data['active'] = !empty($input['active']) ? 1 : 0;   // PDO ส่ง false เป็น '' ซึ่ง MariaDB strict ปฏิเสธ
            }

            $data += $this->thresholdData($input, $existing);

            $wantsOnboard = array_key_exists('onboard_qty', $input) && trim((string)$input['onboard_qty']) !== '';
            if (empty($data) && !$wantsOnboard) {
                throw new ApiException('ไม่มีข้อมูลที่ต้องแก้ไข', 422);
            }

            if ($data) {
                $this->itemModel->update($itemId, $data);
            }
            // ยอดบนรถคำนวณหลังอัปเดตประเภท/มาตรฐาน (อ่านแถวที่ล็อกไว้ใหม่)
            $onboard = $wantsOnboard ? $this->applyOnboardQty($this->itemModel->lockById($itemId), $input, $actor) : null;
            $db->commit();

            $result = $this->itemModel->find($itemId) ?? [];
            $result['updated_fields'] = array_keys($data);
            $result['onboard'] = $onboard;
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * ลบอุปกรณ์
     *  mode 'auto'    → ลบถาวรได้เฉพาะแถวที่ไม่มีใครอ้างถึง; ถ้ามีประวัติ → พักใช้งาน (active=0) แทน
     *  mode 'disable' → พักใช้งานอย่างเดียว
     *  mode 'hard'    → บังคับลบถาวร (ทำเฉพาะเมื่อไม่มีตัว chặn เหมือน auto)
     */
    public function deleteItem(string $itemId, ?array $actor, string $mode = 'auto'): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $item = $this->itemModel->lockById($itemId);
            if (!$item) {
                throw new ApiException('ไม่พบอุปกรณ์นี้ในระบบ: ' . $itemId, 404);
            }

            $refs = $this->itemReferences($itemId, $db);

            if ($mode === 'disable' || !$refs['can_hard_delete']) {
                if ((int)($item['active'] ?? 1) === 0) {
                    $db->rollBack();
                    return ['mode' => 'already_disabled', 'id' => $itemId, 'name' => $item['name'], 'references' => $refs];
                }
                $this->itemModel->update($itemId, ['active' => false]);
                $db->commit();
                return [
                    'mode' => $mode === 'disable' ? 'disabled' : 'auto_disabled',
                    'id' => $itemId,
                    'name' => $item['name'],
                    'references' => $refs,
                ];
            }

            $stmt = $db->prepare("DELETE FROM refer_items WHERE id = ?");
            $stmt->execute([$itemId]);
            if ($stmt->rowCount() < 1) {
                throw new ApiException('ลบอุปกรณ์ไม่สำเร็จ: ไม่พบแถวที่ต้องลบ');
            }
            $db->commit();
            return ['mode' => 'deleted', 'id' => $itemId, 'name' => $item['name'], 'references' => $refs];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * สิ่งที่อ้างถึงอุปกรณ์นี้ — ถ้าเป็น "งานค้าง" ห้ามลบถาวร
     * (refer_check_items ไม่มี FK และเก็บ name/standard ไว้ในตัวเอง จึงไม่พังเมื่ออุปกรณ์ถูกลบ
     *  แต่เพื่อ audit trail ของรอบตรวจ จึงคงแถวที่มีประวัติไว้เป็น "พักใช้งาน")
     */
    private function itemReferences(string $itemId, $db): array
    {
        $count = function (string $sql, array $params) use ($db): int {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        };

        $drafts = $count("SELECT COUNT(*) FROM refer_draft_items WHERE item_id = ?", [$itemId]);
        $openRestock = $count(
            "SELECT COUNT(*) FROM refer_restock_items ri
               JOIN refer_restock_requests rr ON rr.id = ri.request_id
              WHERE ri.item_id = ? AND rr.status IN ("
              . implode(',', array_fill(0, count(self::OPEN_RESTOCK_STATUSES), '?')) . ")",
            array_merge([$itemId], self::OPEN_RESTOCK_STATUSES)
        );
        $restock = $count("SELECT COUNT(*) FROM refer_restock_items WHERE item_id = ?", [$itemId]);
        $checks = $count("SELECT COUNT(*) FROM refer_check_items WHERE item_id = ?", [$itemId]);

        return [
            'draft_items' => $drafts,
            'restock_open' => $openRestock,
            'restock_items' => $restock,
            'check_items' => $checks,
            'can_hard_delete' => ($drafts + $openRestock + $restock + $checks) === 0,
        ];
    }

    // ==================== ช่วย: ตรวจค่า / สต็อก / เวลา ====================

    /**
     * สกัด "จำนวนเป้าหมาย + หน่วย" จากข้อความมาตรฐาน
     *   "1 ชุด" → 1 / "ชุด" • "10" → 10 / "" • "ตามความจำเป็น" → null
     * ใช้สูตรเดียวกับ migration 006 เพื่อให้ค่าที่กรอกใหม่เทียบกันได้กับข้อมูลเดิม
     *
     * @return array{qty: ?float, unit: string}
     */
    public static function deriveTarget(string $standard): array
    {
        $standard = trim($standard);
        if ($standard === '' || !preg_match('/\d+(?:\.\d+)?/u', $standard, $m, PREG_OFFSET_CAPTURE)) {
            return ['qty' => null, 'unit' => ''];
        }
        $qty = round((float)$m[0][0], 2);
        $rest = trim(mb_substr($standard, $m[0][1] + mb_strlen($m[0][0], 'UTF-8'), null, 'UTF-8'));
        if ($qty <= 0) {
            return ['qty' => null, 'unit' => $rest];
        }
        return ['qty' => $qty, 'unit' => $rest];
    }

    private function text(array $input, string $key, int $max, string $label, bool $required = false): string
    {
        if (!array_key_exists($key, $input)) {
            if ($required) {
                throw new ApiException('กรุณากรอก' . $label, 422, ['errors' => [$key => ['กรุณากรอก' . $label]]]);
            }
            return '';
        }
        $value = is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
        if ($required && $value === '') {
            throw new ApiException('กรุณากรอก' . $label, 422, ['errors' => [$key => ['กรุณากรอก' . $label]]]);
        }
        if (mb_strlen($value) > $max) {
            throw new ApiException($label . 'ยาวเกิน ' . $max . ' ตัวอักษร', 422,
                ['errors' => [$key => [$label . 'ยาวเกินกำหนด']]]);
        }
        return $value;
    }

    private function status(mixed $raw): string
    {
        $status = is_scalar($raw) ? trim((string)$raw) : '';
        if ($status === '') {
            return 'available';
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new ApiException('สถานะรถไม่ถูกต้อง (ใช้ได้: available, maintenance, disabled)', 422,
                ['errors' => ['status' => ['สถานะรถไม่ถูกต้อง']]]);
        }
        return $status;
    }

    private function odometer(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_numeric($raw) || (float)$raw < 0 || (int)$raw != (float)$raw) {
            throw new ApiException('เลขไมล์ต้องเป็นจำนวนเต็มไม่ติดลบ', 422,
                ['errors' => ['odometer_initial' => ['เลขไมล์ต้องเป็นจำนวนเต็มไม่ติดลบ']]]);
        }
        return (int)$raw;
    }

    /** ค่าว่าง/null = "ไม่แก้ยอด"; ตัวเลข 0 ขึ้นไป = ตรวจนับจริง */
    private function qty(mixed $raw, string $label, string $key = "qty"): ?float
    {
        if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_numeric($raw)) {
            throw new ApiException($label . 'ต้องเป็นตัวเลข', 422, ['errors' => [$key => [$label . 'ต้องเป็นตัวเลข']]]);
        }
        $value = round((float)$raw, 2);
        if ($value < 0) {
            throw new ApiException($label . 'ต้องไม่ติดลบ', 422, ['errors' => [$key => [$label . 'ต้องไม่ติดลบ']]]);
        }
        if ($value > 1000000) {
            throw new ApiException($label . 'เกินขนาดที่ระบบรองรับ (สูงสุด 1,000,000)', 422);
        }
        return $value;
    }

    private function now(): string
    {
        return (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
    }
}
