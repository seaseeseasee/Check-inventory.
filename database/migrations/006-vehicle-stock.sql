-- ============================================================================
-- Migration 006 — สต็อกประจำรถ + เป้าหมายตัวเลข + รอยเท้าตัวเลขต่อการตรวจ 1 ครั้ง
-- ============================================================================
-- วัตถุประสงค์ (ผลจาก Phase 1 audit)
--   ระบบเดิมไม่มี "ยอดคงเหลือบนรถแต่ละคัน" เลย ทำให้
--     1) หน้าตรวจแสดง "สต็อกปัจจุบัน" ไม่ได้ (ข้อ 1 ของสเปก)
--     2) คำนวณ "ใช้ไป = ก่อน − หลัง" และ "ต้องเติม = เป้าหมาย − คงเหลือจริง" ไม่ได้
--     3) ตรวจสอบย้อนกลับ (audit trail) ไม่ได้ เพราะคอลัมน์เก็บยอดคงเหลือเป็น text ล้วน
--
-- สิ่งที่พบตอนสำรวจฐานข้อมูลจริง ( refer_system บนเครื่อง dev ) — สำคัญมาก
--   ฐานข้อมูลที่รันอยู่ "มีคอลัมน์Placeholder" สำหรับสต็อกประจำรถอยู่แล้ว แต่
--   ไม่มีโค้ด PHP/JS ใดอ่านหรือเขียน และไม่มีข้อมูลเลยแม้แต่รายการเดียว:
--     refer_items.current_qty            DECIMAL(10,2) NOT NULL DEFAULT 0.00  253 แถว = 0.00 ทั้งหมด
--     refer_items.last_restocked_at      VARCHAR(40) NULL                      253 แถว = NULL ทั้งหมด
--     refer_check_items.actual_qty       DECIMAL(10,2) NULL                    380 แถว = NULL ทั้งหมด
--     refer_check_items.used_qty         DECIMAL(10,2) NULL                    380 แถว = NULL ทั้งหมด
--     refer_restock_items.vehicle_stock_before/_after DECIMAL(10,2) NULL        19 แถว = NULL ทั้งหมด
--   (ยืนยันด้วย DESCRIBE + SUM(col IS NOT NULL) และ grep ทั้งโฟลเดอร์ source และ
--    C:\xampp\htdocs\emer — ไม่พบชื่อคอลัมน์เหล่านี้ในโค้ดเลย)
--   Migration นี้จึง "ใช้คอลัมน์เดิมต่อ" ไม่สร้างคอลัมน์ชื่อซ้ำความหมายซ้ำ
--   และเพิ่มเฉพาะส่วนที่ขาดจริง ๆ เท่านั้น
--
-- ขอบเขต (additive เท่านั้น)
--   refer_items          : + target_qty, + unit, เปลี่ยนชื่อ last_restocked_at →
--                          stock_updated_at (คอลัมน์ว่างและไม่มีโค้ดอ้างถึง), + CHECK
--   refer_check_items    : + qty_before, + qty_target, + unit  (actual_qty/used_qty เดิมใช้ต่อ)
--   refer_restock_items  : ไม่มี schema change — ใช้ vehicle_stock_before/_after เดิม
--   refer_stock_items / refer_stock_transactions : ไม่แตะโครงสร้าง
--   ไม่มี DROP TABLE / DROP COLUMN / ลบแถวข้อมูลเดิมออก
--
-- ผลกระทบต่อข้อมูลเดิม
--   current_qty ของ 253 แถวเป็น 0.00 ทั้งหมด ซึ่ง "ไม่ใช่ยอดคงเหลือจริง" แต่เป็นค่า default
--   จึงกำหนดสัญญาใหม่ดังนี้ (สำคัญต่อหน้าจอ):
--     "ยังไม่เคยตรวจนับ" ⇔ stock_updated_at IS NULL  (ไม่ใช่ current_qty = 0)
--   หน้าจอจะแสดง "ยังไม่เคยตรวจนับ" เสมอเมื่อ stock_updated_at เป็น NULL
--   เพื่อให้ไม่มีทางเอาเลข default 0 ไปหลอกว่าเป็นยอดล่าสุดจากฐานข้อมูล (ข้อ 1.5 / ข้อ 5)
--   ไม่ได้ UPDATE ค่า current_qty เดิมให้กลายเป็น NULL ⇒ ข้อมูลเดิมไม่ถูกเขียนทับ
--
-- แผน rollback (ตรวจได้ก่อนใช้จริง)
--   ALTER TABLE refer_check_items
--     DROP CONSTRAINT IF EXISTS chk_check_lines_qty_non_negative,
--     DROP COLUMN IF EXISTS qty_before, DROP COLUMN IF EXISTS qty_target, DROP COLUMN IF EXISTS unit;
--   ALTER TABLE refer_items
--     DROP CONSTRAINT IF EXISTS chk_items_current_qty_non_negative,
--     DROP COLUMN IF EXISTS target_qty, DROP COLUMN IF EXISTS unit,
--     CHANGE COLUMN stock_updated_at last_restocked_at VARCHAR(40) NULL;
--   ทุกคอลัมน์ที่ migration นี้เพิ่มเป็นคอลัมน์ว่าง จึงไม่มีข้อมูลสูญหายเมื่อย้อนกลับ
--   (สำรองข้อมูลก่อนรัน: mysqldump -u root refer_system > backup-pre-006.sql)
--
-- ความต้องการของระบบ
--   MariaDB 10.2.1+ (REGEXP_SUBSTR / REGEXP_REPLACE / CHECK) หรือ MySQL 8.0.4+
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) refer_items — ยอดคงเหลือบนรถ + เป้าหมายตัวเลข + หน่วยนับ
-- ---------------------------------------------------------------------------
ALTER TABLE refer_items
    ADD COLUMN IF NOT EXISTS target_qty DECIMAL(10,2) NULL
        COMMENT 'จำนวนเป้าหมายที่รถควรมี (ตัวเลขล้วน สกัดจาก standard; NULL = มาตรฐานไม่มีตัวเลข)' AFTER standard,
    ADD COLUMN IF NOT EXISTS unit VARCHAR(50) NOT NULL DEFAULT ''
        COMMENT 'หน่วยนับ เช่น ชิ้น กล่อง ขวด ชุด' AFTER target_qty;

