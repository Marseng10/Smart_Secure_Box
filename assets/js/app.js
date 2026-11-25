/**
 * Smart Secure Storage Box — Frontend Controller
 * Arsitektur:
 * 1. Role akun HANYA ada dan berlaku di tabel `device_users` (bukan di `users`).
 * 2. Setelah login, halaman awal adalah Daftar Storage Box (`devices`) agar pengguna memilih box yang ingin dipantau.
 * 3. Pendaftaran box baru menggunakan Scan QR (hanya berisi Kode Box, bukan URL) + Pairing Wi-Fi via Bluetooth (BLE).
 * 4. Dilengkapi monitoring Sensor Getaran (SW-420), DHT11, Door Sensor, dan Auto-Close (Forget to Close).
 */

const BLE_SERVICE_UUID = '4fafc201-1fb5-459e-8fcc-c5c9c331914b';
const BLE_CHAR_UUID    = 'beb5483e-36e1-4688-b7f5-ea07361b26a8';

let appState = {
  has_device: true,
  my_devices: [],
  device: null,
  command: null,
  alerts: [],
  logs: [],
  device_members: [],
  me: null,
  logFilter: 'ALL',
  settingsLoadedForDeviceId: null,
  currentTab: 'devices', // Setelah login tampil Daftar Storage Box terlebih dahulu
  hasSelectedBox: false, // Harus memilih 1 box dulu sebelum Dashboard & menu device muncul
  lastClaimedApiKey: '',
  bleDevice: null,
  bleChar: null,
  qrScanner: null,
  qrMode: 'CLAIM'
};

const TAB_TITLES = {
  devices: 'Storage Box Saya',
  dashboard: 'Dashboard Pemantauan',
  logs: 'Riwayat & Log',
  users: 'Kelola Pengguna & Akses',
  settings: 'Pengaturan Perangkat',
  profile: 'PIN Device & Profil'
};

document.addEventListener('DOMContentLoaded', () => {
  updateThemeUI();
  switchTab('devices');
  fetchAppState();
  setInterval(fetchAppState, 1500);
});

/* ==========================================================================
   1. THEME & NAVIGATION
   ========================================================================== */
function updateThemeUI() {
  const isDark = document.documentElement.classList.contains('dark');
  const icon = document.getElementById('themeIcon');
  const label = document.getElementById('themeLabel');
  if (icon) icon.textContent = isDark ? 'dark_mode' : 'wb_sunny';
  if (label) label.textContent = isDark ? 'Dark' : 'Light';
}

function toggleTheme() {
  const html = document.documentElement;
  html.classList.toggle('dark');
  localStorage.setItem('secbox_theme', html.classList.contains('dark') ? 'dark' : 'light');
  updateThemeUI();
}

function toggleMobileSidebar() {
  const sb = document.getElementById('sidebar');
  if (sb) sb.classList.toggle('-translate-x-full');
}

