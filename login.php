<?php
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
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $errorMsg = 'Masukkan username dan password Anda.';
        } else {
            $res = attempt_login($identifier, $password);
            if ($res['ok']) {
                header('Location: index.php');
                exit;
            }
            $errorMsg = $res['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Masuk — Smart Secure Box</title>
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
    <!-- LEFT PANEL: Clean Visual Sanctuary -->
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
            Penyimpanan Pintar Berbasis IoT
          </h2>
          <p class="text-sm leading-relaxed opacity-80">
            Keamanan berlapis dengan autentikasi PIN 6-digit, pemantauan suhu &amp; pintu secara real-time, serta kendali jarak jauh.
          </p>
        </div>
      </div>
    </div>

    <!-- RIGHT PANEL: Simple & Clean Login Form -->
    <div class="w-full lg:w-1/2 flex flex-col justify-between p-6 sm:p-12 lg:p-16 bg-surface min-h-screen">
      <!-- Top Bar -->
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

      <!-- Form Box -->
      <div class="w-full max-w-sm mx-auto my-auto">
        <div class="mb-8">
          <h1 class="text-3xl font-heading font-semibold text-on-surface tracking-tight mb-2">
            Selamat Datang
          </h1>
          <p class="text-sm text-on-surface-variant">
            Masuk untuk mengakses dashboard Smart Secure Box.
          </p>
        </div>

        <?php if ($errorMsg !== ''): ?>
          <div class="mb-5 p-3.5 rounded-xl bg-error-container text-on-error-container text-xs font-medium flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            <span><?= htmlspecialchars($errorMsg) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-4" id="loginForm">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>"/>
          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1.5" for="identifier">
              Username
            </label>
            <input
              id="identifier"
              name="identifier"
              type="text"
              required
              autofocus
              value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
              placeholder="Masukkan username"
              class="w-full px-4 py-3 rounded-xl bg-surface-container-lowest text-on-surface placeholder:text-outline text-sm border border-outline-variant/50 focus:outline-none focus:ring-2 focus:ring-secondary/30"
            />
          </div>

          <div>
            <div class="flex items-center justify-between mb-1.5">
              <label class="text-xs font-medium text-on-surface-variant" for="password">
                Password
              </label>
              <button
                type="button"
                onclick="togglePasswordVisibility()"
                class="text-xs text-secondary hover:underline"
                id="eyeText"
              >
                Tampilkan
              </button>
            </div>
            <input
              id="password"
              name="password"
              type="password"
              required
              placeholder="••••••••"
              class="w-full px-4 py-3 rounded-xl bg-surface-container-lowest text-on-surface placeholder:text-outline text-sm border border-outline-variant/50 focus:outline-none focus:ring-2 focus:ring-secondary/30"
            />
          </div>

          <button
            type="submit"
            class="w-full mt-2 py-3.5 px-6 rounded-xl bg-secondary text-on-secondary text-sm font-semibold shadow-sm hover:opacity-95 transition-all"
          >
            Masuk
          </button>
        </form>

        <div class="mt-6 text-center text-xs text-on-surface-variant">
          Belum punya akun?
          <a href="register.php" class="font-semibold text-secondary hover:underline ml-1">Daftar Akun Baru</a>
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
    function togglePasswordVisibility() {
      const input = document.getElementById('password');
      const text = document.getElementById('eyeText');
      if (input.type === 'password') {
        input.type = 'text';
        text.textContent = 'Sembunyikan';
      } else {
        input.type = 'password';
        text.textContent = 'Tampilkan';
      }
    }
    updateThemeUI();
  </script>
</body>
</html>
