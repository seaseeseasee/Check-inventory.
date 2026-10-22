-- ============================================================================
-- 011: รายละเอียดล็อต + ประเภทครุภัณฑ์/ถังออกซิเจน + เกณฑ์เตือนรายอุปกรณ์
--
--  - Lot: เลขล็อต / วันที่รับเข้า / สถานะ (active|void) / หมายเหตุ
--  - item_type เพิ่ม durable (ครุภัณฑ์ ไม่มีวันหมดอายุ) และ oxygen (รายถัง + PSI)
--  - เกณฑ์เตือนรายอุปกรณ์: จำนวนขีดแบตเต็ม/ต่ำ, PSI ต่ำ (NULL = หน่วยงานยังไม่กำหนด → ไม่เตือน), วันใกล้หมดอายุ
--  - รายเครื่อง/รายถัง: สถานะ pending (รอตรวจ) / no_power (เปิดไม่ติด/ชำรุด) + pressure_psi
--  - แปลงอุปกรณ์ตามชื่อ (ทุกรถ): O2 Sat / ไฟฉาย / DTX → electric, O2 ถัง → oxygen, Tourniquet / Stethoscope → durable
--    ยอดเดิมในล็อตของรายการที่กลายเป็นรายเครื่อง/รายถัง ถูกปิดด้วย movement ADJUST (ไม่ลบประวัติ)
--    แล้วสร้างเครื่อง/ถังตามจำนวนเดิม สถานะ "รอตรวจ" (ไม่เดาว่าพร้อมใช้)
--
-- รันซ้ำได้ (IF NOT EXISTS / NOT EXISTS) — ย้อนกลับ: 011-rollback.sql
-- ============================================================================

-- ---------- 1) รายละเอียดล็อต ----------
ALTER TABLE refer_item_lots
  ADD COLUMN IF NOT EXISTS lot_number VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'เลขล็อตจากผู้ผลิต (ถ้ามี)' AFTER item_id,
  ADD COLUMN IF NOT EXISTS received_date DATE NULL DEFAULT NULL COMMENT 'วันที่รับเข้า (NULL = ไม่ทราบ/ข้อมูลเก่า)' AFTER expiry_date,
  ADD COLUMN IF NOT EXISTS lot_status ENUM('active','void') NOT NULL DEFAULT 'active'
    COMMENT 'void = ยกเลิกล็อต (ยอดถูกตัดออกด้วย movement แล้ว ประวัติยังอยู่)' AFTER qty_remaining,
  ADD COLUMN IF NOT EXISTS note VARCHAR(500) NOT NULL DEFAULT '' AFTER lot_status;

-- ล็อตที่เติมผ่านระบบใหม่: วันรับเข้า = วันที่บันทึก (เวลาไทย); ล็อตย้ายข้อมูลเดิมไม่ทราบวันรับ → คง NULL
UPDATE refer_item_lots
   SET received_date = DATE(STR_TO_DATE(LEFT(created_at, 19), '%Y-%m-%dT%H:%i:%s') + INTERVAL 7 HOUR)
 WHERE received_date IS NULL AND source = 'REFILL';

-- ---------- 2) ประเภทอุปกรณ์ + เกณฑ์เตือน ----------
ALTER TABLE refer_items
  MODIFY COLUMN item_type ENUM('box','piece','durable','electric','oxygen') NOT NULL DEFAULT 'piece'
    COMMENT 'box/piece=สิ้นเปลืองมีวันหมดอายุ, durable=ครุภัณฑ์, electric=รายเครื่องมีแบต, oxygen=รายถัง PSI',
  ADD COLUMN IF NOT EXISTS battery_max_bars TINYINT NOT NULL DEFAULT 4 COMMENT 'จำนวนขีดแบตเมื่อเต็ม (ตามหน้าจอเครื่องจริง)' AFTER is_battery,
  ADD COLUMN IF NOT EXISTS battery_low_bars TINYINT NULL DEFAULT 1 COMMENT 'เตือนเมื่อแบต ≤ ค่านี้ (NULL = ไม่เตือน)' AFTER battery_max_bars,
  ADD COLUMN IF NOT EXISTS o2_low_psi INT NULL DEFAULT NULL COMMENT 'เตือนเมื่อแรงดัน < ค่านี้ (NULL = หน่วยงานยังไม่กำหนด)' AFTER battery_low_bars,
  ADD COLUMN IF NOT EXISTS expiry_warn_days SMALLINT NULL DEFAULT NULL COMMENT 'เตือนใกล้หมดอายุกี่วัน (NULL = ค่าระบบ 30 วัน)' AFTER o2_low_psi;

-- ---------- 3) รายเครื่อง / รายถัง ----------
ALTER TABLE refer_item_units
  MODIFY COLUMN status ENUM('pending','ready','not_ready','no_power') NOT NULL DEFAULT 'pending'
    COMMENT 'pending=รอตรวจ, ready=พร้อมใช้, not_ready=ไม่พร้อมใช้/ชำรุด, no_power=เปิดไม่ติด',
  ADD COLUMN IF NOT EXISTS pressure_psi INT NULL DEFAULT NULL COMMENT 'แรงดันถังล่าสุด (PSI)' AFTER battery_level;
