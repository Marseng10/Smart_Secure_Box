<?php
/**
 * Smart Secure Storage Box - Endpoint Telemetry, Sensor DHT11/Door, Auto-Close Forget-to-Close, Offline Sync & Remote Command Queue
 * POST /api/telemetry.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$in  = json_in();
$dev = device_auth($in);
$pdo = db();

$deviceId = (int)$dev['id'];

$locked         = !empty($in['locked']) ? 1 : 0;
$doorClosed     = array_key_exists('door_closed', $in) ? (!empty($in['door_closed']) ? 1 : 0) : 1;
$doorAlarm      = !empty($in['door_alarm']) ? 1 : 0;
$vibrationAlarm = (!empty($in['vibration_alarm']) || !empty($in['vibration'])) ? 1 : 0;
$autoClosed     = !empty($in['auto_closed']) ? 1 : 0;
$securityAlarm  = array_key_exists('security_alarm', $in) ? (!empty($in['security_alarm']) ? 1 : 0) : (int)$dev['security_alarm'];
if ($vibrationAlarm === 1) {
    $securityAlarm = 1;
}
$temp           = (isset($in['temperature']) && is_numeric($in['temperature'])) ? round((float)$in['temperature'], 2) : null;
$hum            = (isset($in['humidity']) && is_numeric($in['humidity'])) ? round((float)$in['humidity'], 2) : null;
$rssi           = (isset($in['rssi']) && is_numeric($in['rssi'])) ? (int)$in['rssi'] : null;

// Hitung durasi pintu terbuka
$doorOpenSec = 0;
if ($doorClosed === 0) {
    if (isset($in['door_open_sec']) && is_numeric($in['door_open_sec'])) {
        $doorOpenSec = max(0, (int)$in['door_open_sec']);
    } else {
        $prevSec     = (int)($dev['door_open_seconds'] ?? 0);
        $doorOpenSec = ((int)$dev['door_closed'] === 0) ? ($prevSec + 2) : 1;
    }
}

$doorTimeoutSec = max(5, (int)($dev['door_timeout_sec'] ?? 30));
if ($doorClosed === 0 && ($doorAlarm === 1 || $doorOpenSec >= $doorTimeoutSec)) {
    $doorAlarm = 1;
}
if ($doorClosed === 1) {
    $doorAlarm   = 0;
    $doorOpenSec = 0;
}

// 1. Update status live perangkat di tabel devices
$upd = $pdo->prepare(
    'UPDATE devices
     SET locked = ?,
         door_closed = ?,
         door_open_seconds = ?,
         door_alarm = ?,
         vibration_alarm = ?,
         last_vibration_at = IF(? = 1, NOW(), last_vibration_at),
         security_alarm = ?,
         temperature = COALESCE(?, temperature),
         humidity = COALESCE(?, humidity),
         rssi = COALESCE(?, rssi),
         last_seen = NOW()
     WHERE id = ?'
);
$upd->execute([
    $locked,
    $doorClosed,
    $doorOpenSec,
    $doorAlarm,
    $vibrationAlarm,
    $vibrationAlarm,
    $securityAlarm,
    $temp,
    $hum,
    $rssi,
    $deviceId
]);

// 1B. Deteksi Peringatan Sensor Getaran (Vibration Sensor)
if ($vibrationAlarm === 1 && (int)($dev['vibration_alarm'] ?? 0) === 0) {
    trigger_alert(
        $deviceId,
        'VIBRATION_ALERT',
        "Peringatan Sensor Getaran (Indikasi Pembobolan)",
        "Sensor getaran mendeteksi guncangan/benturan fisik pada Storage Box ({$dev['code']}). Buzzer keamanan diaktifkan."
    );
    log_event(
        $deviceId,
        null,
        null,
        'VIBRATION_ALARM',
        'FAILED',
        "Sensor getaran mendeteksi guncangan/benturan fisik pada kotak ({$dev['code']})",
        'SENSOR'
    );
}

// 2. Deteksi Peringatan Pintu Terbuka > 30 Detik (Forget to Close) & Fitur Otomatis Menutup
if ($autoClosed === 1) {
    trigger_alert(
        $deviceId,
        'FORGOT_CLOSE',
        "Forget to Close — Otomatis Menutup Aktif",
        "Pintu dibiarkan terbuka lebih dari {$doorTimeoutSec} detik. Buzzer berbunyi & motor servo otomatis menutup/mengunci kotak."
    );
    log_event(
        $deviceId,
        null,
        null,
        'AUTO_CLOSE_FORGOT',
        'SUCCESS',
        "Pintu terbuka > {$doorTimeoutSec} detik — Servo otomatis menutup & mengunci kotak (Forget to Close)",
        'SENSOR'
    );
} elseif ($doorAlarm === 1 && (int)$dev['door_alarm'] === 0) {
    trigger_alert(
        $deviceId,
        'FORGOT_CLOSE',
        "Peringatan Forget to Close (> {$doorTimeoutSec} Detik)",
        "Sensor pintu mendeteksi kotak dibiarkan terbuka lebih dari {$doorTimeoutSec} detik. Buzzer peringatan & penutupan otomatis diaktifkan."
    );
    log_event(
        $deviceId,
        null,
        null,
        'DOOR_TIMEOUT_ALARM',
        'FAILED',
        "Pintu dibiarkan terbuka > {$doorTimeoutSec} detik — Buzzer aktif & bersiap menutup otomatis",
        'SENSOR'
    );
} elseif ($doorClosed === 1 && (int)$dev['door_alarm'] === 1) {
    resolve_alerts($deviceId, 'FORGOT_CLOSE');
}

// 3. Deteksi Peringatan Suhu / Kelembapan DHT11
$tempThreshold = (float)($dev['temp_threshold'] ?? 35.0);
$humThreshold  = (float)($dev['humidity_threshold'] ?? 80.0);

if ($temp !== null && $temp >= $tempThreshold) {
    trigger_alert(
        $deviceId,
        'TEMP_WARNING',
        "Suhu Kotak Tinggi ({$temp}°C)",
        "Sensor DHT11 mendeteksi suhu {$temp}°C (melebihi batas aman {$tempThreshold}°C)."
    );
} elseif ($hum !== null && $hum >= $humThreshold) {
    trigger_alert(
        $deviceId,
        'TEMP_WARNING',
        "Kelembapan Kotak Tinggi ({$hum}%)",
        "Sensor DHT11 mendeteksi kelembapan {$hum}% (melebihi batas aman {$humThreshold}%)."
    );
} elseif ($temp !== null && $temp < ($tempThreshold - 1.0) && ($hum === null || $hum < ($humThreshold - 2.0))) {
    resolve_alerts($deviceId, 'TEMP_WARNING');
}

// 4. Sinkronisasi Data Offline dari Memori ESP32 saat Koneksi Kembali Tersedia
if (!empty($in['offline_logs']) && is_array($in['offline_logs'])) {
    foreach ($in['offline_logs'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $uName   = isset($item['username']) ? trim((string)$item['username']) : null;
        $act     = trim((string)($item['action'] ?? 'OFFLINE_AUTH'));
        $res     = in_array(($item['result'] ?? ''), ['SUCCESS', 'FAILED', 'INFO'], true) ? $item['result'] : 'INFO';
        $agoSec  = isset($item['ago_sec']) ? max(0, (int)$item['ago_sec']) : 0;
        $details = trim((string)($item['details'] ?? 'Sinkronisasi data offline ESP32'));
        if ($agoSec > 0) {
            $details .= " (terjadi {$agoSec} detik lalu saat offline)";
        }

        $uId = null;
        if ($uName !== null && $uName !== '') {
            $uSt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $uSt->execute([$uName]);
            $uId = (int)$uSt->fetchColumn() ?: null;
        }

        log_event($deviceId, $uId, $uName, $act, $res, $details, 'OFFLINE_SYNC');

        if ($act === 'OFFLINE_PIN_LOCKOUT') {
            trigger_alert(
                $deviceId,
                'FAILED_ACCESS',
                'Peringatan Offline: PIN Salah Berturut-turut',
                $details
            );
        } elseif ($act === 'OFFLINE_VIBRATION_ALARM') {
            trigger_alert(
                $deviceId,
                'VIBRATION_ALERT',
                'Peringatan Offline: Sensor Getaran Terpicu',
                $details
            );
        }
    }
}

// 5. Proses Konfirmasi Eksekusi Perintah Jarak Jauh (ACK dari ESP32)
if (isset($in['ack']) && is_array($in['ack'])) {
    $cmdId  = (int)($in['ack']['id'] ?? 0);
    $status = (string)($in['ack']['status'] ?? '');
    $msg    = substr(trim((string)($in['ack']['message'] ?? '')), 0, 250);

    if ($cmdId > 0 && in_array($status, ['SUCCESS', 'FAILED'], true)) {
        $cmdStmt = $pdo->prepare('SELECT command_type, requested_by FROM commands WHERE id = ? AND device_id = ? LIMIT 1');
        $cmdStmt->execute([$cmdId, $deviceId]);
        $cmdRow = $cmdStmt->fetch();

        $pdo->prepare(
            "UPDATE commands
             SET status = ?, result_message = ?, completed_at = NOW()
             WHERE id = ? AND device_id = ?"
        )->execute([$status, $msg, $cmdId, $deviceId]);

        $cmdType = $cmdRow['command_type'] ?? 'COMMAND';
        $reqBy   = isset($cmdRow['requested_by']) ? (int)$cmdRow['requested_by'] : null;

        if ($cmdType === 'ALARM_OFF' && $status === 'SUCCESS') {
            $pdo->prepare('UPDATE devices SET security_alarm = 0, door_alarm = 0, vibration_alarm = 0 WHERE id = ?')->execute([$deviceId]);
            $pdo->prepare('UPDATE pin_attempts SET failures = 0, blocked_until = NULL WHERE device_id = ?')->execute([$deviceId]);
            resolve_alerts($deviceId);
        }

        log_event(
            $deviceId,
            $reqBy,
            null,
            'REMOTE_ACK',
            $status,
            "ESP32 konfirmasi {$cmdType} (#{$cmdId}): {$msg}",
            ($cmdType === 'EMERGENCY_UNLOCK' ? 'EMERGENCY' : 'REMOTE')
        );
    }
}

// 6. Ambil Perintah Jarak Jauh Berikutnya yang Berstatus PENDING
$nextCmdStmt = $pdo->prepare(
    "SELECT id, command_type, reason
     FROM commands
     WHERE device_id = ? AND status = 'PENDING'
     ORDER BY id ASC
     LIMIT 1"
);
$nextCmdStmt->execute([$deviceId]);
$nextCommand = $nextCmdStmt->fetch() ?: null;

// 7. Ambil Daftar Hash PIN Aktif pada Device ini untuk Disimpan di Memori Lokal ESP32 (NVS Offline Auth)
$pinsStmt = $pdo->prepare(
    "SELECT u.username AS u, du.role AS r, du.pin_sha256 AS h
     FROM device_users du
     INNER JOIN users u ON u.id = du.user_id
     WHERE du.device_id = ?
       AND du.status = 'APPROVED'
       AND u.active = 1
       AND du.pin_sha256 IS NOT NULL
       AND du.pin_sha256 != ''"
);
$pinsStmt->execute([$deviceId]);
$offlinePins = $pinsStmt->fetchAll();

json_out([
    'ok'             => true,
    'device_matched' => true,
    'command'        => $nextCommand,
    'settings'       => [
        'door_timeout_sec' => $doorTimeoutSec,
        'auto_lock_sec'    => max(3, (int)($dev['auto_lock_sec'] ?? 10)),
        'max_pin_attempts' => max(1, (int)($dev['max_pin_attempts'] ?? 3)),
        'temp_threshold'   => $tempThreshold,
        'hum_threshold'    => $humThreshold,
    ],
    'offline_pins'   => $offlinePins
]);
