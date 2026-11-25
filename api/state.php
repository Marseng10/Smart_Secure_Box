<?php
/**
 * Smart Secure Storage Box - Endpoint Live State Web Dashboard (Multi-Device & Per-Device Role)
 * GET /api/state.php?device_id=...
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = require_api_user();
$pdo  = db();

$requestedDeviceId = isset($_GET['device_id']) && is_numeric($_GET['device_id']) ? (int)$_GET['device_id'] : null;

$myDevices = get_user_device_relations((int)$user['id']);
$ctx       = get_active_device_context((int)$user['id'], $requestedDeviceId);

if (!$ctx) {
    // Pengguna belum memiliki device yang berstatus APPROVED
    json_out([
        'ok'             => true,
        'has_device'     => false,
        'my_devices'     => $myDevices,
        'device'         => null,
        'command'        => null,
        'pin_lockout'    => ['failures' => 0, 'is_blocked' => false, 'remaining_sec' => 0],
        'alerts'         => [],
        'logs'           => [],
        'device_members' => [],
        'me'             => [
            'id'            => (int)$user['id'],
            'username'      => $user['username'],
            'full_name'     => $user['full_name'],
            'role'          => 'NONE',
            'has_pin'       => false,
            'can_view_logs' => false,
        ],
        'server_time'    => date('H:i:s')
    ]);
}

$deviceId    = (int)$ctx['id'];
$deviceRole  = (string)$ctx['device_role']; // 'ADMIN' or 'USER'
$isAdmin     = ($deviceRole === 'ADMIN');
$canViewLogs = $isAdmin || ((int)$ctx['can_view_logs'] === 1);

$secSeen  = $ctx['seconds_since_seen'] !== null ? (int)$ctx['seconds_since_seen'] : null;
$isOnline = ($secSeen !== null && $secSeen >= -2 && $secSeen <= 6);

// Perintah jarak jauh terakhir untuk device ini
$cmdStmt = $pdo->prepare(
    'SELECT c.id, c.command_type, c.status, c.reason, c.result_message, c.created_at, c.completed_at,
            u.username AS requested_by_username
     FROM commands c
     LEFT JOIN users u ON u.id = c.requested_by
     WHERE c.device_id = ?
     ORDER BY c.id DESC
     LIMIT 1'
);
$cmdStmt->execute([$deviceId]);
$lastCommand = $cmdStmt->fetch() ?: null;

// Status lockout PIN keypad untuk device ini
$attStmt = $pdo->prepare(
    'SELECT failures,
            (blocked_until IS NOT NULL AND blocked_until > NOW()) AS is_blocked,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), blocked_until)) AS remaining_sec
     FROM pin_attempts
     WHERE device_id = ?'
);
$attStmt->execute([$deviceId]);
$pinLockout = $attStmt->fetch() ?: ['failures' => 0, 'is_blocked' => 0, 'remaining_sec' => 0];

// Peringatan keamanan & sensor untuk device ini
$alertsStmt = $pdo->prepare(
    'SELECT id, alert_type, title, description, status, created_at, resolved_at
     FROM alerts
     WHERE device_id = ?
     ORDER BY (status = \'ACTIVE\') DESC, id DESC
     LIMIT 25'
);
$alertsStmt->execute([$deviceId]);
$alerts = $alertsStmt->fetchAll();

// Log aktivitas sesuai hak akses (Admin atau User yang diberi izin melihat seluruh log device; selain itu hanya log miliknya)
if ($canViewLogs) {
    $logsStmt = $pdo->prepare(
        'SELECT id, COALESCE(username_snapshot, "SYSTEM") AS username, method, action, result, details, created_at
         FROM logs
         WHERE device_id = ?
         ORDER BY id DESC
         LIMIT 40'
    );
    $logsStmt->execute([$deviceId]);
} else {
    $logsStmt = $pdo->prepare(
        'SELECT id, COALESCE(username_snapshot, "SYSTEM") AS username, method, action, result, details, created_at
         FROM logs
         WHERE device_id = ? AND user_id = ?
         ORDER BY id DESC
         LIMIT 40'
    );
    $logsStmt->execute([$deviceId, (int)$user['id']]);
}
$logs = $logsStmt->fetchAll();

// Daftar anggota & permintaan akses pada device ini (Khusus ADMIN device ini)
$deviceMembers = [];
$pendingCount  = 0;
if ($isAdmin) {
    $memStmt = $pdo->prepare(
        'SELECT du.id AS relation_id,
                du.user_id,
                du.device_id,
                du.role,
                du.status,
                du.can_view_logs,
                du.request_note,
                (du.pin_hash IS NOT NULL AND du.pin_hash != "") AS has_pin,
                du.created_at,
                u.username,
                u.full_name,
                u.active AS account_active
         FROM device_users du
         INNER JOIN users u ON u.id = du.user_id
         WHERE du.device_id = ?
         ORDER BY FIELD(du.status, \'PENDING\', \'APPROVED\', \'REVOKED\', \'REJECTED\'),
                  (du.role = \'ADMIN\') DESC,
                  du.id ASC'
    );
    $memStmt->execute([$deviceId]);
    $deviceMembers = $memStmt->fetchAll();

    foreach ($deviceMembers as $m) {
        if ($m['status'] === 'PENDING') {
            $pendingCount++;
        }
    }
}

$deviceOut = [
    'id'                 => $deviceId,
    'code'               => $ctx['code'],
    'name'               => $ctx['name'],
    'online'             => $isOnline,
    'seconds_since_seen' => $secSeen,
    'locked'             => $isOnline ? (bool)$ctx['locked'] : null,
    'door_closed'        => $isOnline ? (bool)$ctx['door_closed'] : null,
    'door_open_seconds'  => $isOnline ? (int)$ctx['door_open_seconds'] : 0,
    'door_alarm'         => $isOnline ? (bool)$ctx['door_alarm'] : false,
    'vibration_alarm'    => $isOnline ? (bool)($ctx['vibration_alarm'] ?? false) : false,
    'last_vibration_at'  => $ctx['last_vibration_at'] ?? null,
    'security_alarm'     => $isOnline ? (bool)$ctx['security_alarm'] : false,
    'temperature'        => ($isOnline && $ctx['temperature'] !== null) ? (float)$ctx['temperature'] : null,
    'humidity'           => ($isOnline && $ctx['humidity'] !== null) ? (float)$ctx['humidity'] : null,
    'rssi'               => ($isOnline && $ctx['rssi'] !== null) ? (int)$ctx['rssi'] : null,
    'last_seen'          => $ctx['last_seen'],
    'door_timeout_sec'   => (int)$ctx['door_timeout_sec'],
    'auto_lock_sec'      => (int)$ctx['auto_lock_sec'],
    'max_pin_attempts'   => (int)$ctx['max_pin_attempts'],
    'temp_threshold'     => (float)$ctx['temp_threshold'],
    'humidity_threshold' => (float)$ctx['humidity_threshold'],
];

if ($isAdmin) {
    $deviceOut['api_key']            = $ctx['api_key'];
    $deviceOut['invite_code']        = $ctx['invite_code'];
    $deviceOut['invite_expires_at']  = $ctx['invite_expires_at'];
    $deviceOut['invite_single_use']  = (bool)$ctx['invite_single_use'];
    $deviceOut['pending_requests']   = $pendingCount;
}

json_out([
    'ok'             => true,
    'has_device'     => true,
    'my_devices'     => $myDevices,
    'device'         => $deviceOut,
    'command'        => $lastCommand,
    'pin_lockout'    => [
        'failures'      => (int)$pinLockout['failures'],
        'is_blocked'    => (bool)$pinLockout['is_blocked'],
        'remaining_sec' => (int)$pinLockout['remaining_sec'],
    ],
    'alerts'         => $alerts,
    'logs'           => $logs,
    'device_members' => $deviceMembers,
    'me'             => [
        'id'            => (int)$user['id'],
        'username'      => $user['username'],
        'full_name'     => $user['full_name'],
        'role'          => $deviceRole,
        'has_pin'       => (bool)$ctx['has_pin'],
        'can_view_logs' => $canViewLogs,
    ],
    'server_time'    => date('H:i:s')
]);
