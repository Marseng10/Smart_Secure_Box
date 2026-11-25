<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

$currentUser = require_login();
$userId      = (int)$currentUser['id'];
$ctx         = get_active_device_context($userId);
$isAdmin     = ($ctx && $ctx['device_role'] === 'ADMIN');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Smart Secure Box — Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
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
<body class="bg-background text-on-surface antialiased min-h-screen">

  <!-- ========================================================================
       SIDEBAR NAVIGATION (Clean & Minimal + Multi-Device Role Aware)
       ======================================================================== -->
  <aside id="sidebar" class="fixed left-0 top-0 h-screen w-64 bg-surface-container-low border-r border-outline-variant/30 z-50 flex flex-col justify-between p-5 transition-transform duration-300 -translate-x-full lg:translate-x-0">
    <div class="flex flex-col gap-6">
      <!-- Brand -->
      <div class="flex items-center justify-between px-1">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-primary-container text-on-primary-container flex items-center justify-center shadow-sm">
            <span class="material-symbols-outlined text-[20px]">shield_lock</span>
          </div>
          <span class="text-sm font-heading font-bold tracking-wide text-on-surface">Smart Secure Box</span>
        </div>
        <button type="button" onclick="toggleMobileSidebar()" class="lg:hidden text-on-surface-variant hover:text-on-surface">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <!-- Active Storage Box Selector in Sidebar -->
      <div class="p-3 rounded-xl bg-surface-container border border-outline-variant/30 space-y-2">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-on-surface-variant">Storage Box Aktif</span>
          <span id="sidebarRoleBadge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary text-on-secondary">
            <?= htmlspecialchars($ctx['device_role'] ?? 'NONE') ?>
          </span>
        </div>
        <select
          id="sidebarDeviceSelect"
          onchange="onSelectActiveDevice(this.value)"
          class="w-full px-2.5 py-2 rounded-lg bg-surface-container-lowest text-on-surface text-xs font-semibold border border-outline-variant/40 focus:outline-none"
        >
          <option value="">Memuat Storage Box...</option>
        </select>
      </div>

      <!-- Menu -->
      <nav class="flex flex-col gap-1" id="sidebarNav">
        <button type="button" onclick="switchTab('devices')" data-tab="devices" class="nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-semibold transition-all bg-primary-container text-on-primary-container shadow-sm">
          <span class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">inventory_2</span>
            <span>Storage Box Saya</span>
          </span>
          <span id="sidebarDeviceCountBadge" class="text-[11px] font-mono font-semibold px-2 py-0.5 rounded-full bg-surface-container text-on-surface-variant">0</span>
        </button>

        <button type="button" onclick="switchTab('dashboard')" data-tab="dashboard" class="hidden nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-medium text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-all">
          <span class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">grid_view</span>
            <span>Dashboard</span>
          </span>
        </button>

        <button type="button" onclick="switchTab('logs')" data-tab="logs" class="hidden nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-medium text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-all">
          <span class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">history</span>
            <span>Riwayat &amp; Log</span>
          </span>
          <span id="sidebarAlertBadge" class="text-[11px] font-mono font-bold px-2 py-0.5 rounded-full bg-error-container text-on-error-container hidden">0</span>
        </button>

        <button type="button" id="navBtnUsers" onclick="switchTab('users')" data-tab="users" class="hidden nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-medium text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-all">
          <span class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">group</span>
            <span>Kelola Pengguna</span>
          </span>
          <span id="sidebarPendingBadge" class="text-[11px] font-mono font-bold px-2 py-0.5 rounded-full bg-secondary text-on-secondary hidden">0</span>
        </button>

        <button type="button" id="navBtnSettings" onclick="switchTab('settings')" data-tab="settings" class="hidden nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-medium text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-all">
          <span class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">tune</span>
            <span>Pengaturan</span>
          </span>
        </button>

        <button type="button" onclick="switchTab('profile')" data-tab="profile" class="hidden nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-medium text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-all">
          <span class="flex items-center gap-3">
            <span class="material-symbols-outlined text-[20px]">person</span>
            <span>PIN &amp; Profil</span>
          </span>
        </button>
      </nav>
    </div>

    <!-- User Profile Footer -->
    <div class="pt-4 border-t border-outline-variant/30 flex items-center justify-between">
      <div class="flex items-center gap-2.5 min-w-0">
        <div class="w-9 h-9 rounded-full bg-secondary text-on-secondary flex items-center justify-center font-bold text-xs shrink-0">
          <?= strtoupper(substr($currentUser['username'], 0, 2)) ?>
        </div>
        <div class="flex flex-col min-w-0">
          <span class="text-xs font-semibold text-on-surface truncate" id="sidebarUserFullName"><?= htmlspecialchars($currentUser['full_name']) ?></span>
          <span class="text-[11px] text-on-surface-variant truncate" id="sidebarUserRoleSub">@<?= htmlspecialchars($currentUser['username']) ?></span>
        </div>
      </div>
      <a href="logout.php" class="w-8 h-8 rounded-lg flex items-center justify-center text-on-surface-variant hover:bg-error-container hover:text-on-error-container transition-colors shrink-0" title="Keluar">
        <span class="material-symbols-outlined text-[18px]">logout</span>
      </a>
    </div>
  </aside>

  <!-- ========================================================================
       MAIN CONTENT AREA
       ======================================================================== -->
  <div class="lg:pl-64 min-h-screen flex flex-col">
    <!-- CLEAN HEADER -->
    <header class="sticky top-0 h-16 bg-surface/90 backdrop-blur-xl z-40 border-b border-outline-variant/30 px-4 sm:px-8 flex items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <button type="button" onclick="toggleMobileSidebar()" class="lg:hidden p-2 rounded-xl bg-surface-container text-on-surface">
          <span class="material-symbols-outlined">menu</span>
        </button>
        <h2 id="headerBreadcrumb" class="text-base font-heading font-semibold text-on-surface">Storage Box Saya</h2>
      </div>

      <div class="flex items-center gap-2.5">
        <!-- Connection Status Pill (Live ESP32 Status) -->
        <div
          id="headerConnPill"
          class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container text-on-surface-variant text-xs font-medium transition-all"
          title="Status Koneksi Perangkat ESP32"
        >
          <span id="headerConnDot" class="w-2 h-2 rounded-full bg-gray-400"></span>
          <span id="headerConnText">Memuat...</span>
        </div>

        <!-- Theme Toggle -->
        <button
          type="button"
          onclick="toggleTheme()"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface text-xs font-medium border border-outline-variant/30 transition-all"
          title="Ganti Tema Light / Dark"
        >
          <span class="material-symbols-outlined text-[16px] text-secondary" id="themeIcon">wb_sunny</span>
          <span id="themeLabel" class="hidden md:inline">Light</span>
        </button>
      </div>
    </header>

    <!-- ALARM BANNER (Only visible when security alert / buzzer is active) -->
    <div id="globalAlarmBanner" class="hidden bg-error-container text-on-error-container px-4 sm:px-8 py-3 border-b border-error/30">
      <div class="max-w-6xl mx-auto flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
          <span class="material-symbols-outlined text-[22px] animate-bounce">notifications_active</span>
          <span class="text-xs sm:text-sm font-medium" id="globalAlarmText">
            <strong>Peringatan Keamanan Aktif:</strong> Buzzer berbunyi akibat salah PIN berulang atau pintu dibiarkan terbuka &gt; 30 detik.
          </span>
        </div>
        <div id="globalAlarmAdminActions" class="<?= $isAdmin ? '' : 'hidden' ?> flex items-center gap-2 shrink-0">
          <button
            type="button"
            onclick="silenceAlarmAndResolve()"
            class="px-3.5 py-1.5 rounded-lg bg-on-error-container text-error-container text-xs font-semibold hover:opacity-90"
          >
            Matikan Alarm
          </button>
        </div>
      </div>
    </div>

    <!-- ======================================================================
         MAIN VIEWS
         ====================================================================== -->
    <main class="flex-1 px-4 sm:px-8 py-7 max-w-6xl w-full mx-auto">

      <!-- ====================================================================
           0. EMPTY STATE JIKA AKUN BELUM TERHUBUNG KE DEVICE MANA PUN
           ==================================================================== -->
      <div id="noDeviceAssignedCard" class="hidden vault-card rounded-2xl p-8 text-center max-w-xl mx-auto my-6 space-y-4">
        <div class="w-12 h-12 rounded-2xl bg-secondary-container text-on-secondary-container flex items-center justify-center mx-auto">
          <span class="material-symbols-outlined text-[26px]">inventory_2</span>
        </div>
        <h2 class="text-xl font-heading font-semibold text-on-surface">Belum Ada Storage Box yang Aktif</h2>
        <p class="text-xs sm:text-sm text-on-surface-variant leading-relaxed">
          Akun Anda belum memiliki akses yang disetujui (<b>Approved</b>) pada Storage Box mana pun, atau permintaan akses Anda masih berstatus <b>Pending</b>. Masukkan Kode Undangan dari Admin device atau daftarkan Storage Box baru.
        </p>
        <div class="pt-2">
          <button type="button" onclick="switchTab('devices')" class="px-5 py-2.5 rounded-xl bg-secondary text-on-secondary text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95">
            Gabung atau Tambah Storage Box
          </button>
        </div>
      </div>

      <!-- ====================================================================
           1. DASHBOARD VIEW (Tampil setelah memilih Storage Box yang dipantau)
           ==================================================================== -->
      <section id="view-dashboard" class="tab-view hidden space-y-6">
        <!-- Top Greeting & Quick Controls -->
        <div class="vault-card rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-5">
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h1 class="text-2xl font-heading font-semibold text-on-surface tracking-tight">
                Halo, <span id="greetingFullName"><?= htmlspecialchars($currentUser['full_name']) ?></span>
              </h1>
              <span id="dashboardDeviceBadge" class="px-2.5 py-0.5 rounded-full text-xs font-mono font-semibold bg-surface-container text-on-surface-variant">
                <?= htmlspecialchars(($ctx['name'] ?? 'Storage Box') . ' (' . ($ctx['code'] ?? '-') . ')') ?>
              </span>
            </div>
            <p class="text-sm text-on-surface-variant mt-1" id="greetingSubText">
              Pantau kondisi kotak penyimpanan dan kelola akses sesuai role Anda pada device ini.
            </p>
          </div>

          <!-- Action Buttons (Dynamically shown based on ADMIN vs USER role on active device) -->
          <div class="flex flex-wrap items-center gap-2.5 shrink-0">
            <button
              type="button"
              onclick="switchTab('devices')"
              class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface text-xs sm:text-sm font-medium transition-all"
            >
              <span class="material-symbols-outlined text-[18px]">swap_horiz</span>
              <span>Ganti Box</span>
            </button>

            <div id="adminQuickControls" class="<?= $isAdmin ? 'flex' : 'hidden' ?> flex-wrap items-center gap-2.5">
              <button
                type="button"
                onclick="adminRemoteLock()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary-container text-on-primary-container text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95 transition-all"
              >
                <span class="material-symbols-outlined text-[18px]">lock</span>
                <span>Kunci Kotak</span>
              </button>
              <button
                type="button"
                onclick="openEmergencyModal()"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-error-container text-on-error-container text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95 transition-all"
              >
                <span class="material-symbols-outlined text-[18px]">e911_emergency</span>
                <span>Emergency Unlock</span>
              </button>
            </div>

            <div id="userQuickControls" class="<?= !$isAdmin ? 'flex' : 'hidden' ?> flex-wrap items-center gap-2.5">
              <button
                type="button"
                onclick="switchTab('profile')"
                class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-secondary text-on-secondary text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95 transition-all"
              >
                <span class="material-symbols-outlined text-[18px]">dialpad</span>
                <span>PIN Device Ini</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Peringatan Koneksi ESP32 Terputus (Tampil saat ESP32 belum terhubung / Offline) -->
        <div id="offlineSensorWarning" class="vault-card rounded-2xl p-6 border border-error/40 bg-error-container/30 flex items-center gap-4">
          <div class="w-12 h-12 rounded-2xl bg-error-container text-on-error-container flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined text-[26px]">wifi_off</span>
          </div>
          <div>
            <h3 class="text-base font-heading font-semibold text-on-surface">Koneksi Perangkat ESP32 Terputus</h3>
            <p class="text-xs sm:text-sm text-on-surface-variant mt-0.5">
              Informasi sensor (Suhu, Kelembapan, Getaran, Kondisi Pintu, dan Status Kunci) tidak ditampilkan karena perangkat ESP32 belum terhubung. Nyalakan ESP32 dan pastikan tersambung ke Wi-Fi.
            </p>
          </div>
        </div>

        <!-- 5 Clean Status Cards (Hanya ditampilkan ketika ESP32 Online) -->
        <div id="sensorCardsGrid" class="hidden grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
          <!-- Status Kunci -->
          <div class="vault-card rounded-2xl p-5 flex flex-col justify-between gap-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-on-surface-variant">Status Kunci</span>
              <span class="material-symbols-outlined text-secondary text-[22px]" id="cardLockIcon">lock</span>
            </div>
            <div>
              <div id="cardLockStatus" class="text-2xl font-heading font-bold text-on-surface">Terkunci</div>
              <div id="cardLockSub" class="text-xs text-on-surface-variant mt-1">Servo terkunci rapat</div>
            </div>
          </div>

          <!-- Kondisi Pintu -->
          <div class="vault-card rounded-2xl p-5 flex flex-col justify-between gap-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-on-surface-variant">Kondisi Pintu</span>
              <span class="material-symbols-outlined text-tertiary text-[22px]">sensor_door</span>
            </div>
            <div>
              <div class="flex items-baseline gap-2">
                <span id="cardDoorStatus" class="text-2xl font-heading font-bold text-on-surface">Tertutup</span>
                <span id="cardDoorTimerBadge" class="text-xs font-mono text-on-surface-variant"></span>
              </div>
              <div id="cardDoorSub" class="text-xs text-on-surface-variant mt-1">Batas terbuka: 30 detik</div>
            </div>
          </div>

          <!-- Sensor Getaran (SW-420) -->
          <div class="vault-card rounded-2xl p-5 flex flex-col justify-between gap-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-on-surface-variant">Sensor Getaran</span>
              <span class="material-symbols-outlined text-secondary text-[22px]" id="cardVibIcon">vibration</span>
            </div>
            <div>
              <div class="flex items-baseline gap-2">
                <span id="cardVibStatus" class="text-2xl font-heading font-bold text-on-surface">Aman</span>
                <span id="cardVibBadge" class="text-xs px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container font-medium">Stabil</span>
              </div>
              <div id="cardVibSub" class="text-xs text-on-surface-variant mt-1">Tidak ada guncangan</div>
            </div>
          </div>

          <!-- Suhu -->
          <div class="vault-card rounded-2xl p-5 flex flex-col justify-between gap-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-on-surface-variant">Suhu</span>
              <span class="material-symbols-outlined text-secondary text-[22px]">thermostat</span>
            </div>
            <div>
              <div class="flex items-baseline gap-2">
                <span id="cardTempVal" class="text-2xl font-mono font-bold text-on-surface">-- °C</span>
                <span id="cardTempBadge" class="text-xs px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container font-medium">Normal</span>
              </div>
              <div class="text-xs text-on-surface-variant mt-1">Sensor DHT11</div>
            </div>
          </div>

          <!-- Kelembapan -->
          <div class="vault-card rounded-2xl p-5 flex flex-col justify-between gap-4">
            <div class="flex items-center justify-between">
              <span class="text-xs font-medium text-on-surface-variant">Kelembapan</span>
              <span class="material-symbols-outlined text-primary text-[22px]">water_drop</span>
            </div>
            <div>
              <div class="flex items-baseline gap-2">
                <span id="cardHumVal" class="text-2xl font-mono font-bold text-on-surface">-- %</span>
                <span id="cardHumBadge" class="text-xs px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container font-medium">Ideal</span>
              </div>
              <div class="text-xs text-on-surface-variant mt-1">Sensor DHT11</div>
            </div>
          </div>
        </div>

        <!-- 2 Columns: Aktivitas Terakhir & Peringatan Keamanan -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
          <!-- Aktivitas Terakhir (7 cols) -->
          <div class="lg:col-span-7 vault-card rounded-2xl p-6 flex flex-col gap-4">
            <div class="flex items-center justify-between">
              <h2 class="text-base font-heading font-semibold text-on-surface">Aktivitas Terakhir</h2>
              <button type="button" onclick="switchTab('logs')" class="text-xs font-medium text-secondary hover:underline">
                Lihat Semua
              </button>
            </div>
            <div id="dashboardRecentLogs" class="divide-y divide-outline-variant/25">
              <!-- Populated by JS -->
            </div>
          </div>

          <!-- Peringatan Keamanan (5 cols) -->
          <div class="lg:col-span-5 vault-card rounded-2xl p-6 flex flex-col gap-4">
            <div class="flex items-center justify-between">
              <h2 class="text-base font-heading font-semibold text-on-surface">Peringatan Keamanan</h2>
              <span id="dashboardAlertCountBadge" class="px-2.5 py-0.5 rounded-full bg-surface-container text-on-surface-variant text-xs font-medium">
                0 Aktif
              </span>
            </div>
            <div id="dashboardRecentAlerts" class="space-y-2.5">
              <!-- Populated by JS -->
            </div>
          </div>
        </div>
      </section>

      <!-- ====================================================================
           2. STORAGE BOX SAYA (HALAMAN UTAMA SETELAH LOGIN: PILIH BOX UNTUK DIPANTAU)
           ==================================================================== -->
      <section id="view-devices" class="tab-view space-y-6">
        <div class="vault-card rounded-2xl p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 class="text-2xl font-heading font-semibold text-on-surface tracking-tight">
              Pilih Storage Box untuk Dipantau
            </h1>
            <p class="text-xs sm:text-sm text-on-surface-variant mt-1">
              Satu akun dapat terhubung ke lebih dari 1 Storage Box. Role (<b>ADMIN</b> / <b>USER</b>) dan PIN 6-digit ditetapkan secara terpisah pada setiap box. Pilih salah satu box di bawah untuk membuka Dashboard pemantauan.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2 shrink-0">
            <a
              href="pair_box.php"
              class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-secondary text-on-secondary text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95 transition-all"
            >
              <span class="material-symbols-outlined text-[18px]">qr_code_scanner</span>
              <span>Tambah Box Baru (Scan QR)</span>
            </a>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
          <!-- Daftar Storage Box yang Terhubung dengan Akun Ini (8 cols) -->
          <div class="lg:col-span-8 vault-card rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between">
              <div>
                <h2 class="text-lg font-heading font-semibold text-on-surface">Daftar Storage Box Terhubung</h2>
                <p class="text-xs text-on-surface-variant mt-0.5">
                  Klik <b>Pantau Box Ini</b> untuk membuka Dashboard sensor dan kontrol pada perangkat yang dipilih.
                </p>
              </div>
            </div>

            <div id="myDevicesListContainer" class="space-y-3">
              <!-- Populated by JS -->
            </div>
          </div>

          <!-- Kolom Kanan: Gabung ke Storage Box Lain via Kode Undangan (4 cols) -->
          <div class="lg:col-span-4 space-y-6">
            <div class="vault-card rounded-2xl p-6 space-y-4">
              <div>
                <h3 class="text-base font-heading font-semibold text-on-surface">Gabung ke Storage Box Lain</h3>
                <p class="text-xs text-on-surface-variant mt-0.5">
                  Masukkan Kode Undangan (<code>INV-...</code>) dari Admin box untuk mengajukan permintaan akses sebagai anggota.
                </p>
              </div>
              <form onsubmit="submitRequestDeviceAccess(event)" class="space-y-3">
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Kode Undangan</label>
                  <input
                    type="text"
                    id="joinInviteCodeInput"
                    required
                    placeholder="Contoh: INV-BOX01-XXXX"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"
                  />
                </div>
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Catatan Permintaan (Opsional)</label>
                  <input
                    type="text"
                    id="joinRequestNoteInput"
                    placeholder="Contoh: Mohon akses untuk staf laboratorium"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"
                  />
                </div>
                <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-surface-container-high hover:bg-primary-container hover:text-on-primary-container text-on-surface text-xs sm:text-sm font-semibold transition-all">
                  Ajukan Permintaan Akses
                </button>
              </form>
            </div>
          </div>
        </div>
      </section>

      <!-- ====================================================================
           3. RIWAYAT & LOG
           ==================================================================== -->
      <section id="view-logs" class="tab-view hidden space-y-6">
        <div class="vault-card rounded-2xl p-6 space-y-4">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-heading font-semibold text-on-surface">Riwayat Akses</h2>
              <p class="text-xs text-on-surface-variant mt-0.5" id="logsPermissionSubtitle">
                Catatan aktivitas pembukaan kunci, sensor, dan kontrol jarak jauh pada Storage Box ini.
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
              <button type="button" onclick="setLogFilter('ALL')" data-filter="ALL" class="log-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-primary-container text-on-primary-container">Semua</button>
              <button type="button" onclick="setLogFilter('KEYPAD')" data-filter="KEYPAD" class="log-filter-btn px-3 py-1.5 rounded-xl text-xs font-medium bg-surface-container text-on-surface-variant">Keypad</button>
              <button type="button" onclick="setLogFilter('REMOTE')" data-filter="REMOTE" class="log-filter-btn px-3 py-1.5 rounded-xl text-xs font-medium bg-surface-container text-on-surface-variant">Remote</button>
              <button type="button" onclick="setLogFilter('OFFLINE_SYNC')" data-filter="OFFLINE_SYNC" class="log-filter-btn px-3 py-1.5 rounded-xl text-xs font-medium bg-surface-container text-on-surface-variant">Offline</button>
              <button type="button" onclick="setLogFilter('EMERGENCY')" data-filter="EMERGENCY" class="log-filter-btn px-3 py-1.5 rounded-xl text-xs font-medium bg-surface-container text-on-surface-variant">Darurat</button>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
              <thead>
                <tr class="border-b border-outline-variant/30 text-on-surface-variant text-xs font-medium">
                  <th class="py-3 px-3">Waktu</th>
                  <th class="py-3 px-3">Pengguna</th>
                  <th class="py-3 px-3">Metode</th>
                  <th class="py-3 px-3">Aksi</th>
                  <th class="py-3 px-3 text-right">Status</th>
                </tr>
              </thead>
              <tbody id="fullAccessLogTableBody" class="divide-y divide-outline-variant/20 text-sm">
                <!-- Populated by JS -->
              </tbody>
            </table>
          </div>
        </div>

        <div class="vault-card rounded-2xl p-6 space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-heading font-semibold text-on-surface">Riwayat Peringatan Keamanan</h2>
            <button type="button" id="btnResolveAllLogsTab" onclick="silenceAlarmAndResolve()" class="<?= $isAdmin ? '' : 'hidden' ?> text-xs font-semibold text-secondary hover:underline">
              Tandai Semua Selesai
            </button>
          </div>
          <div id="fullAlertLogContainer" class="divide-y divide-outline-variant/20">
            <!-- Populated by JS -->
          </div>
        </div>
      </section>

      <!-- ====================================================================
           4. KELOLA PENGGUNA & PERSETUJUAN AKSES DEVICE (KHUSUS ADMIN DEVICE)
           ==================================================================== -->
      <section id="view-users" class="tab-view hidden space-y-6">
        <!-- Kartu Kode Undangan Device -->
        <div class="vault-card rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div class="flex items-center gap-2">
              <h2 class="text-base font-heading font-semibold text-on-surface">Kode Undangan Storage Box Ini</h2>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-tertiary-container text-on-tertiary-container">Sekali Pakai / Berbatas Waktu</span>
            </div>
            <p class="text-xs text-on-surface-variant mt-1">
              Bagikan kode unik ini kepada pengguna agar mereka dapat mengajukan permintaan akses ke Storage Box ini.
            </p>
            <div class="text-[11px] font-mono text-on-surface-variant mt-1" id="inviteExpiryText"></div>
          </div>

          <div class="flex flex-wrap items-center gap-2 shrink-0">
            <div class="px-4 py-2 rounded-xl bg-surface-container-low border border-outline-variant/40 font-mono text-sm font-bold tracking-wider text-secondary" id="currentInviteCodeDisplay">
              MEMUAT...
            </div>
            <button type="button" onclick="copyInviteCode()" class="px-3.5 py-2 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface text-xs font-semibold">
              Salin Kode
            </button>
            <button type="button" onclick="regenerateInviteCode()" class="px-3.5 py-2 rounded-xl bg-primary-container text-on-primary-container text-xs font-semibold">
              Buat Kode Baru
            </button>
          </div>
        </div>

        <!-- Daftar Permintaan Akses Masuk (PENDING) -->
        <div id="pendingRequestsCard" class="hidden vault-card rounded-2xl p-6 space-y-4 border border-secondary/40">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-base font-heading font-semibold text-on-surface">Permintaan Akses Masuk (Pending)</h2>
              <p class="text-xs text-on-surface-variant mt-0.5">Setujui dan tentukan Role (Admin / User) atau tolak permintaan akses di bawah ini.</p>
            </div>
          </div>
          <div id="pendingRequestsContainer" class="divide-y divide-outline-variant/25">
            <!-- Populated by JS -->
          </div>
        </div>

        <!-- Tabel Pengguna pada Storage Box Ini (device_users) -->
        <div class="vault-card rounded-2xl p-6 space-y-5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-heading font-semibold text-on-surface">Daftar Pengguna pada Storage Box Ini</h2>
              <p class="text-xs text-on-surface-variant mt-0.5">
                Kelola Role (Admin / User), PIN 6-digit khusus device ini, izin riwayat log, atau cabut akses (<i>Revoke</i>) tanpa memengaruhi device lain.
              </p>
            </div>
            <button
              type="button"
              onclick="openUserModal()"
              class="px-4 py-2 rounded-xl bg-secondary text-on-secondary text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95 flex items-center gap-1.5 self-start sm:self-auto"
            >
              <span class="material-symbols-outlined text-[18px]">add</span>
              <span>Tambah Pengguna ke Box Ini</span>
            </button>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
              <thead>
                <tr class="border-b border-outline-variant/30 text-on-surface-variant text-xs font-medium">
                  <th class="py-3 px-3">Nama &amp; Akun</th>
                  <th class="py-3 px-3">Role di Box Ini</th>
                  <th class="py-3 px-3">PIN 6-Digit</th>
                  <th class="py-3 px-3">Izin Riwayat</th>
                  <th class="py-3 px-3">Status Akses</th>
                  <th class="py-3 px-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody id="adminUsersTableBody" class="divide-y divide-outline-variant/20 text-sm">
                <!-- Populated by JS -->
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ====================================================================
           5. PENGATURAN ALARM & AKSES DARURAT (KHUSUS ADMIN DEVICE)
           ==================================================================== -->
      <section id="view-settings" class="tab-view hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
          <!-- Pengaturan Alarm, Sensor & ESP32 -->
          <div class="lg:col-span-7 vault-card rounded-2xl p-6 space-y-5">
            <div>
              <h2 class="text-lg font-heading font-semibold text-on-surface">Pengaturan Alarm &amp; Perangkat</h2>
              <p class="text-xs text-on-surface-variant mt-0.5">Konfigurasi batas peringatan keamanan, sensor DHT11, dan API Key khusus ESP32 ini.</p>
            </div>

            <form id="alarmSettingsForm" onsubmit="saveAlarmSettings(event)" class="space-y-4">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Nama Storage Box</label>
                  <input type="text" id="setDeviceName" required class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
                </div>
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Kode Perangkat (DEVICE_CODE)</label>
                  <input type="text" id="setDeviceCode" required class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
                </div>
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Batas Salah PIN (Kali)</label>
                  <input type="number" id="setMaxPinAttempts" min="1" max="10" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
                </div>
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Batas Pintu Terbuka (Detik)</label>
                  <input type="number" id="setDoorTimeoutSec" min="5" max="300" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
                </div>
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Batas Suhu Aman (°C)</label>
                  <input type="number" step="0.5" id="setTempThreshold" min="20" max="60" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
                </div>
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Batas Kelembapan (%)</label>
                  <input type="number" id="setHumThreshold" min="30" max="95" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
                </div>
                <div>
                  <label class="text-xs font-medium text-on-surface-variant block mb-1">Waktu Auto-Lock (Detik)</label>
                  <input type="number" id="setAutoLockSec" min="3" max="120" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
                </div>
              </div>

              <div>
                <div class="flex items-center justify-between mb-1">
                  <label class="text-xs font-medium text-on-surface-variant">API Key Perangkat ESP32 (Khusus Hardware / Admin)</label>
                  <button type="button" onclick="copyDeviceApiKey()" class="text-xs text-secondary font-medium hover:underline">Salin API Key</button>
                </div>
                <input type="text" id="setDeviceApiKey" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-xs font-mono text-on-surface"/>
              </div>

              <div class="pt-2 flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-secondary text-on-secondary text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95">
                  Simpan Pengaturan
                </button>
              </div>
            </form>
          </div>

          <!-- Emergency Access -->
          <div class="lg:col-span-5 vault-card rounded-2xl p-6 flex flex-col justify-between gap-4">
            <div class="space-y-3">
              <h2 class="text-lg font-heading font-semibold text-on-surface">Emergency Access</h2>
              <p class="text-xs sm:text-sm text-on-surface-variant leading-relaxed">
                Buka kunci kotak secara langsung dalam kondisi darurat (misalnya pengguna lupa PIN atau keypad terkunci).
              </p>
              <div>
                <label class="text-xs font-medium text-on-surface-variant block mb-1" for="emergencyReasonInput">Catatan Alasan Darurat</label>
                <textarea id="emergencyReasonInput" rows="3" class="w-full p-3 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface">Pembukaan kunci darurat oleh Admin</textarea>
              </div>
            </div>

            <div class="space-y-2">
              <button
                type="button"
                onclick="executeEmergencyUnlockFromSettings()"
                class="w-full py-2.5 px-4 rounded-xl bg-error text-on-error text-sm font-semibold shadow-sm hover:opacity-95 transition-all"
              >
                Buka Kunci Darurat
              </button>
              <button
                type="button"
                onclick="silenceAlarmAndResolve()"
                class="w-full py-2.5 px-4 rounded-xl bg-surface-container-high text-on-surface text-xs font-medium hover:opacity-90 transition-all"
              >
                Matikan Buzzer &amp; Reset Lockout
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- ====================================================================
           6. PIN PER DEVICE & PROFIL AKUN GLOBAL
           ==================================================================== -->
      <section id="view-profile" class="tab-view hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- PIN 6-Digit Khusus Device Ini -->
          <div class="vault-card rounded-2xl p-6 space-y-4">
            <div>
              <h2 class="text-lg font-heading font-semibold text-on-surface">
                PIN Keypad 6-Digit <span id="profilePinDeviceLabel" class="text-secondary"></span>
              </h2>
              <p class="text-xs text-on-surface-variant mt-0.5">
                PIN dibuat berbeda untuk setiap kombinasi akun dan Storage Box, serta disimpan dalam bentuk hash.
              </p>
            </div>

            <div class="p-4 rounded-xl bg-surface-container-low flex items-center justify-between">
              <span class="text-xs text-on-surface-variant">Status PIN pada Device Ini</span>
              <span id="profileCurrentPin" class="text-sm font-mono font-bold text-secondary">
                Memuat...
              </span>
            </div>

            <form onsubmit="updateOwnPin(event)" class="space-y-3">
              <div>
                <label class="text-xs font-medium text-on-surface-variant block mb-1" for="newOwnPinInput">PIN 6-Digit Baru (Khusus Storage Box Aktif)</label>
                <input type="password" inputmode="numeric" id="newOwnPinInput" maxlength="6" pattern="\d{6}" required placeholder="Contoh: 556677" class="w-full px-4 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 font-mono text-sm tracking-widest text-on-surface"/>
              </div>
              <button type="submit" class="w-full py-2.5 px-5 rounded-xl bg-secondary text-on-secondary text-sm font-semibold shadow-sm hover:opacity-95">
                Simpan PIN Device Ini
              </button>
            </form>
          </div>

          <!-- Profil Akun Global -->
          <div class="vault-card rounded-2xl p-6 space-y-4">
            <div>
              <h2 class="text-lg font-heading font-semibold text-on-surface">Profil Akun Utama</h2>
              <p class="text-xs text-on-surface-variant mt-0.5">Satu akun berlaku untuk mengakses seluruh Storage Box Anda.</p>
            </div>

            <form onsubmit="updateOwnProfile(event)" class="space-y-3">
              <div>
                <label class="text-xs font-medium text-on-surface-variant block mb-1" for="profileFullName">Nama Lengkap</label>
                <input type="text" id="profileFullName" required value="<?= htmlspecialchars($currentUser['full_name']) ?>" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
              </div>
              <div>
                <label class="text-xs font-medium text-on-surface-variant block mb-1" for="profileNewPassword">Password Login Baru (Opsional)</label>
                <input type="password" id="profileNewPassword" placeholder="Kosongkan jika tidak diubah" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
              </div>
              <button type="submit" class="w-full py-2.5 px-5 rounded-xl bg-primary-container text-on-primary-container text-sm font-semibold shadow-sm hover:opacity-95">
                Simpan Profil
              </button>
            </form>
          </div>
        </div>
      </section>

    </main>
  </div>

  <!-- ========================================================================
       MODALS (ADMIN DEVICE)
       ======================================================================== -->
  <div id="userModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="vault-card rounded-2xl max-w-md w-full p-6 space-y-4">
      <div class="flex items-center justify-between pb-2 border-b border-outline-variant/30">
        <h3 id="userModalTitle" class="text-base font-heading font-semibold text-on-surface">Tambah / Atur Akses Pengguna</h3>
        <button type="button" onclick="closeUserModal()" class="text-on-surface-variant hover:text-on-surface">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <form id="userModalForm" onsubmit="submitUserModal(event)" class="space-y-3">
        <input type="hidden" id="modalRelationId" value=""/>
        <div>
          <label class="text-xs font-medium text-on-surface-variant block mb-1">Username</label>
          <input type="text" id="modalUsername" required placeholder="Username akun baru atau yang sudah ada" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
        </div>
        <div>
          <label class="text-xs font-medium text-on-surface-variant block mb-1">Nama Lengkap</label>
          <input type="text" id="modalFullName" placeholder="Wajib diisi jika membuat akun baru" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1">Role pada Device Ini</label>
            <select id="modalRole" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface">
              <option value="USER">User</option>
              <option value="ADMIN">Admin</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1">PIN 6-Digit Device Ini</label>
            <input type="text" id="modalPin" maxlength="6" pattern="\d{6}" placeholder="Contoh: 123456" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"/>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1">Izin Riwayat Akses</label>
            <select id="modalCanViewLogs" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface">
              <option value="1">Diizinkan Lihat Log</option>
              <option value="0">Hanya Aktivitas Sendiri</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-medium text-on-surface-variant block mb-1">Status Akses</label>
            <select id="modalStatus" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface">
              <option value="APPROVED">Approved (Aktif)</option>
              <option value="PENDING">Pending</option>
              <option value="REVOKED">Revoked (Dicabut)</option>
              <option value="REJECTED">Rejected (Ditolak)</option>
            </select>
          </div>
        </div>
        <div id="modalPasswordWrap">
          <label class="text-xs font-medium text-on-surface-variant block mb-1">Password Login (Jika Akun Baru)</label>
          <input type="password" id="modalPassword" placeholder="Kosongkan jika akun sudah terdaftar" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
        </div>

        <div class="pt-2 flex justify-end gap-2">
          <button type="button" onclick="closeUserModal()" class="px-4 py-2 rounded-xl bg-surface-container text-on-surface text-xs font-medium">Batal</button>
          <button type="submit" class="px-5 py-2 rounded-xl bg-secondary text-on-secondary text-xs font-semibold">Simpan</button>
        </div>
      </form>
    </div>
  </div>

  <div id="emergencyModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="vault-card rounded-2xl max-w-md w-full p-6 space-y-4">
      <div class="flex items-center justify-between pb-2 border-b border-outline-variant/30">
        <h3 class="text-base font-heading font-semibold text-error">Buka Kunci Darurat</h3>
        <button type="button" onclick="closeEmergencyModal()" class="text-on-surface-variant hover:text-on-surface">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <p class="text-xs sm:text-sm text-on-surface-variant">
        Kotak akan segera dibuka dari jarak jauh dan tindakan ini akan tercatat di riwayat log.
      </p>
      <div>
        <label class="text-xs font-medium text-on-surface-variant block mb-1">Alasan Darurat</label>
        <input type="text" id="modalEmergencyReason" value="Akses darurat oleh Admin" class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"/>
      </div>
      <div class="pt-2 flex justify-end gap-2">
        <button type="button" onclick="closeEmergencyModal()" class="px-4 py-2 rounded-xl bg-surface-container text-on-surface text-xs font-medium">Batal</button>
        <button type="button" onclick="confirmEmergencyModal()" class="px-5 py-2 rounded-xl bg-error text-on-error text-xs font-semibold">Buka Sekarang</button>
      </div>
    </div>
  </div>

  <!-- TOAST -->
  <div id="appToast" class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 px-4 py-2.5 rounded-xl bg-primary-container text-on-primary-container shadow-lg text-xs sm:text-sm font-medium flex items-center gap-2 transition-all duration-300 opacity-0 pointer-events-none translate-y-8">
    <span class="material-symbols-outlined text-[18px]" id="appToastIcon">check_circle</span>
    <span id="appToastText">Berhasil</span>
  </div>

  <script>
    window.SECBOX_CONFIG = {
      csrf: <?= json_encode(csrf_token()) ?>,
      isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
      userId: <?= (int)$currentUser['id'] ?>,
      username: <?= json_encode($currentUser['username']) ?>,
      fullName: <?= json_encode($currentUser['full_name']) ?>
    };
  </script>
  <script src="assets/js/app.js"></script>
</body>
</html>
