<?php
/**
 * Smart Secure Storage Box - Registrasi Akun Pengguna Baru
 * Catatan Arsitektur:
 * - Tidak ada penetapan Role pada saat pembuatan akun (tabel `users` tidak memiliki kolom role).
 * - Role (ADMIN / USER) hanya ditetapkan dan berlaku di tabel `device_users` ketika akun terhubung ke sebuah Storage Box setelah login.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!setup_done()) {
    header('Location: setup.php');
    exit;
}

if (current_user()) {
    header('Location: index.php');
    exit;
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        $errorMsg = 'Sesi tidak valid. Silakan muat ulang halaman.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $errorMsg = 'Username harus 3–30 karakter (huruf, angka, atau underscore).';
        } elseif ($fullName === '') {
            $errorMsg = 'Nama lengkap wajib diisi.';
        } elseif (strlen($password) < 6) {
            $errorMsg = 'Password minimal 6 karakter.';
        } else {
            $pdo = db();
            try {
                $ins = $pdo->prepare('INSERT INTO users (username, full_name, password_hash, active) VALUES (?, ?, ?, 1)');
                $ins->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT)]);
                $newUserId = (int)$pdo->lastInsertId();

                log_event(null, $newUserId, $username, 'ACCOUNT_REGISTERED', 'SUCCESS', 'Pendaftaran akun pengguna baru', 'WEB');

                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;

                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                $errorMsg = 'Username "' . $username . '" sudah digunakan. Pilih username lain.';
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
  <title>Daftar Akun — Smart Secure Box</title>
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
<body class="bg-surface text-on-surface min-h-screen antialiased">
  <main class="w-full min-h-screen flex flex-col lg:flex-row">
    <!-- LEFT PANEL -->
    <div class="relative hidden lg:flex lg:w-1/2 min-h-screen flex-col justify-between p-12 overflow-hidden bg-primary-container text-white">
      <img
        alt="Smart Secure Storage Box"
        class="absolute inset-0 w-full h-full object-cover object-center opacity-75 dark:opacity-35"
        src="https://lh3.googleusercontent.com/aida-public/AB6AXuAMlHs7Gavk5SkjwIA1wlGRB7iRa6ldTObD4nprxtskQ9nWOU0daq6B0gIMn_UaeesK77HpDFZX7umFfqydeSxQU-ISSigXO5zMO12RNOGFVFdgntoL0ypMX9Bi2DUEQzBkTMu7yfzfVY5L2IWk75MBkVIGrda7f5ydGXPSHL7PQTL2MFetrKYeVzc7r5U8DjWURKKtcc9mo0z8kVfWjCFrCRDuUmDE0o-_eEUnDQy9"
      />
      <div class="absolute inset-0 bg-gradient-to-t from-[#111c26]/90 via-[#111c26]/35 to-[#111c26]/30 dark:from-[#0d0e0f]/95 dark:via-[#121414]/70 dark:to-[#121414]/60"></div>

      <div class="relative z-10 flex items-center gap-2.5">
        <div class="w-9 h-9 rounded-xl bg-white/15 backdrop-blur-md flex items-center justify-center">
          <span class="material-symbols-outlined text-[20px]">shield_lock</span>
        </div>
        <span class="text-sm font-semibold tracking-wider uppercase">Smart Secure Box</span>
      </div>

      <div class="relative z-10 max-w-md">
        <div class="p-7 rounded-2xl bg-white/90 dark:bg-[#1a1c1c]/90 backdrop-blur-xl shadow-xl text-[#111c26] dark:text-[#e3e2e2]">
          <h2 class="text-xl font-semibold tracking-tight mb-2">
            Satu Akun untuk Banyak Storage Box
          </h2>
          <p class="text-sm leading-relaxed opacity-80">
            Setelah masuk, Anda dapat mendaftarkan Storage Box baru melalui Scan QR &amp; Bluetooth atau bergabung ke Storage Box lain dengan peran (Role) dan PIN yang spesifik pada tiap perangkat.
          </p>
        </div>
      </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="w-full lg:w-1/2 flex flex-col justify-between p-6 sm:p-12 lg:p-16 bg-surface min-h-screen">
      <div class="flex items-center justify-between w-full max-w-sm mx-auto">
        <div class="flex items-center gap-2 lg:invisible">
          <div class="w-8 h-8 rounded-lg bg-primary-container text-on-primary-container flex items-center justify-center">
            <span class="material-symbols-outlined text-[18px]">shield_lock</span>
          </div>
          <span class="text-xs font-bold tracking-wider uppercase text-primary">Smart Secure Box</span>
        </div>

        <button
          type="button"
          onclick="toggleTheme()"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface text-xs font-medium border border-outline-variant/40 transition-all"
        >
          <span class="material-symbols-outlined text-[16px] text-secondary" id="themeIcon">wb_sunny</span>
          <span id="themeLabel">Light</span>
        </button>
      </div>

      <div class="w-full max-w-sm mx-auto my-auto py-6">
        <div class="mb-6">
          <h1 class="text-3xl font-heading font-semibold text-on-surface tracking-tight mb-2">
            Buat Akun Baru
          </h1>
          <p class="text-sm text-on-surface-variant">
            Daftarkan akun Anda. Pendaftaran atau pemilihan Storage Box dilakukan setelah Anda masuk.
          </p>
        </div>

        <?php if ($errorMsg !== ''): ?>
          <div class="mb-5 p-3.5 rounded-xl bg-error-container text-on-error-container text-xs font-medium flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            <span><?= htmlspecialchars($errorMsg) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST" action="register.php" class="space-y-4">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>"/>
          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1.5">Username</label>
            <input
              name="username"
              type="text"
              required
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              placeholder="Contoh: andi_01"
              class="w-full px-4 py-2.5 rounded-xl bg-surface-container-lowest text-on-surface placeholder:text-outline text-sm border border-outline-variant/50 focus:outline-none focus:ring-2 focus:ring-secondary/30"
            />
          </div>

          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1.5">Nama Lengkap</label>
            <input
              name="full_name"
              type="text"
              required
              value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
              placeholder="Masukkan nama lengkap"
              class="w-full px-4 py-2.5 rounded-xl bg-surface-container-lowest text-on-surface placeholder:text-outline text-sm border border-outline-variant/50 focus:outline-none focus:ring-2 focus:ring-secondary/30"
            />
          </div>

          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1.5">Password Login</label>
            <input
              name="password"
              type="password"
              required
              minlength="6"
              placeholder="Minimal 6 karakter"
              class="w-full px-4 py-2.5 rounded-xl bg-surface-container-lowest text-on-surface placeholder:text-outline text-sm border border-outline-variant/50 focus:outline-none focus:ring-2 focus:ring-secondary/30"
            />
          </div>

          <button
            type="submit"
            class="w-full mt-2 py-3.5 px-6 rounded-xl bg-secondary text-on-secondary text-sm font-semibold shadow-sm hover:opacity-95 transition-all"
          >
            Daftar &amp; Masuk
          </button>
        </form>

        <div class="mt-6 text-center text-xs text-on-surface-variant">
          Sudah punya akun?
          <a href="login.php" class="font-semibold text-secondary hover:underline ml-1">Masuk di sini</a>
        </div>
      </div>

      <div class="w-full max-w-sm mx-auto text-center text-xs text-on-surface-variant/70">
        Smart Secure Storage Box
      </div>
    </div>
  </main>

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
