-- ========================================
-- NLETA DATABASE FULL RESET
-- ========================================
-- Run this in phpMyAdmin SQL tab or MySQL CLI
-- This will DELETE all existing data and create fresh tables with test users

DROP DATABASE IF EXISTS maintenance_system;
CREATE DATABASE maintenance_system;
USE maintenance_system;

-- USERS TABLE
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','owner','technician','client') NOT NULL,
  assigned_technician_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ASSETS TABLE
CREATE TABLE assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_name VARCHAR(150) NOT NULL,
  asset_type VARCHAR(50),
  category VARCHAR(10),
  location VARCHAR(200),
  owner_id INT NOT NULL,
  status ENUM('Active','Under Maintenance','Out of Service') DEFAULT 'Active',
  maintenance_interval INT DEFAULT 30,
  updated_at DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id)
);

-- MAINTENANCE RECORDS
CREATE TABLE maintenance_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  maintenance_date DATE NOT NULL,
  remarks TEXT,
  status VARCHAR(50) DEFAULT 'completed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- MAINTENANCE JOBS
CREATE TABLE maintenance_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  owner_id INT DEFAULT NULL,
  technician_id INT,
  status ENUM('assigned','in_progress','pending_owner','approved','rejected','completed') DEFAULT 'assigned',
  checklist JSON,
  remarks TEXT,
  file VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- CALLBACKS
CREATE TABLE callbacks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  callback_number VARCHAR(50) UNIQUE,
  asset_id INT NOT NULL,
  technician_id INT,
  issue VARCHAR(100),
  description TEXT,
  status ENUM('open','in_progress','closed','approved') DEFAULT 'open',
  work_report TEXT,
  work_report_file VARCHAR(255),
  work_started_at DATETIME,
  work_closed_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- INSURANCE
CREATE TABLE insurance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  expiry_date DATE NOT NULL,
  report_file VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- NOC REGISTRATION
CREATE TABLE noc_registration (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT,
  noc_number VARCHAR(100),
  noc_expiry_date DATE,
  next_safety_date DATE,
  next_mock_drill_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- FORTNIGHTLY CHECKS
CREATE TABLE fortnightly_checks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT,
  owner_id INT,
  check_date DATE NOT NULL,
  checklist JSON,
  remarks TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- NEWS
CREATE TABLE news (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255),
  content TEXT,
  image VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ========================================
-- INSERT TEST USERS
-- ========================================
-- Password for ALL accounts: nleta2025

INSERT INTO users (id, name, email, password, role, assigned_technician_id) VALUES
(1, 'Admin User',    'admin@nleta.org',      '$2y$12$slyAUOHAcbXRUgR7jAt.qOeQaLdJlvJNIFhCNl.aWGyIo5y/F4Zme', 'admin',      NULL),
(2, 'John Owner',    'owner@nleta.org',      '$2y$12$slyAUOHAcbXRUgR7jAt.qOeQaLdJlvJNIFhCNl.aWGyIo5y/F4Zme', 'owner',      3),
(3, 'Mike Tech',     'technician@nleta.org', '$2y$12$slyAUOHAcbXRUgR7jAt.qOeQaLdJlvJNIFhCNl.aWGyIo5y/F4Zme', 'technician', NULL);

-- ========================================
-- INSERT SAMPLE ASSET
-- ========================================
INSERT INTO assets (id, asset_name, asset_type, category, location, owner_id, status, maintenance_interval) VALUES
(2, 'Lift A - Block 1', 'Lift', 'I', 'Block 1, Ground Floor', 2, 'Active', 30);

-- ========================================
-- DONE!
-- ========================================
-- Login credentials:
-- Admin:      admin@nleta.org      / nleta2025
-- Owner:      owner@nleta.org      / nleta2025
-- Technician: technician@nleta.org / nleta2025
