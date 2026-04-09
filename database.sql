-- CRM LSR LEADS 2026 — Database Schema
-- Import this in phpMyAdmin AFTER selecting your database (u389532358_crm_lsr)
-- NOTE: Do NOT include CREATE DATABASE — Hostinger creates it via hPanel

-- Users (Admin + Sales Managers)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','sales_manager') DEFAULT 'sales_manager',
    mobile VARCHAR(20),                  -- Sales Manager mobile for SMS alerts
    is_active TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- OTP for admin login
CREATE TABLE otp_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sessions
CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Projects (fetched from sheet or manually added)
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Leads
CREATE TABLE leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source ENUM('website','meta','google','manual') DEFAULT 'manual',
    source_row_id VARCHAR(100),          -- external row reference
    project_id INT,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    mobile VARCHAR(20),
    email VARCHAR(150),
    preference TEXT,
    lead_type ENUM('hot','warm','cold') DEFAULT 'warm',
    lead_status ENUM('sv_pending','sv_done','closed','spam') DEFAULT 'sv_pending',
    assigned_to INT,                     -- FK to users
    comments TEXT,
    audio_file VARCHAR(255),
    call_count INT DEFAULT 0,
    sheet_date VARCHAR(50),
    sheet_time VARCHAR(50),
    page_url TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

-- Follow-up / Call history
CREATE TABLE followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    user_id INT NOT NULL,
    call_response TEXT,
    next_followup DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sync log
CREATE TABLE sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50),
    rows_synced INT DEFAULT 0,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Notifications (for lead assignment alerts etc.)
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) DEFAULT 'lead_assigned',
    title VARCHAR(255) NOT NULL,
    message TEXT,
    lead_id INT,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
);

-- Seed admin users
INSERT INTO users (name, email, password, role, is_active) VALUES
('PD Admin', 'PD@propnmore.com', '$2y$12$placeholder_will_use_otp', 'admin', 1),
('CS Admin', 'CS@propnmore.com', '$2y$12$placeholder_will_use_otp', 'admin', 1);

-- Calendar Events (Action plan / reminders for users)
CREATE TABLE calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    lead_id INT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    event_time TIME NULL,
    is_completed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
);