function switchTab(tabId) {
  if (tabId !== 'devices' && appState.qrScanner) {
    stopQrScanner();
  }

  appState.currentTab = tabId;
  document.querySelectorAll('.tab-view').forEach(el => el.classList.add('hidden'));
  const target = document.getElementById('view-' + tabId);
  if (target) target.classList.remove('hidden');

  document.querySelectorAll('#sidebarNav .nav-btn').forEach(btn => {
    const isCurrent = btn.getAttribute('data-tab') === tabId;
    const isHidden = btn.classList.contains('hidden');
    if (isCurrent) {
      btn.className = (isHidden ? 'hidden ' : '') + 'nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-semibold transition-all bg-primary-container text-on-primary-container shadow-sm';
    } else {
      btn.className = (isHidden ? 'hidden ' : '') + 'nav-btn flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-medium text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-all';
    }
  });

  const bc = document.getElementById('headerBreadcrumb');
  if (bc) bc.textContent = TAB_TITLES[tabId] || 'Storage Box Saya';

  const sb = document.getElementById('sidebar');
  if (sb && window.innerWidth < 1024) {
    sb.classList.add('-translate-x-full');
  }
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

function esc(str) {
  return String(str ?? '').replace(/[&<>"']/g, m => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[m]));
}

/* ==========================================================================
   2. POLLING STATE & MULTI-DEVICE ROLE SYNC
   ========================================================================== */
async function fetchAppState() {
  try {
    const res = await fetch('api/state.php', { cache: 'no-store' });
    if (res.status === 401) {
      window.location.href = 'login.php';
      return;
    }
    const data = await res.json();
    if (!data || !data.ok) return;

    appState.has_device = Boolean(data.has_device);
    appState.my_devices = data.my_devices || [];
    appState.device = data.device || null;
    appState.command = data.command || null;
    appState.alerts = data.alerts || [];
    appState.logs = data.logs || [];
    appState.device_members = data.device_members || [];
    appState.me = data.me || null;

    // Sinkronisasi Role pada Storage Box yang sedang dipilih
    const activeRole = appState.hasSelectedBox ? (appState.me?.role || 'NONE') : 'NONE';
    window.SECBOX_CONFIG.isAdmin = (activeRole === 'ADMIN');

    syncRoleVisibility(activeRole);
    renderSidebarDevices();
    renderMyDevicesTab();

    if (!appState.hasSelectedBox) {
      const pill = document.getElementById('headerConnPill');
      const dot = document.getElementById('headerConnDot');
      const text = document.getElementById('headerConnText');
      if (pill && dot && text) {
        pill.className = 'flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container text-on-surface-variant text-xs font-medium';
        dot.className = 'w-2 h-2 rounded-full bg-gray-400';
        text.textContent = 'Pilih Storage Box';
      }
      return;
    }

    const noDevCard = document.getElementById('noDeviceAssignedCard');
    if (!appState.has_device || !appState.device) {
      if (noDevCard) {
        noDevCard.classList.toggle('hidden', appState.currentTab === 'devices');
      }
      const pill = document.getElementById('headerConnPill');
      const dot = document.getElementById('headerConnDot');
      const text = document.getElementById('headerConnText');
      if (pill && dot && text) {
        pill.className = 'flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container text-on-surface-variant text-xs font-medium';
        dot.className = 'w-2 h-2 rounded-full bg-gray-400';
        text.textContent = 'Belum Ada Box Aktif';
      }
      return;
    } else {
      if (noDevCard) noDevCard.classList.add('hidden');
    }

    renderHeaderAndCards();
    renderAlertsUI();
    renderLogsUI();
    renderProfileUI();

    if (window.SECBOX_CONFIG.isAdmin) {
      renderInviteCodeAndPendingRequests();
      renderAdminUsersTable();
      populateSettingsForActiveDevice();
    }
  } catch (e) {
    // Silent retry on next poll
  }
}

function syncRoleVisibility(role) {
  const hasBox = appState.hasSelectedBox && role !== 'NONE';
  const isAdmin = hasBox && (role === 'ADMIN');

  // Role badge di sidebar (menunjukkan role pada box yang sedang dipilih)
  const roleBadge = document.getElementById('sidebarRoleBadge');
  if (roleBadge) {
    roleBadge.textContent = hasBox ? role : 'PILIH BOX';
    roleBadge.className = isAdmin
      ? 'px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary text-on-secondary'
      : (hasBox
        ? 'px-2 py-0.5 rounded-full text-[10px] font-bold bg-tertiary-container text-on-tertiary-container'
        : 'px-2 py-0.5 rounded-full text-[10px] font-bold bg-surface-container-high text-on-surface-variant');
  }

  // Menu Dashboard, Riwayat & Profil hanya muncul setelah 1 box dipilih
  const navDash = document.querySelector('#sidebarNav button[data-tab="dashboard"]');
  const navLogs = document.querySelector('#sidebarNav button[data-tab="logs"]');
  const navProfile = document.querySelector('#sidebarNav button[data-tab="profile"]');
  if (navDash) navDash.classList.toggle('hidden', !hasBox);
  if (navLogs) navLogs.classList.toggle('hidden', !hasBox);
  if (navProfile) navProfile.classList.toggle('hidden', !hasBox);

  // Menu khusus Admin pada device ini
  const navUsers = document.getElementById('navBtnUsers');
  const navSettings = document.getElementById('navBtnSettings');
  if (navUsers) navUsers.classList.toggle('hidden', !isAdmin);
  if (navSettings) navSettings.classList.toggle('hidden', !isAdmin);

  // Jika belum pilih box, pastikan tetap di tab 'devices'
  if (!hasBox && appState.currentTab !== 'devices') {
    switchTab('devices');
  }

  // Jika sedang membuka tab khusus Admin lalu beralih ke device di mana ia berstatus USER, kembalikan ke dashboard
  if (hasBox && !isAdmin && (appState.currentTab === 'users' || appState.currentTab === 'settings')) {
    switchTab('dashboard');
  }

  // Tombol kontrol cepat di Dashboard
  const adminQuick = document.getElementById('adminQuickControls');
  const userQuick = document.getElementById('userQuickControls');
  if (adminQuick) {
    adminQuick.classList.toggle('hidden', !isAdmin);
    adminQuick.classList.toggle('flex', isAdmin);
  }
  if (userQuick) {
    userQuick.classList.toggle('hidden', isAdmin);
    userQuick.classList.toggle('flex', !isAdmin);
  }

  // Tombol Matikan Alarm & Reset
  const alarmAdminActions = document.getElementById('globalAlarmAdminActions');
  const btnResolveAllLogs = document.getElementById('btnResolveAllLogsTab');
  if (alarmAdminActions) alarmAdminActions.classList.toggle('hidden', !isAdmin);
  if (btnResolveAllLogs) btnResolveAllLogs.classList.toggle('hidden', !isAdmin);
}

function renderSidebarDevices() {
  const select = document.getElementById('sidebarDeviceSelect');
  const countBadge = document.getElementById('sidebarDeviceCountBadge');
  const myDevices = appState.my_devices || [];
  const approvedDevices = myDevices.filter(d => (d.access_status || d.status) === 'APPROVED');

  if (countBadge) {
    countBadge.textContent = myDevices.length;
  }

  if (!select) return;

  if (approvedDevices.length === 0) {
    select.innerHTML = `<option value="">Belum ada Storage Box aktif</option>`;
    return;
  }

  const activeId = appState.hasSelectedBox && appState.device ? Number(appState.device.id) : 0;
  const currentOptionsSig = approvedDevices.map(d => `${d.device_id}:${d.device_code}:${d.role}`).join('|') + `@${activeId}`;

  if (select.getAttribute('data-sig') !== currentOptionsSig) {
    select.setAttribute('data-sig', currentOptionsSig);
    const placeholderOpt = `<option value="" ${activeId === 0 ? 'selected' : ''} disabled>-- Pilih Storage Box --</option>`;
    select.innerHTML = placeholderOpt + approvedDevices.map(d => {
      const sel = Number(d.device_id) === activeId ? 'selected' : '';
      return `<option value="${Number(d.device_id)}" ${sel}>${esc(d.device_code)} — ${esc(d.device_name)} (${esc(d.role)})</option>`;
    }).join('');
  }
}

async function onSelectActiveDevice(deviceId) {
  if (!deviceId) return;
  appState.hasSelectedBox = true;
  const res = await postCommand({
    command: 'SWITCH_DEVICE',
    device_id: Number(deviceId)
  });
  if (res && res.ok) {
    appState.settingsLoadedForDeviceId = null;
    switchTab('dashboard');
  }
}

async function monitorDeviceAndOpenDashboard(deviceId) {
  if (!deviceId) return;
  appState.hasSelectedBox = true;
  if (!appState.device || Number(appState.device.id) !== Number(deviceId)) {
    const res = await postCommand({
      command: 'SWITCH_DEVICE',
      device_id: Number(deviceId)
    });
    if (res && res.ok) {
      appState.settingsLoadedForDeviceId = null;
    }
  } else {
    await fetchAppState();
  }
  switchTab('dashboard');
}

function renderMyDevicesTab() {
  const container = document.getElementById('myDevicesListContainer');
  if (!container) return;

  const myDevices = appState.my_devices || [];
  const activeDeviceId = (appState.hasSelectedBox && appState.device) ? Number(appState.device.id) : 0;

  if (myDevices.length === 0) {
    container.innerHTML = `
      <div class="p-6 rounded-xl bg-surface-container-low text-center space-y-2">
        <div class="w-10 h-10 rounded-full bg-surface-container-high text-on-surface-variant flex items-center justify-center mx-auto">
          <span class="material-symbols-outlined text-[20px]">inventory_2</span>
        </div>
        <div class="text-sm font-semibold text-on-surface">Belum Ada Storage Box Terhubung</div>
        <p class="text-xs text-on-surface-variant max-w-md mx-auto">
          Gunakan panel di sebelah kanan untuk <b>Scan QR Box Baru</b> &amp; menyambungkan via Bluetooth (sebagai Admin pertama), atau masukkan <b>Kode Undangan</b> untuk bergabung ke box yang sudah ada.
        </p>
      </div>
    `;
    return;
  }

  container.innerHTML = myDevices.map(d => {
    const statusVal = d.access_status || d.status;
    const isCurrent = Number(d.device_id) === activeDeviceId && statusVal === 'APPROVED';
    const isApproved = statusVal === 'APPROVED';
    const isPending = statusVal === 'PENDING';
    const isRevoked = statusVal === 'REVOKED';
    const isOnline = Number(d.is_online) === 1;

    let statusBadge = '';
    if (isApproved) {
      statusBadge = isOnline
        ? `<span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-tertiary-container text-on-tertiary-container flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>ESP32 Online</span>`
        : `<span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-surface-container text-on-surface-variant">ESP32 Offline</span>`;
    } else if (isPending) {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-secondary-container text-on-secondary-container">Menunggu Persetujuan Admin</span>`;
    } else if (isRevoked) {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-error-container text-on-error-container">Akses Dicabut (Revoked)</span>`;
    } else {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-error-container text-on-error-container">Ditolak (Rejected)</span>`;
    }

    const rolePill = d.role === 'ADMIN'
      ? `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-secondary text-on-secondary">ROLE: ADMIN</span>`
      : `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-surface-container-high text-on-surface">ROLE: USER</span>`;

    const pinInfo = Number(d.has_pin) === 1
      ? `<span class="text-[11px] font-mono text-secondary font-semibold">PIN 6-Digit Aktif</span>`
      : `<span class="text-[11px] text-on-surface-variant">PIN Belum Diatur</span>`;

    return `
      <div class="p-4 rounded-xl border ${isCurrent ? 'border-secondary bg-surface-container-low' : 'border-outline-variant/30 bg-surface-container-lowest'} flex flex-col sm:flex-row sm:items-center justify-between gap-3 transition-all">
        <div class="space-y-1.5">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-heading font-bold text-sm sm:text-base text-on-surface">${esc(d.device_name)}</span>
            <span class="font-mono text-xs text-on-surface-variant">(${esc(d.device_code)})</span>
            ${rolePill}
            ${statusBadge}
          </div>
          <div class="flex items-center gap-3 text-xs text-on-surface-variant flex-wrap">
            ${pinInfo}
            <span>•</span>
            <span>Izin Log: ${d.role === 'ADMIN' || Number(d.can_view_logs) === 1 ? 'Semua Aktivitas' : 'Hanya Sendiri'}</span>
          </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          ${isApproved ? `
            <button
              type="button"
              onclick="monitorDeviceAndOpenDashboard(${Number(d.device_id)})"
              class="px-4 py-2 rounded-xl ${isCurrent ? 'bg-secondary text-on-secondary' : 'bg-primary-container text-on-primary-container'} text-xs font-semibold shadow-sm hover:opacity-95 flex items-center gap-1.5"
            >
              <span class="material-symbols-outlined text-[16px]">monitoring</span>
              <span>Pantau Box Ini</span>
            </button>
          ` : ''}
        </div>
      </div>
    `;
  }).join('');
}

/* ==========================================================================
   3. QR CODE SCANNER (HANYA KODE BOX, BUKAN URL) & BLUETOOTH (BLE) PAIRING
   ========================================================================== */
async function startQrScanner(mode = 'CLAIM') {
  appState.qrMode = mode;
  const card = document.getElementById('qrScannerCard');
  const title = document.getElementById('qrScannerTitle');
  if (card) card.classList.remove('hidden');
  if (title) {
    title.textContent = mode === 'CLAIM'
      ? 'Scan QR Kode Box Baru (Untuk Pendaftaran Admin)'
      : 'Scan QR Kode Undangan Box';
  }

  if (typeof Html5Qrcode === 'undefined') {
    showToast('Library kamera QR belum termuat. Anda tetap dapat mengetik kode secara manual.', 'warning');
    return;
  }

  if (appState.qrScanner) {
    await stopQrScanner(true);
  }

  try {
    const html5QrCode = new Html5Qrcode('qrReaderBox');
    appState.qrScanner = html5QrCode;
    await html5QrCode.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: { width: 200, height: 200 } },
      (decodedText) => {
        handleQrDecoded(decodedText);
      },
      () => {}
    );
  } catch (err) {
    showToast('Kamera tidak dapat diakses. Pastikan izin kamera aktif atau masukkan kode manual.', 'error');
  }
}

async function stopQrScanner(keepCardVisible = false) {
  if (appState.qrScanner) {
    try {
      await appState.qrScanner.stop();
      await appState.qrScanner.clear();
    } catch (e) {}
    appState.qrScanner = null;
  }
  if (!keepCardVisible) {
    const card = document.getElementById('qrScannerCard');
    if (card) card.classList.add('hidden');
  }
}

function handleQrDecoded(rawText) {
  const clean = String(rawText || '').trim();
  if (!clean) return;

  // Sesuai ketentuan: QR hanya boleh berisi kode perangkat (bukan URL langsung)
  if (/^https?:\/\//i.test(clean)) {
    showToast('QR Code tidak boleh berupa URL! Gunakan QR yang hanya berisi Kode Box (mis. BOX-01).', 'error');
    return;
  }

  stopQrScanner(false);

  if (appState.qrMode === 'JOIN') {
    const joinInput = document.getElementById('joinInviteCodeInput');
    if (joinInput) joinInput.value = clean.toUpperCase();
    showToast(`Kode QR terbaca: ${clean.toUpperCase()}`, 'qr_code_scanner');
  } else {
    const codeInput = document.getElementById('createDevCodeInput');
    const nameInput = document.getElementById('createDevNameInput');
    // Jika format "BOX-01|APIKEY"
    const codeOnly = clean.split('|')[0].trim().toUpperCase();
    if (codeInput) codeInput.value = clean.toUpperCase();
    if (nameInput && !nameInput.value.trim()) {
      nameInput.value = `Smart Secure Box (${codeOnly})`;
    }
    showToast(`Kode Box terbaca: ${codeOnly}. Silakan lanjutkan pendaftaran & pairing Bluetooth.`, 'qr_code_scanner');
  }
}

async function connectBleProvisioning() {
  if (!navigator.bluetooth) {
    showToast('Browser ini belum mendukung Web Bluetooth. Gunakan Google Chrome / Edge di Android atau Desktop.', 'error');
    return;
  }

  const badge = document.getElementById('bleStatusBadge');
  const btnLabel = document.getElementById('btnBleConnectLabel');

  try {
    if (btnLabel) btnLabel.textContent = 'Mencari Bluetooth ESP32...';
    const device = await navigator.bluetooth.requestDevice({
      filters: [{ namePrefix: 'SmartBox' }],
      optionalServices: [BLE_SERVICE_UUID]
    });

    appState.bleDevice = device;
    device.addEventListener('gattserverdisconnected', () => {
      appState.bleChar = null;
      if (badge) {
        badge.textContent = 'Terputus';
        badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-error-container text-on-error-container';
      }
      if (btnLabel) btnLabel.textContent = 'Cari & Sambungkan Bluetooth ESP32';
    });

    const server = await device.gatt.connect();
    const service = await server.getPrimaryService(BLE_SERVICE_UUID);
    const characteristic = await service.getCharacteristic(BLE_CHAR_UUID);
    appState.bleChar = characteristic;

    // Baca info kode perangkat dari karakteristik BLE jika tersedia
    try {
      const val = await characteristic.readValue();
      const decoder = new TextDecoder('utf-8');
      const infoStr = decoder.decode(val).trim();
      if (infoStr) {
        const info = JSON.parse(infoStr);
        if (info.code) {
          const codeEl = document.getElementById('createDevCodeInput');
          if (codeEl && !codeEl.value.trim()) {
            codeEl.value = info.code;
          }
        }
        if (info.ssid) {
          const ssidEl = document.getElementById('bleWifiSsidInput');
          if (ssidEl && !ssidEl.value.trim()) {
            ssidEl.value = info.ssid;
          }
        }
      }
    } catch (e) {}

    if (badge) {
      badge.textContent = `Terhubung (${device.name || 'ESP32'})`;
      badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-tertiary-container text-on-tertiary-container';
    }
    if (btnLabel) btnLabel.textContent = `Terhubung ke ${device.name || 'SmartBox BLE'}`;
    showToast(`Bluetooth terhubung ke ${device.name || 'ESP32'}! Masukkan SSID & Password Wi-Fi.`, 'bluetooth_connected');
  } catch (err) {
    if (btnLabel) btnLabel.textContent = 'Cari & Sambungkan Bluetooth ESP32';
    showToast('Pencarian Bluetooth dibatalkan atau pastikan Bluetooth perangkat menyala.', 'info');
  }
}

async function sendWifiViaBle() {
  if (!appState.bleChar) {
    showToast('Sambungkan Bluetooth ke ESP32 terlebih dahulu (Klik tombol Cari & Sambungkan Bluetooth).', 'warning');
    return;
  }

  const ssid = document.getElementById('bleWifiSsidInput')?.value.trim() || '';
  const pass = document.getElementById('bleWifiPassInput')?.value || '';
  const rawCode = document.getElementById('createDevCodeInput')?.value.trim() || (appState.device?.code || 'BOX-01');
  const codeOnly = rawCode.split('|')[0].trim().toUpperCase();
  const apiKey = appState.lastClaimedApiKey || (appState.device?.api_key || '');

  if (!ssid) {
    showToast('Masukkan Nama Wi-Fi (SSID) terlebih dahulu.', 'error');
    return;
  }

  const payloadObj = {
    ssid: ssid,
    pass: pass,
    code: codeOnly
  };
  if (apiKey) {
    payloadObj.key = apiKey;
  }

  try {
    const encoder = new TextEncoder();
    const dataBytes = encoder.encode(JSON.stringify(payloadObj));
    await appState.bleChar.writeValue(dataBytes);
    showToast(`Konfigurasi Wi-Fi "${ssid}" berhasil dikirim ke memori NVS ESP32 via Bluetooth!`, 'check_circle');
  } catch (err) {
    showToast('Gagal mengirim data Wi-Fi lewat Bluetooth: ' + (err.message || 'Error'), 'error');
  }
}

/* ==========================================================================
   4. DASHBOARD & SENSOR CARDS (TERMASUK SENSOR GETARAN)
   ========================================================================== */
function renderHeaderAndCards() {
  const d = appState.device;
  const cmd = appState.command;
  if (!d) return;

  // Badge nama Storage Box di Dashboard
  const dashDevBadge = document.getElementById('dashboardDeviceBadge');
  if (dashDevBadge) {
    dashDevBadge.textContent = `${d.name} (${d.code}) • Role di Box Ini: ${appState.me?.role || '-'}`;
  }

  // Header Connection Pill & Sensor Cards Visibility
  const pill = document.getElementById('headerConnPill');
  const dot = document.getElementById('headerConnDot');
  const text = document.getElementById('headerConnText');
  const offlineWarning = document.getElementById('offlineSensorWarning');
  const sensorGrid = document.getElementById('sensorCardsGrid');

  if (pill && dot && text) {
    if (d.online) {
      pill.className = 'flex items-center gap-2 px-3 py-1.5 rounded-full bg-tertiary-container text-on-tertiary-container text-xs font-medium transition-all';
      dot.className = 'w-2 h-2 rounded-full bg-emerald-500 animate-pulse';
      text.textContent = `ESP32 Online (${d.code})`;
    } else {
      pill.className = 'flex items-center gap-2 px-3 py-1.5 rounded-full bg-error-container text-on-error-container text-xs font-medium transition-all';
      dot.className = 'w-2 h-2 rounded-full bg-rose-500';
      text.textContent = `Koneksi ESP32 Terputus (${d.code})`;
    }
  }

  // Jika ESP32 belum terhubung / offline, sembunyikan kartu sensor dan tampilkan peringatan saja
  if (!d.online) {
    if (offlineWarning) offlineWarning.classList.remove('hidden');
    if (sensorGrid) sensorGrid.classList.add('hidden');
    return;
  }

  if (offlineWarning) offlineWarning.classList.add('hidden');
  if (sensorGrid) sensorGrid.classList.remove('hidden');

  // Card 1: Status Kunci
  const lockIcon = document.getElementById('cardLockIcon');
  const lockStatus = document.getElementById('cardLockStatus');
  const lockSub = document.getElementById('cardLockSub');
  if (lockStatus) {
    if (d.locked) {
      lockStatus.textContent = 'Terkunci';
      if (lockIcon) lockIcon.textContent = 'lock';
      if (lockSub) {
        lockSub.textContent = (cmd && cmd.status === 'PENDING')
          ? `Menunggu ESP32: ${cmd.command_type}...`
          : 'Servo terkunci rapat';
      }
    } else {
      lockStatus.textContent = 'Terbuka';
      if (lockIcon) lockIcon.textContent = 'lock_open';
      if (lockSub) {
        lockSub.textContent = (cmd && cmd.status === 'PENDING')
          ? `Menunggu ESP32: ${cmd.command_type}...`
          : `Auto-lock aktif (${d.auto_lock_sec} dtk)`;
      }
    }
  }

  // Card 2: Kondisi Pintu
  const doorStatus = document.getElementById('cardDoorStatus');
  const doorTimer = document.getElementById('cardDoorTimerBadge');
  const doorSub = document.getElementById('cardDoorSub');
  if (doorStatus) {
    if (d.door_closed) {
      doorStatus.textContent = 'Tertutup';
      if (doorTimer) doorTimer.textContent = '';
      if (doorSub) doorSub.textContent = `Batas terbuka: ${d.door_timeout_sec} detik`;
    } else {
      doorStatus.textContent = 'Terbuka';
      if (doorTimer) doorTimer.textContent = d.door_open_seconds > 0 ? `(${d.door_open_seconds} dtk)` : '';
      if (doorSub) {
        doorSub.textContent = d.door_alarm
          ? `Melewati batas ${d.door_timeout_sec} detik!`
          : `Batas terbuka: ${d.door_timeout_sec} detik`;
      }
    }
  }

  // Card 3: Sensor Getaran (SW-420)
  const vibStatus = document.getElementById('cardVibStatus');
  const vibBadge = document.getElementById('cardVibBadge');
  const vibSub = document.getElementById('cardVibSub');
  const vibIcon = document.getElementById('cardVibIcon');
  if (vibStatus) {
    if (d.vibration_alarm) {
      vibStatus.textContent = 'Getaran!';
      if (vibIcon) vibIcon.className = 'material-symbols-outlined text-error text-[22px] animate-bounce';
      if (vibBadge) {
        vibBadge.textContent = 'Bahaya';
        vibBadge.className = 'text-xs px-2 py-0.5 rounded-full bg-error-container text-on-error-container font-semibold';
      }
      if (vibSub) vibSub.textContent = 'Guncangan fisik terdeteksi!';
    } else {
      vibStatus.textContent = 'Aman';
      if (vibIcon) vibIcon.className = 'material-symbols-outlined text-secondary text-[22px]';
      if (vibBadge) {
        vibBadge.textContent = 'Stabil';
        vibBadge.className = 'text-xs px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container font-medium';
      }
      if (vibSub) {
        vibSub.textContent = d.last_vibration_at
          ? `Terakhir: ${d.last_vibration_at}`
          : 'Tidak ada guncangan';
      }
    }
  }

  // Card 4: Suhu (DHT11)
  const tempVal = document.getElementById('cardTempVal');
  const tempBadge = document.getElementById('cardTempBadge');
  if (tempVal) {
    if (d.temperature !== null) {
      tempVal.textContent = `${Number(d.temperature).toFixed(1)} °C`;
      const isHot = Number(d.temperature) >= Number(d.temp_threshold);
      if (tempBadge) {
        tempBadge.textContent = isHot ? 'Tinggi' : 'Normal';
        tempBadge.className = isHot
          ? 'text-xs px-2 py-0.5 rounded-full bg-error-container text-on-error-container font-medium'
          : 'text-xs px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container font-medium';
      }
    } else {
      tempVal.textContent = '-- °C';
      if (tempBadge) tempBadge.textContent = 'Menunggu';
    }
  }

  // Card 5: Kelembapan (DHT11)
  const humVal = document.getElementById('cardHumVal');
  const humBadge = document.getElementById('cardHumBadge');
  if (humVal) {
    if (d.humidity !== null) {
      humVal.textContent = `${Number(d.humidity).toFixed(0)} %`;
      const isHumid = Number(d.humidity) >= Number(d.humidity_threshold);
      if (humBadge) {
        humBadge.textContent = isHumid ? 'Lembap' : 'Ideal';
        humBadge.className = isHumid
          ? 'text-xs px-2 py-0.5 rounded-full bg-error-container text-on-error-container font-medium'
          : 'text-xs px-2 py-0.5 rounded-full bg-tertiary-container text-on-tertiary-container font-medium';
      }
    } else {
      humVal.textContent = '-- %';
      if (humBadge) humBadge.textContent = 'Menunggu';
    }
  }
}

/* ==========================================================================
   5. ALERTS & LOGS RENDERING
   ========================================================================== */
function renderAlertsUI() {
  const alerts = appState.alerts || [];
  const d = appState.device;
  const activeAlerts = alerts.filter(a => a.status === 'ACTIVE');

  // Sidebar badge
  const sbBadge = document.getElementById('sidebarAlertBadge');
  if (sbBadge) {
    if (activeAlerts.length > 0) {
      sbBadge.textContent = activeAlerts.length;
      sbBadge.classList.remove('hidden');
    } else {
      sbBadge.classList.add('hidden');
    }
  }

  // Dashboard Alert Count Badge
  const dashBadge = document.getElementById('dashboardAlertCountBadge');
  if (dashBadge) {
    dashBadge.textContent = `${activeAlerts.length} Aktif`;
    dashBadge.className = activeAlerts.length > 0
      ? 'px-2.5 py-0.5 rounded-full bg-error-container text-on-error-container text-xs font-semibold'
      : 'px-2.5 py-0.5 rounded-full bg-surface-container text-on-surface-variant text-xs font-medium';
  }

  // Global Alarm Banner
  const banner = document.getElementById('globalAlarmBanner');
  const bannerText = document.getElementById('globalAlarmText');
  if (banner) {
    if (activeAlerts.length > 0 || (d && (d.door_alarm || d.security_alarm || d.vibration_alarm))) {
      banner.classList.remove('hidden');
      if (bannerText && activeAlerts.length > 0) {
        bannerText.innerHTML = `<strong>${esc(activeAlerts[0].title)}:</strong> ${esc(activeAlerts[0].description)}`;
      } else if (bannerText && d && d.vibration_alarm) {
        bannerText.innerHTML = `<strong>Peringatan Sensor Getaran:</strong> Terdeteksi guncangan/benturan fisik pada kotak (${esc(d.code)})!`;
      }
    } else {
      banner.classList.add('hidden');
    }
  }

  // Dashboard Recent Alerts
  const dashAlerts = document.getElementById('dashboardRecentAlerts');
  if (dashAlerts) {
    if (alerts.length === 0) {
      dashAlerts.innerHTML = `<div class="py-6 text-center text-xs text-on-surface-variant">Tidak ada peringatan keamanan. Sistem aman.</div>`;
    } else {
      dashAlerts.innerHTML = alerts.slice(0, 4).map(a => {
        const isActive = a.status === 'ACTIVE';
        return `
          <div class="p-3.5 rounded-xl ${isActive ? 'bg-error-container/60 text-on-error-container' : 'bg-surface-container-low text-on-surface'} flex items-start justify-between gap-3">
            <div class="space-y-0.5">
              <div class="text-xs font-semibold flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">${isActive ? 'warning' : 'check_circle'}</span>
                <span>${esc(a.title)}</span>
              </div>
              <p class="text-[11px] opacity-85">${esc(a.description)}</p>
              <div class="text-[10px] font-mono opacity-70 pt-0.5">${esc(a.created_at)}</div>
            </div>
            ${isActive && window.SECBOX_CONFIG.isAdmin ? `
              <button type="button" onclick="resolveSingleAlert(${Number(a.id)})" class="text-[11px] font-semibold underline shrink-0">
                Selesai
              </button>
            ` : ''}
          </div>
        `;
      }).join('');
    }
  }

  // Full Alert Log in Riwayat & Log view
  const fullAlerts = document.getElementById('fullAlertLogContainer');
  if (fullAlerts) {
    if (alerts.length === 0) {
      fullAlerts.innerHTML = `<div class="py-6 text-center text-xs text-on-surface-variant">Belum ada riwayat peringatan keamanan.</div>`;
    } else {
      fullAlerts.innerHTML = alerts.map(a => {
        const isActive = a.status === 'ACTIVE';
        return `
          <div class="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
              <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold ${isActive ? 'bg-error-container text-on-error-container' : 'bg-tertiary-container text-on-tertiary-container'}">
                  ${isActive ? 'AKTIF' : 'SELESAI'}
                </span>
                <span class="text-sm font-semibold text-on-surface">${esc(a.title)}</span>
              </div>
              <p class="text-xs text-on-surface-variant mt-1">${esc(a.description)}</p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <span class="text-xs font-mono text-on-surface-variant">${esc(a.created_at)}</span>
              ${isActive && window.SECBOX_CONFIG.isAdmin ? `
                <button type="button" onclick="resolveSingleAlert(${Number(a.id)})" class="px-2.5 py-1 rounded-lg bg-surface-container-high text-on-surface text-xs font-medium">
                  Selesaikan
                </button>
              ` : ''}
            </div>
          </div>
        `;
      }).join('');
    }
  }
}

function renderLogsUI() {
  const logs = appState.logs || [];
  const me = appState.me;

  const logSub = document.getElementById('logsPermissionSubtitle');
  if (logSub && me && appState.device) {
    logSub.textContent = me.can_view_logs
      ? `Menampilkan seluruh riwayat akses pada ${appState.device.name} (${appState.device.code}).`
      : `Menampilkan aktivitas milik Anda sendiri pada ${appState.device.name} (${appState.device.code}).`;
  }

  // Dashboard Recent Logs
  const dashLogs = document.getElementById('dashboardRecentLogs');
  if (dashLogs) {
    if (logs.length === 0) {
      dashLogs.innerHTML = `<div class="py-6 text-center text-xs text-on-surface-variant">Belum ada aktivitas tercatat.</div>`;
    } else {
      dashLogs.innerHTML = logs.slice(0, 5).map(l => {
        const isOk = l.result === 'SUCCESS';
        const isFail = l.result === 'FAILED';
        const pillClass = isOk
          ? 'bg-tertiary-container text-on-tertiary-container'
          : (isFail ? 'bg-error-container text-on-error-container' : 'bg-surface-container text-on-surface-variant');

        return `
          <div class="py-3 flex items-center justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-on-surface">${esc(l.username)}</span>
                <span class="text-[11px] font-mono text-on-surface-variant">• ${esc(l.method)}</span>
              </div>
              <p class="text-xs text-on-surface-variant truncate mt-0.5">${esc(l.details || l.action)}</p>
            </div>
            <div class="text-right shrink-0">
              <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold ${pillClass}">${esc(l.result)}</span>
              <div class="text-[11px] font-mono text-on-surface-variant mt-1">${esc(l.created_at)}</div>
            </div>
          </div>
        `;
      }).join('');
    }
  }

  // Full Logs Table with Filter
  const tbody = document.getElementById('fullAccessLogTableBody');
  if (tbody) {
    const filtered = appState.logFilter === 'ALL'
      ? logs
      : logs.filter(l => l.method === appState.logFilter);

    if (filtered.length === 0) {
      tbody.innerHTML = `<tr><td colspan="5" class="py-6 text-center text-xs text-on-surface-variant">Tidak ada data riwayat untuk filter ini.</td></tr>`;
    } else {
      tbody.innerHTML = filtered.map(l => {
        const isOk = l.result === 'SUCCESS';
        const isFail = l.result === 'FAILED';
        const pillClass = isOk
          ? 'bg-tertiary-container text-on-tertiary-container'
          : (isFail ? 'bg-error-container text-on-error-container' : 'bg-surface-container text-on-surface-variant');

        return `
          <tr>
            <td class="py-3 px-3 text-xs font-mono text-on-surface-variant whitespace-nowrap">${esc(l.created_at)}</td>
            <td class="py-3 px-3 text-xs font-semibold text-on-surface">${esc(l.username)}</td>
            <td class="py-3 px-3 text-xs font-mono text-on-surface-variant">${esc(l.method)}</td>
            <td class="py-3 px-3 text-xs text-on-surface">${esc(l.details || l.action)}</td>
            <td class="py-3 px-3 text-right">
              <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${pillClass}">${esc(l.result)}</span>
            </td>
          </tr>
        `;
      }).join('');
    }
  }
}

function setLogFilter(filter) {
  appState.logFilter = filter;
  document.querySelectorAll('.log-filter-btn').forEach(btn => {
    if (btn.getAttribute('data-filter') === filter) {
      btn.className = 'log-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-primary-container text-on-primary-container';
    } else {
      btn.className = 'log-filter-btn px-3 py-1.5 rounded-xl text-xs font-medium bg-surface-container text-on-surface-variant';
    }
  });
  renderLogsUI();
}

function renderProfileUI() {
  const me = appState.me;
  const d = appState.device;
  if (!me) return;

  const devLabel = document.getElementById('profilePinDeviceLabel');
  if (devLabel && d) {
    devLabel.textContent = `(${d.code} — Role: ${me.role})`;
  }

  const pinStatus = document.getElementById('profileCurrentPin');
  if (pinStatus) {
    pinStatus.textContent = me.has_pin ? 'Sudah Aktif (6 Digit Tersinkronisasi)' : 'Belum Diatur pada Box Ini';
  }
}

/* ==========================================================================
   6. ADMIN: INVITE CODE, PENDING REQUESTS & DEVICE MEMBERS (device_users)
   ========================================================================== */
function renderInviteCodeAndPendingRequests() {
  const d = appState.device;
  if (!d) return;

  const codeDisplay = document.getElementById('currentInviteCodeDisplay');
  const expiryText = document.getElementById('inviteExpiryText');
  if (codeDisplay) {
    codeDisplay.textContent = d.invite_code || d.code;
  }
  if (expiryText) {
    expiryText.textContent = d.invite_expires_at
      ? `Berlaku hingga: ${d.invite_expires_at} • Otomatis diganti setelah dipakai`
      : 'Kode aktif untuk pengajuan akses baru';
  }

  const members = appState.device_members || [];
  const pendingList = members.filter(m => m.status === 'PENDING');

  // Badge jumlah permintaan pending di Sidebar
  const sbPending = document.getElementById('sidebarPendingBadge');
  if (sbPending) {
    if (pendingList.length > 0) {
      sbPending.textContent = pendingList.length;
      sbPending.classList.remove('hidden');
    } else {
      sbPending.classList.add('hidden');
    }
  }

  const pendingCard = document.getElementById('pendingRequestsCard');
  const pendingContainer = document.getElementById('pendingRequestsContainer');
  if (!pendingCard || !pendingContainer) return;

  if (pendingList.length === 0) {
    pendingCard.classList.add('hidden');
    pendingContainer.innerHTML = '';
    return;
  }

  pendingCard.classList.remove('hidden');
  pendingContainer.innerHTML = pendingList.map(m => `
    <div class="py-3.5 flex flex-col md:flex-row md:items-center justify-between gap-3">
      <div>
        <div class="flex items-center gap-2">
          <span class="font-semibold text-sm text-on-surface">${esc(m.full_name)}</span>
          <span class="text-xs font-mono text-on-surface-variant">@${esc(m.username)}</span>
          <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-secondary-container text-on-secondary-container">PENDING</span>
        </div>
        <p class="text-xs text-on-surface-variant mt-0.5">${esc(m.request_note || 'Meminta akses ke Storage Box ini')}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 shrink-0">
        <select id="pendingRole_${Number(m.relation_id)}" class="px-2.5 py-1.5 rounded-lg bg-surface-container-low border border-outline-variant/40 text-xs font-semibold text-on-surface">
          <option value="USER">Tetapkan sebagai USER</option>
          <option value="ADMIN">Tetapkan sebagai ADMIN</option>
        </select>
        <button type="button" onclick="approveAccessRequest(${Number(m.relation_id)})" class="px-3 py-1.5 rounded-lg bg-tertiary-container text-on-tertiary-container text-xs font-semibold hover:opacity-90">
          Setujui (Approve)
        </button>
        <button type="button" onclick="rejectAccessRequest(${Number(m.relation_id)})" class="px-3 py-1.5 rounded-lg bg-error-container text-on-error-container text-xs font-semibold hover:opacity-90">
          Tolak
        </button>
      </div>
    </div>
  `).join('');
}

function renderAdminUsersTable() {
  const tbody = document.getElementById('adminUsersTableBody');
  if (!tbody) return;

  const members = appState.device_members || [];
  if (members.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="py-6 text-center text-xs text-on-surface-variant">Belum ada pengguna pada Storage Box ini.</td></tr>`;
    return;
  }

  tbody.innerHTML = members.map(u => {
    const isSelf = Number(u.user_id) === Number(window.SECBOX_CONFIG.userId);

    let statusPill = '';
    if (u.status === 'APPROVED') {
      statusPill = `<span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-tertiary-container text-on-tertiary-container">Approved</span>`;
    } else if (u.status === 'PENDING') {
      statusPill = `<span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">Pending</span>`;
    } else if (u.status === 'REVOKED') {
      statusPill = `<span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-error-container text-on-error-container">Revoked</span>`;
    } else {
      statusPill = `<span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-error-container text-on-error-container">Rejected</span>`;
    }

    const pinPill = Number(u.has_pin) === 1
      ? `<span class="font-mono text-xs font-semibold text-secondary">•••••• (Aktif)</span>`
      : `<span class="text-xs text-on-surface-variant">Belum diatur</span>`;

    const logPermText = (u.role === 'ADMIN' || Number(u.can_view_logs) === 1)
      ? `<span class="text-xs text-on-surface font-medium">Semua Log</span>`
      : `<span class="text-xs text-on-surface-variant">Hanya Sendiri</span>`;

    const userJson = encodeURIComponent(JSON.stringify(u));

    return `
      <tr>
        <td class="py-3 px-3">
          <div class="font-semibold text-on-surface text-xs sm:text-sm">${esc(u.full_name)}</div>
          <div class="text-xs text-on-surface-variant font-mono">@${esc(u.username)}</div>
        </td>
        <td class="py-3 px-3">
          <span class="px-2 py-0.5 rounded-md text-xs font-bold ${u.role === 'ADMIN' ? 'bg-secondary text-on-secondary' : 'bg-surface-container-high text-on-surface'}">
            ${esc(u.role)}
          </span>
        </td>
        <td class="py-3 px-3">${pinPill}</td>
        <td class="py-3 px-3">${logPermText}</td>
        <td class="py-3 px-3">${statusPill}</td>
        <td class="py-3 px-3 text-right space-x-1 whitespace-nowrap">
          <button type="button" onclick="openEditUserModal('${userJson}')" class="px-2.5 py-1 rounded-lg bg-surface-container text-on-surface text-xs font-medium hover:bg-surface-container-high">
            Atur Role / PIN
          </button>
          ${!isSelf ? (
            u.status === 'APPROVED'
              ? `<button type="button" onclick="revokeDeviceAccess(${Number(u.relation_id)}, '${esc(u.username)}')" class="px-2.5 py-1 rounded-lg bg-surface-container text-on-surface text-xs font-medium hover:bg-surface-container-high">Cabut Akses</button>`
              : `<button type="button" onclick="restoreDeviceAccess(${Number(u.relation_id)})" class="px-2.5 py-1 rounded-lg bg-tertiary-container text-on-tertiary-container text-xs font-medium hover:opacity-90">Aktifkan</button>`
          ) : ''}
          ${!isSelf ? `
            <button type="button" onclick="deleteUserFromDevice(${Number(u.relation_id)}, '${esc(u.username)}')" class="px-2.5 py-1 rounded-lg bg-error-container text-on-error-container text-xs font-medium hover:opacity-90">
              Hapus
            </button>
          ` : ''}
        </td>
      </tr>
    `;
  }).join('');
}

