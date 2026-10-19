<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Vehicle;
use App\Models\Nurse;
use App\Models\Item;
use App\Models\Inspection;
use App\Models\RestockRequest;
use App\Models\RestockItem;
use App\Models\RestockAction;
use App\Config\Database;
use App\Helpers\Response;

class RestockService
{
    private Vehicle $vehicleModel;
    private Nurse $nurseModel;
    private Item $itemModel;
    private Inspection $inspectionModel;
    private RestockRequest $requestModel;
    private RestockItem $itemModel2;
    private RestockAction $actionModel;
    private StockService $stockService;

    public function __construct()
    {
        $this->vehicleModel = new Vehicle();
        $this->nurseModel = new Nurse();
        $this->itemModel = new Item();
        $this->inspectionModel = new Inspection();
        $this->requestModel = new RestockRequest();
        $this->itemModel2 = new RestockItem();
        $this->actionModel = new RestockAction();
        $this->stockService = new StockService();
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
        return $this->requestModel->getAllWithDetailsForStaff($userId);
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

    /**
     * ตรวจสิทธิ์ "ฝั่งเซิร์ฟเวอร์ทุกครั้ง" ก่อนเปลี่ยนแปลงคำขอ (ข้อ 7.8 — ห้ามพึ่งการซ่อนปุ่ม)
     *
     * กติกา: ผู้ดูแลระบบทำได้ทุกงาน | staff ทำได้เฉพาะงานที่มอบหมายให้ตัวเอง
     * และงานที่ยังไม่มอบหมาย (ใครก็กดยึดทำได้) | ถ้าไม่มีข้อมูลผู้ใช้ที่ส่งมา = ปฏิเสธ
     *
     * @param array<string,mixed> $request แถวคำขอที่ล็อกอยู่แล้ว
     * @param array<string,mixed>|null $operator ผู้ใช้จาก session (role/id/full_name)
     */
    private function assertOperator(array $request, ?array $operator, string $actionLabel): void
    {
        if ($operator === null || !isset($operator['role'])) {
            throw new \RuntimeException('ไม่พบข้อมูลผู้ดำเนินการ จึงไม่สามารถ' . $actionLabel . 'ได้');
        }

        if ((string)$operator['role'] === 'admin') {
            return;
        }

        $userId = trim((string)($operator['id'] ?? ''));
        $assigned = trim((string)($request['assigned_to_user_id'] ?? ''));

        // ยังไม่มอบหมาย → เปิดให้ staff ทุกคนเริ่มได้ | มอบหมายแล้ว → ผู้ได้รับมอบหมายเท่านั้น
        // (ไม่ยกเว้นให้ "ผู้สร้างคำขอ" เพราะเมื่อมีคนยึดงานแล้ว ใครมาทำต่อในชื่อตัวเองจะทำให้
        //  ประวัติผู้ดำเนินการไม่ตรงจริง และแข่งกับผู้ยึดงาน — ข้อ 7.2/7.5)
        if ($assigned === '' || ($userId !== '' && $assigned === $userId)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'งานนี้มอบหมายให้ผู้ดำเนินการท่านอื่นแล้ว จึง%sในชื่อบัญชีนี้ไม่ได้ (ผู้ดูแลระบบสามารถมอบหมายงานใหม่ได้)',
            $actionLabel
        ));
    }

    /**
     * Start restocking - change status from PENDING to IN_PROGRESS
     * Assign to current user
     */
    public function startRestock(string $id, string $userId, string $userName, ?array $operator = null): ?array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // ล็อกหัวคำขอก่อนกันสองคนกดยึดงานพร้อมกัน
            $request = $this->requestModel->lockById($id);
            if (!$request) {
                $db->rollBack();
                return null;
            }

            $this->assertOperator($request, $operator, 'เริ่มเติม');

