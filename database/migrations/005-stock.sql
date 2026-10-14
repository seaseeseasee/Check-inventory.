-- Migration 005: Central Warehouse Stock (คลังสต็อกกลาง) tables
-- Run after 004-vehicle-details.sql
--
-- Additive only: creates 2 NEW tables. No existing table/column is dropped, renamed or retyped.
--   refer_stock_items        : คลังสต็อกกลาง คีย์ด้วยชื่ออุปกรณ์ (แชร์กันทุกรถ)
--   refer_stock_transactions : บันทึกประวัติการเคลื่อนไหวสต็อกทุกรายการ (INIT/RECEIVE/ISSUE/ADJUST)
-- Rollback (if ever needed):
--   DROP TABLE refer_stock_transactions; DROP TABLE refer_stock_items;

CREATE TABLE IF NOT EXISTS refer_stock_items (
    id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'ชื่ออุปกรณ์ (คีย์กลาง ใช้ร่วมกันทุกรถ)',
    unit VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'หน่วยนับ เช่น ชิ้น/กล่อง',
    current_qty DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'สต็อกปัจจุบันในคลัง',
    min_qty DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'จำนวนมาตรฐานขั้นต่ำ (เตือนเมื่อต่ำกว่านี้)',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at VARCHAR(40) NOT NULL DEFAULT '',
    updated_at VARCHAR(40) NOT NULL DEFAULT '',
    UNIQUE KEY uk_stock_name (name),
    INDEX idx_stock_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refer_stock_transactions (
    id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
    stock_item_id VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'อ้างอิงรายการคลัง (ไม่ผูก FK ให้คงอยู่แม้รายการคลังถูกลบ)',
    name VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่ออุปกรณ์ ณ เวลาบันทึก',
    type VARCHAR(20) NOT NULL COMMENT 'INIT|RECEIVE|ISSUE|ADJUST',
    qty DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'จำนวนเปลี่ยนแปลง (ลบ = ตัดจ่าย, บวก = รับเข้า)',
    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'ยอดคงเหลือในคลังหลังรายการนี้',
    car_id VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'รถที่ถูกตัดจ่าย (ถ้ามี)',
    car_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'สำเนาชื่อรถ ณ เวลาตัดจ่าย',
    check_id VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'รอบตรวจต้นทาง (ไม่ผูก FK เพราะประวัติตรวจถูกล้างได้)',
    by_user_id VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'ผู้ดำเนินการ (ระบบผู้ใช้)',
    by_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'สำเนาชื่อผู้ดำเนินการ',
    note VARCHAR(500) NOT NULL DEFAULT '',
    at VARCHAR(40) NOT NULL COMMENT 'เวลาที่เกิดรายการ (UTC)',
    FOREIGN KEY(car_id) REFERENCES refer_vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY(by_user_id) REFERENCES refer_users(id) ON DELETE SET NULL,
    INDEX idx_stx_item (stock_item_id, at),
    INDEX idx_stx_at (at),
    INDEX idx_stx_car (car_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: สร้างรายการคลังตั้งต้นจากชื่ออุปกรณ์ที่มีอยู่แล้วในทะเบียนรถ (ยอด 0 / ขั้นต่ำ 0)
-- ผู้ดูแลสามารถกำหนดสต็อกปัจจุบันและขั้นต่ำได้จากหน้าจอเพิ่มอุปกรณ์ หรือหน้าคลังสต็อกกลาง
INSERT INTO refer_stock_items (id, name, unit, current_qty, min_qty, active, created_at, updated_at)
SELECT CONCAT('stk-', SUBSTRING(MD5(t.name), 1, 20)), t.name, '', 0, 0, TRUE, '', ''
FROM (SELECT DISTINCT name FROM refer_items WHERE name IS NOT NULL AND name <> '') t
WHERE NOT EXISTS (SELECT 1 FROM refer_stock_items s WHERE s.name = t.name);
