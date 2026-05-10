-- Migration v2: Generic admin token support
-- Run once via phpMyAdmin before deploying

-- Make department_id nullable (NULL = admin token; MySQL allows NULL in FK columns)
ALTER TABLE auth_tokens MODIFY COLUMN department_id INT NULL;

-- Add is_admin flag
ALTER TABLE auth_tokens ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER department_id;
