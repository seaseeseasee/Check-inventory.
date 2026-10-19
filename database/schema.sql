-- ============================================
-- Ambulance Refer Checklist Database Schema
-- Version: 5.0.0 — single-shot snapshot equivalent to running
-- database/migrations/001 through 009 in order on an empty database
-- For phpMyAdmin Import
-- Target: MySQL 8.0+ / MariaDB 10.5+
-- Character Set: utf8mb4
-- ============================================
--
-- หมายเหตุสำคัญ (แก้ 2026-10-19 จากการตรวจสอบโค้ดจริง):
--   ไฟล์นี้เคยไม่ตรงกับ app/Models/* จริง (ขาดตาราง stock/departments, ขาดคอลัมน์
--   vehicle_stock_before/after, ใช้ชื่อคอลัมน์ "lines" แทน "action_lines" ที่โค้ดใช้จริง,
--   FK ของ refer_restock_actions.by_id ชี้ผิดตาราง) — ตรวจสอบและแก้ให้ตรงกับ
--   migrations/001-009 (ฉบับแก้แล้ว) และ app/Models/* ทุกไฟล์แล้ว
--   หากฐานข้อมูลใดเคยรัน migrations 001-009 มาก่อน "ห้ามรันไฟล์นี้ซ้ำ" (CREATE DATABASE/
--   CREATE TABLE ไม่มี OR REPLACE และ FK ใหม่อาจชนของเดิม) ใช้ไฟล์นี้สำหรับ "ฐานข้อมูลใหม่
--   ที่ยังไม่มีอะไรเลย" เท่านั้น — ฐานข้อมูลที่มีอยู่แล้วให้ไล่รัน migrations/*.sql ตามเลขลำดับ
-- ============================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `refer_system`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `refer_system`;

-- ============================================
-- 1. DEPARTMENTS TABLE (Wards/Units master list)
-- ============================================
CREATE TABLE `refer_departments` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'ชื่อวอร์ด/หน่วยงาน ใช้แสดงในตารางพยาบาลและ dropdown',
    `notes` TEXT NOT NULL,
    `active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` VARCHAR(40) NOT NULL DEFAULT '',
    `updated_at` VARCHAR(40) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_department_name` (`name`),
    KEY `idx_department_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Wards/departments used by the nurse directory';

-- ============================================
-- 2. NURSES TABLE (Staff Directory)
-- ============================================
CREATE TABLE `refer_nurses` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `department` VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่อวอร์ด — เขียนทับอัตโนมัติเมื่อแก้ชื่อใน refer_departments',
    `department_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'FK ไปยัง refer_departments',
    `phone` VARCHAR(100) NOT NULL DEFAULT '',
    `active` BOOLEAN NOT NULL DEFAULT TRUE,
    `start_text` VARCHAR(100) NOT NULL DEFAULT '',
    `end_text` VARCHAR(100) NOT NULL DEFAULT '',
    `note` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_nurse_department` (`department_id`),
    CONSTRAINT `fk_nurse_department` FOREIGN KEY (`department_id`) REFERENCES `refer_departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Nurse/Staff directory for inspection assignments';

-- ============================================
-- 3. USERS TABLE (System Authentication)
-- ============================================
CREATE TABLE `refer_users` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `username` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    `nurse_id` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `active` BOOLEAN NOT NULL DEFAULT TRUE,
    `last_login_at` VARCHAR(40) NULL,
    `created_at` VARCHAR(40) NOT NULL,
    `updated_at` VARCHAR(40) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    KEY `idx_role` (`role`),
    KEY `idx_nurse_id` (`nurse_id`),
    CONSTRAINT `fk_user_nurse` FOREIGN KEY (`nurse_id`) REFERENCES `refer_nurses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='System user accounts with roles';

-- ============================================
-- 4. VEHICLES TABLE (Ambulance Fleet)
-- ============================================
CREATE TABLE `refer_vehicles` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `plate` VARCHAR(50) COLLATE utf8mb4_bin NULL COMMENT 'License plate e.g. กข-1234 กรุงเทพฯ',
    `name` VARCHAR(255) NOT NULL,
    `brand_model` VARCHAR(255) NULL COMMENT 'Brand / model e.g. Toyota Commuter',
    `status` VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'available | maintenance | disabled',
    `odometer_initial` INT NULL COMMENT 'Initial odometer reading (km)',
    `notes` VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_vehicle_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Ambulance vehicles';

-- ============================================
-- 5. ITEMS TABLE (Standard Equipment per Vehicle, with onboard stock)
-- ============================================
CREATE TABLE `refer_items` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `standard` VARCHAR(100) NOT NULL,
    `target_qty` DECIMAL(10,2) NULL COMMENT 'จำนวนเป้าหมายที่รถควรมี (ตัวเลขล้วน สกัดจาก standard)',
    `unit` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'หน่วยนับ เช่น ชิ้น กล่อง ขวด ชุด',
    `expiry_text` VARCHAR(100) NOT NULL DEFAULT '',
    `is_battery` BOOLEAN NOT NULL DEFAULT FALSE,
    `current_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ยอดคงเหลือบนรถ (เขียนโดย Item::updateStock)',
    `stock_updated_at` VARCHAR(40) NULL COMMENT 'เวลาล่าสุดที่เขียน current_qty; NULL = ยังไม่เคยตรวจนับ',
    `active` BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (`id`),
    KEY `idx_car_active` (`car_id`, `active`),
    KEY `idx_items_name` (`name`),
    CONSTRAINT `fk_item_vehicle` FOREIGN KEY (`car_id`) REFERENCES `refer_vehicles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_items_current_qty_non_negative` CHECK (`current_qty` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Standard equipment list per vehicle';

-- ============================================
-- 6. INSPECTIONS TABLE (Completed Checklists)
-- ============================================
CREATE TABLE `refer_checks` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `nurse_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_name` VARCHAR(255) NOT NULL,
    `nurse_name` VARCHAR(255) NOT NULL,
    `shift_name` VARCHAR(100) NOT NULL,
    `started_at` VARCHAR(40) NOT NULL,
    `completed_at` VARCHAR(40) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_car_completed` (`car_id`, `completed_at`),
    CONSTRAINT `fk_check_vehicle` FOREIGN KEY (`car_id`) REFERENCES `refer_vehicles` (`id`),
    CONSTRAINT `fk_check_nurse` FOREIGN KEY (`nurse_id`) REFERENCES `refer_nurses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Completed inspection rounds';

-- ============================================
-- 7. INSPECTION ITEMS TABLE (Results per Inspection, with numeric footprint)
-- ============================================
CREATE TABLE `refer_check_items` (
    `check_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `item_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `sort_order` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `standard` VARCHAR(100) NOT NULL,
    `expiry_text` VARCHAR(100) NOT NULL,
    `is_battery` BOOLEAN NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `qty_before` DECIMAL(10,2) NULL COMMENT 'ยอดบนรถก่อนตรวจ (NULL = ยังไม่เคยตรวจนับก่อนหน้า)',
    `actual_qty` DECIMAL(10,2) NULL COMMENT 'จำนวนคงเหลือจริงที่ผู้ตรวจนับ',
    `used_qty` DECIMAL(10,2) NULL COMMENT 'จำนวนที่ใช้ไป = qty_before - actual_qty',
    `qty_target` DECIMAL(10,2) NULL COMMENT 'เป้าหมาย/มาตรฐาน (ตัวเลข) ที่ใช้ ณ ตอนตรวจ',
    `unit` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'หน่วยนับ ณ ตอนตรวจ',
    `detail` TEXT NOT NULL,
    `battery_level` TINYINT NULL,
    PRIMARY KEY (`check_id`, `item_id`),
    CONSTRAINT `fk_check_item_check` FOREIGN KEY (`check_id`) REFERENCES `refer_checks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_check_lines_qty_non_negative` CHECK (
        (`qty_before` IS NULL OR `qty_before` >= 0)
        AND (`actual_qty` IS NULL OR `actual_qty` >= 0)
        AND (`used_qty` IS NULL OR `used_qty` >= 0)
        AND (`qty_target` IS NULL OR `qty_target` >= 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Equipment results for each inspection';

-- ============================================
-- 8. DRAFTS TABLE (In-progress Inspections)
-- ============================================
CREATE TABLE `refer_drafts` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL UNIQUE,
    `nurse_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_name` VARCHAR(255) NOT NULL,
    `nurse_name` VARCHAR(255) NOT NULL,
    `shift_name` VARCHAR(100) NOT NULL,
    `started_at` VARCHAR(40) NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_draft_vehicle` FOREIGN KEY (`car_id`) REFERENCES `refer_vehicles` (`id`),
    CONSTRAINT `fk_draft_nurse` FOREIGN KEY (`nurse_id`) REFERENCES `refer_nurses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='In-progress inspection drafts';

-- ============================================
-- 9. DRAFT ITEMS TABLE (Draft Inspection Items)
-- ============================================
CREATE TABLE `refer_draft_items` (
    `draft_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `item_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `sort_order` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `standard` VARCHAR(100) NOT NULL,
    `expiry_text` VARCHAR(100) NOT NULL,
    `is_battery` BOOLEAN NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `detail` TEXT NOT NULL,
    `battery_level` TINYINT NULL,
    PRIMARY KEY (`draft_id`, `item_id`),
    CONSTRAINT `fk_draft_item_draft` FOREIGN KEY (`draft_id`) REFERENCES `refer_drafts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Equipment items for draft inspections';

-- ============================================
-- 10. RESTOCK REQUESTS TABLE
-- ============================================
CREATE TABLE `refer_restock_requests` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `no` VARCHAR(100) NOT NULL COMMENT 'เลขที่คำขอเติม เช่น RT-20261012-001',
    `car_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_name` VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่อรถ ณ เวลาแจ้ง',
    `check_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'รอบตรวจต้นทาง (สำเนาอ้างอิง ไม่ผูก FK)',
    `created_by` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL COMMENT 'พยาบาลผู้ตรวจพบ',
    `created_name` VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่อผู้แจ้ง',
    `created_by_user_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'ผู้ใช้ระบบที่สร้างคำขอ',
    `assigned_to_user_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'เจ้าหน้าที่คลังที่รับมอบหมาย',
    `reason` TEXT NOT NULL,
    `note` TEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL COMMENT 'PENDING|IN_PROGRESS|PARTIAL|COMPLETED|CANCELLED (ดู App\\Models\\RestockRequest::VALID_STATUSES)',
    `created_at` VARCHAR(40) NOT NULL,
    `completed_at` VARCHAR(40) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_no` (`no`),
    KEY `idx_car_status` (`car_id`, `status`),
    KEY `idx_check_id` (`check_id`),
    KEY `idx_created_by_user` (`created_by_user_id`),
    KEY `idx_assigned_to_user` (`assigned_to_user_id`),
    CONSTRAINT `fk_restock_vehicle` FOREIGN KEY (`car_id`) REFERENCES `refer_vehicles` (`id`),
    CONSTRAINT `fk_restock_created_by` FOREIGN KEY (`created_by`) REFERENCES `refer_nurses` (`id`),
    CONSTRAINT `fk_restock_created_by_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `refer_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_restock_assigned_to_user` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `refer_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Restock requests raised from inspections';

-- ============================================
-- 11. RESTOCK ITEMS TABLE (Equipment lines per Request, with vehicle balance footprint)
-- ============================================
CREATE TABLE `refer_restock_items` (
    `request_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `item_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `sort_order` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่ออุปกรณ์ ณ เวลาขอเติม',
    `standard` VARCHAR(100) NOT NULL COMMENT 'สำเนาจำนวนมาตรฐาน ณ เวลาขอเติม',
    `actual_qty` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่มีอยู่จริง',
    `qty_requested` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่ขอเติม',
    `qty_issued` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่จ่ายจากคลัง (สะสม)',
    `qty_filled` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'จำนวนที่เติมลงรถจริง (สะสม)',
    `vehicle_stock_before` DECIMAL(10,2) NULL COMMENT 'ยอดบนรถก่อนเติม (NULL = ยังไม่เคยตรวจนับ)',
    `vehicle_stock_after` DECIMAL(10,2) NULL COMMENT 'ยอดบนรถหลังเติม = before + ที่เติมจริง',
    PRIMARY KEY (`request_id`, `item_id`),
    CONSTRAINT `fk_restock_item_request` FOREIGN KEY (`request_id`) REFERENCES `refer_restock_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Equipment items for each restock request';

-- ============================================
-- 12. RESTOCK ACTIONS TABLE (Audit Trail)
-- ============================================
CREATE TABLE `refer_restock_actions` (
    `request_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `seq` INT NOT NULL,
    `type` VARCHAR(20) NOT NULL COMMENT 'create|start|restock|complete|cancel',
    `at` VARCHAR(40) NOT NULL,
    `by_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL COMMENT 'refer_users.id ของผู้ดำเนินการ',
    `by_name` VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่อผู้ดำเนินการ',
    `note` TEXT NOT NULL,
    `action_lines` JSON NOT NULL COMMENT 'รายการ itemId/qty ของการจ่ายหรือเติมรายครั้ง (ชื่อคอลัมน์ต้องตรงกับ App\\Models\\RestockAction)',
    PRIMARY KEY (`request_id`, `seq`),
    CONSTRAINT `fk_restock_action_request` FOREIGN KEY (`request_id`) REFERENCES `refer_restock_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_restock_action_user` FOREIGN KEY (`by_id`) REFERENCES `refer_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Audit trail for restock request actions';

-- ============================================
-- 13. CENTRAL WAREHOUSE STOCK (คลังสต็อกกลาง)
-- ============================================
CREATE TABLE `refer_stock_items` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'ชื่ออุปกรณ์ (คีย์กลาง ใช้ร่วมกันทุกรถ)',
    `unit` VARCHAR(50) NOT NULL DEFAULT '',
    `current_qty` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `min_qty` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` VARCHAR(40) NOT NULL DEFAULT '',
    `updated_at` VARCHAR(40) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_stock_name` (`name`),
    KEY `idx_stock_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Central warehouse stock levels';

CREATE TABLE `refer_stock_transactions` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `stock_item_id` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'สำเนาชื่ออุปกรณ์ ณ เวลาบันทึก',
    `type` VARCHAR(20) NOT NULL COMMENT 'INIT|RECEIVE|ISSUE|ADJUST',
    `qty` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `car_id` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `car_name` VARCHAR(255) NOT NULL DEFAULT '',
    `check_id` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `by_user_id` VARCHAR(100) COLLATE utf8mb4_bin NULL,
    `by_name` VARCHAR(255) NOT NULL DEFAULT '',
    `note` VARCHAR(500) NOT NULL DEFAULT '',
    `at` VARCHAR(40) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_stx_item` (`stock_item_id`, `at`),
    KEY `idx_stx_at` (`at`),
    KEY `idx_stx_car` (`car_id`),
    CONSTRAINT `fk_stx_vehicle` FOREIGN KEY (`car_id`) REFERENCES `refer_vehicles` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_stx_user` FOREIGN KEY (`by_user_id`) REFERENCES `refer_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Central warehouse stock ledger';

-- ============================================
-- 14. METADATA TABLE (System Configuration)
-- ============================================
CREATE TABLE `refer_metadata` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `payload` JSON NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='System metadata and configuration';

-- ============================================
-- 15. LOGIN ATTEMPTS TABLE (Brute-force tracking)
-- ============================================
CREATE TABLE `refer_login_attempts` (
    `ip_hash` CHAR(64) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `window_start` BIGINT NOT NULL,
    PRIMARY KEY (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Login attempt tracking for brute-force protection';

-- ============================================
-- 16. REVISION TABLE (Optimistic Locking)
-- ============================================
CREATE TABLE `refer_revision` (
    `id` TINYINT NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Global revision counter for optimistic locking';

-- ============================================
-- INITIAL DATA
-- ============================================
INSERT INTO `refer_revision` (`id`, `revision`) VALUES (1, 0);

INSERT INTO `refer_metadata` (`id`, `payload`) VALUES
('schema', JSON_OBJECT('version', 5));

-- ============================================
-- END OF SCHEMA
-- ============================================
