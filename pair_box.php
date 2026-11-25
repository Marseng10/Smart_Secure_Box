<?php
/**
 * Smart Secure Storage Box - Halaman Khusus Pendaftaran Box Baru (Scan QR + Pairing Bluetooth BLE)
 * Alur:
 * 1. Scan QR yang berisi Kode Unik Box (bukan URL).
 * 2. Daftarkan Box & atur PIN 6-digit awal (Akun otomatis menjadi ADMIN pada box ini di `device_users`).
 * 3. Sambungkan Bluetooth (BLE) ke ESP32 untuk mengirim SSID & Password Wi-Fi ke memori NVS ESP32.
 * 4. Setelah selesai atau dibatalkan, kembali ke halaman `index.php` (Storage Box Saya).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentUser = require_login();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pendaftaran Storage Box Baru (Scan QR &amp; Bluetooth) — Smart Secure Box</title>
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
<body class="bg-background text-on-surface antialiased min-h-screen flex flex-col">

  <!-- TOP HEADER -->
  <header class="sticky top-0 h-16 bg-surface/90 backdrop-blur-xl z-40 border-b border-outline-variant/30 px-4 sm:px-8 flex items-center justify-between gap-4">
    <div class="flex items-center gap-3">
      <a
        href="index.php"
        onclick="cancelAndReturn(event)"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-surface-container hover:bg-error-container hover:text-on-error-container text-on-surface text-xs font-semibold transition-all"
      >
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Batalkan &amp; Kembali</span>
      </a>
      <h1 class="text-sm sm:text-base font-heading font-semibold text-on-surface">
        Pendaftaran Storage Box Baru
      </h1>
    </div>

    <div class="flex items-center gap-2.5">
      <button
        type="button"
        onclick="toggleTheme()"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface text-xs font-medium border border-outline-variant/30 transition-all"
      >
        <span class="material-symbols-outlined text-[16px] text-secondary" id="themeIcon">wb_sunny</span>
        <span id="themeLabel" class="hidden md:inline">Light</span>
      </button>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <main class="flex-1 px-4 sm:px-8 py-8 max-w-4xl w-full mx-auto space-y-6">
    <!-- Banner Penjelasan Alur -->
    <div class="vault-card rounded-2xl p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div class="space-y-1">
        <div class="flex items-center gap-2">
          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-secondary text-on-secondary">PAIRING PERANGKAT BARU</span>
          <span class="text-xs font-mono text-on-surface-variant">Akun: @<?= htmlspecialchars($currentUser['username']) ?></span>
        </div>
        <h2 class="text-xl font-heading font-semibold text-on-surface">
          Scan QR Kode Box &amp; Sambungkan via Bluetooth
        </h2>
        <p class="text-xs sm:text-sm text-on-surface-variant leading-relaxed">
          Arahkan kamera ke kode QR pada Storage Box (berisi kode perangkat, bukan URL), lalu sambungkan Bluetooth untuk mengatur Wi-Fi ESP32. Anda otomatis menjadi <b>ADMIN</b> pada box yang didaftarkan.
        </p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- KOLOM KIRI: LANGKAH 1 — SCAN QR KODE BOX (6 cols) -->
      <div class="lg:col-span-6 vault-card rounded-2xl p-6 space-y-4 flex flex-col justify-between">
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="w-6 h-6 rounded-full bg-secondary text-on-secondary text-xs font-bold flex items-center justify-center">1</span>
              <h3 class="text-base font-heading font-semibold text-on-surface">Scan QR Kode Box</h3>
            </div>
            <span id="scanStatusBadge" class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-surface-container text-on-surface-variant">
              Menunggu Scan QR
            </span>
          </div>

          <p class="text-xs text-on-surface-variant leading-relaxed">
            QR Code pada perangkat hanya berisi <b>Kode Unik Box</b> (contoh: <code>BOX-01</code> atau <code>BOX-02</code>), bukan URL.
          </p>

          <!-- Area Kamera QR -->
          <div id="qrReaderBox" class="w-full overflow-hidden rounded-2xl bg-surface-container-low border border-outline-variant/40 min-h-[240px] flex items-center justify-center">
            <div class="text-center p-6 space-y-2 text-on-surface-variant">
              <span class="material-symbols-outlined text-[36px] text-secondary">qr_code_scanner</span>
              <p class="text-xs">Klik tombol <b>Nyalakan Kamera Scan QR</b> atau pilih gambar QR di bawah.</p>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              id="btnStartCamera"
              onclick="startPairQrCamera()"
              class="flex-1 py-2.5 px-4 rounded-xl bg-secondary text-on-secondary text-xs font-semibold shadow-sm hover:opacity-95 flex items-center justify-center gap-1.5"
            >
              <span class="material-symbols-outlined text-[18px]">photo_camera</span>
              <span>Nyalakan Kamera Scan QR</span>
            </button>

            <button
              type="button"
              id="btnStopCamera"
              onclick="stopPairQrCamera()"
              class="hidden py-2.5 px-4 rounded-xl bg-error-container text-on-error-container text-xs font-semibold hover:opacity-95"
            >
              Matikan Kamera
            </button>

            <label class="py-2.5 px-3.5 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface text-xs font-semibold cursor-pointer flex items-center gap-1.5 border border-outline-variant/30">
              <span class="material-symbols-outlined text-[17px] text-secondary">upload_file</span>
              <span>Scan dari Gambar QR</span>
              <input type="file" id="qrImageFileInput" accept="image/*" class="hidden" onchange="scanQrFromFile(event)"/>
            </label>
          </div>
        </div>

        <div class="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/30 flex items-center justify-between">
          <span class="text-xs text-on-surface-variant">Kode Box Terbaca dari QR:</span>
          <span id="scannedCodePreview" class="font-mono text-sm font-bold text-secondary">BELUM DI-SCAN</span>
        </div>
      </div>

      <!-- KOLOM KANAN: LANGKAH 2 & 3 — KLAIM BOX & PAIRING BLUETOOTH WIFI (6 cols) -->
      <div class="lg:col-span-6 space-y-6">
        <!-- Langkah 2: Form Daftarkan Box Hasil Scan QR -->
        <div class="vault-card rounded-2xl p-6 space-y-4">
          <div class="flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-secondary text-on-secondary text-xs font-bold flex items-center justify-center">2</span>
            <h3 class="text-base font-heading font-semibold text-on-surface">Detail &amp; PIN Awal Storage Box</h3>
          </div>

          <form onsubmit="submitClaimPairedDevice(event)" class="space-y-3.5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-medium text-on-surface-variant block mb-1">Kode Box (Hasil Scan QR)</label>
                <input
                  type="text"
                  id="pairDevCodeInput"
                  required
                  readonly
                  placeholder="Scan QR terlebih dahulu"
                  class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container border border-outline-variant/40 text-sm font-mono font-bold text-secondary cursor-not-allowed"
                />
              </div>
              <div>
                <label class="text-xs font-medium text-on-surface-variant block mb-1">PIN Keypad 6-Digit Awal</label>
                <input
                  type="password"
                  inputmode="numeric"
                  maxlength="6"
                  pattern="\d{6}"
                  id="pairDevPinInput"
                  required
                  placeholder="Contoh: 123456"
                  class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm font-mono text-on-surface"
                />
              </div>
            </div>

            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Nama Storage Box</label>
              <input
                type="text"
                id="pairDevNameInput"
                required
                placeholder="Contoh: Storage Box Utama"
                class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 text-sm text-on-surface"
              />
            </div>

            <button
              type="submit"
              id="btnSubmitClaimBox"
              class="w-full py-2.5 px-4 rounded-xl bg-primary-container text-on-primary-container text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95 flex items-center justify-center gap-2"
            >
              <span class="material-symbols-outlined text-[18px]">verified_user</span>
              <span>Simpan &amp; Daftarkan Box (Jadi ADMIN)</span>
            </button>
          </form>
        </div>

        <!-- Langkah 3: Pairing Bluetooth (BLE) untuk Mengatur Wi-Fi ESP32 -->
        <div class="vault-card rounded-2xl p-6 space-y-4">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="w-6 h-6 rounded-full bg-secondary text-on-secondary text-xs font-bold flex items-center justify-center">3</span>
              <h3 class="text-base font-heading font-semibold text-on-surface">Koneksi Bluetooth &amp; Wi-Fi ESP32</h3>
            </div>
            <span id="bleStatusBadge" class="px-2.5 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-surface-container text-on-surface-variant">
              Belum Terhubung
            </span>
          </div>

          <p class="text-xs text-on-surface-variant leading-relaxed">
            Pastikan Bluetooth HP/Laptop menyala untuk mengirim SSID &amp; Password Wi-Fi ke memori NVS ESP32.
          </p>

          <button
            type="button"
            onclick="connectBleProvisioning()"
            class="w-full py-2.5 px-4 rounded-xl bg-surface-container hover:bg-surface-container-high text-on-surface text-xs font-semibold border border-outline-variant/40 flex items-center justify-center gap-2"
          >
            <span class="material-symbols-outlined text-[18px] text-secondary">bluetooth_searching</span>
            <span id="btnBleConnectLabel">Cari &amp; Sambungkan Bluetooth ESP32</span>
          </button>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Nama Wi-Fi (SSID)</label>
              <input
                type="text"
                id="bleWifiSsidInput"
                placeholder="Nama Wi-Fi lokasi"
                class="w-full px-3.5 py-2 rounded-xl bg-surface-container-low border border-outline-variant/40 text-xs text-on-surface"
              />
            </div>
            <div>
              <label class="text-xs font-medium text-on-surface-variant block mb-1">Password Wi-Fi</label>
              <input
                type="password"
                id="bleWifiPassInput"
                placeholder="Password Wi-Fi"
                class="w-full px-3.5 py-2 rounded-xl bg-surface-container-low border border-outline-variant/40 text-xs text-on-surface"
              />
            </div>
          </div>

          <button
            type="button"
            onclick="sendWifiViaBle()"
            class="w-full py-2.5 px-4 rounded-xl bg-secondary text-on-secondary text-xs font-semibold shadow-sm hover:opacity-95 flex items-center justify-center gap-1.5"
          >
            <span class="material-symbols-outlined text-[18px]">wifi_tethering</span>
            <span>Kirim Wi-Fi ke ESP32 via Bluetooth</span>
          </button>
        </div>

        <!-- Tombol Selesai / Batalkan -->
        <div class="flex items-center justify-between gap-3 pt-1">
          <button
            type="button"
            onclick="cancelAndReturn(event)"
            class="px-4 py-2.5 rounded-xl bg-surface-container hover:bg-error-container hover:text-on-error-container text-on-surface text-xs font-semibold transition-all"
          >
            Batalkan Pendaftaran
          </button>
          <a
            href="index.php"
            class="px-5 py-2.5 rounded-xl bg-tertiary-container text-on-tertiary-container text-xs sm:text-sm font-semibold shadow-sm hover:opacity-95 flex items-center gap-1.5"
          >
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            <span>Selesai &amp; Kembali ke Storage Box Saya</span>
          </a>
        </div>
      </div>
    </div>
  </main>

  <!-- TOAST -->
  <div id="appToast" class="fixed bottom-5 left-1/2 -translate-x-1/2 z-50 px-4 py-2.5 rounded-xl bg-primary-container text-on-primary-container shadow-lg text-xs sm:text-sm font-medium flex items-center gap-2 transition-all duration-300 opacity-0 pointer-events-none translate-y-8">
    <span class="material-symbols-outlined text-[18px]" id="appToastIcon">check_circle</span>
    <span id="appToastText">Berhasil</span>
  </div>

  <script>
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    const BLE_SERVICE_UUID = '4fafc201-1fb5-459e-8fcc-c5c9c331914b';
    const BLE_CHAR_UUID    = 'beb5483e-36e1-4688-b7f5-ea07361b26a8';

    let qrScannerInstance = null;
    let bleDevice = null;
    let bleChar = null;
    let claimedApiKey = '';
    let isBoxRegistered = false;

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

    function showToast(text, icon = 'check_circle') {
      const toast = document.getElementById('appToast');
      const tIcon = document.getElementById('appToastIcon');
      const tText = document.getElementById('appToastText');
      if (!toast || !tText) return;
      if (tIcon) tIcon.textContent = icon;
      tText.textContent = text;
      toast.classList.remove('opacity-0', 'pointer-events-none', 'translate-y-8');
      toast.classList.add('opacity-100', 'translate-y-0');
      clearTimeout(window._toastTimer);
      window._toastTimer = setTimeout(() => {
        toast.classList.add('opacity-0', 'pointer-events-none', 'translate-y-8');
        toast.classList.remove('opacity-100', 'translate-y-0');
      }, 3600);
    }

    async function startPairQrCamera() {
      if (typeof Html5Qrcode === 'undefined') {
        showToast('Library scanner QR belum termuat.', 'error');
        return;
      }
      await stopPairQrCamera();
      try {
        qrScannerInstance = new Html5Qrcode('qrReaderBox');
        document.getElementById('btnStartCamera').classList.add('hidden');
        document.getElementById('btnStopCamera').classList.remove('hidden');
        await qrScannerInstance.start(
          { facingMode: 'environment' },
          { fps: 10, qrbox: { width: 210, height: 210 } },
          (decodedText) => {
            onQrCodeScanned(decodedText);
          },
          () => {}
        );
      } catch (err) {
        document.getElementById('btnStartCamera').classList.remove('hidden');
        document.getElementById('btnStopCamera').classList.add('hidden');
        showToast('Kamera tidak dapat diakses. Gunakan opsi "Scan dari Gambar QR" atau izinkan akses kamera.', 'error');
      }
    }

    async function stopPairQrCamera() {
      if (qrScannerInstance) {
        try {
          if (qrScannerInstance.isScanning) {
            await qrScannerInstance.stop();
          }
          await qrScannerInstance.clear();
        } catch (e) {}
        qrScannerInstance = null;
      }
      document.getElementById('btnStartCamera').classList.remove('hidden');
      document.getElementById('btnStopCamera').classList.add('hidden');
    }

    async function scanQrFromFile(e) {
      const file = e.target.files?.[0];
      if (!file) return;
      await stopPairQrCamera();
      try {
        const html5QrCode = new Html5Qrcode('qrReaderBox');
        const decodedText = await html5QrCode.scanFile(file, true);
        onQrCodeScanned(decodedText);
      } catch (err) {
        showToast('Kode QR tidak terbaca pada gambar tersebut.', 'error');
      }
    }

    function onQrCodeScanned(rawText) {
      const clean = String(rawText || '').trim();
      if (!clean) return;

      // Tolak jika isi QR berupa URL
      if (/^https?:\/\//i.test(clean)) {
        showToast('QR Code tidak boleh berisi URL! Gunakan QR yang hanya berisi Kode Box (mis. BOX-01).', 'error');
        return;
      }

      stopPairQrCamera();

      const codeOnly = clean.split('|')[0].trim().toUpperCase();
      document.getElementById('pairDevCodeInput').value = clean.toUpperCase();
      document.getElementById('scannedCodePreview').textContent = codeOnly;

      const badge = document.getElementById('scanStatusBadge');
      badge.textContent = 'QR Terbaca: ' + codeOnly;
      badge.className = 'px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-tertiary-container text-on-tertiary-container';

      const nameEl = document.getElementById('pairDevNameInput');
      if (!nameEl.value.trim()) {
        nameEl.value = 'Smart Secure Box (' + codeOnly + ')';
      }

      showToast('Kode Box ' + codeOnly + ' berhasil di-scan! Lanjutkan langkah 2 & 3.', 'qr_code_scanner');
    }

    async function submitClaimPairedDevice(e) {
      e.preventDefault();
      const rawCode = document.getElementById('pairDevCodeInput').value.trim();
      const devName = document.getElementById('pairDevNameInput').value.trim();
      const pin     = document.getElementById('pairDevPinInput').value.trim();

      if (!rawCode) {
        showToast('Silakan Scan QR Kode Box terlebih dahulu pada Langkah 1!', 'warning');
        return;
      }

      try {
        const res = await fetch('api/command.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            csrf: CSRF_TOKEN,
            command: 'CLAIM_DEVICE_QR',
            device_code: rawCode,
            device_name: devName,
            pin: pin
          })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          showToast(data.error || 'Gagal mendaftarkan Storage Box', 'error');
          return;
        }

        isBoxRegistered = true;
        claimedApiKey = data.api_key || '';
        showToast(data.message || 'Storage Box berhasil didaftarkan!', 'check_circle');

        // Jika Bluetooth belum digunakan atau sudah selesai, beri opsi kembali atau lanjut Bluetooth
        const btn = document.getElementById('btnSubmitClaimBox');
        if (btn) {
          btn.innerHTML = '<span class="material-symbols-outlined text-[18px]">check_circle</span><span>Box Terdaftar sebagai ADMIN (Lanjut Bluetooth atau Klik Selesai)</span>';
          btn.className = 'w-full py-2.5 px-4 rounded-xl bg-tertiary-container text-on-tertiary-container text-xs sm:text-sm font-semibold flex items-center justify-center gap-2';
        }
      } catch (err) {
        showToast('Gagal menghubungi server.', 'error');
      }
    }

    async function connectBleProvisioning() {
      if (!navigator.bluetooth) {
        showToast('Browser ini belum mendukung Web Bluetooth. Gunakan Chrome / Edge.', 'error');
        return;
      }

      const badge = document.getElementById('bleStatusBadge');
      const btnLabel = document.getElementById('btnBleConnectLabel');

      try {
        btnLabel.textContent = 'Mencari Bluetooth ESP32...';
        const device = await navigator.bluetooth.requestDevice({
          filters: [{ namePrefix: 'SmartBox' }],
          optionalServices: [BLE_SERVICE_UUID]
        });

        bleDevice = device;
        device.addEventListener('gattserverdisconnected', () => {
          bleChar = null;
          badge.textContent = 'Terputus';
          badge.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-error-container text-on-error-container';
          btnLabel.textContent = 'Cari & Sambungkan Bluetooth ESP32';
        });

        const server = await device.gatt.connect();
        const service = await server.getPrimaryService(BLE_SERVICE_UUID);
        const characteristic = await service.getCharacteristic(BLE_CHAR_UUID);
        bleChar = characteristic;

        // Jika QR belum di-scan, baca kode dari BLE jika tersedia
        try {
          const val = await characteristic.readValue();
          const infoStr = new TextDecoder('utf-8').decode(val).trim();
          if (infoStr) {
            const info = JSON.parse(infoStr);
            if (info.ssid && !document.getElementById('bleWifiSsidInput').value.trim()) {
              document.getElementById('bleWifiSsidInput').value = info.ssid;
            }
          }
        } catch (e) {}

        badge.textContent = 'Terhubung (' + (device.name || 'ESP32') + ')';
        badge.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-tertiary-container text-on-tertiary-container';
        btnLabel.textContent = 'Terhubung ke ' + (device.name || 'SmartBox BLE');
        showToast('Bluetooth terhubung! Silakan kirim SSID & Password Wi-Fi.', 'bluetooth_connected');
      } catch (err) {
        btnLabel.textContent = 'Cari & Sambungkan Bluetooth ESP32';
        showToast('Pencarian Bluetooth dibatalkan atau Bluetooth belum menyala.', 'info');
      }
    }

    async function sendWifiViaBle() {
      if (!bleChar) {
        showToast('Sambungkan Bluetooth ke ESP32 terlebih dahulu!', 'warning');
        return;
      }

      const ssid = document.getElementById('bleWifiSsidInput').value.trim();
      const pass = document.getElementById('bleWifiPassInput').value;
      const rawCode = document.getElementById('pairDevCodeInput').value.trim() || 'BOX-01';
      const codeOnly = rawCode.split('|')[0].trim().toUpperCase();

      if (!ssid) {
        showToast('Masukkan Nama Wi-Fi (SSID) terlebih dahulu.', 'error');
        return;
      }

      const payloadObj = {
        ssid: ssid,
        pass: pass,
        code: codeOnly
      };
      if (claimedApiKey) {
        payloadObj.key = claimedApiKey;
      }

      try {
        const bytes = new TextEncoder().encode(JSON.stringify(payloadObj));
        await bleChar.writeValue(bytes);
        showToast('Wi-Fi berhasil dikirim ke ESP32! Mengalihkan kembali ke Storage Box Saya...', 'check_circle');
        setTimeout(() => {
          window.location.href = 'index.php';
        }, 1800);
      } catch (err) {
        showToast('Gagal mengirim data Wi-Fi via Bluetooth: ' + (err.message || 'Error'), 'error');
      }
    }

    async function cancelAndReturn(e) {
      if (e) e.preventDefault();
      await stopPairQrCamera();
      window.location.href = 'index.php';
    }

    updateThemeUI();
  </script>
</body>
</html>
