<?php
/**
 * Smart Secure Storage Box - Endpoint Verifikasi PIN Keypad ESP32 per Device
 * Konsep: One Account, Multiple Devices, Different Roles (Tabel device_users)
 * POST /api/verify.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$in  = json_in();
$dev = device_auth($in);
$pin = trim((string)($in['pin'] ?? ''));

$deviceId    = (int)$dev['id'];
$maxAttempts = max(1, (int)($dev['max_pin_attempts'] ?? 3));

if (!preg_match('/^[0-9]{6}$/', $pin)) {
    json_out([
        'authorized' => false,
        'error'      => 'PIN harus terdiri dari 6 angka'
    ], 422);
}

$pdo = db();

// Pastikan baris pin_attempts tersedia untuk perangkat ini
$pdo->prepare('INSERT IGNORE INTO pin_attempts (device_id, failures) VALUES (?, 0)')->execute([$deviceId]);

$attStmt = $pdo->prepare(
    'SELECT failures, blocked_until,
            (blocked_until IS NOT NULL AND blocked_until > NOW()) AS is_blocked,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), blocked_until)) AS remaining_sec
     FROM pin_attempts WHERE device_id = ?'
);
$attStmt->execute([$deviceId]);
$att = $attStmt->fetch() ?: ['failures' => 0, 'is_blocked' => 0, 'remaining_sec' => 0];

if ((int)$att['is_blocked'] === 1) {
    $rem = (int)$att['remaining_sec'] ?: 30;
    log_event($deviceId, null, null, 'PIN_BLOCKED', 'FAILED', "Keypad masih terkunci sementara ({$rem} detik tersisa)", 'KEYPAD');
    json_out([
        'authorized'    => false,
        'error'         => "Terlalu banyak percobaan. Tunggu {$rem} detik.",
        'remaining_sec' => $rem
    ], 429);
}

// Cari relasi user yang APPROVED pada device ini dan memiliki PIN yang cocok
$relStmt = $pdo->prepare(
    "SELECT u.id, u.username, u.full_name, du.role, du.pin_hash
     FROM device_users du
     INNER JOIN users u ON u.id = du.user_id
     WHERE du.device_id = ?
       AND du.status = 'APPROVED'
       AND u.active = 1
       AND du.pin_hash IS NOT NULL"
);
$relStmt->execute([$deviceId]);

$matchedUser = null;
foreach ($relStmt as $row) {
    if (password_verify($pin, (string)$row['pin_hash'])) {
        $matchedUser = $row;
        break;
    }
}

if ($matchedUser) {
    $pdo->prepare('UPDATE pin_attempts SET failures = 0, blocked_until = NULL WHERE device_id = ?')
        ->execute([$deviceId]);

    $pdo->prepare('UPDATE devices SET locked = 0, security_alarm = 0, last_seen = NOW() WHERE id = ?')
        ->execute([$deviceId]);

    resolve_alerts($deviceId, 'FAILED_ACCESS');

    log_event(
        $deviceId,
        (int)$matchedUser['id'],
        (string)$matchedUser['username'],
        'PIN_SUCCESS',
        'SUCCESS',
        'Akses buka kunci via Keypad 4x4 berhasil pada ' . $dev['code'] . ' (' . $matchedUser['role'] . ')',
        'KEYPAD'
    );

    json_out([
        'authorized' => true,
        'username'   => $matchedUser['username'],
        'full_name'  => $matchedUser['full_name'],
        'role'       => $matchedUser['role']
    ]);
}

// Jika PIN salah: tambah counter kegagalan pada device ini
$fail = (int)$att['failures'] + 1;

if ($fail >= $maxAttempts) {
    $pdo->prepare(
        'UPDATE pin_attempts
         SET failures = ?, blocked_until = DATE_ADD(NOW(), INTERVAL 30 SECOND)
         WHERE device_id = ?'
    )->execute([$fail, $deviceId]);

    $pdo->prepare('UPDATE devices SET security_alarm = 1, last_seen = NOW() WHERE id = ?')
        ->execute([$deviceId]);

    trigger_alert(
        $deviceId,
        'FAILED_ACCESS',
        "PIN Salah {$fail}x Berturut-turut ({$dev['code']})",
        "Terdeteksi {$fail} kali kesalahan input PIN berturut-turut pada Keypad {$dev['code']}. Buzzer peringatan & lockout 30 detik diaktifkan."
    );

    log_event(
        $deviceId,
        null,
        null,
        'PIN_LOCKOUT',
        'FAILED',
        "PIN salah {$fail}x berturut-turut pada {$dev['code']} — Buzzer & Lockout 30 detik aktif",
        'KEYPAD'
    );
} else {
    $pdo->prepare('UPDATE pin_attempts SET failures = ? WHERE device_id = ?')
        ->execute([$fail, $deviceId]);

    $pdo->prepare('UPDATE devices SET last_seen = NOW() WHERE id = ?')
        ->execute([$deviceId]);

    log_event(
        $deviceId,
        null,
        null,
        'PIN_FAILED',
        'FAILED',
        "Percobaan PIN keypad salah ke-{$fail} dari {$maxAttempts} pada {$dev['code']}",
        'KEYPAD'
    );
}

json_out([
    'authorized'      => false,
    'error'           => 'PIN salah',
    'failed_attempts' => $fail,
    'max_attempts'    => $maxAttempts,
    'alarm_triggered' => ($fail >= $maxAttempts)
], 403);
