<?php
/**
 * Smart Secure Storage Box - Authentication & Multi-Device Role Guard
 * Konsep: One Account, Multiple Devices, Different Roles (device_users)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Return current logged-in user from database (or null)
 */
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null && (int)$cachedUser['id'] === (int)$_SESSION['user_id']) {
        return $cachedUser;
    }

    $st = db()->prepare(
        'SELECT id, username, full_name, active, created_at
         FROM users WHERE id = ? AND active = 1 LIMIT 1'
    );
    $st->execute([(int)$_SESSION['user_id']]);
    $user = $st->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    $cachedUser = $user;
    return $user;
}

/**
 * Return all device memberships / access requests for a user
 */
function get_user_device_relations(int $userId): array {
    $st = db()->prepare(
        'SELECT du.id AS relation_id,
                du.device_id,
                du.role,
                du.status,
                du.status AS access_status,
                du.can_view_logs,
                du.request_note,
                (du.pin_hash IS NOT NULL AND du.pin_hash != "") AS has_pin,
                du.created_at AS joined_at,
                d.code AS device_code,
                d.name AS device_name,
                d.locked,
                d.door_closed,
                d.vibration_alarm,
                d.security_alarm,
                d.door_alarm,
                d.last_seen,
                (d.last_seen IS NOT NULL AND TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) >= -2 AND TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) <= 6) AS is_online
         FROM device_users du
         INNER JOIN devices d ON d.id = du.device_id
         WHERE du.user_id = ?
         ORDER BY (du.status = \'APPROVED\') DESC, du.device_id ASC'
    );
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * Get the user's active APPROVED device context (device info + role + PIN status on that device)
 */
function get_active_device_context(int $userId, ?int $requestedDeviceId = null): ?array {
    $pdo = db();

    if ($requestedDeviceId !== null && $requestedDeviceId > 0) {
        $_SESSION['active_device_id'] = $requestedDeviceId;
    }

    $preferredDeviceId = (int)($_SESSION['active_device_id'] ?? 0);

    if ($preferredDeviceId > 0) {
        $st = $pdo->prepare(
            'SELECT d.*,
                    TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS seconds_since_seen,
                    du.id AS relation_id,
                    du.role AS device_role,
                    du.status AS access_status,
                    du.can_view_logs,
                    (du.pin_hash IS NOT NULL AND du.pin_hash != "") AS has_pin
             FROM device_users du
             INNER JOIN devices d ON d.id = du.device_id
             WHERE du.user_id = ? AND du.device_id = ? AND du.status = \'APPROVED\'
             LIMIT 1'
        );
        $st->execute([$userId, $preferredDeviceId]);
        $ctx = $st->fetch();
        if ($ctx) {
            return $ctx;
        }
    }

    // Fallback ke device APPROVED pertama milik user ini
    $st = $pdo->prepare(
        'SELECT d.*,
                TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS seconds_since_seen,
                du.id AS relation_id,
                du.role AS device_role,
                du.status AS access_status,
                du.can_view_logs,
                (du.pin_hash IS NOT NULL AND du.pin_hash != "") AS has_pin
         FROM device_users du
         INNER JOIN devices d ON d.id = du.device_id
         WHERE du.user_id = ? AND du.status = \'APPROVED\'
         ORDER BY d.id ASC
         LIMIT 1'
    );
    $st->execute([$userId]);
    $ctx = $st->fetch();

    if ($ctx) {
        $_SESSION['active_device_id'] = (int)$ctx['id'];
        return $ctx;
    }

    return null;
}

/**
 * Require user to be logged in for Web pages
 */
function require_login(): array {
    if (!setup_done()) {
        header('Location: setup.php');
        exit;
    }

    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

/**
 * Require logged-in user for JSON API calls
 */
function require_api_user(): array {
    $user = current_user();
    if (!$user) {
        json_out(['ok' => false, 'error' => 'Sesi berakhir. Silakan login kembali.'], 401);
    }
    return $user;
}

/**
 * Require ADMIN role on a specific device
 */
function require_device_admin(int $userId, int $deviceId): array {
    $st = db()->prepare(
        'SELECT * FROM device_users
         WHERE user_id = ? AND device_id = ? AND status = \'APPROVED\' AND role = \'ADMIN\'
         LIMIT 1'
    );
    $st->execute([$userId, $deviceId]);
    $rel = $st->fetch();
    if (!$rel) {
        json_out(['ok' => false, 'error' => 'Akses ditolak. Anda bukan ADMIN pada Storage Box ini.'], 403);
    }
    return $rel;
}

/**
 * Authenticate login with username & password
 */
function attempt_login(string $username, string $password): array {
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([trim($username)]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return ['ok' => false, 'message' => 'Username atau password salah.'];
    }

    if ((int)$user['active'] !== 1) {
        return ['ok' => false, 'message' => 'Akun ini sedang dinonaktifkan.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    $ctx = get_active_device_context((int)$user['id']);
    $devId = $ctx ? (int)$ctx['id'] : null;
    $roleLabel = $ctx ? $ctx['device_role'] : 'UNASSIGNED';

    log_event($devId, (int)$user['id'], $user['username'], 'WEB_LOGIN', 'SUCCESS', "Login ke Web Dashboard (Role aktif: {$roleLabel})", 'WEB');

    return ['ok' => true, 'user' => $user];
}
