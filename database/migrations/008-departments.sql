-- ============================================================================
-- Migration 008 — ทะเบียนวอร์ด/หน่วยงาน (Departments) สำหรับตารางพยาบาล
-- ============================================================================
-- วัตถุประสงค์ (ตามคำขอ: จัดการวอร์ดได้สมบูรณ์ + ฟอร์มพยาบาลใช้ dropdown)
--   เดิม refer_nurses.department เป็น VARCHAR(255) ฟรีเท็กซ์ ไม่มีตาราง master
--   ทำให้ชื่อวอร์ดพิมพ์ซ้ำ/พิมพ์ผิดได้เอง กรองตามวอร์ดไม่ได้ และไม่มีจุดลบ/แก้ชื่อกลาง
--
-- สำรวจฐานข้อมูลจริงก่อนเขียน ( refer_system บนเครื่อง dev, วันเดียวกับการรัน )
--   refer_nurses = 16 แถว, คอลัมน์ department varchar(255) NOT NULL, ยังไม่มี
--   คอลัมน์ department_id และไม่มีตาราง refer_departments (ยืนยันด้วย SHOW FULL COLUMNS + SHOW TABLES)
--   ค่า distinct ที่ใช้จริง: 'ER' (14), 'ICU' (1), 'Ward 2' (1) — ไม่มีค่าว่าง ไม่มีค่าเกิน 60 ตัวอักษร
--   ค่าเหล่านี้ถูก backfill เป็นแถว master ใน migration นี้ทั้งหมด จึงไม่มีพยาบาลตกค้างนอกทะเบียนวอร์ด
--
-- แบบที่เลือก: master table + คอลัมน์ FK + คงคอลัมน์ชื่อเดิมไว้เป็น "สำเนาที่แสดง"
--   เพราะทุกหน้าจอที่อ่านค่าวอร์ดในปัจจุบัน (การ์ดพยาบาล, dropdown ผู้ตรวจใน
--   inspections.js / login.js / users.js, ไฟล์ Excel นำเข้า) อ่านจาก nurse.department
--   การเปลี่ยนเป็น FK ล้วนโดยไม่คงชื่อเดิมจะทำให้อ่านค่าไม่ได้ต้องแก้ทุกที่พร้อมกัน
--   ดังนั้น: refer_departments คือแหล่งความจริง (แก้ชื่อ/ลบ/ปิดใช้งานที่นี่)
--          refer_nurses.department_id = FK ไปยังแถว master
--          refer_nurses.department    = ชื่อสำเนาที่ระบบเขียนทับให้อัตโนมัติเมื่อแก้ชื่อ
--   ผล: หน้าจอเดิมอ่านต่อได้ทันที + แก้ชื่อครั้งเดียวแล้วหายทั้งระบบ + ข้อมูลจริงไม่หลุดลอย
--
-- ขอบเขต (additive เท่านั้น)
--   เพิ่มตาราง refer_departments
--   เพิ่มคอลัมน์ refer_nurses.department_id (NULL ได้, ต่อท้ายตาราง) + ดัชนี + FK
--   ไม่มี DROP TABLE / DROP COLUMN / RENAME / เปลี่ยนชนิดคอลัมน์เดิม
--   ไม่เขียนทับค่า department เดิม (backfill แค่เติม department_id ให้ตรงแถว master)
--
-- ผลกระทบต่อการทำงานจริง
--   refer_nurses เป็นตารางที่ทีมใช้อยู่ จึงเลี่ยง ALTER ที่ต้อง rebuild ตาราง:
--     - ไม่ใช้ AFTER department (จะไม่ได้ INSTANT) → ปล่อยคอลัมน์ใหม่ต่อท้าย
--     - ขนาดตาราง 16 แถว LOCK เป็นช่วงสั้นมาก แต่รันนอกเวลาใช้งานจะปลอดภัยกว่า
--   FK เป็น ON DELETE RESTRICT → ห้ามลบวอร์ดที่ยังมีพยาบาลสังกัด (ป้องกันข้อมูลหลุด)
--     ตัว service ตรวจก่อนอยู่แล้ว FK เป็นเกราะชั้นที่สองกรณี schema เปลี่ยน
--
-- แผน rollback (ตรวจได้ก่อนใช้จริง — ย้อนเฉพาะโครงสร้าง ข้อมูลพยาบาลไม่หาย)
--   ALTER TABLE refer_nurses DROP FOREIGN KEY fk_nurse_department;
--   ALTER TABLE refer_nurses DROP INDEX idx_nurse_department, DROP COLUMN department_id;
--   DROP TABLE refer_departments;
--   (สำรองก่อนรัน: mysqldump -u root refer_system > backup-pre-008.sql)
--
-- ความต้องการ: MariaDB 10.2+ / MySQL 8.0+  (ADD COLUMN IF NOT EXISTS เป็น syntax ของ MariaDB)
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) ตาราง master ของวอร์ด/หน่วยงาน
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refer_departments (
    id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'ชื่อวอร์ด/หน่วยงาน ใช้แสดงในตารางพยาบาลและ dropdown',
    notes TEXT NOT NULL COMMENT 'รายละเอียดเพิ่มเติมของวอร์ด',
    active BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'ปิดใช้งาน = ไม่ให้เลือกเพิ่ม แต่สังกัดเดิมยังอยู่',
    created_at VARCHAR(40) NOT NULL DEFAULT '',
    updated_at VARCHAR(40) NOT NULL DEFAULT '',
    UNIQUE KEY uk_department_name (name),
    INDEX idx_department_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Wards/departments used by the nurse directory';

-- ---------------------------------------------------------------------------
-- 2) ผูกพยาบาลเข้ากับวอร์ด
-- ---------------------------------------------------------------------------
ALTER TABLE refer_nurses
    ADD COLUMN IF NOT EXISTS department_id VARCHAR(100) COLLATE utf8mb4_bin NULL
        COMMENT 'FK ไปยัง refer_departments (NULL = ยังไม่ได้ผูก หลังรัน migration นี้จะเต็มทุกแถวเดิม)';

