-- ============================================================================
-- Migration 009 — Reconcile refer_restock_actions / refer_restock_items with
-- the columns the current application code actually reads and writes
-- ============================================================================
-- พบจากการตรวจสอบโค้ดจริง (read-only audit, 2026-10-19):
--   app/Models/RestockAction.php  fillable ใช้ชื่อคอลัมน์ "action_lines"
--   แต่ migration 001/002 สร้างคอลัมน์นี้ไว้ในชื่อ "lines" — คนละชื่อกัน
--
--   app/Models/RestockItem.php    writeQuantities() เขียนคอลัมน์
--   "vehicle_stock_before" / "vehicle_stock_after" บนตาราง refer_restock_items
--   แต่ไม่มี migration ใดเลย (001-008) ที่ CREATE หรือ ADD คอลัมน์นี้
--   (migration 006 เขียนไว้เองว่าพบคอลัมน์นี้ "อยู่แล้ว" บนฐานข้อมูล dev จริง
--    โดยไม่มีไฟล์ migration ใดสร้างมันขึ้นมา — เป็นการแก้ฐานข้อมูลตรงที่ไม่ถูกบันทึกไว้)
--
--   app/Controllers/StaffController.php ส่ง by_id = refer_users.id (เช่น เลขฐาน 16 จาก
--   generate_id()) แต่ migration 001/002 ผูก by_id ด้วย FOREIGN KEY ไปที่ refer_nurses(id)
--   คนละ id space กัน — insert จะชน foreign key constraint
--
-- ผลกระทบถ้าไม่แก้: ฐานข้อมูลใหม่ที่สร้างจาก migration 001-008 ตามลำดับ (เช่น
-- เครื่อง dev/XAMPP เครื่องใหม่) จะบันทึกการ start/restock/complete/cancel คำขอ
-- เบิก-เติมไม่ได้เลย ขณะที่ฐานข้อมูลเดิมที่เคยถูกแก้ตรงมาก่อนหน้านี้ไม่ได้รับผลกระทบ
-- เพราะคำสั่งทุกตัวด้านล่างเป็น no-op ถ้าโครงสร้างนั้นมีอยู่แล้ว
--
-- ขอบเขต: additive/reconciliation เท่านั้น ไม่ลบคอลัมน์ ไม่ลบข้อมูล
-- แผน rollback (ตรวจก่อนใช้จริง):
--   ALTER TABLE refer_restock_actions CHANGE COLUMN action_lines lines JSON NOT NULL;
--   ALTER TABLE refer_restock_items DROP COLUMN vehicle_stock_before, DROP COLUMN vehicle_stock_after;
--   (คืน FK by_id กลับไปที่ refer_nurses ด้วยมือ หากจำเป็นจริง ๆ)
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) refer_restock_actions.lines -> action_lines (เปลี่ยนชื่อถ้ายังเป็นชื่อเดิม)
-- ---------------------------------------------------------------------------
ALTER TABLE refer_restock_actions
    CHANGE COLUMN IF EXISTS lines action_lines JSON NOT NULL
        COMMENT 'รายการ itemId/qty ของการจ่ายหรือเติมรายครั้ง';

-- เผื่อกรณีตารางถูกสร้างโดยไม่มีคอลัมน์นี้เลยมาก่อน (ไม่ควรเกิด แต่กันไว้)
ALTER TABLE refer_restock_actions
    ADD COLUMN IF NOT EXISTS action_lines JSON NOT NULL
        COMMENT 'รายการ itemId/qty ของการจ่ายหรือเติมรายครั้ง' AFTER note;

-- ---------------------------------------------------------------------------
-- 2) refer_restock_items — เติมคอลัมน์ยอดคงเหลือบนรถที่ RestockItem::writeQuantities() ใช้
--    (migration 006 ที่แก้พร้อมกันนี้สร้างคอลัมน์นี้ไปแล้วด้วย guard เดียวกัน — ขั้นนี้จึง
--    เป็น no-op บนฐานข้อมูลที่รัน 006 ฉบับแก้แล้ว เก็บไว้เป็นเกราะกันอีกชั้นเท่านั้น)
-- ---------------------------------------------------------------------------
ALTER TABLE refer_restock_items
    ADD COLUMN IF NOT EXISTS vehicle_stock_before DECIMAL(10,2) NULL
        COMMENT 'ยอดบนรถก่อนเติม (NULL = ยังไม่เคยตรวจนับ)' AFTER qty_filled,
    ADD COLUMN IF NOT EXISTS vehicle_stock_after DECIMAL(10,2) NULL
        COMMENT 'ยอดบนรถหลังเติม = vehicle_stock_before + จำนวนที่เติมจริง' AFTER vehicle_stock_before;

-- ---------------------------------------------------------------------------
-- 3) refer_restock_actions.by_id — ย้าย FK จาก refer_nurses ไป refer_users
--    (แอปปัจจุบันส่ง id ของ "ผู้ใช้ระบบ" ไม่ใช่ "พยาบาล" เข้าคอลัมน์นี้)
--    ชื่อ constraint เดิมไม่ได้ตั้งไว้ตรง ๆ ใน migration 001/002 จึงต้องหาชื่อจริงจาก
--    information_schema ก่อนลบ (ชื่อที่ MySQL/MariaDB auto-gen มักเป็น
--    refer_restock_actions_ibfk_2 แต่ไม่ควร hardcode เผื่อฐานข้อมูลบางเครื่องตั้งชื่อไว้เอง)
-- ---------------------------------------------------------------------------
SET @fk_name := (
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'refer_restock_actions'
      AND COLUMN_NAME = 'by_id'
      AND REFERENCED_TABLE_NAME = 'refer_nurses'
    LIMIT 1
);
SET @drop_sql := IF(@fk_name IS NOT NULL,
    CONCAT('ALTER TABLE refer_restock_actions DROP FOREIGN KEY `', @fk_name, '`'),
    'DO 0');
PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_user_fk := (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'refer_restock_actions'
      AND COLUMN_NAME = 'by_id'
      AND REFERENCED_TABLE_NAME = 'refer_users'
);
SET @add_sql := IF(@has_user_fk = 0,
    'ALTER TABLE refer_restock_actions ADD CONSTRAINT fk_restock_action_user FOREIGN KEY (by_id) REFERENCES refer_users(id)',
    'DO 0');
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4) ตรวจผลหลังรัน (รัน query นี้ดูคำตอบก่อนใช้งานหน้าจอ)
-- ---------------------------------------------------------------------------
--   SHOW COLUMNS FROM refer_restock_actions LIKE 'action_lines';   -- ต้องเจอ 1 แถว
--   SHOW COLUMNS FROM refer_restock_items LIKE 'vehicle_stock_%';  -- ต้องเจอ 2 แถว
--   SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refer_restock_actions' AND COLUMN_NAME = 'by_id';
--   -- คาดหวัง REFERENCED_TABLE_NAME = 'refer_users'
