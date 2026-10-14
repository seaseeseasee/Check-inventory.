-- ============================================
-- Ambulance Refer Checklist Database Schema
-- Version: 2.0.0
-- For phpMyAdmin Import
-- Target: MySQL 8.0+ / MariaDB 10.5+
-- Character Set: utf8mb4
-- ============================================

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `refer_system` 
    DEFAULT CHARACTER SET utf8mb4 
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `refer_system`;

-- ============================================
-- 1. NURSES TABLE (Staff Directory)
-- ============================================
CREATE TABLE `refer_nurses` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `department` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(100) NOT NULL DEFAULT '',
    `active` BOOLEAN NOT NULL DEFAULT TRUE,
    `start_text` VARCHAR(100) NOT NULL DEFAULT '',
    `end_text` VARCHAR(100) NOT NULL DEFAULT '',
    `note` TEXT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Nurse/Staff directory for inspection assignments';

-- ============================================
-- 2. USERS TABLE (System Authentication)
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
-- 3. VEHICLES TABLE (Ambulance Fleet)
-- ============================================
CREATE TABLE `refer_vehicles` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `plate` VARCHAR(50) COLLATE utf8mb4_bin NULL COMMENT 'License plate e.g. กข-1234 กรุงเทพฯ',
    `name` VARCHAR(255) NOT NULL,
    `brand_model` VARCHAR(255) NULL COMMENT 'Brand / model e.g. Toyota Commuter',
    `status` VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'available | maintenance | disabled',
    `odometer_initial` INT NULL COMMENT 'Initial odometer reading (km)',
    `notes` VARCHAR(500) NULL COMMENT 'Additional notes',
    PRIMARY KEY (`id`),
    KEY `idx_vehicle_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Ambulance vehicles';

-- ============================================
-- 4. ITEMS TABLE (Standard Equipment per Vehicle)
-- ============================================
CREATE TABLE `refer_items` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `standard` VARCHAR(100) NOT NULL,
    `expiry_text` VARCHAR(100) NOT NULL DEFAULT '',
    `is_battery` BOOLEAN NOT NULL DEFAULT FALSE,
    `active` BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (`id`),
    KEY `idx_car_active` (`car_id`, `active`),
    CONSTRAINT `fk_item_vehicle` FOREIGN KEY (`car_id`) REFERENCES `refer_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Standard equipment list per vehicle';

-- ============================================
-- 5. INSPECTIONS TABLE (Completed Checklists)
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
-- 6. INSPECTION ITEMS TABLE (Results per Inspection)
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
    `detail` TEXT NOT NULL,
    `battery_level` TINYINT NULL,
    PRIMARY KEY (`check_id`, `item_id`),
    CONSTRAINT `fk_check_item_check` FOREIGN KEY (`check_id`) REFERENCES `refer_checks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Equipment results for each inspection';

-- ============================================
-- 7. DRAFTS TABLE (In-progress Inspections)
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
-- 8. DRAFT ITEMS TABLE (Draft Inspection Items)
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
-- 9. RESTOCK REQUESTS TABLE (Auto-generated from Inspections)
-- ============================================
CREATE TABLE `refer_restock_requests` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `no` VARCHAR(100) NOT NULL COMMENT 'Request number e.g., RS-20261012-0001',
    `car_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `car_name` VARCHAR(255) NOT NULL COMMENT 'Vehicle name at time of request',
    `check_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'Source inspection ID (reference only)',
    `created_by` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL COMMENT 'Nurse who performed inspection',
    `created_name` VARCHAR(255) NOT NULL COMMENT 'Nurse name at time of request',
    `created_by_user_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'System user who created request',
    `assigned_to_user_id` VARCHAR(100) COLLATE utf8mb4_bin NULL COMMENT 'Assigned staff for restocking',
    `reason` TEXT NOT NULL,
    `note` TEXT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING, IN_PROGRESS, PARTIAL, COMPLETED, CANCELLED',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Restock requests auto-generated from inspections';

-- ============================================
-- 10. RESTOCK ITEMS TABLE (Equipment per Request)
-- ============================================
CREATE TABLE `refer_restock_items` (
    `request_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `item_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `sort_order` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'Equipment name at time of request',
    `standard` VARCHAR(100) NOT NULL COMMENT 'Standard quantity at time of request',
    `actual_qty` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Actual quantity found during inspection',
    `qty_requested` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Quantity requested to restock',
    `qty_issued` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Quantity issued from storage (cumulative)',
    `qty_filled` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Quantity actually filled in vehicle (cumulative)',
    PRIMARY KEY (`request_id`, `item_id`),
    CONSTRAINT `fk_restock_item_request` FOREIGN KEY (`request_id`) REFERENCES `refer_restock_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Equipment items for each restock request';

-- ============================================
-- 11. RESTOCK ACTIONS TABLE (Audit Trail)
-- ============================================
CREATE TABLE `refer_restock_actions` (
    `request_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `seq` INT NOT NULL,
    `type` VARCHAR(20) NOT NULL COMMENT 'create, start, restock, complete, cancel',
    `at` VARCHAR(40) NOT NULL,
    `by_id` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL COMMENT 'User who performed action',
    `by_name` VARCHAR(255) NOT NULL COMMENT 'User name at time of action',
    `note` TEXT NOT NULL,
    `action_lines` JSON NOT NULL COMMENT 'Array of {itemId, qty} for restock actions',
    PRIMARY KEY (`request_id`, `seq`),
    CONSTRAINT `fk_restock_action_request` FOREIGN KEY (`request_id`) REFERENCES `refer_restock_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_restock_action_user` FOREIGN KEY (`by_id`) REFERENCES `refer_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Audit trail for restock request actions';

-- ============================================
-- 12. METADATA TABLE (System Configuration)
-- ============================================
CREATE TABLE `refer_metadata` (
    `id` VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
    `payload` JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='System metadata and configuration';

-- ============================================
-- 13. LOGIN ATTEMPTS TABLE (Rate Limiting)
-- ============================================
CREATE TABLE `refer_login_attempts` (
    `ip_hash` CHAR(64) PRIMARY KEY,
    `attempts` INT NOT NULL DEFAULT 0,
    `window_start` BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Login attempt tracking for rate limiting';

-- ============================================
-- 14. REVISION TABLE (Optimistic Locking)
-- ============================================
CREATE TABLE `refer_revision` (
    `id` TINYINT PRIMARY KEY,
    `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='Global revision counter for optimistic locking';

-- ============================================
-- INITIAL DATA
-- ============================================

-- Insert initial revision
INSERT INTO `refer_revision` (`id`, `revision`) VALUES (1, 0);

-- Insert schema version metadata
INSERT INTO `refer_metadata` (`id`, `payload`) VALUES 
('schema', JSON_OBJECT('version', 2)),
('legacy', JSON_OBJECT('reports', JSON_ARRAY(), 'items', JSON_ARRAY()));

-- ============================================
-- END OF SCHEMA
-- ============================================