            if (!$this->requestModel->isValidStatusTransition($request['status'], RestockRequest::STATUS_IN_PROGRESS)) {
                throw new \RuntimeException($this->statusErrorMessage($request['status'], 'เริ่มเติม'));
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            $this->requestModel->updateStatus($id, RestockRequest::STATUS_IN_PROGRESS);
            $this->requestModel->update($id, ['assigned_to_user_id' => $userId]);

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'start',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => 'เริ่มดำเนินการเติมอุปกรณ์',
                'action_lines' => [],
            ]);

            $db->commit();
            return $this->requestModel->getWithDetails($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * บันทึก "จำนวนที่เติมขึ้นรถจริง" ต่อรายการ (ข้อ 4 ของสเปก)
     *
     * สิ่งที่เกิดขึ้นใน transaction เดียว:
     *   1) ล็อกคำขอ + ล็อกบรรทัด + ล็อกแถวอุปกรณ์บนรถ (กันสองคนเติมรถคันเดียวกันพร้อมกัน)
     *   2) ยอดที่บันทึก = MIN(ที่กรอก, ส่วนต่างที่ยังขาด) → กดย้ำปุ่มเดิมไม่ทำให้ยอดเพิ่ม (ข้อ 4.6)
     *   3) ยอดคงเหลือบนรถใหม่ = ยอดก่อนเติม + ที่เติมจริง (ข้อ 4.3) พร้อม stamp เวลา
     *   4) ตัดคลังกลางเท่าที่ "เติมจริง" (ISSUE) — ถ้าของในคลังไม่พอจะ throw ทั้งชุด (ข้อ 4.4)
     *   5) เขียน qty_issued/qty_filled + vehicle_stock_before/after + timeline action
     *   6) สถานะใหม่คำนวณจากยอดที่เติมจริง (PARTIAL เมื่อไม่ครบ, COMPLETED เมื่อครบ + เขียน completed_at)
     *
     * @return array|null คำขอพร้อมรายละเอียด + key restock_result สำหรับแสดงบนหน้าจอ
     */
    public function recordRestock(string $id, string $userId, string $userName, array $items, string $note = '', ?array $operator = null): ?array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $request = $this->requestModel->lockById($id);
            if (!$request) {
                $db->rollBack();
                return null;
            }

            $this->assertOperator($request, $operator, 'บันทึกการเติม');

            if (!in_array($request['status'], [RestockRequest::STATUS_IN_PROGRESS, RestockRequest::STATUS_PARTIAL], true)) {
                throw new \RuntimeException($this->statusErrorMessage($request['status'], 'บันทึกการเติม'));
            }

            $carId = (string)($request['car_id'] ?? '');
            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            // ล็อกแถวอุปกรณ์บนรถของทุกรายการที่จะแตะ (เรียง id) ก่อนอ่านยอด "ล่าสุด" จาก DB
            $vehicleItems = [];
            $wantedIds = [];
            foreach ($items as $item) {
                if (isset($item['item_id'])) {
                    $wantedIds[] = (string)$item['item_id'];
                }
            }
            foreach ($this->itemModel->lockByIds($wantedIds) as $row) {
                $vehicleItems[(string)$row['id']] = $row;
            }

            $actionLines = [];
            $fillLines = [];
            $skipped = [];
            $warnings = [];

            foreach ($items as $item) {
                $itemId = (string)($item['item_id'] ?? '');
                if ($itemId === '') {
                    $skipped[] = ['item_id' => '', 'name' => '', 'reason' => 'ไม่มีรหัสอุปกรณ์ในข้อมูลที่ส่งมา'];
                    continue;
                }
                if (!isset($item['qty']) || $item['qty'] === '' || !is_numeric($item['qty'])) {
                    throw new \RuntimeException('จำนวนที่เติมต้องเป็นตัวเลข (รายการ ' . $itemId . ')');
                }
                $want = round((float)$item['qty'], 2);
                if ($want < 0) {
                    throw new \RuntimeException('จำนวนที่เติมต้องไม่ติดลบ (รายการ ' . $itemId . ')');
                }

                $line = $this->itemModel2->lockLine($id, $itemId);
                if (!$line) {
                    throw new \RuntimeException('ไม่พบรายการอุปกรณ์นี้ในคำขอเติมนี้ (รหัส ' . $itemId . ')');
                }

                // ข้อ 4.1: อุปกรณ์ต้องยังเป็นของรถคันเดียวกับคำขอจริง
                $vehicleItem = $vehicleItems[$itemId] ?? null;
                if (!$vehicleItem || (string)$vehicleItem['car_id'] !== $carId) {
                    throw new \RuntimeException(sprintf(
                        'อุปกรณ์ "%s" ไม่ได้อยู่ในรถ "%s" ของคำขอนี้ จึงไม่อนุญาตให้เติม',
                        (string)$line['name'],
                        (string)($request['car_name'] ?? '')
                    ));
                }

                $name = (string)$line['name'];
                $requested = (float)$line['qty_requested'];
                $alreadyFilled = (float)$line['qty_filled'];

                if ($requested <= 0) {
                    $skipped[] = ['item_id' => $itemId, 'name' => $name, 'reason' => 'รายการนี้ไม่ต้องเติม (ยอดที่ต้องเติม = 0)'];
                    continue;
                }
                if ($want <= 0) {
                    $skipped[] = ['item_id' => $itemId, 'name' => $name, 'reason' => 'ไม่ได้กรอกจำนวน (0)'];
                    continue;
                }

                $stillNeeded = round(max(0.0, $requested - $alreadyFilled), 2);
                if ($stillNeeded <= 0) {
                    $skipped[] = ['item_id' => $itemId, 'name' => $name, 'reason' => sprintf('เติมครบ %s แล้ว จึงไม่เติมซ้ำ', self::fmtQty($requested))];
                    continue;
                }

                // clamp: บันทึกเฉพาะส่วนที่ยังขาดจริง → กดปุ่มซ้ำ/ส่งข้อมูลเดิมไม่ทำให้ยอดเกิน
                $applied = round(min($want, $stillNeeded), 2);
                $newFilled = round($alreadyFilled + $applied, 2);

                $stockBefore = Item::countedQty($vehicleItem);
                if ($stockBefore === null) {
                    // ไม่เดายอดคงเหลือ (ข้อ 1.5): ยังไม่เคยนับรถคันนี้ จึงยังไม่เขียนยอดคงเหลือ
                    $stockAfter = null;
                    $warnings[] = sprintf(
                        'รถยังไม่มี "ยอดตรวจนับ" สำหรับ %s จึงบันทึกเฉพาะจำนวนที่เติม %s ไว้ — ยอดคงเหลือจะคำนวณใหม่ตอนตรวจรถครั้งถัดไป',
                        $name,
                        self::fmtQty($applied)
                    );
                } else {
                    $stockAfter = round($stockBefore + $applied, 2);   // ข้อ 4.3: ก่อนเติม + เติมจริง
                }

                // qty_issued = ยอดที่จ่ายจากคลังเพื่อรถคันนี้สะสม (เท่ากับยอดที่เติมจริง)
                $this->itemModel2->writeQuantities($id, $itemId, $newFilled, $newFilled, $stockBefore, $stockAfter);

                if ($stockAfter !== null) {
                    $this->itemModel->updateStock($itemId, $stockAfter, $now);
                }

                $actionLines[] = [
                    'itemId' => $itemId,
                    'name' => $name,
                    'qty' => self::fmtQty($applied),
                    'stockBefore' => $stockBefore,
                    'stockAfter' => $stockAfter,
                ];
                $fillLines[] = ['name' => $name, 'qty' => $applied];
            }

            if (empty($actionLines)) {
                $reasons = array_map(fn($s) => (string)($s['name'] ?? '') . ': ' . (string)($s['reason'] ?? ''), $skipped);
                throw new \RuntimeException('ไม่มีรายการเติมที่บันทึกได้' . ($reasons ? ' (' . implode('; ', array_slice($reasons, 0, 3)) . ')' : ''));
            }

            // ตัดคลังกลาง "ตอนยกของขึ้นรถจริง" — คลังไม่พอจะ throw ให้ rollback ทั้งชุด
            // ยกเว้นคลังที่ยังไม่เคยเปิดสมุดบัญชี (ยอด 0 + ไม่มีประวัติ ledger) ซึ่งจะคืนเป็น untracked แทน
            // (ถ้าคลังยังไม่เคยเปิดสมุดบัญชีจะได้ untracked กลับมาแทน เพื่อไม่ให้งานจริงค้าง)
            $issue = $this->stockService->issueForRestockFill($fillLines, [
                'car_id' => $carId,
                'car_name' => (string)($request['car_name'] ?? ''),
                'check_id' => $request['check_id'] ?? null,
                'request_no' => (string)($request['no'] ?? ''),
            ], $userId, $userName);

            $newStatus = $this->calculateNewStatus($id);
            if ($newStatus !== (string)$request['status']) {
                $this->requestModel->updateStatus($id, $newStatus);   // COMPLETED → เขียน completed_at ด้วย
            }

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'restock',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => $note !== '' ? $note : 'บันทึกการเติมอุปกรณ์' . count($actionLines) . ' รายการ',
                'action_lines' => $actionLines,
            ]);

            $db->commit();

            $result = $this->requestModel->getWithDetails($id);
            if ($result === null) {
                return null;
            }
            $result['restock_result'] = [
                'applied' => $actionLines,
                'issued' => $issue['issued'],
                'alerts' => $issue['alerts'],
                'untracked' => $issue['untracked'] ?? [],
                'skipped' => $skipped,
                'warnings' => $warnings,
                'status' => $newStatus,
            ];
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * ปิดงานเติมให้ครบถ้วน — ยืนยันว่าทุกบรรทัดถูกเติมจริงครบแล้วเท่านั้น
     * ใช้ updateStatus() เพื่อให้ completed_at ถูกเขียน (เดิมข้าม method นี้ทำให้ completed_atว่างทั้ง 7 คำขอ)
     * สถานะ COMPLETED/CANCELLED เป็นสถานะปิด → กดย้ำครั้งที่สองจะถูกกันไว้ (ข้อ 4.6)
     */
    public function completeRestock(string $id, string $userId, string $userName, string $note = '', ?array $operator = null): ?array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $request = $this->requestModel->lockById($id);
            if (!$request) {
                $db->rollBack();
                return null;
            }

            $this->assertOperator($request, $operator, 'ยืนยันปิดงาน');

            if (!$this->requestModel->isValidStatusTransition($request['status'], RestockRequest::STATUS_COMPLETED)) {
                throw new \RuntimeException($this->statusErrorMessage($request['status'], 'ยืนยันว่าเติมครบ'));
            }

            foreach ($this->itemModel2->getByRequest($id) as $item) {
                $requested = (float)($item['qty_requested'] ?? 0);
                $filled = (float)($item['qty_filled'] ?? 0);
                if ($requested > 0 && $filled < $requested) {
                    throw new \RuntimeException(sprintf(
                        'ยังไม่สามารถปิดงานได้: "%s" เติมแล้ว %s จากที่ต้องเติม %s',
                        (string)$item['name'],
                        self::fmtQty($filled),
                        self::fmtQty($requested)
                    ));
                }
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            // updateStatus() ตั้ง completed_at ให้ด้วยเมื่อสถานะเป็น COMPLETED
            $this->requestModel->updateStatus($id, RestockRequest::STATUS_COMPLETED);

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'complete',
                'at' => $now,
                'by_id' => $userId,
                'by_name' => $userName,
                'note' => $note !== '' ? $note : 'เติมอุปกรณ์ครบถ้วนตามมาตรฐาน',
                'action_lines' => [],
            ]);

            $db->commit();
            return $this->requestModel->getWithDetails($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** ข้อความ error ภาษาไทยเมื่อสถานะไม่อนุญาตให้ดำเนินการนั้นได้ */
    private function statusErrorMessage(string $status, string $action): string
    {
        return sprintf('สถานะ "%s" ไม่อนุญาตให้%s (งานอาจถูกยึด/ปิด/ยกเลิกไปแล้ว)', $status, $action);
    }

    private static function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Assign restock request to a staff member
     *
     * หมายเหตุการออกแบบ: การ "ยกเลิก" ไม่มีการคืนของเข้าคลังกลาง เพราะระบบปัจจุบัน
     * ตัดคลังเฉพาะตอน "บันทึกการเติมขึ้นรถจริง" (recordRestock) เท่านั้น ส่วนที่ยังไม่เติม
     * จึงไม่เคยถูกตัดออกไปตั้งแต่แรก
     */
    public function cancelRestock(string $id, string $userId, string $userName, string $reason, ?array $operator = null): ?array
    {
        if (empty(trim($reason))) {
            throw new \RuntimeException('กรุณาระบุเหตุผลการยกเลิก');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // ล็อกหัวคำขอ: กันการยกเลิกแข่งกับเติม/ปิดงานของอีกคน (ข้อ 4.6 / 7.9)
            $request = $this->requestModel->lockById($id);
            if (!$request) {
                $db->rollBack();
                return null;
            }

            $this->assertOperator($request, $operator, 'ยกเลิกงาน');

            // Validate status transition
            if (!$this->requestModel->isValidStatusTransition($request['status'], RestockRequest::STATUS_CANCELLED)) {
                $db->rollBack();
                throw new \RuntimeException($this->statusErrorMessage((string)$request['status'], 'ยกเลิก'));
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            $this->requestModel->update($id, [
                'status' => RestockRequest::STATUS_CANCELLED,
            ]);

            $this->actionModel->create([
                'request_id' => $id,
                'type' => 'cancel',
                'at' => $now,
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

    /**
     * Assign restock request to a staff member
     */
    public function assignToStaff(string $id, string $assignedUserId): ?array
    {
        $request = $this->requestModel->find($id);
        if (!$request) {
            return null;
        }

        if (!in_array($request['status'], [RestockRequest::STATUS_PENDING], true)) {
            throw new \RuntimeException(sprintf(
                'สถานะ "%s" ไม่อนุญาตให้มอบหมายงาน (มอบหมายได้เฉพาะงานที่ยังไม่เริ่ม)',
                (string)$request['status']
            ));
        }

        $this->requestModel->assignToUser($id, $assignedUserId);
        return $this->requestModel->getWithDetails($id);
    }

    /**
     * Unassign restock request
     */
    public function unassign(string $id): ?array
    {
        $request = $this->requestModel->find($id);
        if (!$request) {
            return null;
        }

        $this->requestModel->unassign($id);
        return $this->requestModel->getWithDetails($id);
    }

    /**
     * Calculate new status based on filled quantities
     */
    private function calculateNewStatus(string $requestId): string
    {
        $items = $this->itemModel2->getByRequest($requestId);
        
        $allCompleted = true;
        $anyPartial = false;

        foreach ($items as $item) {
            $requested = (float)($item['qty_requested'] ?? 0);
            $filled = (float)($item['qty_filled'] ?? 0);
            
            if ($filled >= $requested && $requested > 0) {
                // This item is complete
            } elseif ($filled > 0) {
                $anyPartial = true;
                $allCompleted = false;
            } else {
                $allCompleted = false;
            }
        }

        if ($allCompleted) {
            return RestockRequest::STATUS_COMPLETED;
        } elseif ($anyPartial) {
            return RestockRequest::STATUS_PARTIAL;
        } else {
            return RestockRequest::STATUS_IN_PROGRESS;
        }
    }

    public function getExpiringItems(int $days = 60): array
    {
        $items = $this->itemModel->all();
        $expiring = [];
        $now = new \DateTime();
        
        foreach ($items as $item) {
            if (!$item['active'] || empty($item['expiry_text'])) continue;
            
            $expiry = $this->parseExpiryDate($item['expiry_text']);
            if (!$expiry) continue;
            
            $diff = (int)(($expiry->getTimestamp() - $now->getTimestamp()) / 86400);
            if ($diff < 0 || $diff <= $days) {
                $expiring[] = array_merge($item, ['days_left' => $diff]);
            }
        }
        
        usort($expiring, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
        return $expiring;
    }

    private function parseExpiryDate(string $text): ?\DateTime
    {
        $text = trim($text);
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $text, $m)) {
            $y = (int)$m[3];
            if ($y < 100) $y += 2500;
            if ($y > 2400) $y -= 543;
            $d = \DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]));
            return $d && !$d->getTimestamp() ? null : $d;
        }
        return null;
    }
}