function populateSettingsForActiveDevice() {
  if (!appState.device) return;
  const d = appState.device;
  if (appState.settingsLoadedForDeviceId === d.id) return;

  const maxPinEl = document.getElementById('setMaxPinAttempts');
  if (!maxPinEl) return;

  appState.settingsLoadedForDeviceId = d.id;
  const nameEl = document.getElementById('setDeviceName');
  if (nameEl) nameEl.value = d.name ?? 'Smart Secure Storage Box';
  document.getElementById('setDeviceCode').value = d.code ?? 'BOX-01';
  maxPinEl.value = d.max_pin_attempts ?? 3;
  document.getElementById('setDoorTimeoutSec').value = d.door_timeout_sec ?? 30;
  document.getElementById('setTempThreshold').value = d.temp_threshold ?? 35;
  document.getElementById('setHumThreshold').value = d.humidity_threshold ?? 80;
  document.getElementById('setAutoLockSec').value = d.auto_lock_sec ?? 10;
  if (d.api_key) {
    document.getElementById('setDeviceApiKey').value = d.api_key;
  }
}

/* ==========================================================================
   7. API COMMAND HELPERS & ACTIONS
   ========================================================================== */
async function postCommand(payload) {
  try {
    const res = await fetch('api/command.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: window.SECBOX_CONFIG.csrf,
        ...payload
      })
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      showToast(data.error || 'Gagal memproses perintah', 'error');
      return null;
    }
    if (data.message) {
      showToast(data.message, 'check_circle');
    }
    await fetchAppState();
    return data;
  } catch (e) {
    showToast('Gagal terhubung ke server', 'error');
    return null;
  }
}

