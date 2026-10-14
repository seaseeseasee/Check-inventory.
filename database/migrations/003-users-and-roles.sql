-- Migration 003: Users table and role-based access control
-- Run after 001-initial.sql and 002-restock.sql

-- Users table for system authentication (separate from nurses)
CREATE TABLE IF NOT EXISTS refer_users (
    id VARCHAR(100) COLLATE utf8mb4_bin PRIMARY KEY,
    username VARCHAR(100) COLLATE utf8mb4_bin NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    nurse_id VARCHAR(100) COLLATE utf8mb4_bin NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at VARCHAR(40) NULL,
    created_at VARCHAR(40) NOT NULL,
    updated_at VARCHAR(40) NOT NULL,
    FOREIGN KEY (nurse_id) REFERENCES refer_nurses(id) ON DELETE SET NULL,
    INDEX(role), INDEX(username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add created_by_user_id to restock_requests to track actual system user
ALTER TABLE refer_restock_requests 
ADD COLUMN IF NOT EXISTS created_by_user_id VARCHAR(100) COLLATE utf8mb4_bin NULL,
ADD COLUMN IF NOT EXISTS assigned_to_user_id VARCHAR(100) COLLATE utf8mb4_bin NULL,
ADD COLUMN IF NOT EXISTS completed_at VARCHAR(40) NULL,
ADD INDEX IF NOT EXISTS idx_created_by_user (created_by_user_id),
ADD INDEX IF NOT EXISTS idx_assigned_to_user (assigned_to_user_id);

-- Add foreign keys (will need to be added separately if not exists)
-- ALTER TABLE refer_restock_requests 
-- ADD CONSTRAINT fk_restock_created_by_user FOREIGN KEY (created_by_user_id) REFERENCES refer_users(id) ON DELETE SET NULL,
-- ADD CONSTRAINT fk_restock_assigned_to_user FOREIGN KEY (assigned_to_user_id) REFERENCES refer_users(id) ON DELETE SET NULL;

-- Update restock_requests status enum to new values
-- Note: MySQL ENUM requires table rebuild, so we'll use VARCHAR with CHECK constraint in application layer
-- New statuses: PENDING, IN_PROGRESS, PARTIAL, COMPLETED, CANCELLED

-- Add check constraint for valid statuses (MySQL 8.0.16+)
-- ALTER TABLE refer_restock_requests 
-- ADD CONSTRAINT chk_restock_status 
-- CHECK (status IN ('PENDING', 'IN_PROGRESS', 'PARTIAL', 'COMPLETED', 'CANCELLED'));

-- Add restock_actions for new action types
-- New action types: start, restock, complete, cancel

-- Add indexes for better query performance
CREATE INDEX IF NOT EXISTS idx_restock_status_car ON refer_restock_requests(status, car_id);
CREATE INDEX IF NOT EXISTS idx_restock_assigned_status ON refer_restock_requests(assigned_to_user_id, status);

-- Default admin user (password: admin123 - change on first login)
-- INSERT INTO refer_users (id, username, password_hash, full_name, role, active, created_at, updated_at)
-- VALUES ('usr_admin_001', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', TRUE, NOW(), NOW());