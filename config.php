<?php
/**
 * Smart Secure Storage Box - Core Configuration & Database Helpers
 * Architecture: One Account, Multiple Devices, Different Roles (device_users)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'smart_secure_box_v3';
const DB_USER = 'root';
const DB_PASS = '';

/**
 * Get PDO connection & auto-ensure multi-device schema exists
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            $options
        );
    } catch (PDOException $e) {
        $rootPdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            $options
        );
        $rootPdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            $options
        );
    }

    ensure_schema($pdo);
    return $pdo;
}

/**
 * Generate a unique readable Invite Code for a Storage Box (e.g., INV-BOX01-A9F2)
 */
function generate_invite_code(string $deviceCode = 'BOX'): string {
    $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($deviceCode)) ?: 'BOX';
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    return 'INV-' . substr($clean, 0, 8) . '-' . $suffix;
}

/**
 * Ensure all tables & multi-device columns exist, and migrate existing data automatically
 */
function ensure_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $hasDevices = (bool)$pdo->query("SHOW TABLES LIKE 'devices'")->fetchColumn();
    if (!$hasDevices) {
        $sqlFile = __DIR__ . '/database/smart_secure_box.sql';
        if (file_exists($sqlFile)) {
            $pdo->exec((string)file_get_contents($sqlFile));
        }
        return;
    }

    // 1. Pastikan kolom invite_code ada di tabel devices
    $hasInviteCol = (bool)$pdo->query("SHOW COLUMNS FROM devices LIKE 'invite_code'")->fetchColumn();
    if (!$hasInviteCol) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN invite_code VARCHAR(32) NULL AFTER name");
        $pdo->exec("ALTER TABLE devices ADD COLUMN invite_expires_at DATETIME NULL AFTER invite_code");
        $pdo->exec("ALTER TABLE devices ADD COLUMN invite_single_use TINYINT(1) NOT NULL DEFAULT 1 AFTER invite_expires_at");

        $devs = $pdo->query("SELECT id, code FROM devices")->fetchAll();
        foreach ($devs as $d) {
            $inv = generate_invite_code((string)$d['code']);
            $pdo->prepare("UPDATE devices SET invite_code = ?, invite_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?")
                ->execute([$inv, (int)$d['id']]);
        }
        $pdo->exec("ALTER TABLE devices MODIFY COLUMN invite_code VARCHAR(32) NOT NULL UNIQUE");
    }

    // 2. Pastikan tabel device_users ada & migrasikan relasi user lama jika baru pertama kali dibuat
    $hasDeviceUsers = (bool)$pdo->query("SHOW TABLES LIKE 'device_users'")->fetchColumn();
    if (!$hasDeviceUsers) {
        $pdo->exec("
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
            ) ENGINE=InnoDB
        ");

        // Migrasikan data users yang sudah ada ke device pertama (BOX-01)
        $firstDevId = (int)$pdo->query("SELECT id FROM devices ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstDevId > 0) {
            $hasLegacyRole = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetchColumn();
            if ($hasLegacyRole) {
                $existingUsers = $pdo->query("SELECT id, role, pin_hash, pin_sha256 FROM users")->fetchAll();
                $insRel = $pdo->prepare("
                    INSERT IGNORE INTO device_users (user_id, device_id, role, pin_hash, pin_sha256, status, can_view_logs, request_note)
                    VALUES (?, ?, ?, ?, ?, 'APPROVED', 1, 'Migrasi otomatis akun awal')
                ");
                foreach ($existingUsers as $u) {
                    $r = (strtoupper((string)($u['role'] ?? 'USER')) === 'ADMIN') ? 'ADMIN' : 'USER';
                    $insRel->execute([
                        (int)$u['id'],
                        $firstDevId,
                        $r,
                        $u['pin_hash'] ?? null,
                        $u['pin_sha256'] ?? null
                    ]);
                }
            }
        }
    }

    // 3. Pastikan kolom role/pin_hash lama di tabel users dihapus (Role & PIN HANYA ada di device_users)
    $hasLegacyRoleCol = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetchColumn();
    if ($hasLegacyRoleCol) {
        $pdo->exec("ALTER TABLE users DROP COLUMN role");
    }
    $hasLegacyPinCol = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'pin_hash'")->fetchColumn();
    if ($hasLegacyPinCol) {
        $pdo->exec("ALTER TABLE users DROP COLUMN pin_hash");
    }
    $hasLegacyPinShaCol = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'pin_sha256'")->fetchColumn();
    if ($hasLegacyPinShaCol) {
        $pdo->exec("ALTER TABLE users DROP COLUMN pin_sha256");
    }

    // 4. Pastikan kolom sensor getaran (vibration_alarm & last_vibration_at) ada di tabel devices
    $hasVibrationCol = (bool)$pdo->query("SHOW COLUMNS FROM devices LIKE 'vibration_alarm'")->fetchColumn();
    if (!$hasVibrationCol) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN vibration_alarm TINYINT(1) NOT NULL DEFAULT 0 AFTER door_alarm");
        $pdo->exec("ALTER TABLE devices ADD COLUMN last_vibration_at DATETIME NULL AFTER vibration_alarm");
    }

    // 5. Pastikan kolom alert_type di tabel alerts mendukung VIBRATION_ALERT
    $pdo->exec("ALTER TABLE alerts MODIFY COLUMN alert_type VARCHAR(40) NOT NULL");

    // 6. Pastikan view access_logs tersedia
    $pdo->exec("
        CREATE OR REPLACE VIEW access_logs AS
        SELECT id, device_id, user_id, username_snapshot, method, action, result, details, created_at
        FROM logs
    ");
}