ALTER TABLE refer_nurses
    ADD INDEX IF NOT EXISTS idx_nurse_department (department_id);

-- ---------------------------------------------------------------------------
-- 3) backfill: สร้างแถว master จากค่าที่ใช้งานอยู่จริง แล้วผูกกลับเข้าพยาบาล
--    id ตั้งจาก MD5 ของชื่อ → ถ้ารันซ้ำได้ id เดิม (INSERT IGNORE ไม่สร้างซ้ำ)
--    เปรียบเทียบด้วย TRIM เพื่อไม่ให้ชื่อที่มีช่องว่างเกินมาสร้างวอร์ดซ้ำ
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO refer_departments (id, name, notes, active, created_at, updated_at)
SELECT CONCAT('dept-', MD5(t.department)), t.department, '', TRUE,
       DATE_FORMAT(NOW(), '%Y-%m-%dT%H:%i:%s.000000Z'),
       DATE_FORMAT(NOW(), '%Y-%m-%dT%H:%i:%s.000000Z')
  FROM (SELECT DISTINCT TRIM(department) AS department
          FROM refer_nurses
         WHERE TRIM(department) <> '') AS t;

-- ผูกแถว master เข้ากับพยาบาลตามชื่อ (ไม่แก้ค่า department เดิม)
UPDATE refer_nurses n
    JOIN refer_departments d ON d.name = TRIM(n.department)
   SET n.department_id = d.id
 WHERE n.department_id IS NULL;

-- ---------------------------------------------------------------------------
-- 4) FK: ใส่หลัง backfill เสร็จ เพื่อไม่ให้การตรวจ FK ล้มกับแถวเดิมที่ยังไม่มี master
--    (MariaDB ไม่รองรับ ADD CONSTRAINT IF NOT EXISTS — รันขั้นตอนนี้ครั้งเดียว)
-- ---------------------------------------------------------------------------
ALTER TABLE refer_nurses
    ADD CONSTRAINT fk_nurse_department FOREIGN KEY (department_id)
        REFERENCES refer_departments (id) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------------
-- 5) ตรวจผลหลังรัน (รัน query นี้ดูคำตอบก่อนใช้งานหน้าจอ)
-- ---------------------------------------------------------------------------
--   SELECT COUNT(*) AS nurses_total,
--          SUM(department_id IS NULL) AS nurses_unlinked
--     FROM refer_nurses;                      -- คาดหวัง nurses_unlinked = 0
--   SELECT d.name, d.active,
--          (SELECT COUNT(*) FROM refer_nurses n WHERE n.department_id = d.id) AS nurse_count
--     FROM refer_departments d ORDER BY d.name;