ALTER TABLE refer_item_units DROP CONSTRAINT IF EXISTS chk_unit_battery;
ALTER TABLE refer_item_units DROP CONSTRAINT IF EXISTS chk_unit_psi;
ALTER TABLE refer_item_units
  ADD CONSTRAINT chk_unit_battery CHECK (battery_level IS NULL OR battery_level BETWEEN 0 AND 10),
  ADD CONSTRAINT chk_unit_psi CHECK (pressure_psi IS NULL OR pressure_psi BETWEEN 0 AND 10000);

ALTER TABLE refer_check_items
  ADD COLUMN IF NOT EXISTS condition_status VARCHAR(20) NULL DEFAULT NULL COMMENT 'ครุภัณฑ์: ready/not_ready' AFTER check_status,
  ADD COLUMN IF NOT EXISTS lot_detail LONGTEXT NULL DEFAULT NULL COMMENT 'การใช้/การนับรายล็อตที่ผู้ตรวจระบุ (JSON)' AFTER lots_snapshot;

-- ---------- 4) แปลงประเภทตามชื่อ (ทุกรถ) ----------
CREATE TEMPORARY TABLE IF NOT EXISTS mig011_targets (item_id VARCHAR(100) PRIMARY KEY, new_type VARCHAR(20) NOT NULL);
DELETE FROM mig011_targets;
INSERT INTO mig011_targets (item_id, new_type)
SELECT id, CASE
         WHEN name LIKE 'O2 ถัง%' THEN 'oxygen'
         WHEN name IN ('Tourniquet', 'Stethoscope') THEN 'durable'
         ELSE 'electric'
       END
  FROM refer_items
 WHERE (name LIKE 'เครื่องวัด O2 Sat%' OR name = 'ไฟฉาย' OR name LIKE 'เครื่องเจาะ DTX%'
        OR name LIKE 'O2 ถัง%' OR name IN ('Tourniquet', 'Stethoscope'))
   AND item_type IN ('box', 'piece');

-- 4a) รายการที่จะเป็นรายเครื่อง/รายถัง: ปิดยอดล็อตด้วย movement (ไม่ลบประวัติ)
INSERT INTO refer_stock_movements (id, item_id, car_id, lot_id, type, qty, balance_after, by_name, reason, at)
SELECT CONCAT('mv-mig011-', l.id), l.item_id, i.car_id, l.id, 'ADJUST', -l.qty_remaining, 0,
       'migration 011', CONCAT('เปลี่ยนเป็นนับ', IF(t.new_type = 'oxygen', 'รายถัง', 'รายเครื่อง'), ' — ย้ายยอดไปทะเบียนเครื่อง/ถัง'),
       DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ')
  FROM refer_item_lots l
  JOIN refer_items i ON i.id = l.item_id
  JOIN mig011_targets t ON t.item_id = l.item_id AND t.new_type IN ('electric', 'oxygen')
 WHERE l.qty_remaining > 0
   AND NOT EXISTS (SELECT 1 FROM refer_stock_movements m WHERE m.id = CONCAT('mv-mig011-', l.id));

UPDATE refer_item_lots l
  JOIN mig011_targets t ON t.item_id = l.item_id AND t.new_type IN ('electric', 'oxygen')
   SET l.qty_remaining = 0, l.lot_status = 'void', l.note = 'ปิดโดย migration 011: เปลี่ยนเป็นรายเครื่อง/รายถัง';

-- 4b) สร้างเครื่อง/ถังตามจำนวนเดิม (เฉพาะที่เคยตรวจนับ) สถานะรอตรวจ
INSERT INTO refer_item_units (id, item_id, label, status, battery_level, pressure_psi, note, active, created_at, updated_at, updated_by)
SELECT CONCAT('unit-mig011-', i.id, '-', s.seq), i.id,
       CONCAT(IF(t.new_type = 'oxygen', 'ถังที่ ', 'เครื่องที่ '), s.seq),
       'pending', NULL, NULL, 'ย้ายจากระบบเดิม — รอตรวจสถานะ', 1,
       DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ'),
       DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ'), 'migration 011'
  FROM refer_items i
  JOIN mig011_targets t ON t.item_id = i.id AND t.new_type IN ('electric', 'oxygen')
  JOIN seq_1_to_50 s ON s.seq <= FLOOR(i.current_qty)
 WHERE i.stock_updated_at IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM refer_item_units u WHERE u.item_id = i.id);

-- 4c) เปลี่ยนประเภท (current_qty ของรายเครื่อง/รายถัง = จำนวนเครื่องที่ใช้งาน ซึ่งเท่ากับยอดเดิม)
UPDATE refer_items i JOIN mig011_targets t ON t.item_id = i.id
   SET i.item_type = t.new_type,
       i.is_battery = IF(t.new_type = 'electric', 1, 0),
       i.expiry_text = IF(t.new_type IN ('electric', 'oxygen', 'durable'), '', i.expiry_text);

DROP TEMPORARY TABLE IF EXISTS mig011_targets;
