-- ============================================================================
-- 010: ยกเลิกคลังกลาง + สีรถ + ประเภทอุปกรณ์ + Lot วันหมดอายุ + เครื่องไฟฟ้ารายเครื่อง
--      + ตรวจเช็คตามเวร (1 รอบต่อรถ/วัน/เวร) + ledger การเคลื่อนไหวของยอดบนรถ
--
-- หลักการ: เพิ่มอย่างเดียว ไม่ลบตาราง/คอลัมน์/ข้อมูลเดิม
--   - refer_stock_items / refer_stock_transactions (คลังกลาง) ถูกเลิกใช้ในโค้ด แต่ "คงตารางไว้"
--   - refer_items.current_qty ยังเป็นยอดรวมบนรถ (ให้หน้าจอ/ประวัติเดิมอ่านได้)
--     แต่จากนี้ = SUM(refer_item_lots.qty_remaining) สำหรับของสิ้นเปลือง
--     และ = จำนวนเครื่องที่ใช้งานอยู่ สำหรับเครื่องไฟฟ้า — เขียนใน transaction เดียวกับ lot/movement เสมอ
--   - รถที่ "ยังไม่เคยตรวจนับ" (stock_updated_at IS NULL) จะไม่ถูกสร้าง lot ให้ (ยอด 0 = ไม่ทราบยอด ไม่ใช่หมด)
--
-- รันซ้ำได้: ทุก ALTER ใช้ IF NOT EXISTS และทุก INSERT ข้อมูลตั้งต้นกันซ้ำด้วย NOT EXISTS
-- ย้อนกลับ: database/migrations/010-rollback.sql
-- ============================================================================

-- ---------- 1) สีรถ ----------
ALTER TABLE refer_vehicles
  ADD COLUMN IF NOT EXISTS vehicle_color CHAR(7) NULL DEFAULT NULL
    COMMENT 'สีประจำรถ #RRGGBB (NULL = ใช้สีเริ่มต้นของระบบ)' AFTER name;

-- ---------- 2) ประเภทอุปกรณ์ ----------
ALTER TABLE refer_items
  ADD COLUMN IF NOT EXISTS item_type ENUM('box','piece','electric') NOT NULL DEFAULT 'piece'
    COMMENT 'box=กล่อง/Lot, piece=รายชิ้นตามวันหมดอายุ, electric=เครื่องใช้ไฟฟ้า (ไม่ตัดสต๊อค)' AFTER standard;

UPDATE refer_items SET item_type = 'electric' WHERE is_battery = 1 AND item_type <> 'electric';

