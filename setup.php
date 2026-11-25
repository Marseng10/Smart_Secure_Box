<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (setup_done()) {
    header('Location: index.php');
    exit;
}

$error = '';
$defaultApiKey = '300eb5caab196331ba736b19be3fa491114acfc1a5d4b6a1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        $error = 'Sesi tidak valid, silakan coba lagi.';
    } else {
        $username   = trim((string)($_POST['username'] ?? ''));
        $fullName   = trim((string)($_POST['full_name'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');
        $pin        = trim((string)($_POST['pin'] ?? ''));
        $deviceCode = strtoupper(trim((string)($_POST['device_code'] ?? 'BOX-01')));
        $deviceName = trim((string)($_POST['device_name'] ?? 'Smart Secure Storage Box'));
        $apiKey     = trim((string)($_POST['api_key'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $error = 'Username harus 3–30 karakter (huruf, angka, atau underscore).';
        } elseif ($fullName === '') {
            $error = 'Nama lengkap wajib diisi.';
        } elseif (strlen($password) < 6) {
            $error = 'Password login minimal 6 karakter.';
        } elseif ($pin !== '' && !preg_match('/^[0-9]{6}$/', $pin)) {
            $error = 'PIN Keypad harus tepat 6 angka (0–9) atau kosongkan untuk diatur nanti.';
        } elseif (!preg_match('/^[A-Z0-9_-]{3,30}$/', $deviceCode)) {
            $error = 'Kode perangkat (DEVICE_CODE) harus 3–30 karakter huruf/angka/strip.';
        } elseif (strlen($apiKey) < 12) {
            $error = 'DEVICE_API_KEY minimal 12 karakter.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pinHash    = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null;
                $pinSha256  = $pin !== '' ? compute_offline_pin_sha256($pin) : null;
                $inviteCode = generate_invite_code($deviceCode);

                $uStmt = $pdo->prepare(
                    "INSERT INTO users (username, full_name, password_hash, active)
                     VALUES (?, ?, ?, 1)"
                );
                $uStmt->execute([
                    $username,
                    $fullName,
                    password_hash($password, PASSWORD_DEFAULT)
                ]);
                $adminId = (int)$pdo->lastInsertId();

                $dStmt = $pdo->prepare(
                    "INSERT INTO devices (code, name, invite_code, invite_expires_at, invite_single_use, api_key, api_key_hash, locked, door_closed, door_timeout_sec, auto_lock_sec, max_pin_attempts)
                     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), 1, ?, ?, 1, 1, 30, 10, 3)"
                );
                $dStmt->execute([
                    $deviceCode,
                    $deviceName !== '' ? $deviceName : 'Smart Secure Storage Box',
                    $inviteCode,
                    $apiKey,
                    hash('sha256', $apiKey)
                ]);
                $deviceId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    "INSERT INTO device_users (user_id, device_id, role, pin_hash, pin_sha256, status, can_view_logs, request_note)
                     VALUES (?, ?, 'ADMIN', ?, ?, 'APPROVED', 1, 'Setup Awal Administrator')"
                )->execute([$adminId, $deviceId, $pinHash, $pinSha256]);

                $pdo->prepare('INSERT IGNORE INTO pin_attempts (device_id, failures) VALUES (?, 0)')
                    ->execute([$deviceId]);

                $pdo->commit();

                log_event($deviceId, $adminId, $username, 'SYSTEM_SETUP', 'SUCCESS', "Setup awal selesai: Admin ({$username}) & Perangkat ({$deviceCode})", 'WEB');

                session_regenerate_id(true);
                $_SESSION['user_id'] = $adminId;

                header('Location: index.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Gagal menyimpan setup: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Setup Awal — Smart Secure Box</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    (function() {
      const savedTheme = localStorage.getItem('secbox_theme') || 'light';
      if (savedTheme === 'dark') {
        document.documentElement.classList.add('dark');
      } else {
        document.documentElement.classList.remove('dark');
      }
    })();

    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            'surface': 'var(--surface)',
            'background': 'var(--background)',
            'surface-container-lowest': 'var(--surface-container-lowest)',
            'surface-container-low': 'var(--surface-container-low)',
            'surface-container': 'var(--surface-container)',
            'surface-container-high': 'var(--surface-container-high)',
            'surface-container-highest': 'var(--surface-container-highest)',
            'surface-variant': 'var(--surface-variant)',
            'on-surface': 'var(--on-surface)',
            'on-surface-variant': 'var(--on-surface-variant)',
            'primary': 'var(--primary)',
            'on-primary': 'var(--on-primary)',
            'primary-container': 'var(--primary-container)',
            'on-primary-container': 'var(--on-primary-container)',
            'secondary': 'var(--secondary)',
            'on-secondary': 'var(--on-secondary)',
            'secondary-container': 'var(--secondary-container)',
            'on-secondary-container': 'var(--on-secondary-container)',
            'tertiary': 'var(--tertiary)',
            'on-tertiary': 'var(--on-tertiary)',
            'tertiary-container': 'var(--tertiary-container)',
            'on-tertiary-container': 'var(--on-tertiary-container)',
            'error': 'var(--error)',
            'on-error': 'var(--on-error)',
            'error-container': 'var(--error-container)',
            'on-error-container': 'var(--on-error-container)',
            'outline': 'var(--outline)',
            'outline-variant': 'var(--outline-variant)'
          },
          fontFamily: {
            sans: ['Plus Jakarta Sans', 'Inter', 'sans-serif'],
            mono: ['JetBrains Mono', 'monospace'],
            display: ['Space Grotesk', 'Plus Jakarta Sans', 'sans-serif']
          }
        }
      }
    };
  </script>
  <link rel="stylesheet" href="assets/css/theme.css"/>
