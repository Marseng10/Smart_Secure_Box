-- =========================================================================
-- SMART SECURE STORAGE BOX - DATABASE SCHEMA
-- Konsep: One Account, Multiple Devices, Different Roles (Role HANYA di device_users)
-- =========================================================================

CREATE DATABASE IF NOT EXISTS smart_secure_box_v3
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_secure_box_v3;

-- 1. Tabel Akun Pengguna Global (Satu akun untuk seluruh Storage Box, TANPA kolom role)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabel Perangkat Storage Box (ESP32)
CREATE TABLE IF NOT EXISTS devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  invite_code VARCHAR(32) NOT NULL UNIQUE,
  invite_expires_at DATETIME NULL,
  invite_single_use TINYINT(1) NOT NULL DEFAULT 1,
  api_key VARCHAR(128) NOT NULL,
  api_key_hash CHAR(64) NOT NULL,
  locked TINYINT(1) NOT NULL DEFAULT 1,
  door_closed TINYINT(1) NOT NULL DEFAULT 1,
  door_open_seconds INT NOT NULL DEFAULT 0,
  door_alarm TINYINT(1) NOT NULL DEFAULT 0,
  vibration_alarm TINYINT(1) NOT NULL DEFAULT 0,
  last_vibration_at DATETIME NULL,
  security_alarm TINYINT(1) NOT NULL DEFAULT 0,
  temperature DECIMAL(5,2) NULL,
  humidity DECIMAL(5,2) NULL,
  rssi INT NULL,
  door_timeout_sec INT NOT NULL DEFAULT 30,
  auto_lock_sec INT NOT NULL DEFAULT 10,
  max_pin_attempts INT NOT NULL DEFAULT 3,
  temp_threshold DECIMAL(5,2) NOT NULL DEFAULT 35.00,
  humidity_threshold DECIMAL(5,2) NOT NULL DEFAULT 80.00,
  last_seen DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Tabel Penghubung User <-> Device (Role HANYA berlaku di sini, beserta PIN 6-Digit per Device)
CREATE TABLE IF NOT EXISTS device_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  device_id INT NOT NULL,
  role ENUM('ADMIN', 'USER') NOT NULL DEFAULT 'USER',
  pin_hash VARCHAR(255) NULL,
  pin_sha256 CHAR(64) NULL,
  status ENUM('PENDING', 'APPROVED', 'REJECTED', 'REVOKED') NOT NULL DEFAULT 'PENDING',
  can_view_logs TINYINT(1) NOT NULL DEFAULT 1,
  request_note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_device (user_id, device_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Tabel Antrian Perintah Jarak Jauh per Device (Remote Commands & ACK ESP32)
CREATE TABLE IF NOT EXISTS commands (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  command_type ENUM('LOCK', 'UNLOCK', 'EMERGENCY_UNLOCK', 'ALARM_ON', 'ALARM_OFF') NOT NULL,
  status ENUM('PENDING', 'SUCCESS', 'FAILED') NOT NULL DEFAULT 'PENDING',
  requested_by INT NULL,
  reason VARCHAR(255) NULL,
  result_message VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Tabel Riwayat Aktivitas Akses (access_logs / logs)
CREATE TABLE IF NOT EXISTS logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NULL,
  user_id INT NULL,
  username_snapshot VARCHAR(50) NULL,
  method VARCHAR(30) NOT NULL DEFAULT 'SYSTEM',
  action VARCHAR(60) NOT NULL,
  result ENUM('SUCCESS', 'FAILED', 'INFO') NOT NULL DEFAULT 'INFO',
  details VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- View access_logs agar sesuai dengan penamaan di laporan maupun kode
CREATE OR REPLACE VIEW access_logs AS
  SELECT id, device_id, user_id, username_snapshot, method, action, result, details, created_at
  FROM logs;

-- 6. Tabel Peringatan Keamanan & Sensor per Device (Alerts termasuk Sensor Getaran)
CREATE TABLE IF NOT EXISTS alerts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NULL,
  alert_type VARCHAR(40) NOT NULL,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL,
  status ENUM('ACTIVE', 'RESOLVED') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 7. Tabel Proteksi Brute-Force PIN Keypad per Device
CREATE TABLE IF NOT EXISTS pin_attempts (
  device_id INT PRIMARY KEY,
  failures INT NOT NULL DEFAULT 0,
  blocked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