/* Multi-Device Actions (Join via Invite Code / Create & Claim New Box via QR) */
async function submitRequestDeviceAccess(e) {
  e.preventDefault();
  const codeInput = document.getElementById('joinInviteCodeInput');
  const noteInput = document.getElementById('joinRequestNoteInput');
  const res = await postCommand({
    command: 'REQUEST_DEVICE_ACCESS',
    invite_code: codeInput.value.trim(),
    note: noteInput.value.trim()
  });
  if (res && res.ok) {
    codeInput.value = '';
    noteInput.value = '';
  }
}

async function submitCreateNewDevice(e) {
  e.preventDefault();
  const codeEl = document.getElementById('createDevCodeInput');
  const nameEl = document.getElementById('createDevNameInput');
  const pinEl = document.getElementById('createDevPinInput');
  const res = await postCommand({
    command: 'CREATE_NEW_DEVICE',
    device_code: codeEl.value.trim(),
    device_name: nameEl.value.trim(),
    pin: pinEl.value.trim()
  });
  if (res && res.ok) {
    if (res.api_key) {
      appState.lastClaimedApiKey = res.api_key;
    }
    pinEl.value = '';
    appState.settingsLoadedForDeviceId = null;
  }
}

/* Invite Code & Approval Actions */
function copyInviteCode() {
  const code = document.getElementById('currentInviteCodeDisplay')?.textContent?.trim() || '';
  if (!code) return;
  navigator.clipboard.writeText(code);
  showToast(`Kode undangan (${code}) disalin!`, 'content_copy');
}

