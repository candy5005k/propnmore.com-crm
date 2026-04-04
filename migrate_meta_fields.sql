-- Run this in phpMyAdmin on database: u389532358_crm_lsr
-- Adds Meta campaign/ad tracking columns to leads table

ALTER TABLE leads
    ADD COLUMN campaign_name VARCHAR(255) DEFAULT NULL AFTER preference,
    ADD COLUMN ad_name VARCHAR(255) DEFAULT NULL AFTER campaign_name,
    ADD COLUMN form_name VARCHAR(255) DEFAULT NULL AFTER ad_name,
    ADD COLUMN project_name VARCHAR(255) DEFAULT NULL AFTER form_name,
    ADD COLUMN notes TEXT DEFAULT NULL AFTER project_name;

-- Update admin profiles with correct names
UPDATE users SET name = 'Prashanta Das' WHERE email = 'PD@propnmore.com';
UPDATE users SET name = 'Chirag Shah'  WHERE email = 'CS@propnmore.com';
