-- Migration 001: เพิ่มตารางระบบเบิก–เติมอุปกรณ์ประจำรถ (restock)
-- ใช้กับฐานข้อมูลที่ติดตั้ง v4 แล้ว (schema.sql เดิม) รันครั้งเดียวบนฐานข้อมูลระบบรถรีเฟอร์เท่านั้น
-- เป็นการเพิ่มตารางใหม่ล้วน (CREATE TABLE IF NOT EXISTS = รันซ้ำได้) ไม่ลบ/ไม่แก้ข้อมูลเดิม
-- ห้ามนำไฟล์นี้ไปรันบนฐานข้อมูลอื่น

CREATE TABLE IF NOT EXISTS refer_restock_requests (
 id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
 no VARCHAR(100) NOT NULL COMMENT 'เลขที่คำขอเติม เช่น RT-20261012-001',
 car_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 car_name VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่อรถ ณ เวลาแจ้ง',
 check_id VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'รอบตรวจต้นทาง (สำเนาอ้างอิง ไม่ผูก FK เพราะประวัติตรวจถูกล้างได้)',
 created_by VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 created_name VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่อผู้แจ้ง',
 reason TEXT NOT NULL,
 note TEXT NOT NULL,
 status VARCHAR(20) NOT NULL COMMENT 'draft|submitted|partial|fulfilled|cancelled',
 created_at VARCHAR(40) NOT NULL,
 FOREIGN KEY(car_id) REFERENCES refer_vehicles(id),
 FOREIGN KEY(created_by) REFERENCES refer_nurses(id),
 INDEX(car_id,status), INDEX(check_id), INDEX(no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refer_restock_items (
 request_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 item_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 sort_order INT NOT NULL,
 name VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่ออุปกรณ์ ณ เวลาขอเติม',
 standard VARCHAR(100) NOT NULL COMMENT 'สำเนาจำนวนมาตรฐาน ณ เวลาขอเติม',
 actual_qty VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่มีอยู่จริง',
 qty_requested VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่ขอเติม',
 qty_issued VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่จ่ายจากคลัง (สะสม)',
 qty_filled VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่เติมลงรถจริง (สะสม แยกจากจ่าย)',
 PRIMARY KEY(request_id,item_id),
 FOREIGN KEY(request_id) REFERENCES refer_restock_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refer_restock_actions (
 request_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 seq INT NOT NULL,
 type VARCHAR(20) NOT NULL COMMENT 'create|submit|issue|fill|fulfil|cancel',
 at VARCHAR(40) NOT NULL,
 by_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 by_name VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่อผู้ดำเนินการ',
 note TEXT NOT NULL,
 lines JSON NOT NULL COMMENT 'รายการ itemId/qty ของการจ่ายหรือเติมรายครั้ง',
 PRIMARY KEY(request_id,seq),
 FOREIGN KEY(request_id) REFERENCES refer_restock_requests(id) ON DELETE CASCADE,
 FOREIGN KEY(by_id) REFERENCES refer_nurses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