function regenerateInviteCode() {
  return postCommand({ command: 'REGENERATE_INVITE_CODE', single_use: 1, valid_days: 7 });
}

function approveAccessRequest(relationId) {
  const roleEl = document.getElementById('pendingRole_' + relationId);
  const role = roleEl ? roleEl.value : 'USER';
  return postCommand({
    command: 'APPROVE_ACCESS_REQUEST',
    relation_id: relationId,
    role: role,
    can_view_logs: 1
  });
}

function rejectAccessRequest(relationId) {
  return postCommand({
    command: 'REJECT_ACCESS_REQUEST',
    relation_id: relationId
  });
}

function revokeDeviceAccess(relationId, username) {
  if (!window.confirm(`Cabut akses @${username} dari Storage Box ini? (Akses pengguna tersebut di Storage Box lain tidak akan terpengaruh)`)) {
    return;
  }
  return postCommand({
    command: 'REVOKE_DEVICE_ACCESS',
    relation_id: relationId
  });
}

function restoreDeviceAccess(relationId) {
  return postCommand({
    command: 'RESTORE_DEVICE_ACCESS',
    relation_id: relationId
  });
}

function deleteUserFromDevice(relationId, username) {
  if (!window.confirm(`Hapus hak akses @${username} dari Storage Box ini?`)) return;
  return postCommand({
    command: 'DELETE_USER',
    relation_id: relationId
  });
}