</head>
<body class="bg-background text-on-surface min-h-screen antialiased flex flex-col justify-center items-center p-4 sm:p-6">
  <div class="fixed top-5 right-5 z-50">
    <button
      type="button"
      onclick="toggleTheme()"
      class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface text-xs font-medium border border-outline-variant/40 transition-all"
    >
      <span class="material-symbols-outlined text-[16px] text-secondary" id="themeIcon">wb_sunny</span>
      <span id="themeLabel">Light</span>
    </button>
  </div>

  <div class="w-full max-w-xl my-6">
    <div class="text-center mb-6">
      <div class="w-11 h-11 rounded-2xl bg-primary-container text-on-primary-container mx-auto flex items-center justify-center mb-3 shadow-sm">
        <span class="material-symbols-outlined text-[22px]">shield_lock</span>
      </div>
      <h1 class="text-2xl font-heading font-semibold text-on-surface tracking-tight">Setup Awal Smart Secure Box</h1>
      <p class="text-sm text-on-surface-variant mt-1">Buat akun Admin utama dan sambungkan ke perangkat ESP32 Anda.</p>
    </div>

    <div class="vault-card rounded-2xl p-6 sm:p-8">
      <?php if ($error !== ''): ?>
        <div class="mb-5 p-3.5 rounded-xl bg-error-container text-on-error-container text-xs font-medium flex items-center gap-2">
          <span class="material-symbols-outlined text-[18px]">error</span>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="setup.php" class="space-y-5">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>"/>

        <div>
          <h2 class="text-xs font-bold uppercase tracking-wider text-secondary mb-3">1. Akun Administrator</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Username Admin</label>
              <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>"
                class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
            </div>
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Nama Lengkap</label>
              <input type="text" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? 'Administrator') ?>"
                class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
            </div>
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Password Login</label>
              <input type="password" name="password" minlength="6" required placeholder="Minimal 6 karakter"
                class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
            </div>
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">PIN Keypad 6-Digit</label>
              <input type="text" name="pin" maxlength="6" pattern="[0-9]{6}" value="<?= htmlspecialchars($_POST['pin'] ?? '123456') ?>"
                class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono tracking-widest text-on-surface"/>
            </div>
          </div>
        </div>

        <div class="pt-4 border-t border-outline-variant/30">
          <h2 class="text-xs font-bold uppercase tracking-wider text-secondary mb-3">2. Kredensial Perangkat ESP32</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Kode Perangkat (DEVICE_CODE)</label>
              <input type="text" name="device_code" required value="<?= htmlspecialchars($_POST['device_code'] ?? 'BOX-01') ?>"
                class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
            </div>
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Nama Perangkat</label>
              <input type="text" name="device_name" required value="<?= htmlspecialchars($_POST['device_name'] ?? 'Smart Secure Box Utama') ?>"
                class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
            </div>
          </div>

          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1">API Key Perangkat (DEVICE_API_KEY)</label>
            <input type="text" name="api_key" required value="<?= htmlspecialchars($_POST['api_key'] ?? $defaultApiKey) ?>"
              class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-xs font-mono text-on-surface"/>
          </div>
        </div>

        <button type="submit" class="w-full py-3.5 px-6 rounded-xl bg-secondary text-on-secondary text-sm font-semibold shadow-sm hover:opacity-95 transition-all">
          Simpan &amp; Masuk ke Dashboard
        </button>
      </form>
    </div>
  </div>

  <script>
    function updateThemeUI() {
      const isDark = document.documentElement.classList.contains('dark');
      document.getElementById('themeIcon').textContent = isDark ? 'dark_mode' : 'wb_sunny';
      document.getElementById('themeLabel').textContent = isDark ? 'Dark' : 'Light';
    }
    function toggleTheme() {
      const html = document.documentElement;
      html.classList.toggle('dark');
      localStorage.setItem('secbox_theme', html.classList.contains('dark') ? 'dark' : 'light');
      updateThemeUI();
    }
    updateThemeUI();
  </script>
</body>
</html>