-- Reconciliation guard (เพิ่ม 2026-10-19): เครื่อง dev ที่สำรวจไว้ตอนเขียน migration นี้
-- มีคอลัมน์ current_qty / last_restocked_at อยู่แล้วนอกบันทึก migration ใด ๆ — บนฐานข้อมูล
-- ใหม่ที่ไล่รัน 001-005 ตามลำดับ (ไม่มี patch นอกบันทึกนี้) คอลัมน์ทั้งสองจะไม่มีอยู่เลย
-- ทำให้ CHANGE COLUMN ด้านล่างพังทันที (เปลี่ยนชื่อคอลัมน์ที่ไม่มีอยู่ไม่ได้) จึงต้องสร้างให้
-- แน่ใจก่อนว่ามีอยู่ (ค่าเริ่มต้นเท่ากับที่ Model/StockService อ่าน/เขียนจริง)
ALTER TABLE refer_items
    ADD COLUMN IF NOT EXISTS current_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'ยอดคงเหลือบนรถ (เขียนโดย Item::updateStock); NULL ไม่ได้ ใช้ stock_updated_at แยกแยะ "ยังไม่ตรวจนับ"' AFTER is_battery,
    ADD COLUMN IF NOT EXISTS last_restocked_at VARCHAR(40) NULL AFTER current_qty;

-- เปลี่ยนชื่อคอลัมน์ placeholder ที่ว่างและไม่มีโค้ดอ้าง ให้ตรงกับหน้าที่จริง
-- (เดิม last_restocked_at = "เติมล่าสุด" ซึ่งไม่เคยถูกเขียน; ใช้เป็น "แก้ยอดล่าสุด")
ALTER TABLE refer_items
    CHANGE COLUMN IF EXISTS last_restocked_at stock_updated_at VARCHAR(40) NULL
        COMMENT 'เวลาล่าสุดที่ระบบเขียน current_qty (ตรวจรถ / เติมของ / ปรับยอด); NULL = ยังไม่เคยตรวจนับ';

-- เป้าหมายตัวเลขจากข้อความมาตรฐาน: "1 ชุด" → 1 / "10" → 10 / "2" → 2
UPDATE refer_items
   SET target_qty = CAST(REGEXP_SUBSTR(standard, '[0-9]+([.][0-9]+)?') AS DECIMAL(10,2))
 WHERE standard REGEXP '[0-9]'
   AND target_qty IS NULL;

-- หน่วยนับ: ส่วนที่ตามหลังตัวเลขใน standard (ค่าเดียวที่มีในข้อมูลจริงคือ "ชุด")
UPDATE refer_items
   SET unit = TRIM(REGEXP_REPLACE(standard, '^[^0-9]*[0-9]+([.][0-9]+)?[[:space:]]*', ''))
 WHERE standard REGEXP '^[^0-9]*[0-9]+([.][0-9]+)?[[:space:]]'
   AND unit = '';

-- เติมหน่วยจากคลังกลางเมื่อชื่อเดียวกัน (ปัจจุบัน refer_stock_items.unit ว่างทั้ง 64 แถว
-- ดังนั้น ขั้นตอนนี้มักยังไม่ได้ผล — ต้องให้ผู้ดูแลกรอกหน่วยในคลังจริงก่อนใช้จริง)
UPDATE refer_items i
    JOIN refer_stock_items s ON s.name = i.name
   SET i.unit = s.unit
 WHERE i.unit = '' AND s.unit <> '';

ALTER TABLE refer_items
    ADD CONSTRAINT chk_items_current_qty_non_negative CHECK (current_qty >= 0);