/**
 * Check if initial setup has been completed
 */
function setup_done(): bool {
    try {
        return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0
            && (int)db()->query('SELECT COUNT(*) FROM devices')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * CSRF Token generator & validator
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token = null): void {
    $provided = $token ?? ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals(csrf_token(), (string)$provided)) {
        json_out(['ok' => false, 'error' => 'Sesi keamanan (CSRF) tidak valid. Muat ulang halaman.'], 403);
    }
}

/**
 * Parse incoming JSON body or POST array
 */
function json_in(): array {
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return $_POST ?: [];
}

/**
 * Output JSON response and terminate
 */
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Compute deterministic SHA-256 hash of a 6-digit PIN for ESP32 offline NVS sync
 */
function compute_offline_pin_sha256(string $pin): string {
    return hash('sha256', 'SMARTBOX_V3_PIN:' . trim($pin));
}

/**
 * Authenticate ESP32 request using device_code and api_key
 */
function device_auth(array $in): array {
    $code = trim((string)($in['device_code'] ?? ($_SERVER['HTTP_X_DEVICE_CODE'] ?? '')));
    $key  = trim((string)($in['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '')));

    if ($code === '' || $key === '') {
        json_out([
            'ok'             => false,
            'device_matched' => false,
            'error'          => 'device_code dan api_key wajib dikirim dari ESP32'
        ], 401);
    }

    $st = db()->prepare('SELECT * FROM devices WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $device = $st->fetch();

    if (!$device) {
        json_out([
            'ok'             => false,
            'device_matched' => false,
            'error'          => 'DEVICE_CODE (' . $code . ') tidak terdaftar di Web Server'
        ], 401);
    }

    $hashMatch = hash_equals((string)$device['api_key_hash'], hash('sha256', $key));
    $rawMatch  = ($device['api_key'] !== '' && hash_equals((string)$device['api_key'], $key));

    if (!$hashMatch && !$rawMatch) {
        json_out([
            'ok'             => false,
            'device_matched' => false,
            'error'          => 'DEVICE_API_KEY tidak cocok dengan Web Server'
        ], 401);
    }

    return $device;
}

/**
 * Record an event in the `logs` (and `access_logs`) table
 */
function log_event(
    ?int $deviceId,
    ?int $userId,
    ?string $username,
    string $action,
    string $result = 'INFO',
    ?string $details = null,
    string $method = 'SYSTEM'
): void {
    $st = db()->prepare(
        'INSERT INTO logs (device_id, user_id, username_snapshot, method, action, result, details)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$deviceId, $userId, $username, $method, $action, $result, $details]);
}

/**
 * Create an active security/sensor alert if an identical active alert doesn't already exist
 */
function trigger_alert(int $deviceId, string $type, string $title, string $description): void {
    $check = db()->prepare(
        "SELECT id FROM alerts WHERE device_id = ? AND alert_type = ? AND status = 'ACTIVE' LIMIT 1"
    );
    $check->execute([$deviceId, $type]);
    if ($check->fetchColumn()) {
        return;
    }

    $ins = db()->prepare(
        "INSERT INTO alerts (device_id, alert_type, title, description, status)
         VALUES (?, ?, ?, ?, 'ACTIVE')"
    );
    $ins->execute([$deviceId, $type, $title, $description]);
}

/**
 * Resolve active alerts of a specific type (or all types if null) for a device
 */
function resolve_alerts(int $deviceId, ?string $type = null): void {
    if ($type !== null) {
        $st = db()->prepare(
            "UPDATE alerts SET status = 'RESOLVED', resolved_at = NOW()
             WHERE device_id = ? AND alert_type = ? AND status = 'ACTIVE'"
        );
        $st->execute([$deviceId, $type]);
    } else {
        $st = db()->prepare(
            "UPDATE alerts SET status = 'RESOLVED', resolved_at = NOW()
             WHERE device_id = ? AND status = 'ACTIVE'"
        );
        $st->execute([$deviceId]);
    }
}