/* Hardware Remote Controls */
function adminRemoteLock() {
  return postCommand({ command: 'LOCK' });
}

function silenceAlarmAndResolve() {
  return postCommand({ command: 'RESOLVE_ALL_ALERTS' });
}

function resolveSingleAlert(alertId) {
  return postCommand({ command: 'RESOLVE_ALERT', alert_id: alertId });
}

/* Emergency Modal */
function openEmergencyModal() {
  const m = document.getElementById('emergencyModal');
  if (m) {
    m.classList.remove('hidden');
    m.classList.add('flex');
  }
}

function closeEmergencyModal() {
  const m = document.getElementById('emergencyModal');
  if (m) {
    m.classList.add('hidden');
    m.classList.remove('flex');
  }
}

async function confirmEmergencyModal() {
  const reason = document.getElementById('modalEmergencyReason')?.value.trim() || 'Akses darurat oleh Admin';
  const res = await postCommand({ command: 'EMERGENCY_UNLOCK', reason });
  if (res && res.ok) {
    closeEmergencyModal();
  }
}

function executeEmergencyUnlockFromSettings() {
  const reason = document.getElementById('emergencyReasonInput')?.value.trim() || 'Pembukaan kunci darurat oleh Admin';
  return postCommand({ command: 'EMERGENCY_UNLOCK', reason });
}

