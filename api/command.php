<?php
/**
 * Smart Secure Storage Box - Endpoint Kontrol Perangkat, Multi-Device, Persetujuan Akses, & PIN per Device
 * Konsep: One Account, Multiple Devices, Different Roles (device_users)
 * POST /api/command.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user   = require_api_user();
$userId = (int)$user['id'];
$in     = json_in();
csrf_check((string)($in['csrf'] ?? ''));

$pdo    = db();
$action = strtoupper(trim((string)($in['command'] ?? ($in['action'] ?? ''))));

// =========================================================================
// 1. AKSI GLOBAL AKUN (TIDAK BERGANTUNG PADA DEVICE AKTIF)
// =========================================================================

// A. Pindah Storage Box Aktif (Device Switcher)
if ($action === 'SWITCH_DEVICE') {
    $targetDeviceId = (int)($in['device_id'] ?? 0);
    $ctx = get_active_device_context($userId, $targetDeviceId);
    if (!$ctx || (int)$ctx['id'] !== $targetDeviceId) {
        json_out(['ok' => false, 'error' => 'Anda belum memiliki akses APPROVED pada Storage Box tersebut.'], 403);
    }
    json_out([
        'ok'      => true,
        'message' => "Beralih ke {$ctx['name']} ({$ctx['code']}) sebagai {$ctx['device_role']}."
    ]);
}

// B. Ajukan Permintaan Akses ke Storage Box Menggunakan Kode Undangan / Kode Unik
if ($action === 'REQUEST_DEVICE_ACCESS') {
    $codeInput = strtoupper(trim((string)($in['invite_code'] ?? ($in['code'] ?? ''))));
    $note      = trim((string)($in['note'] ?? 'Permintaan akses Storage Box'));

    if ($codeInput === '') {
        json_out(['ok' => false, 'error' => 'Masukkan Kode Undangan atau Kode Unik Storage Box.'], 422);
    }

    // Cari berdasarkan invite_code atau code perangkat
    $st = $pdo->prepare('SELECT * FROM devices WHERE invite_code = ? OR code = ? LIMIT 1');
    $st->execute([$codeInput, $codeInput]);
    $targetDev = $st->fetch();

    if (!$targetDev) {
        json_out(['ok' => false, 'error' => 'Kode Undangan / Storage Box tidak ditemukan.'], 404);
    }

    $targetDevId = (int)$targetDev['id'];
    $matchedByInvite = hash_equals(strtoupper((string)$targetDev['invite_code']), $codeInput);

    // Jika menggunakan invite_code, periksa masa berlaku
    if ($matchedByInvite && !empty($targetDev['invite_expires_at'])) {
        if (strtotime((string)$targetDev['invite_expires_at']) < time()) {
            json_out(['ok' => false, 'error' => 'Kode undangan ini sudah kedaluwarsa. Minta kode baru kepada Admin device.'], 422);
        }
    }

    // Cek apakah user sudah punya relasi dengan device ini
    $relSt = $pdo->prepare('SELECT * FROM device_users WHERE user_id = ? AND device_id = ? LIMIT 1');
    $relSt->execute([$userId, $targetDevId]);
    $existingRel = $relSt->fetch();

    if ($existingRel) {
        if ($existingRel['status'] === 'APPROVED') {
            $_SESSION['active_device_id'] = $targetDevId;
            json_out(['ok' => false, 'error' => 'Anda sudah terdaftar dan disetujui pada Storage Box ini.'], 422);
        }
        if ($existingRel['status'] === 'PENDING') {
            json_out(['ok' => false, 'error' => 'Permintaan akses Anda untuk Storage Box ini masih menunggu persetujuan Admin.'], 422);
        }

        // Jika sebelumnya REJECTED atau REVOKED, ajukan ulang menjadi PENDING
        $pdo->prepare("UPDATE device_users SET status = 'PENDING', request_note = ? WHERE id = ?")
            ->execute([$note, (int)$existingRel['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO device_users (user_id, device_id, role, status, can_view_logs, request_note)
             VALUES (?, ?, 'USER', 'PENDING', 1, ?)"
        )->execute([$userId, $targetDevId, $note]);
    }

    // Jika kode undangan bersifat sekali pakai (single-use) dan dipakai via invite_code, perbarui kode undangan
    if ($matchedByInvite && (int)$targetDev['invite_single_use'] === 1) {
        $newInvite = generate_invite_code((string)$targetDev['code']);
        $pdo->prepare("UPDATE devices SET invite_code = ?, invite_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?")
            ->execute([$newInvite, $targetDevId]);
    }

    log_event(
        $targetDevId,
        $userId,
        (string)$user['username'],
        'ACCESS_REQUESTED',
        'INFO',
        "Mengajukan permintaan akses ke {$targetDev['code']} ({$note})",
        'WEB'
    );

    json_out([
        'ok'      => true,
        'message' => "Permintaan akses ke {$targetDev['name']} ({$targetDev['code']}) berhasil dikirim! Menunggu persetujuan Admin device."
    ]);
}

// C. Pendaftaran / Klaim Storage Box Baru via Kode QR & Bluetooth (Otomatis Menjadi ADMIN pada Device Tersebut)
if ($action === 'CREATE_NEW_DEVICE' || $action === 'CLAIM_DEVICE_QR') {
    $rawCode = trim((string)($in['device_code'] ?? ($in['qr_code'] ?? '')));
    $devName = trim((string)($in['device_name'] ?? ''));
    $initPin = trim((string)($in['pin'] ?? ''));
    $providedApiKey = trim((string)($in['api_key'] ?? ''));

    // Tolak jika isi QR berupa URL (sesuai spesifikasi: QR hanya berisi kode perangkat)
    if (preg_match('/^https?:\/\//i', $rawCode)) {
        json_out(['ok' => false, 'error' => 'QR Code hanya boleh berisi Kode Perangkat (bukan URL).'], 422);
    }

    // Dukung format kode murni "BOX-01" atau "BOX-01|APIKEY"
    if (str_contains($rawCode, '|')) {
        [$partCode, $partKey] = array_pad(explode('|', $rawCode, 2), 2, '');
        $devCode = strtoupper(trim($partCode));
        if ($providedApiKey === '' && trim($partKey) !== '') {
            $providedApiKey = trim($partKey);
        }
    } else {
        $devCode = strtoupper($rawCode);
    }

    if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $devCode)) {
        json_out(['ok' => false, 'error' => 'Kode QR / Kode Device (contoh: BOX-01) harus 3–30 karakter huruf/angka/strip.'], 422);
    }
    if ($devName === '') {
        $devName = 'Smart Secure Box (' . $devCode . ')';
    }
    if ($initPin !== '' && !preg_match('/^[0-9]{6}$/', $initPin)) {
        json_out(['ok' => false, 'error' => 'PIN awal harus terdiri dari tepat 6 angka.'], 422);
    }

    $pinHash   = $initPin !== '' ? password_hash($initPin, PASSWORD_DEFAULT) : null;
    $pinSha256 = $initPin !== '' ? compute_offline_pin_sha256($initPin) : null;

    // Cek apakah device_code sudah ada di tabel devices
    $existingDevSt = $pdo->prepare('SELECT * FROM devices WHERE code = ? LIMIT 1');
    $existingDevSt->execute([$devCode]);
    $existingDev = $existingDevSt->fetch();

    if ($existingDev) {
        $existingDevId = (int)$existingDev['id'];
        // Cek apakah sudah ada ADMIN pada device ini di tabel device_users
        $adminCheck = $pdo->prepare("SELECT user_id FROM device_users WHERE device_id = ? AND role = 'ADMIN' AND status = 'APPROVED' LIMIT 1");
        $adminCheck->execute([$existingDevId]);
        $currentAdminId = (int)$adminCheck->fetchColumn();

        if ($currentAdminId > 0 && $currentAdminId !== $userId) {
            json_out([
                'ok'    => false,
                'error' => "Storage Box {$devCode} sudah memiliki Admin. Gunakan fitur 'Gabung ke Storage Box' dengan Kode Undangan untuk meminta akses."
            ], 422);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE devices SET name = ? WHERE id = ?')->execute([$devName, $existingDevId]);
            $pdo->prepare(
                "INSERT INTO device_users (user_id, device_id, role, pin_hash, pin_sha256, status, can_view_logs, request_note)
                 VALUES (?, ?, 'ADMIN', ?, ?, 'APPROVED', 1, 'Pendaftaran Box via QR & Bluetooth')
                 ON DUPLICATE KEY UPDATE
                    role = 'ADMIN',
                    status = 'APPROVED',
                    can_view_logs = 1,
                    pin_hash = COALESCE(VALUES(pin_hash), pin_hash),
                    pin_sha256 = COALESCE(VALUES(pin_sha256), pin_sha256)"
            )->execute([$userId, $existingDevId, $pinHash, $pinSha256]);
            $pdo->commit();

            $_SESSION['active_device_id'] = $existingDevId;
            log_event($existingDevId, $userId, (string)$user['username'], 'DEVICE_CLAIMED_QR', 'SUCCESS', "Mendaftarkan/mengonfigurasi {$devName} ({$devCode}) sebagai ADMIN", 'WEB');

            json_out([
                'ok'          => true,
                'device_id'   => $existingDevId,
                'device_code' => $devCode,
                'api_key'     => $existingDev['api_key'],
                'message'     => "Storage Box {$devName} ({$devCode}) berhasil dihubungkan! Anda ditetapkan sebagai ADMIN pada box ini."
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'error' => 'Gagal mengaitkan Storage Box.'], 500);
        }
    }

    $apiKey     = $providedApiKey !== '' ? $providedApiKey : bin2hex(random_bytes(24));
    $inviteCode = generate_invite_code($devCode);

    $pdo->beginTransaction();
    try {
        $insDev = $pdo->prepare(
            "INSERT INTO devices (code, name, invite_code, invite_expires_at, invite_single_use, api_key, api_key_hash, locked, door_closed)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), 1, ?, ?, 1, 1)"
        );
        $insDev->execute([$devCode, $devName, $inviteCode, $apiKey, hash('sha256', $apiKey)]);
        $newDevId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT IGNORE INTO pin_attempts (device_id, failures) VALUES (?, 0)')
            ->execute([$newDevId]);

        $pdo->prepare(
            "INSERT INTO device_users (user_id, device_id, role, pin_hash, pin_sha256, status, can_view_logs, request_note)
             VALUES (?, ?, 'ADMIN', ?, ?, 'APPROVED', 1, 'Pendaftaran Box Pertama via QR & Bluetooth')"
        )->execute([$userId, $newDevId, $pinHash, $pinSha256]);

        $pdo->commit();
        $_SESSION['active_device_id'] = $newDevId;

        log_event($newDevId, $userId, (string)$user['username'], 'DEVICE_CREATED', 'SUCCESS', "Mendaftarkan Storage Box baru via QR: {$devName} ({$devCode})", 'WEB');

        json_out([
            'ok'          => true,
            'device_id'   => $newDevId,
            'device_code' => $devCode,
            'api_key'     => $apiKey,
            'message'     => "Storage Box {$devName} ({$devCode}) berhasil didaftarkan! Anda adalah ADMIN pada box ini."
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => "Kode perangkat {$devCode} sudah digunakan."], 422);
    }
}

// D. Update Profil Global Akun (Nama Lengkap & Password Login)
if ($action === 'UPDATE_PROFILE') {
    $fullName    = trim((string)($in['full_name'] ?? ''));
    $newPassword = (string)($in['new_password'] ?? '');

    if ($fullName === '') {
        json_out(['ok' => false, 'error' => 'Nama lengkap tidak boleh kosong.'], 422);
    }

    if ($newPassword !== '') {
        if (strlen($newPassword) < 6) {
            json_out(['ok' => false, 'error' => 'Password baru minimal 6 karakter.'], 422);
        }
        $pdo->prepare('UPDATE users SET full_name = ?, password_hash = ? WHERE id = ?')
            ->execute([$fullName, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    } else {
        $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')
            ->execute([$fullName, $userId]);
    }

    json_out(['ok' => true, 'message' => 'Profil akun berhasil diperbarui.']);
}

// =========================================================================
// 2. AKSI PADA STORAGE BOX YANG SEDANG AKTIF (MEMBUTUHKAN AKSES APPROVED)
// =========================================================================
$requestedDevId = isset($in['device_id']) && is_numeric($in['device_id']) ? (int)$in['device_id'] : null;
$ctx = get_active_device_context($userId, $requestedDevId);

if (!$ctx) {
    json_out(['ok' => false, 'error' => 'Anda belum terhubung ke Storage Box mana pun yang berstatus APPROVED.'], 403);
}

$deviceId = (int)$ctx['id'];

// E. Atur / Ubah PIN 6-Digit untuk Storage Box Aktif Ini (Berbeda per Device!)
if ($action === 'UPDATE_OWN_PIN') {
    $pin = trim((string)($in['pin'] ?? ($in['new_pin'] ?? '')));

    if (!preg_match('/^[0-9]{6}$/', $pin)) {
        json_out(['ok' => false, 'error' => 'PIN harus terdiri dari tepat 6 angka (0-9).'], 422);
    }

    // Pastikan PIN tidak bentrok dengan user lain pada DEVICE YANG SAMA
    $othersStmt = $pdo->prepare(
        "SELECT user_id, pin_hash
         FROM device_users
         WHERE device_id = ? AND status = 'APPROVED' AND pin_hash IS NOT NULL"
    );
    $othersStmt->execute([$deviceId]);
    foreach ($othersStmt as $other) {
        if ((int)$other['user_id'] !== $userId && password_verify($pin, (string)$other['pin_hash'])) {
            json_out([
                'ok'    => false,
                'error' => "PIN tersebut sudah dipakai oleh pengguna lain pada {$ctx['code']}. Gunakan kombinasi 6 angka berbeda."
            ], 422);
        }
    }

    $pinBcrypt = password_hash($pin, PASSWORD_DEFAULT);
    $pinSha256 = compute_offline_pin_sha256($pin);

    $pdo->prepare('UPDATE device_users SET pin_hash = ?, pin_sha256 = ? WHERE user_id = ? AND device_id = ?')
        ->execute([$pinBcrypt, $pinSha256, $userId, $deviceId]);

    log_event(
        $deviceId,
        $userId,
        (string)$user['username'],
        'PIN_UPDATED',
        'SUCCESS',
        "Memperbarui PIN Keypad 6-digit khusus untuk {$ctx['code']}",
        'WEB'
    );

    json_out([
        'ok'      => true,
        'message' => "PIN 6-digit Anda untuk {$ctx['name']} ({$ctx['code']}) berhasil disimpan & disinkronkan!"
    ]);
}

// =========================================================================
// 3. AKSI KHUSUS ADMIN PADA STORAGE BOX INI
// =========================================================================
require_device_admin($userId, $deviceId);

$isOnline = ($ctx['seconds_since_seen'] !== null && (int)$ctx['seconds_since_seen'] >= -2 && (int)$ctx['seconds_since_seen'] <= 6);

switch ($action) {
    // ---------------------------------------------------------------------
    // A. REMOTE LOCK / EMERGENCY ACCESS / ALARM BUZZER
    // ---------------------------------------------------------------------
    case 'LOCK':
    case 'EMERGENCY_UNLOCK':
    case 'ALARM_ON':
    case 'ALARM_OFF':
        $reason = trim((string)($in['reason'] ?? ''));

        if ($action === 'LOCK' && $isOnline && (int)$ctx['door_closed'] !== 1) {
            json_out([
                'ok'    => false,
                'error' => 'Pintu fisik masih terbuka! Tutup pintu kotak terlebih dahulu sebelum mengunci.'
            ], 409);
        }

        if ($action === 'EMERGENCY_UNLOCK' && $reason === '') {
            $reason = 'Akses darurat oleh Admin';
        }

        $pdo->prepare(
            "UPDATE commands
             SET status = 'FAILED', result_message = 'Digantikan oleh perintah baru', completed_at = NOW()
             WHERE device_id = ? AND status = 'PENDING'"
        )->execute([$deviceId]);

        $ins = $pdo->prepare(
            "INSERT INTO commands (device_id, command_type, status, requested_by, reason)
             VALUES (?, ?, 'PENDING', ?, ?)"
        );
        $ins->execute([$deviceId, $action, $userId, $reason ?: null]);
        $cmdId = (int)$pdo->lastInsertId();

        if ($action === 'EMERGENCY_UNLOCK') {
            $pdo->prepare('UPDATE devices SET security_alarm = 0 WHERE id = ?')->execute([$deviceId]);
            $pdo->prepare('UPDATE pin_attempts SET failures = 0, blocked_until = NULL WHERE device_id = ?')->execute([$deviceId]);
            trigger_alert(
                $deviceId,
                'EMERGENCY_OVERRIDE',
                "Emergency Access Diaktifkan ({$ctx['code']})",
                "Admin ({$user['username']}) membuka paksa kunci {$ctx['code']} via Emergency Access. Alasan: {$reason}"
            );
            log_event(
                $deviceId,
                $userId,
                (string)$user['username'],
                'EMERGENCY_UNLOCK',
                'SUCCESS',
                "Emergency Access (#{$cmdId}): {$reason}",
                'EMERGENCY'
            );
        } elseif ($action === 'ALARM_OFF') {
            $pdo->prepare('UPDATE devices SET security_alarm = 0, door_alarm = 0, vibration_alarm = 0 WHERE id = ?')->execute([$deviceId]);
            $pdo->prepare('UPDATE pin_attempts SET failures = 0, blocked_until = NULL WHERE device_id = ?')->execute([$deviceId]);
            resolve_alerts($deviceId);
            log_event(
                $deviceId,
                $userId,
                (string)$user['username'],
                'ALARM_RESET',
                'SUCCESS',
                "Admin mematikan buzzer & mereset lockout keamanan (#{$cmdId})",
                'REMOTE'
            );
        } else {
            log_event(
                $deviceId,
                $userId,
                (string)$user['username'],
                'REMOTE_' . $action,
                'INFO',
                "Perintah {$action} (#{$cmdId}) dikirim ke {$ctx['code']}",
                'REMOTE'
            );
        }

        json_out([
            'ok'         => true,
            'command_id' => $cmdId,
            'status'     => 'PENDING',
            'message'    => "Perintah {$action} berhasil dikirim ke {$ctx['code']}!"
        ]);

    // ---------------------------------------------------------------------
    // B. RESOLUSI PERINGATAN KEAMANAN (ALERTS)
    // ---------------------------------------------------------------------
    case 'RESOLVE_ALERT':
        $alertId = (int)($in['alert_id'] ?? 0);
        $pdo->prepare("UPDATE alerts SET status = 'RESOLVED', resolved_at = NOW() WHERE id = ? AND device_id = ?")
            ->execute([$alertId, $deviceId]);

        $remActive = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE device_id = {$deviceId} AND status = 'ACTIVE'")->fetchColumn();
        if ($remActive === 0) {
            $pdo->prepare('UPDATE devices SET security_alarm = 0, door_alarm = 0, vibration_alarm = 0 WHERE id = ?')->execute([$deviceId]);
            $pdo->prepare('UPDATE pin_attempts SET failures = 0, blocked_until = NULL WHERE device_id = ?')->execute([$deviceId]);
        }
        json_out(['ok' => true, 'message' => 'Peringatan keamanan ditandai selesai.']);

    case 'RESOLVE_ALL_ALERTS':
        resolve_alerts($deviceId);
        $pdo->prepare('UPDATE devices SET security_alarm = 0, door_alarm = 0, vibration_alarm = 0 WHERE id = ?')->execute([$deviceId]);
        $pdo->prepare('UPDATE pin_attempts SET failures = 0, blocked_until = NULL WHERE device_id = ?')->execute([$deviceId]);

        $pdo->prepare(
            "INSERT INTO commands (device_id, command_type, status, requested_by, reason)
             VALUES (?, 'ALARM_OFF', 'PENDING', ?, 'Reset seluruh peringatan')"
        )->execute([$deviceId, $userId]);

        log_event($deviceId, $userId, (string)$user['username'], 'ALERTS_RESOLVED', 'SUCCESS', 'Seluruh peringatan & buzzer direset oleh Admin', 'REMOTE');
        json_out(['ok' => true, 'message' => 'Alarm dimatikan & seluruh peringatan ditandai selesai.']);

    // ---------------------------------------------------------------------
    // C. PERSETUJUAN AKSES & MANAJEMEN ROLE USER PER DEVICE (device_users)
    // ---------------------------------------------------------------------
    case 'APPROVE_ACCESS_REQUEST':
        $relationId  = (int)($in['relation_id'] ?? 0);
        $assignRole  = strtoupper(trim((string)($in['role'] ?? 'USER'))) === 'ADMIN' ? 'ADMIN' : 'USER';
        $canViewLogs = isset($in['can_view_logs']) ? ((int)$in['can_view_logs'] ? 1 : 0) : 1;

        $pdo->prepare(
            "UPDATE device_users
             SET status = 'APPROVED', role = ?, can_view_logs = ?
             WHERE id = ? AND device_id = ?"
        )->execute([$assignRole, $canViewLogs, $relationId, $deviceId]);

        log_event($deviceId, $userId, (string)$user['username'], 'ACCESS_APPROVED', 'SUCCESS', "Menyetujui akses relasi #{$relationId} sebagai {$assignRole}", 'WEB');
        json_out(['ok' => true, 'message' => "Permintaan akses disetujui dengan role {$assignRole}."]);

    case 'REJECT_ACCESS_REQUEST':
        $relationId = (int)($in['relation_id'] ?? 0);
        $pdo->prepare("UPDATE device_users SET status = 'REJECTED' WHERE id = ? AND device_id = ?")
            ->execute([$relationId, $deviceId]);

        log_event($deviceId, $userId, (string)$user['username'], 'ACCESS_REJECTED', 'INFO', "Menolak permintaan akses relasi #{$relationId}", 'WEB');
        json_out(['ok' => true, 'message' => 'Permintaan akses telah ditolak.']);

    case 'REVOKE_DEVICE_ACCESS':
        $relationId = (int)($in['relation_id'] ?? 0);
        $relCheck   = $pdo->prepare('SELECT user_id FROM device_users WHERE id = ? AND device_id = ? LIMIT 1');
        $relCheck->execute([$relationId, $deviceId]);
        $targetUserId = (int)$relCheck->fetchColumn();

        if ($targetUserId === $userId) {
            json_out(['ok' => false, 'error' => 'Anda tidak dapat mencabut akses Anda sendiri pada device ini.'], 422);
        }

        // Mencabut akses hanya pada device ini; akses ke device lain tidak terpengaruh!
        $pdo->prepare("UPDATE device_users SET status = 'REVOKED' WHERE id = ? AND device_id = ?")
            ->execute([$relationId, $deviceId]);

        log_event($deviceId, $userId, (string)$user['username'], 'ACCESS_REVOKED', 'INFO', "Mencabut akses user ID #{$targetUserId} dari {$ctx['code']}", 'WEB');
        json_out(['ok' => true, 'message' => "Akses pengguna ke {$ctx['code']} telah dicabut (Revoked). Aksesnya di device lain tidak terpengaruh."]);

    case 'RESTORE_DEVICE_ACCESS':
        $relationId = (int)($in['relation_id'] ?? 0);
        $pdo->prepare("UPDATE device_users SET status = 'APPROVED' WHERE id = ? AND device_id = ?")
            ->execute([$relationId, $deviceId]);

        log_event($deviceId, $userId, (string)$user['username'], 'ACCESS_RESTORED', 'SUCCESS', "Mengaktifkan kembali akses relasi #{$relationId} pada {$ctx['code']}", 'WEB');
        json_out(['ok' => true, 'message' => 'Akses pengguna ke Storage Box ini telah diaktifkan kembali.']);

    case 'SAVE_USER':
    case 'CREATE_USER':
        // Admin dapat menambahkan akun baru / mengaitkan akun yang sudah ada ke device ini, atau mengedit role & PIN per device
        $relationId  = (int)($in['relation_id'] ?? 0);
        $username    = trim((string)($in['username'] ?? ''));
        $fullName    = trim((string)($in['full_name'] ?? ''));
        $password    = (string)($in['password'] ?? '');
        $role        = strtoupper(trim((string)($in['role'] ?? 'USER'))) === 'ADMIN' ? 'ADMIN' : 'USER';
        $pin         = trim((string)($in['pin'] ?? ''));
        $canViewLogs = isset($in['can_view_logs']) ? ((int)$in['can_view_logs'] ? 1 : 0) : 1;
        $status      = in_array(strtoupper((string)($in['status'] ?? 'APPROVED')), ['APPROVED', 'PENDING', 'REVOKED', 'REJECTED'], true)
            ? strtoupper((string)$in['status'])
            : 'APPROVED';

        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            json_out(['ok' => false, 'error' => 'Username harus 3–30 karakter (huruf, angka, atau underscore).'], 422);
        }

        // Cek apakah PIN 6-digit diisi dan valid
        $pinHash   = null;
        $pinSha256 = null;
        if ($pin !== '') {
            if (!preg_match('/^[0-9]{6}$/', $pin)) {
                json_out(['ok' => false, 'error' => 'PIN harus terdiri dari tepat 6 angka.'], 422);
            }
            $others = $pdo->prepare("SELECT id, pin_hash FROM device_users WHERE device_id = ? AND status = 'APPROVED' AND pin_hash IS NOT NULL");
            $others->execute([$deviceId]);
            foreach ($others as $other) {
                if ((int)$other['id'] !== $relationId && password_verify($pin, (string)$other['pin_hash'])) {
                    json_out(['ok' => false, 'error' => "PIN 6-digit tersebut sudah digunakan oleh pengguna lain pada {$ctx['code']}."], 422);
                }
            }
            $pinHash   = password_hash($pin, PASSWORD_DEFAULT);
            $pinSha256 = compute_offline_pin_sha256($pin);
        }

        if ($relationId > 0) {
            // Edit relasi user pada device ini
            $relStmt = $pdo->prepare('SELECT user_id FROM device_users WHERE id = ? AND device_id = ? LIMIT 1');
            $relStmt->execute([$relationId, $deviceId]);
            $targetUserId = (int)$relStmt->fetchColumn();
            if (!$targetUserId) {
                json_out(['ok' => false, 'error' => 'Relasi pengguna tidak ditemukan pada device ini.'], 404);
            }

            if ($fullName !== '') {
                $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$fullName, $targetUserId]);
            }
            if ($password !== '') {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $targetUserId]);
            }

            $pdo->prepare('UPDATE device_users SET role = ?, status = ?, can_view_logs = ? WHERE id = ? AND device_id = ?')
                ->execute([$role, $status, $canViewLogs, $relationId, $deviceId]);

            if ($pinHash !== null) {
                $pdo->prepare('UPDATE device_users SET pin_hash = ?, pin_sha256 = ? WHERE id = ? AND device_id = ?')
                    ->execute([$pinHash, $pinSha256, $relationId, $deviceId]);
            }

            log_event($deviceId, $userId, (string)$user['username'], 'MEMBER_UPDATED', 'SUCCESS', "Memperbarui akses {$username} pada {$ctx['code']} (Role: {$role}, Status: {$status})", 'WEB');
            json_out(['ok' => true, 'message' => "Hak akses {$username} pada {$ctx['code']} berhasil diperbarui."]);
        } else {
            // Cek apakah username sudah ada di tabel users global
            $uStmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $uStmt->execute([$username]);
            $existingUser = $uStmt->fetch();

            if ($existingUser) {
                $targetUserId = (int)$existingUser['id'];
            } else {
                if ($fullName === '') {
                    json_out(['ok' => false, 'error' => 'Nama lengkap wajib diisi untuk akun baru.'], 422);
                }
                if (strlen($password) < 6) {
                    json_out(['ok' => false, 'error' => 'Password minimal 6 karakter untuk akun baru.'], 422);
                }
                $insU = $pdo->prepare('INSERT INTO users (username, full_name, password_hash, active) VALUES (?, ?, ?, 1)');
                $insU->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT)]);
                $targetUserId = (int)$pdo->lastInsertId();
            }

            // Kaitkan akun tersebut ke device ini di tabel device_users
            $insRel = $pdo->prepare(
                "INSERT INTO device_users (user_id, device_id, role, pin_hash, pin_sha256, status, can_view_logs, request_note)
                 VALUES (?, ?, ?, ?, ?, 'APPROVED', ?, 'Ditambahkan oleh Admin')
                 ON DUPLICATE KEY UPDATE
                    role = VALUES(role),
                    status = 'APPROVED',
                    can_view_logs = VALUES(can_view_logs),
                    pin_hash = COALESCE(VALUES(pin_hash), pin_hash),
                    pin_sha256 = COALESCE(VALUES(pin_sha256), pin_sha256)"
            );
            $insRel->execute([$targetUserId, $deviceId, $role, $pinHash, $pinSha256, $canViewLogs]);

            log_event($deviceId, $userId, (string)$user['username'], 'MEMBER_ADDED', 'SUCCESS', "Menambahkan {$username} ke {$ctx['code']} sebagai {$role}", 'WEB');
            json_out(['ok' => true, 'message' => "Pengguna {$username} berhasil diberikan akses {$role} pada {$ctx['code']}."]);
        }

    case 'DELETE_USER':
        // Menghapus relasi user dari device ini (tanpa menghapus akunnya di device lain!)
        $relationId = (int)($in['relation_id'] ?? ($in['user_id'] ?? 0));
        $relCheck   = $pdo->prepare('SELECT user_id FROM device_users WHERE id = ? AND device_id = ? LIMIT 1');
        $relCheck->execute([$relationId, $deviceId]);
        $targetUserId = (int)$relCheck->fetchColumn();

        if ($targetUserId === $userId) {
            json_out(['ok' => false, 'error' => 'Tidak dapat menghapus akses Anda sendiri dari device ini.'], 422);
        }

        $pdo->prepare('DELETE FROM device_users WHERE id = ? AND device_id = ?')->execute([$relationId, $deviceId]);
        log_event($deviceId, $userId, (string)$user['username'], 'MEMBER_REMOVED', 'INFO', "Menghapus relasi akses #{$relationId} dari {$ctx['code']}", 'WEB');
        json_out(['ok' => true, 'message' => "Akses pengguna dari {$ctx['code']} berhasil dihapus."]);

    case 'REGENERATE_INVITE_CODE':
        $singleUse = isset($in['single_use']) ? ((int)$in['single_use'] ? 1 : 0) : 1;
        $validDays = max(1, min(30, (int)($in['valid_days'] ?? 7)));
        $newInvite = generate_invite_code((string)$ctx['code']);

        $pdo->prepare(
            "UPDATE devices
             SET invite_code = ?,
                 invite_single_use = ?,
                 invite_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)
             WHERE id = ?"
        )->execute([$newInvite, $singleUse, $validDays, $deviceId]);

        log_event($deviceId, $userId, (string)$user['username'], 'INVITE_REGENERATED', 'SUCCESS', "Membuat kode undangan baru: {$newInvite}", 'WEB');
        json_out([
            'ok'          => true,
            'invite_code' => $newInvite,
            'message'     => "Kode undangan baru ({$newInvite}) berhasil dibuat!"
        ]);

    // ---------------------------------------------------------------------
    // D. PENGATURAN ALARM, SENSOR & KONFIGURASI PERANGKAT ESP32
    // ---------------------------------------------------------------------
    case 'UPDATE_SETTINGS':
        $devName        = trim((string)($in['device_name'] ?? $ctx['name']));
        $devCode        = strtoupper(trim((string)($in['device_code'] ?? $ctx['code'])));
        $newApiKey      = trim((string)($in['api_key'] ?? $ctx['api_key']));
        $doorTimeoutSec = max(5, min(600, (int)($in['door_timeout_sec'] ?? 30)));
        $autoLockSec    = max(3, min(300, (int)($in['auto_lock_sec'] ?? 10)));
        $maxPinAttempts = max(1, min(10, (int)($in['max_pin_attempts'] ?? 3)));
        $tempThreshold  = max(15.0, min(80.0, (float)($in['temp_threshold'] ?? 35.0)));
        $humThreshold   = max(20.0, min(100.0, (float)($in['humidity_threshold'] ?? 80.0)));

        if ($devCode === '' || $newApiKey === '') {
            json_out(['ok' => false, 'error' => 'DEVICE_CODE dan DEVICE_API_KEY tidak boleh kosong.'], 422);
        }

        $pdo->prepare(
            'UPDATE devices
             SET code = ?,
                 name = ?,
                 api_key = ?,
                 api_key_hash = ?,
                 door_timeout_sec = ?,
                 auto_lock_sec = ?,
                 max_pin_attempts = ?,
                 temp_threshold = ?,
                 humidity_threshold = ?
             WHERE id = ?'
        )->execute([
            $devCode,
            $devName ?: 'Smart Secure Storage Box',
            $newApiKey,
            hash('sha256', $newApiKey),
            $doorTimeoutSec,
            $autoLockSec,
            $maxPinAttempts,
            $tempThreshold,
            $humThreshold,
            $deviceId
        ]);

        log_event(
            $deviceId,
            $userId,
            (string)$user['username'],
            'SETTINGS_UPDATED',
            'SUCCESS',
            "Pengaturan {$devCode} diperbarui (Door: {$doorTimeoutSec}s, Auto-lock: {$autoLockSec}s, Max PIN: {$maxPinAttempts}x)",
            'WEB'
        );

        json_out([
            'ok'      => true,
            'message' => "Pengaturan {$devName} ({$devCode}) berhasil disimpan!"
        ]);

    default:
        json_out(['ok' => false, 'error' => 'Perintah tidak dikenali: ' . $action], 422);
}