-- ---------- 3) Lot วันหมดอายุ (ของสิ้นเปลือง: box / piece) ----------
CREATE TABLE IF NOT EXISTS refer_item_lots (
  id            VARCHAR(100) NOT NULL,
  item_id       VARCHAR(100) NOT NULL,
  expiry_date   DATE NULL DEFAULT NULL COMMENT 'NULL = ไม่ทราบวันหมดอายุ (ข้อมูลเก่า/ปรับยอด) ต้องทบทวน',
  qty_received  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'จำนวนตอนรับเข้า Lot',
  qty_remaining DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'จำนวนคงเหลือใน Lot',
  source        VARCHAR(20) NOT NULL COMMENT 'MIGRATION|REFILL|ADJUST',
  created_at    VARCHAR(40) NOT NULL,
  created_by    VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_lot_item_expiry (item_id, expiry_date),
  CONSTRAINT fk_lot_item FOREIGN KEY (item_id) REFERENCES refer_items (id) ON DELETE CASCADE,
  CONSTRAINT chk_lot_qty CHECK (qty_remaining >= 0 AND qty_received >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Expiry lots of consumables on each vehicle';

-- ---------- 4) เครื่องใช้ไฟฟ้ารายเครื่อง ----------
CREATE TABLE IF NOT EXISTS refer_item_units (
  id            VARCHAR(100) NOT NULL,
  item_id       VARCHAR(100) NOT NULL,
  label         VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'รหัส/เลขเครื่อง (ถ้ามี)',
  status        ENUM('ready','not_ready') NOT NULL DEFAULT 'ready',
  battery_level TINYINT NULL DEFAULT NULL COMMENT '1-4 ขีด (NULL = ยังไม่ระบุ)',
  note          VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'สาเหตุเมื่อไม่พร้อมใช้งาน',
  active        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = ปลดออก/เปลี่ยนเครื่องแล้ว (เก็บไว้เป็นประวัติ)',
  created_at    VARCHAR(40) NOT NULL,
  updated_at    VARCHAR(40) NOT NULL,
  updated_by    VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_unit_item (item_id, active),
  CONSTRAINT fk_unit_item FOREIGN KEY (item_id) REFERENCES refer_items (id) ON DELETE CASCADE,
  CONSTRAINT chk_unit_battery CHECK (battery_level IS NULL OR battery_level BETWEEN 1 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Individual electric devices on each vehicle';

-- ---------- 5) หัวรายการเติมของ (กันกดซ้ำด้วย client_token) ----------
CREATE TABLE IF NOT EXISTS refer_refill_batches (
  id            VARCHAR(100) NOT NULL,
  car_id        VARCHAR(100) NOT NULL,
  client_token  VARCHAR(64)  NOT NULL COMMENT 'สุ่มจากหน้าจอ 1 ค่าต่อการกดบันทึก 1 ครั้ง — ส่งซ้ำ = คืนผลเดิม',
  by_user_id    VARCHAR(100) NULL DEFAULT NULL,
  by_name       VARCHAR(255) NOT NULL DEFAULT '',
  note          VARCHAR(500) NOT NULL DEFAULT '',
  at            VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_refill_token (client_token),
  KEY idx_refill_car (car_id, at),
  CONSTRAINT fk_refill_vehicle FOREIGN KEY (car_id) REFERENCES refer_vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Direct refill sessions (no central warehouse)';

-- ---------- 6) Ledger การเคลื่อนไหวของยอดบนรถ ----------
CREATE TABLE IF NOT EXISTS refer_stock_movements (
  id            VARCHAR(100) NOT NULL,
  item_id       VARCHAR(100) NOT NULL,
  car_id        VARCHAR(100) NOT NULL,
  lot_id        VARCHAR(100) NULL DEFAULT NULL,
  type          VARCHAR(20)  NOT NULL COMMENT 'INIT|USE|REFILL|ADJUST|REVERSAL',
  qty           DECIMAL(10,2) NOT NULL COMMENT 'บวก = เพิ่มเข้ารถ, ลบ = ออกจากรถ',
  balance_after DECIMAL(10,2) NOT NULL COMMENT 'ยอดรวมของอุปกรณ์รายการนี้บนรถหลังบันทึกแถวนี้',
  check_id      VARCHAR(100) NULL DEFAULT NULL,
  batch_id      VARCHAR(100) NULL DEFAULT NULL,
  by_user_id    VARCHAR(100) NULL DEFAULT NULL,
  by_name       VARCHAR(255) NOT NULL DEFAULT '',
  reason        VARCHAR(500) NOT NULL DEFAULT '',
  at            VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id),
  KEY idx_mv_item (item_id, at),
  KEY idx_mv_car (car_id, at),
  KEY idx_mv_check (check_id),
  KEY idx_mv_batch (batch_id),
  CONSTRAINT fk_mv_item FOREIGN KEY (item_id) REFERENCES refer_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Ledger of every quantity change on vehicles';

-- ---------- 7) ตรวจเช็คตามเวร ----------
ALTER TABLE refer_checks
  ADD COLUMN IF NOT EXISTS check_date DATE NULL DEFAULT NULL COMMENT 'วันที่ของเวร (เวลาไทย)' AFTER nurse_name,
  ADD COLUMN IF NOT EXISTS shift_code ENUM('morning','afternoon','night') NULL DEFAULT NULL
    COMMENT 'morning 08-16 / afternoon 16-24 / night 00-08 (วันเดียวกัน)' AFTER check_date,
  ADD COLUMN IF NOT EXISTS revision INT NOT NULL DEFAULT 1 COMMENT 'เพิ่มทีละ 1 ทุกครั้งที่แก้รอบเดิม' AFTER completed_at,
  ADD COLUMN IF NOT EXISTS updated_at VARCHAR(40) NULL DEFAULT NULL AFTER revision,
  ADD COLUMN IF NOT EXISTS updated_by VARCHAR(255) NOT NULL DEFAULT '' AFTER updated_at;

-- เติม check_date/shift_code ให้รอบตรวจเดิม (started_at เก็บเป็น UTC → +7 ชม.)
UPDATE refer_checks
   SET check_date = DATE(STR_TO_DATE(LEFT(started_at, 19), '%Y-%m-%dT%H:%i:%s') + INTERVAL 7 HOUR),
       shift_code = CASE shift_name WHEN 'เช้า' THEN 'morning' WHEN 'บ่าย' THEN 'afternoon' WHEN 'ดึก' THEN 'night' END
 WHERE check_date IS NULL;

-- ถ้าข้อมูลเก่ามีรอบซ้ำ (รถ+วัน+เวร) ให้รอบใหม่สุดเป็นตัวแทน รอบเก่ายังอยู่ครบแต่ไม่ผูกเวร (NULL ไม่ชน UNIQUE)
UPDATE refer_checks c
  JOIN refer_checks newer
    ON newer.car_id = c.car_id AND newer.check_date = c.check_date AND newer.shift_code = c.shift_code
   AND newer.completed_at > c.completed_at
   SET c.check_date = NULL, c.shift_code = NULL;

SET @has_uk := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'refer_checks' AND index_name = 'uk_check_car_date_shift');
SET @sql := IF(@has_uk = 0,
  'ALTER TABLE refer_checks ADD UNIQUE KEY uk_check_car_date_shift (car_id, check_date, shift_code), ADD KEY idx_check_date (check_date)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE refer_check_items
  ADD COLUMN IF NOT EXISTS check_status VARCHAR(20) NULL DEFAULT NULL
    COMMENT 'complete|needs_refill|anomaly|pending (NULL = รอบตรวจรุ่นเก่า ดู status)' AFTER status,
  ADD COLUMN IF NOT EXISTS item_type VARCHAR(20) NULL DEFAULT NULL AFTER check_status,
  ADD COLUMN IF NOT EXISTS expected_qty DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'ยอดคาดการณ์ = ก่อนตรวจ − ใช้ไป' AFTER used_qty,
  ADD COLUMN IF NOT EXISTS discrepancy DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'นับจริง − คาดการณ์ (0 = ตรง)' AFTER expected_qty,
  ADD COLUMN IF NOT EXISTS note VARCHAR(500) NOT NULL DEFAULT '' AFTER detail,
  ADD COLUMN IF NOT EXISTS lots_snapshot LONGTEXT NULL DEFAULT NULL COMMENT 'Lot/วันหมดอายุ ณ ตอนตรวจ (JSON)' AFTER note,
  ADD COLUMN IF NOT EXISTS units_snapshot LONGTEXT NULL DEFAULT NULL COMMENT 'สถานะ/แบตรายเครื่อง ณ ตอนตรวจ (JSON)' AFTER lots_snapshot;

-- ประวัติก่อนแก้ไขรอบตรวจ (เก็บทั้งรอบเป็น JSON ทุกครั้งที่มีการแก้)
CREATE TABLE IF NOT EXISTS refer_check_history (
  check_id   VARCHAR(100) NOT NULL,
  revision   INT NOT NULL COMMENT 'revision ของรอบตรวจ "ก่อน" ถูกแก้',
  snapshot   LONGTEXT NOT NULL CHECK (json_valid(snapshot)),
  by_name    VARCHAR(255) NOT NULL DEFAULT '',
  at         VARCHAR(40) NOT NULL,
  PRIMARY KEY (check_id, revision),
  CONSTRAINT fk_check_history_check FOREIGN KEY (check_id) REFERENCES refer_checks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Previous versions of edited inspections';

-- ร่างการตรวจ: ผูกวัน/เวร + เก็บสถานะฟอร์มรูปแบบใหม่ทั้งชุดเป็น JSON
ALTER TABLE refer_drafts
  ADD COLUMN IF NOT EXISTS check_date DATE NULL DEFAULT NULL AFTER shift_name,
  ADD COLUMN IF NOT EXISTS shift_code ENUM('morning','afternoon','night') NULL DEFAULT NULL AFTER check_date,
  ADD COLUMN IF NOT EXISTS payload LONGTEXT NULL DEFAULT NULL COMMENT 'สถานะฟอร์มตรวจ (JSON)' AFTER started_at;

-- ---------- 8) ย้ายยอดเดิมเข้า Lot / เครื่อง (เฉพาะรายการที่ "เคยตรวจนับแล้ว" และยอด > 0) ----------
INSERT INTO refer_item_lots (id, item_id, expiry_date, qty_received, qty_remaining, source, created_at, created_by)
SELECT CONCAT('lot-mig-', i.id),
       i.id,
       IF(i.expiry_text REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$', STR_TO_DATE(i.expiry_text, '%Y-%m-%d'), NULL),
       i.current_qty, i.current_qty, 'MIGRATION',
       DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ'), 'migration 010'
  FROM refer_items i
 WHERE i.item_type IN ('box','piece')
   AND i.stock_updated_at IS NOT NULL
   AND i.current_qty > 0
   AND NOT EXISTS (SELECT 1 FROM refer_item_lots l WHERE l.item_id = i.id);

INSERT INTO refer_stock_movements (id, item_id, car_id, lot_id, type, qty, balance_after, by_name, reason, at)
SELECT CONCAT('mv-mig-', l.item_id), l.item_id, i.car_id, l.id, 'INIT', l.qty_remaining, i.current_qty,
       'migration 010', 'ยอดตั้งต้นจากระบบเดิม (current_qty)', l.created_at
  FROM refer_item_lots l
  JOIN refer_items i ON i.id = l.item_id
 WHERE l.source = 'MIGRATION'
   AND NOT EXISTS (SELECT 1 FROM refer_stock_movements m WHERE m.id = CONCAT('mv-mig-', l.item_id));

-- เครื่องไฟฟ้า: 1 แถวต่อ 1 เครื่องตามยอดที่นับได้ ใช้แบตจากรอบตรวจล่าสุด (เดิมเก็บ 0-4; 0 → ยังไม่ระบุ)
INSERT INTO refer_item_units (id, item_id, label, status, battery_level, note, active, created_at, updated_at, updated_by)
SELECT CONCAT('unit-mig-', i.id, '-', s.seq),
       i.id,
       IF(FLOOR(i.current_qty) > 1, CONCAT('เครื่องที่ ', s.seq), ''),
       'ready',
       NULLIF((SELECT ci.battery_level FROM refer_check_items ci JOIN refer_checks c ON c.id = ci.check_id
                WHERE ci.item_id = i.id ORDER BY c.completed_at DESC LIMIT 1), 0),
       '', 1,
       DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ'),
       DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ'),
       'migration 010'
  FROM refer_items i
  JOIN seq_1_to_50 s ON s.seq <= FLOOR(i.current_qty)
 WHERE i.item_type = 'electric'
   AND i.stock_updated_at IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM refer_item_units u WHERE u.item_id = i.id);