/* User Modal (Add / Edit Device Member) */
function openUserModal() {
  const m = document.getElementById('userModal');
  if (!m) return;
  document.getElementById('userModalTitle').textContent = 'Tambah Pengguna ke Storage Box Ini';
  document.getElementById('modalRelationId').value = '';
  document.getElementById('modalUsername').value = '';
  document.getElementById('modalUsername').readOnly = false;
  document.getElementById('modalFullName').value = '';
  document.getElementById('modalRole').value = 'USER';
  document.getElementById('modalPin').value = '';
  document.getElementById('modalPin').placeholder = 'Contoh: 123456';
  document.getElementById('modalCanViewLogs').value = '1';
  document.getElementById('modalStatus').value = 'APPROVED';
  document.getElementById('modalPassword').value = '';
  document.getElementById('modalPassword').placeholder = 'Minimal 6 karakter (jika akun baru)';
  m.classList.remove('hidden');
  m.classList.add('flex');
}

function openEditUserModal(encodedUser) {
  const u = JSON.parse(decodeURIComponent(encodedUser));
  const m = document.getElementById('userModal');
  if (!m) return;
  document.getElementById('userModalTitle').textContent = `Atur Role & PIN @${u.username} (${appState.device?.code || ''})`;
  document.getElementById('modalRelationId').value = u.relation_id;
  document.getElementById('modalUsername').value = u.username;
  document.getElementById('modalUsername').readOnly = true;
  document.getElementById('modalFullName').value = u.full_name;
  document.getElementById('modalRole').value = u.role;
  document.getElementById('modalPin').value = '';
  document.getElementById('modalPin').placeholder = 'Isi 6 angka baru jika ingin ubah PIN';
  document.getElementById('modalCanViewLogs').value = String(Number(u.can_view_logs) === 1 ? 1 : 0);
  document.getElementById('modalStatus').value = u.status || 'APPROVED';
  document.getElementById('modalPassword').value = '';
  document.getElementById('modalPassword').placeholder = 'Kosongkan jika password tetap';
  m.classList.remove('hidden');
  m.classList.add('flex');
}