-- ดัชนีสำหรับค้นของบนรถตามชื่อ (ใช้ตอนผูกกับคลังกลาง) — เดิมมีเฉพาะ idx ตาม car_id
ALTER TABLE refer_items
    ADD INDEX IF NOT EXISTS idx_items_name (name);

-- ---------------------------------------------------------------------------
-- 2) refer_check_items — รอยเท้าตัวเลขของแต่ละบรรทัดที่ตรวจ
--    ใช้ actual_qty (คงเหลือจริง) และ used_qty (ใช้ไป) ที่มีอยู่แล้ว + เพิ่ม 3 คอลัมน์
-- ---------------------------------------------------------------------------
-- Reconciliation guard (เพิ่ม 2026-10-19): เหตุผลเดียวกับ refer_items ด้านบน —
-- actual_qty/used_qty ถูกสมมติว่ามีอยู่แล้ว (จริงบนเครื่อง dev ที่สำรวจ) แต่ไม่มี migration
-- ใดสร้างไว้ ฐานข้อมูลใหม่จึงต้องสร้างก่อน ไม่อย่างนั้น ADD COLUMN...AFTER used_qty และ
-- MODIFY COLUMN ด้านล่างจะพังเพราะคอลัมน์ used_qty ยังไม่มีอยู่จริง
ALTER TABLE refer_check_items
    ADD COLUMN IF NOT EXISTS actual_qty DECIMAL(10,2) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS used_qty DECIMAL(10,2) NULL AFTER actual_qty;

ALTER TABLE refer_check_items
    ADD COLUMN IF NOT EXISTS qty_before DECIMAL(10,2) NULL
        COMMENT 'ยอดบนรถก่อนตรวจ (NULL = ยังไม่เคยตรวจนับก่อนหน้า)' AFTER status,
    ADD COLUMN IF NOT EXISTS qty_target DECIMAL(10,2) NULL
        COMMENT 'เป้าหมาย/มาตรฐาน (ตัวเลข) ที่ใช้ ณ ตอนตรวจ' AFTER used_qty,
    ADD COLUMN IF NOT EXISTS unit VARCHAR(50) NOT NULL DEFAULT ''
        COMMENT 'หน่วยนับ ณ ตอนตรวจ' AFTER qty_target;

-- ปรับคำอธิบายคอลัมน์เดิมให้ตรงความหมายที่ใช้จริง (ไม่เปลี่ยนชนิดข้อมูล/ไม่แตะค่า)
ALTER TABLE refer_check_items
    MODIFY COLUMN actual_qty DECIMAL(10,2) NULL
        COMMENT 'จำนวนคงเหลือจริงที่ผู้ตรวจนับ (NULL = ไม่ได้ นับ เช่นยังไม่ตรวจ/เป็นอุปกรณ์ถาวร)',
    MODIFY COLUMN used_qty DECIMAL(10,2) NULL
        COMMENT 'จำนวนที่ใช้ไป = qty_before - actual_qty (NULL เมื่อไม่มียอดก่อนตรวจ หรือยอดเกินกว่าก่อนตรวจ)';

ALTER TABLE refer_check_items
    ADD CONSTRAINT chk_check_lines_qty_non_negative CHECK (
        (qty_before  IS NULL OR qty_before  >= 0)
        AND (actual_qty   IS NULL OR actual_qty   >= 0)
        AND (used_qty     IS NULL OR used_qty     >= 0)
        AND (qty_target   IS NULL OR qty_target   >= 0)
    );

-- ---------------------------------------------------------------------------
-- 3) refer_restock_items — ใช้ vehicle_stock_before / vehicle_stock_after เดิม
--    (ก่อนเติม + ที่เติมจริง = หลังเติม; เป็นรอยเท้าตามข้อ 4 และข้อ 6)
--    ไม่เพิ่มคอลัมน์ใหม่ — แก้ COMMENT ให้ตรงความหมายเท่านั้น
-- ---------------------------------------------------------------------------
-- Reconciliation guard (เพิ่ม 2026-10-19): เหตุผลเดียวกัน — คอลัมน์นี้ไม่มี migration ใด
-- สร้างไว้จริง ต้องมีก่อนจึง MODIFY ได้
ALTER TABLE refer_restock_items
    ADD COLUMN IF NOT EXISTS vehicle_stock_before DECIMAL(10,2) NULL AFTER qty_filled,
    ADD COLUMN IF NOT EXISTS vehicle_stock_after DECIMAL(10,2) NULL AFTER vehicle_stock_before;

ALTER TABLE refer_restock_items
    MODIFY COLUMN vehicle_stock_before DECIMAL(10,2) NULL
        COMMENT 'ยอดบนรถก่อนเติม (NULL = ยังไม่เคยตรวจนับ)',
    MODIFY COLUMN vehicle_stock_after DECIMAL(10,2) NULL
        COMMENT 'ยอดบนรถหลังเติม = vehicle_stock_before + จำนวนที่เติมจริง';