function closeUserModal() {
  const m = document.getElementById('userModal');
  if (m) {
    m.classList.add('hidden');
    m.classList.remove('flex');
  }
}

async function submitUserModal(e) {
  e.preventDefault();
  const payload = {
    command: 'SAVE_USER',
    relation_id: Number(document.getElementById('modalRelationId').value || 0),
    username: document.getElementById('modalUsername').value.trim(),
    full_name: document.getElementById('modalFullName').value.trim(),
    role: document.getElementById('modalRole').value,
    pin: document.getElementById('modalPin').value.trim(),
    can_view_logs: Number(document.getElementById('modalCanViewLogs').value),
    status: document.getElementById('modalStatus').value,
    password: document.getElementById('modalPassword').value
  };
  const res = await postCommand(payload);
  if (res && res.ok) {
    closeUserModal();
  }
}

/* Settings Form */
async function saveAlarmSettings(e) {
  e.preventDefault();
  const res = await postCommand({
    command: 'UPDATE_SETTINGS',
    device_name: document.getElementById('setDeviceName')?.value.trim() || 'Smart Secure Storage Box',
    device_code: document.getElementById('setDeviceCode').value.trim(),
    max_pin_attempts: Number(document.getElementById('setMaxPinAttempts').value),
    door_timeout_sec: Number(document.getElementById('setDoorTimeoutSec').value),
    temp_threshold: Number(document.getElementById('setTempThreshold').value),
    humidity_threshold: Number(document.getElementById('setHumThreshold').value),
    auto_lock_sec: Number(document.getElementById('setAutoLockSec').value),
    api_key: document.getElementById('setDeviceApiKey').value.trim()
  });
  if (res && res.ok) {
    appState.settingsLoadedForDeviceId = null;
  }
}

function copyDeviceApiKey() {
  const key = document.getElementById('setDeviceApiKey')?.value || '';
  if (!key) return;
  navigator.clipboard.writeText(key);
  showToast('API Key disalin ke clipboard!', 'content_copy');
}

/* Own PIN per Device & Global Profile */
async function updateOwnPin(e) {
  e.preventDefault();
  const pin = document.getElementById('newOwnPinInput').value.trim();
  const res = await postCommand({
    command: 'UPDATE_OWN_PIN',
    pin
  });
  if (res && res.ok) {
    document.getElementById('newOwnPinInput').value = '';
  }
}

async function updateOwnProfile(e) {
  e.preventDefault();
  const fullName = document.getElementById('profileFullName').value.trim();
  const newPassword = document.getElementById('profileNewPassword').value;
  const res = await postCommand({
    command: 'UPDATE_PROFILE',
    full_name: fullName,
    new_password: newPassword
  });
  if (res && res.ok) {
    document.getElementById('profileNewPassword').value = '';
    const gName = document.getElementById('greetingFullName');
    const sName = document.getElementById('sidebarUserFullName');
    if (gName) gName.textContent = fullName;
    if (sName) sName.textContent = fullName;
  }
}
