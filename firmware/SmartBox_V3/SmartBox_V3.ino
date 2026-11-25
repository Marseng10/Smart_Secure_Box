/*
 * ============================================================
 * SMART SECURE STORAGE BOX V3 - ESP32 (2 SERVO + SW-420 + WEB API + BLE)
 * ============================================================
 *
 * PIN ESP32:
 *  Servo pengunci : GPIO16     Servo pintu : GPIO5
 *  Reed switch    : GPIO4      SW-420 DO   : GPIO35 (VCC 3.3V)
 *  Buzzer         : GPIO19     DHT11       : GPIO18
 *  I2C LCD SDA    : GPIO21     I2C LCD SCL : GPIO22
 *  Keypad R1..R4  : 13, 14, 25, 26; C1..C4 : 27, 32, 33, 23
 *
 * FITUR TERINTEGRASI:
 * 1. Logika Pintu & 2 Servo (FasePintu):
 *    PIN benar / Emergency Unlock -> buka servo kunci -> tunda 600ms ->
 *    buka servo pintu -> otomatis menutup setelah 60 detik (peringatan 30 detik)
 *    atau saat pintu ditutup manual -> kunci kembali.
 * 2. Sensor Getaran SW-420 (active LOW, edge-triggered, abaikan getaran servo 2.2s).
 * 3. Deteksi buka paksa (FORCED_OPEN_OR_REED_TAMPER_ALERT) jika reed terbuka saat TERKUNCI.
 * 4. Pairing & Pengaturan Wi-Fi via Bluetooth (BLE) + Penyimpanan Permanen NVS.
 * 5. Autentikasi PIN 6-digit ke Backend (/api/verify.php) + Fallback Offline NVS.
 * 6. Telemetry & Remote Command (/api/telemetry.php): LOCK, EMERGENCY_UNLOCK, ALARM_ON, ALARM_OFF.
 * ============================================================
 */

#include <Arduino.h>
#include <cmath>
#include <Wire.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <LiquidCrystal_I2C.h>
#include <Keypad.h>
#include <ESP32Servo.h>
#include <DHT.h>
#include <Preferences.h>
#include <BLEDevice.h>
#include <BLEServer.h>
#include <BLEUtils.h>
#include "mbedtls/md.h"


// ============================================================
// 1. KONFIGURASI DEFAULT WIFI, SERVER, DAN BLE UUID
// ============================================================

const char* DEFAULT_WIFI_SSID      = "Mahalll";
const char* DEFAULT_WIFI_PASSWORD  = "12345678910";
const char* DEFAULT_SERVER_BASE    = "http://10.227.161.71/secure-box";
const char* DEFAULT_DEVICE_CODE    = "BOX-01";
const char* DEFAULT_DEVICE_API_KEY = "300eb5caab196331ba736b19be3fa491114acfc1a5d4b6a1";

#define BLE_SERVICE_UUID        "4fafc201-1fb5-459e-8fcc-c5c9c331914b"
#define BLE_CHARACTERISTIC_UUID "beb5483e-36e1-4688-b7f5-ea07361b26a8"


// ============================================================
// 2. PIN ESP32
// ============================================================

constexpr uint8_t PIN_SERVO_KUNCI = 16;
constexpr uint8_t PIN_SERVO_PINTU = 5;
constexpr uint8_t PIN_REED        = 4;
constexpr uint8_t PIN_SW420       = 35;
constexpr uint8_t PIN_BUZZER      = 19;
constexpr uint8_t PIN_DHT         = 18;
constexpr uint8_t PIN_LCD_SDA     = 21;
constexpr uint8_t PIN_LCD_SCL     = 22;

#define DHT_TYPE DHT11


// ============================================================
// 3. KALIBRASI & DURASI
// ============================================================

constexpr int SUDUT_KUNCI_TUTUP   = 90;
constexpr int SUDUT_KUNCI_BUKA    = 0;
constexpr int SUDUT_PINTU_TUTUP   = 90;
constexpr int SUDUT_PINTU_BUKA    = 0;

constexpr int LEVEL_REED_TERBUKA  = HIGH;
constexpr int LEVEL_SW420_GETAR   = LOW;

constexpr uint32_t TUNDA_BUKA_SETELAH_PIN       = 600UL;
constexpr uint32_t BATAS_BUKA_PINTU_MS          = 4500UL;
constexpr uint32_t BATAS_TUTUP_PINTU_MS         = 5000UL;
constexpr uint32_t TUNGGU_MOTOR_TUTUP_MS        = 1600UL;
constexpr uint32_t TUNDA_KUNCI_SETELAH_TUTUP_MS = 500UL;
constexpr uint32_t DEBOUNCE_REED_MS             = 80UL;
constexpr uint32_t COOLDOWN_GETAR_MS            = 1200UL;
constexpr uint32_t ABAIKAN_GETAR_SERVO_MS       = 2200UL;
constexpr uint32_t LAMA_ALARM_GETAR_MS          = 4000UL;
constexpr uint32_t WAKTU_PESAN_ERROR_MS         = 4000UL;

constexpr uint32_t TELEMETRY_MS  = 1500UL;
constexpr uint32_t WIFI_RETRY_MS = 10000UL;

// Pengaturan yang dapat disinkronkan dari Web Dashboard
uint32_t PERINGATAN_PINTU_MS = 30000UL;
uint32_t TUTUP_OTOMATIS_MS   = 60000UL;
uint32_t LOCKOUT_PIN_MS      = 30000UL;
int MAX_PIN_ATTEMPTS         = 3;


// ============================================================
// 4. OBJEK & KEYPAD
// ============================================================

Servo servoKunci;
Servo servoPintu;
DHT dht(PIN_DHT, DHT_TYPE);
LiquidCrystal_I2C lcd(0x27, 16, 2);
Preferences prefs;
BLECharacteristic* pBleCharacteristic = nullptr;

constexpr byte BARIS = 4;
constexpr byte KOLOM = 4;
char susunanKeypad[BARIS][KOLOM] = {
  {'1', '2', '3', 'A'},
  {'4', '5', '6', 'B'},
  {'7', '8', '9', 'C'},
  {'*', '0', '#', 'D'}
};
byte pinBaris[BARIS] = {13, 14, 25, 26};
byte pinKolom[KOLOM] = {27, 32, 33, 23};
Keypad keypad = Keypad(makeKeymap(susunanKeypad), pinBaris, pinKolom, BARIS, KOLOM);


// ============================================================
// 5. STATE MACHINE & VARIABEL GLOBAL
// ============================================================

enum FasePintu : uint8_t {
  TERKUNCI,
  MENUNGGU_BUKA,      // servo kunci membuka, tunggu sebelum buka pintu
  SEDANG_MEMBUKA,     // servo pintu sudah diperintah membuka
  TERBUKA,            // menunggu 60 detik dari perintah membuka
  SEDANG_MENUTUP,     // servo pintu diperintah menutup
  MENUNGGU_KUNCI,     // reed sudah tertutup, tunggu sebelum servo kunci
  GAGAL_MENUTUP
};
FasePintu fase = TERKUNCI;

String activeWifiSsid   = DEFAULT_WIFI_SSID;
String activeWifiPass   = DEFAULT_WIFI_PASSWORD;
String activeServerBase = DEFAULT_SERVER_BASE;
String activeDeviceCode = DEFAULT_DEVICE_CODE;
String activeApiKey     = DEFAULT_DEVICE_API_KEY;
bool bleWifiUpdatePending = false;

String bufferPIN = "";
String lcdSebelumnya1 = "";
String lcdSebelumnya2 = "";
String lcdPesanSementara1 = "";
String lcdPesanSementara2 = "";
uint32_t lcdPesanHingga = 0;

bool pintuTerbuka = false;
bool pembacaanReedTerakhir = false;
bool pernahTerdeteksiTerbuka = false;
bool sudahPeringatan = false;
bool kunciTerbuka = false;
bool sedangAutoCloseTimeout = false;
bool autoClosedReportPending = false;

uint32_t reedBerubahPada = 0;
uint32_t faseDimulaiPada = 0;
uint32_t mulaiSesiBukaPada = 0;
uint32_t servoTerakhirBergerak = 0;

uint8_t salahPIN = 0;
bool keypadLockout = false;
bool remoteSecurityAlarm = false;
uint32_t lockoutMulai = 0;
uint32_t bunyiPendekHingga = 0;
uint32_t alarmGetarHingga = 0;
uint32_t getarTerakhir = 0;
bool bacaanGetarSebelumnya = false;

bool gagalMembuka = false;
uint32_t pesanErrorHingga = 0;
float suhu = NAN;
float kelembapan = NAN;
uint32_t bacaDHTPada = 0;
uint32_t perbaruiLCDPada = 0;
uint32_t lastTelemetry = 0;
uint32_t lastWiFi = 0;

long long ackId = 0;
long long lastExecutedId = 0;
String ackStatus = "";
String ackMessage = "";

struct LocalPinEntry {
  String username;
  String role;
  String pinSha256;
};
LocalPinEntry localPins[15];
int localPinCount = 0;

struct OfflineLogEntry {
  String username;
  String action;
  String result;
  String details;
  uint32_t createdMs;
};
OfflineLogEntry offlineLogs[12];
int offlineLogCount = 0;


// ============================================================
// 6. UTILITAS NVS, HASH PIN, & LOG OFFLINE
// ============================================================

void logEvent(const char* nama) {
  Serial.print("[EVENT ");
  Serial.print(millis() / 1000UL);
  Serial.print("s] ");
  Serial.println(nama);
}

void pushOfflineLog(const String& user, const String& action, const String& result, const String& details) {
  if (offlineLogCount >= 12) {
    for (int i = 1; i < 12; i++) {
      offlineLogs[i - 1] = offlineLogs[i];
    }
    offlineLogCount = 11;
  }
  offlineLogs[offlineLogCount].username  = user;
  offlineLogs[offlineLogCount].action    = action;
  offlineLogs[offlineLogCount].result    = result;
  offlineLogs[offlineLogCount].details   = details;
  offlineLogs[offlineLogCount].createdMs = millis();
  offlineLogCount++;
}

void loadDeviceAndWifiFromNVS() {
  prefs.begin("smartbox_cfg", true);
  activeWifiSsid   = prefs.getString("ssid", DEFAULT_WIFI_SSID);
  activeWifiPass   = prefs.getString("pass", DEFAULT_WIFI_PASSWORD);
  activeServerBase = prefs.getString("srv", DEFAULT_SERVER_BASE);
  activeDeviceCode = prefs.getString("code", DEFAULT_DEVICE_CODE);
  activeApiKey     = prefs.getString("key", DEFAULT_DEVICE_API_KEY);
  prefs.end();
  Serial.printf("[NVS] Box: %s | Wi-Fi: %s\n", activeDeviceCode.c_str(), activeWifiSsid.c_str());
}

void saveDeviceAndWifiToNVS(const String& ssid, const String& pass, const String& code, const String& key) {
  prefs.begin("smartbox_cfg", false);
  if (ssid.length() > 0) {
    prefs.putString("ssid", ssid);
    activeWifiSsid = ssid;
    prefs.putString("pass", pass);
    activeWifiPass = pass;
  }
  if (code.length() > 0) {
    prefs.putString("code", code);
    activeDeviceCode = code;
  }
  if (key.length() > 0) {
    prefs.putString("key", key);
    activeApiKey = key;
  }
  prefs.end();
}

String computePinSha256(const String& pin) {
  String raw = "SMARTBOX_V3_PIN:" + pin;
  byte digest[32];
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 0);
  mbedtls_md_starts(&ctx);
  mbedtls_md_update(&ctx, (const unsigned char*)raw.c_str(), raw.length());
  mbedtls_md_finish(&ctx, digest);
  mbedtls_md_free(&ctx);

  char hex[65];
  for (int i = 0; i < 32; i++) {
    sprintf(hex + (i * 2), "%02x", digest[i]);
  }
  hex[64] = '\0';
  return String(hex);
}

void saveLocalPinsToNVS() {
  prefs.begin("smartbox_v3", false);
  prefs.putInt("count", localPinCount);
  for (int i = 0; i < localPinCount; i++) {
    prefs.putString(("u" + String(i)).c_str(), localPins[i].username);
    prefs.putString(("r" + String(i)).c_str(), localPins[i].role);
    prefs.putString(("h" + String(i)).c_str(), localPins[i].pinSha256);
  }
  prefs.end();
}

void loadLocalPinsFromNVS() {
  prefs.begin("smartbox_v3", true);
  localPinCount = prefs.getInt("count", 0);
  if (localPinCount > 15) localPinCount = 15;
  for (int i = 0; i < localPinCount; i++) {
    localPins[i].username  = prefs.getString(("u" + String(i)).c_str(), "");
    localPins[i].role      = prefs.getString(("r" + String(i)).c_str(), "USER");
    localPins[i].pinSha256 = prefs.getString(("h" + String(i)).c_str(), "");
  }
  prefs.end();
}

bool verifyPinLocally(const String& pin, String& matchedUser) {
  String hash = computePinSha256(pin);
  for (int i = 0; i < localPinCount; i++) {
    if (localPins[i].pinSha256.equalsIgnoreCase(hash)) {
      matchedUser = localPins[i].username;
      return true;
    }
  }
  return false;
}


// ============================================================
// 7. LCD & PESAN SEMENTARA
// ============================================================

void tampilLCD(String a, String b) {
  a = a.substring(0, 16);
  b = b.substring(0, 16);
  while (a.length() < 16) a += ' ';
  while (b.length() < 16) b += ' ';
  if (a != lcdSebelumnya1) {
    lcd.setCursor(0, 0);
    lcd.print(a);
    lcdSebelumnya1 = a;
  }
  if (b != lcdSebelumnya2) {
    lcd.setCursor(0, 1);
    lcd.print(b);
    lcdSebelumnya2 = b;
  }
}

void tampilPesanSementara(const String& a, const String& b, uint32_t durasiMs) {
  lcdPesanSementara1 = a;
  lcdPesanSementara2 = b;
  lcdPesanHingga = millis() + durasiMs;
  tampilLCD(a, b);
}


// ============================================================
// 8. BLUETOOTH LOW ENERGY (BLE) UNTUK PAIRING & SET WIFI DARI WEB
// ============================================================

class SmartBoxBleCallbacks : public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* pCharacteristic) override {
    String rxValue = pCharacteristic->getValue().c_str();
    rxValue.trim();
    if (rxValue.length() == 0) return;

    Serial.printf("[BLE] Data diterima dari Web: %s\n", rxValue.c_str());

    JsonDocument doc;
    if (deserializeJson(doc, rxValue) == DeserializationError::Ok) {
      String newSsid = String((const char*)(doc["ssid"] | ""));
      String newPass = String((const char*)(doc["pass"] | ""));
      String newCode = String((const char*)(doc["code"] | ""));
      String newKey  = String((const char*)(doc["key"] | ""));

      saveDeviceAndWifiToNVS(newSsid, newPass, newCode, newKey);
      bleWifiUpdatePending = true;
    }
  }
};

class SmartBoxServerCallbacks : public BLEServerCallbacks {
  void onConnect(BLEServer* pServer) override {
    Serial.println("[BLE] Perangkat Web/HP Terhubung via Bluetooth");
  }
  void onDisconnect(BLEServer* pServer) override {
    Serial.println("[BLE] Bluetooth Terputus, memulai ulang advertising...");
    BLEDevice::startAdvertising();
  }
};

void initBLEProvisioning() {
  String bleName = "SmartBox-" + activeDeviceCode;
  BLEDevice::init(bleName.c_str());
  BLEServer* pServer = BLEDevice::createServer();
  pServer->setCallbacks(new SmartBoxServerCallbacks());

  BLEService* pService = pServer->createService(BLE_SERVICE_UUID);
  pBleCharacteristic = pService->createCharacteristic(
    BLE_CHARACTERISTIC_UUID,
    BLECharacteristic::PROPERTY_READ | BLECharacteristic::PROPERTY_WRITE
  );
  pBleCharacteristic->setCallbacks(new SmartBoxBleCallbacks());

  JsonDocument infoDoc;
  infoDoc["code"] = activeDeviceCode;
  infoDoc["ssid"] = activeWifiSsid;
  String infoStr;
  serializeJson(infoDoc, infoStr);
  pBleCharacteristic->setValue(infoStr.c_str());

  pService->start();
  BLEAdvertising* pAdvertising = BLEDevice::getAdvertising();
  pAdvertising->addServiceUUID(BLE_SERVICE_UUID);
  pAdvertising->setScanResponse(true);
  BLEDevice::startAdvertising();

  Serial.printf("[BLE] Siap menerima Pairing & Wi-Fi sebagai '%s'\n", bleName.c_str());
}


// ============================================================
// 9. KONTROL SERVO & STATE MACHINE PINTU
// ============================================================

void gerakkanKunci(bool buka, uint32_t sekarang) {
  servoKunci.write(buka ? SUDUT_KUNCI_BUKA : SUDUT_KUNCI_TUTUP);
  kunciTerbuka = buka;
  servoTerakhirBergerak = sekarang;
}

void gerakkanPintu(bool buka, uint32_t sekarang) {
  if (!servoPintu.attached()) {
    servoPintu.attach(PIN_SERVO_PINTU, 500, 2400);
  }
  servoPintu.write(buka ? SUDUT_PINTU_BUKA : SUDUT_PINTU_TUTUP);
  servoTerakhirBergerak = sekarang;
}

bool dalamLockout(uint32_t sekarang) {
  if (!keypadLockout) return false;
  if ((uint32_t)(sekarang - lockoutMulai) < LOCKOUT_PIN_MS) return true;
  keypadLockout = false;
  salahPIN = 0;
  logEvent("KEYPAD_UNLOCKED");
  return false;
}

void mulaiMenutup(uint32_t sekarang, bool karenaTimeout) {
  if (fase == SEDANG_MENUTUP || fase == MENUNGGU_KUNCI || fase == TERKUNCI) return;
  fase = SEDANG_MENUTUP;
  faseDimulaiPada = sekarang;
  sudahPeringatan = false;
  sedangAutoCloseTimeout = karenaTimeout;
  if (!kunciTerbuka) gerakkanKunci(true, sekarang);
  gerakkanPintu(false, sekarang);
  logEvent(karenaTimeout ? "AUTO_CLOSE_STARTED_AFTER_TIMEOUT"
                         : "DOOR_CLOSED_MANUALLY_SERVO_RETURNING");
}

void terimaAksesPIN(uint32_t sekarang) {
  salahPIN = 0;
  keypadLockout = false;
  remoteSecurityAlarm = false;
  alarmGetarHingga = 0;
  gagalMembuka = false;
  pesanErrorHingga = 0;
  sudahPeringatan = false;
  sedangAutoCloseTimeout = false;
  pernahTerdeteksiTerbuka = pintuTerbuka;

  gerakkanKunci(true, sekarang);
  fase = MENUNGGU_BUKA;
  faseDimulaiPada = sekarang;
  bunyiPendekHingga = sekarang + 180UL;
  logEvent("ACCESS_SUCCESS_UNLOCK_STARTED");
}

void pintuBaruTerbuka(uint32_t sekarang) {
  pernahTerdeteksiTerbuka = true;
  if (fase == MENUNGGU_KUNCI) {
    servoPintu.detach();
    fase = GAGAL_MENUTUP;
    faseDimulaiPada = sekarang;
    alarmGetarHingga = sekarang + LAMA_ALARM_GETAR_MS;
    logEvent("DOOR_REOPENED_BEFORE_LOCKING");
  } else if (fase == TERKUNCI) {
    fase = TERBUKA;
    mulaiSesiBukaPada = sekarang;
    faseDimulaiPada = sekarang;
    sudahPeringatan = false;
    gerakkanKunci(true, sekarang);
    alarmGetarHingga = sekarang + LAMA_ALARM_GETAR_MS;
    logEvent("FORCED_OPEN_OR_REED_TAMPER_ALERT");
    if (WiFi.status() != WL_CONNECTED) {
      pushOfflineLog("", "OFFLINE_VIBRATION_ALARM", "FAILED", "Pintu terbuka paksa tanpa PIN saat offline");
    }
  } else if (fase == SEDANG_MEMBUKA) {
    fase = TERBUKA;
    faseDimulaiPada = sekarang;
    mulaiSesiBukaPada = sekarang;
    logEvent("DOOR_OPENED_BY_SERVO");
  } else if (fase == MENUNGGU_BUKA) {
    mulaiSesiBukaPada = sekarang;
    logEvent("DOOR_OPENED_DURING_UNLOCK");
  } else if (fase == TERBUKA) {
    logEvent("DOOR_OPEN");
  }
}

void pintuBaruTertutup(uint32_t sekarang) {
  if ((fase == TERBUKA || fase == SEDANG_MEMBUKA || fase == MENUNGGU_BUKA) &&
      pernahTerdeteksiTerbuka) {
    mulaiMenutup(sekarang, false);
  } else if (fase == GAGAL_MENUTUP) {
    fase = MENUNGGU_KUNCI;
    faseDimulaiPada = sekarang;
    logEvent("DOOR_CLOSED_AFTER_FAILED_AUTO_CLOSE");
  } else if (fase == SEDANG_MENUTUP) {
    logEvent("REED_CONFIRMED_DOOR_CLOSED");
  }
}

void cekSensorPintu(uint32_t sekarang) {
  const bool bacaan = digitalRead(PIN_REED) == LEVEL_REED_TERBUKA;
  if (bacaan != pembacaanReedTerakhir) {
    pembacaanReedTerakhir = bacaan;
    reedBerubahPada = sekarang;
  }
  if (bacaan != pintuTerbuka &&
      (uint32_t)(sekarang - reedBerubahPada) >= DEBOUNCE_REED_MS) {
    pintuTerbuka = bacaan;
    if (pintuTerbuka) pintuBaruTerbuka(sekarang);
    else pintuBaruTertutup(sekarang);
  }
}

void prosesPintu(uint32_t sekarang) {
  switch (fase) {
    case TERKUNCI:
      break;

    case MENUNGGU_BUKA:
      if ((uint32_t)(sekarang - faseDimulaiPada) >= TUNDA_BUKA_SETELAH_PIN) {
        gerakkanPintu(true, sekarang);
        fase = SEDANG_MEMBUKA;
        faseDimulaiPada = sekarang;
        if (!pintuTerbuka) mulaiSesiBukaPada = sekarang;
        logEvent("AUTO_DOOR_OPEN_STARTED_AFTER_VALID_PIN");
      }
      break;

    case SEDANG_MEMBUKA:
      if (pintuTerbuka) {
        fase = TERBUKA;
        faseDimulaiPada = sekarang;
      } else if ((uint32_t)(sekarang - faseDimulaiPada) >= BATAS_BUKA_PINTU_MS) {
        servoPintu.detach();
        if (!pintuTerbuka) gerakkanKunci(false, sekarang);
        fase = TERKUNCI;
        gagalMembuka = true;
        pesanErrorHingga = sekarang + WAKTU_PESAN_ERROR_MS;
        logEvent("AUTO_OPEN_FAILED_CHECK_SERVO_OR_REED");
      }
      break;

    case TERBUKA: {
      const uint32_t berjalan = (uint32_t)(sekarang - mulaiSesiBukaPada);
      if (!sudahPeringatan && berjalan >= PERINGATAN_PINTU_MS) {
        sudahPeringatan = true;
        logEvent("FORGOT_CLOSE_WARNING_TRIGGERED");
      }
      if (berjalan >= TUTUP_OTOMATIS_MS) {
        mulaiMenutup(sekarang, true);
      }
      break;
    }

    case SEDANG_MENUTUP: {
      const uint32_t gerak = (uint32_t)(sekarang - faseDimulaiPada);
      if (!pintuTerbuka && gerak >= TUNGGU_MOTOR_TUTUP_MS) {
        fase = MENUNGGU_KUNCI;
        faseDimulaiPada = sekarang;
        logEvent("AUTO_CLOSE_SUCCESS_REED_CONFIRMED");
      } else if (pintuTerbuka && gerak >= BATAS_TUTUP_PINTU_MS) {
        servoPintu.detach();
        fase = GAGAL_MENUTUP;
        faseDimulaiPada = sekarang;
        logEvent("AUTO_CLOSE_FAILED_DOOR_STILL_OPEN");
      }
      break;
    }

    case MENUNGGU_KUNCI:
      if (pintuTerbuka) {
        fase = GAGAL_MENUTUP;
        faseDimulaiPada = sekarang;
        servoPintu.detach();
        logEvent("LOCK_CANCELLED_DOOR_OPEN_AGAIN");
      } else if ((uint32_t)(sekarang - faseDimulaiPada) >= TUNDA_KUNCI_SETELAH_TUTUP_MS) {
        gerakkanKunci(false, sekarang);
        servoPintu.detach();
        fase = TERKUNCI;
        faseDimulaiPada = sekarang;
        pernahTerdeteksiTerbuka = false;
        sudahPeringatan = false;
        if (sedangAutoCloseTimeout) {
          autoClosedReportPending = true;
          sedangAutoCloseTimeout = false;
          if (WiFi.status() != WL_CONNECTED) {
            pushOfflineLog("", "AUTO_CLOSE_FORGOT", "SUCCESS", "Servo pintu & kunci otomatis menutup setelah timeout");
          }
        }
        logEvent("DOOR_LOCKED_AFTER_CLOSE");
      }
      break;

    case GAGAL_MENUTUP:
      break;
  }
}


// ============================================================
// 10. KOMUNIKASI HTTP & VERIFIKASI PIN (ONLINE + FALLBACK NVS)
// ============================================================

bool post(const char* endpoint, JsonDocument& doc, JsonDocument& reply, int& status) {
  status = 0;
  if (WiFi.status() != WL_CONNECTED) {
    return false;
  }

  WiFiClient client;
  HTTPClient http;
  http.setConnectTimeout(1800);
  http.setTimeout(3000);

  String url = activeServerBase + String(endpoint);
  if (!http.begin(client, url)) {
    return false;
  }

  http.addHeader("Content-Type", "application/json");

  String body;
  serializeJson(doc, body);
  status = http.POST(body);

  String response = status > 0 ? http.getString() : "";
  http.end();

  if (status <= 0) {
    return false;
  }

  if (deserializeJson(reply, response)) {
    Serial.printf("[HTTP] JSON invalid, status %d\n", status);
    return false;
  }

  return status >= 200 && status < 300;
}

void addCredentials(JsonDocument& doc) {
  doc["device_code"] = activeDeviceCode;
  doc["api_key"]     = activeApiKey;
}

void processWrongPinAttempt(uint32_t sekarang, bool isOffline) {
  salahPIN++;
  bunyiPendekHingga = sekarang + 500UL;
  logEvent("PIN_INCORRECT");

  if (salahPIN >= MAX_PIN_ATTEMPTS) {
    keypadLockout = true;
    lockoutMulai = sekarang;
    logEvent("FAILED_ACCESS_LOCKOUT");
    tampilPesanSementara("SECURITY ALERT", "KEYPAD TERKUNCI", 1600);
    if (isOffline) {
      pushOfflineLog("", "OFFLINE_PIN_LOCKOUT", "FAILED", "Salah PIN berturut-turut saat offline (Lockout & Buzzer aktif)");
    }
  } else {
    tampilPesanSementara("ACCESS DENIED", "PIN SALAH", 1200);
    if (isOffline) {
      pushOfflineLog("", "OFFLINE_PIN_FAILED", "FAILED", "Percobaan PIN salah ke-" + String(salahPIN) + " saat offline");
    }
  }
}

void checkPIN(uint32_t sekarang) {
  if (bufferPIN.length() != 6) {
    bufferPIN = "";
    bunyiPendekHingga = sekarang + 250UL;
    tampilPesanSementara("PIN HARUS", "6 DIGIT", 1200);
    return;
  }

  String enteredPin = bufferPIN;
  bufferPIN = "";

  JsonDocument req;
  JsonDocument res;
  addCredentials(req);
  req["pin"] = enteredPin;

  int status = 0;
  bool ok = post("/api/verify.php", req, res, status);

  Serial.printf("[PIN] HTTP %d\n", status);

  if (ok && (res["authorized"] | false)) {
    terimaAksesPIN(sekarang);
    return;
  }

  if (status <= 0) {
    String matchedUser = "";
    if (verifyPinLocally(enteredPin, matchedUser)) {
      terimaAksesPIN(sekarang);
      tampilPesanSementara("OFFLINE ACCESS", matchedUser, 1500);
      pushOfflineLog(matchedUser, "OFFLINE_PIN_SUCCESS", "SUCCESS", "Buka kunci via memori lokal NVS ESP32 saat offline");
    } else {
      processWrongPinAttempt(sekarang, true);
    }
    return;
  }

  if (status == 403) {
    processWrongPinAttempt(sekarang, false);
    return;
  }

  if (status == 429) {
    salahPIN = MAX_PIN_ATTEMPTS;
    keypadLockout = true;
    lockoutMulai = sekarang;
    tampilPesanSementara("SERVER LOCKOUT", "TUNGGU 30 DETIK", 1600);
    return;
  }

  tampilPesanSementara("SERVER ERROR", "ACCESS DENIED", 1400);
  bunyiPendekHingga = sekarang + 400UL;
}


// ============================================================
// 11. KEYPAD, GETARAN SW-420, BUZZER, DHT, LCD
// ============================================================

void cekKeypad(uint32_t sekarang) {
  char tombol = keypad.getKey();
  if (tombol == NO_KEY) return;
  if (dalamLockout(sekarang)) return;

  // Perintah tutup pintu via keypad (tombol 'D' atau '#' saat pintu terbuka / gagal menutup)
  if (tombol == 'D' || (tombol == '#' && fase != TERKUNCI)) {
    if (fase == TERBUKA || fase == SEDANG_MEMBUKA || fase == MENUNGGU_BUKA || fase == GAGAL_MENUTUP) {
      bunyiPendekHingga = sekarang + 120UL;
      tampilPesanSementara("TUTUP PINTU", "VIA KEYPAD...", 1200);
      mulaiMenutup(sekarang, false);
    }
    return;
  }

  if (fase != TERKUNCI) return;

  if (tombol == '*') {
    bufferPIN = "";
    return;
  }
  if (tombol >= '0' && tombol <= '9') {
    if (bufferPIN.length() < 6) {
      bufferPIN += tombol;
      bunyiPendekHingga = sekarang + 35UL;
    }
    return;
  }
  if (tombol == '#') {
    checkPIN(sekarang);
  }
}

void cekGetaran(uint32_t sekarang) {
  bool sedangGetar = digitalRead(PIN_SW420) == LEVEL_SW420_GETAR;
  bool tepiGetar = sedangGetar && !bacaanGetarSebelumnya;
  bacaanGetarSebelumnya = sedangGetar;

  if (!tepiGetar || fase != TERKUNCI || pintuTerbuka ||
      (uint32_t)(sekarang - servoTerakhirBergerak) < ABAIKAN_GETAR_SERVO_MS ||
      (uint32_t)(sekarang - getarTerakhir) < COOLDOWN_GETAR_MS) {
    return;
  }

  getarTerakhir = sekarang;
  alarmGetarHingga = sekarang + LAMA_ALARM_GETAR_MS;
  logEvent("VIBRATION_ALERT_SW420");

  if (WiFi.status() != WL_CONNECTED) {
    pushOfflineLog("", "OFFLINE_VIBRATION_ALARM", "FAILED", "Sensor getaran SW-420 mendeteksi guncangan saat offline");
  }
}

void prosesBuzzer(uint32_t sekarang) {
  bool alarmGagalTutup = fase == GAGAL_MENUTUP;
  bool alarmPIN = dalamLockout(sekarang) || remoteSecurityAlarm;
  bool alarmGetar = (int32_t)(alarmGetarHingga - sekarang) > 0;
  bool bipPIN = (int32_t)(bunyiPendekHingga - sekarang) > 0;
  bool alarmPintu = fase == TERBUKA && sudahPeringatan;
  bool alarmGagalBuka = gagalMembuka && (int32_t)(pesanErrorHingga - sekarang) > 0;

  bool bunyi;
  if (alarmGagalTutup)      bunyi = (sekarang % 650UL) < 320UL;
  else if (alarmPIN)        bunyi = (sekarang % 500UL) < 180UL;
  else if (alarmGetar)      bunyi = (sekarang % 450UL) < 180UL;
  else if (alarmGagalBuka)  bunyi = (sekarang % 600UL) < 250UL;
  else if (alarmPintu)      bunyi = (sekarang % 1000UL) < 200UL;
  else                      bunyi = bipPIN;

  digitalWrite(PIN_BUZZER, bunyi ? HIGH : LOW);
}

void cekDHT(uint32_t sekarang) {
  if ((uint32_t)(sekarang - bacaDHTPada) < 2500UL) return;
  bacaDHTPada = sekarang;
  float t = dht.readTemperature();
  float h = dht.readHumidity();
  if (!std::isnan(t) && !std::isnan(h)) {
    suhu = t;
    kelembapan = h;
  }
}

void perbaruiLCD(uint32_t sekarang) {
  if ((uint32_t)(sekarang - perbaruiLCDPada) < 250UL) return;
  perbaruiLCDPada = sekarang;

  if ((int32_t)(lcdPesanHingga - sekarang) > 0) {
    tampilLCD(lcdPesanSementara1, lcdPesanSementara2);
    return;
  }

  String baris1, baris2;
  if (dalamLockout(sekarang)) {
    uint32_t sisa = LOCKOUT_PIN_MS - (sekarang - lockoutMulai);
    baris1 = "KEYPAD TERKUNCI";
    baris2 = "Tunggu " + String((sisa + 999UL) / 1000UL) + " dtk";
  } else if (fase == GAGAL_MENUTUP) {
    baris1 = "GAGAL MENUTUP!";
    baris2 = "Cek pintu";
  } else if (fase == TERKUNCI && (int32_t)(alarmGetarHingga - sekarang) > 0) {
    baris1 = "ALARM GETARAN!";
    baris2 = "Periksa kotak";
  } else if (fase == MENUNGGU_BUKA) {
    baris1 = "PIN BENAR";
    baris2 = "Buka kunci...";
  } else if (fase == SEDANG_MEMBUKA) {
    baris1 = "BUKA OTOMATIS";
    baris2 = "Servo pintu...";
  } else if (fase == SEDANG_MENUTUP) {
    baris1 = "TUTUP OTOMATIS";
    baris2 = "Jauhkan tangan";
  } else if (fase == MENUNGGU_KUNCI) {
    baris1 = "PINTU TERTUTUP";
    baris2 = "Mengunci...";
  } else if (fase == TERBUKA) {
    uint32_t s = (uint32_t)(sekarang - mulaiSesiBukaPada) / 1000UL;
    uint32_t totalDetik = TUTUP_OTOMATIS_MS / 1000UL;
    uint32_t sisa = s >= totalDetik ? 0UL : totalDetik - s;
    baris1 = sudahPeringatan ? "SEGERA TUTUP!" : "PINTU TERBUKA";
    baris2 = "Auto: " + String(sisa) + " detik";
  } else if (gagalMembuka && (int32_t)(pesanErrorHingga - sekarang) > 0) {
    baris1 = "GAGAL MEMBUKA";
    baris2 = "Cek servo/reed";
  } else if ((sekarang / 4000UL) % 2UL && !std::isnan(suhu) && !std::isnan(kelembapan)) {
    baris1 = "Suhu: " + String(suhu, 1) + " C";
    baris2 = "Lembap: " + String(kelembapan, 0) + "%";
  } else {
    baris1 = "PINTU TERKUNCI";
    baris2 = "Masukkan PIN";
  }

  if (bufferPIN.length() > 0 && fase == TERKUNCI) {
    baris2 = "PIN: ";
    for (size_t i = 0; i < bufferPIN.length(); ++i) baris2 += '*';
  }
  tampilLCD(baris1, baris2);
}


// ============================================================
// 12. WIFI & TELEMETRY / REMOTE COMMANDS
// ============================================================

void updateWiFi(uint32_t sekarang) {
  if (bleWifiUpdatePending) {
    bleWifiUpdatePending = false;
    tampilPesanSementara("BLE WIFI SAVED!", activeWifiSsid, 2200);
    bunyiPendekHingga = sekarang + 200UL;
    Serial.printf("[WIFI] Menyambungkan ke Wi-Fi baru dari BLE: %s\n", activeWifiSsid.c_str());
    WiFi.disconnect();
    WiFi.begin(activeWifiSsid.c_str(), activeWifiPass.c_str());
    lastWiFi = sekarang;
    return;
  }

  if (WiFi.status() == WL_CONNECTED) return;
  if ((uint32_t)(sekarang - lastWiFi) < WIFI_RETRY_MS) return;
  lastWiFi = sekarang;

  Serial.printf("[WIFI] Menghubungkan ulang ke NVS SSID: %s...\n", activeWifiSsid.c_str());
  WiFi.disconnect();
  WiFi.begin(activeWifiSsid.c_str(), activeWifiPass.c_str());
}

void sendTelemetry(uint32_t sekarang) {
  if (WiFi.status() != WL_CONNECTED) return;

  JsonDocument req;
  JsonDocument res;

  addCredentials(req);

  bool vibAlarmAktif = (int32_t)(alarmGetarHingga - sekarang) > 0;
  bool isLocked      = (fase == TERKUNCI) && !kunciTerbuka;

  req["locked"]          = isLocked;
  req["door_closed"]     = !pintuTerbuka;
  req["door_alarm"]      = (fase == TERBUKA && sudahPeringatan) || (fase == GAGAL_MENUTUP);
  req["vibration_alarm"] = vibAlarmAktif;
  req["auto_closed"]     = autoClosedReportPending;
  req["security_alarm"]  = keypadLockout || remoteSecurityAlarm || vibAlarmAktif;
  req["door_open_sec"]   = (pintuTerbuka && mulaiSesiBukaPada > 0) ? (int)((sekarang - mulaiSesiBukaPada) / 1000UL) : 0;

  if (std::isnan(suhu)) req["temperature"] = nullptr;
  else req["temperature"] = suhu;

  if (std::isnan(kelembapan)) req["humidity"] = nullptr;
  else req["humidity"] = kelembapan;

  req["rssi"] = WiFi.RSSI();

  if (ackId > 0) {
    req["ack"]["id"]      = ackId;
    req["ack"]["status"]  = ackStatus;
    req["ack"]["message"] = ackMessage;
  }

  if (offlineLogCount > 0) {
    JsonArray logsArr = req["offline_logs"].to<JsonArray>();
    for (int i = 0; i < offlineLogCount; i++) {
      JsonObject item = logsArr.add<JsonObject>();
      item["username"] = offlineLogs[i].username;
      item["action"]   = offlineLogs[i].action;
      item["result"]   = offlineLogs[i].result;
      item["details"]  = offlineLogs[i].details;
      item["ago_sec"]  = (int)((sekarang - offlineLogs[i].createdMs) / 1000UL);
    }
  }

  int status = 0;
  bool ok = post("/api/telemetry.php", req, res, status);
  Serial.printf("[TELEMETRY] HTTP %d %s\n", status, ok ? "OK" : "FAILED");

  if (!ok) return;

  if (ackId > 0) {
    Serial.printf("[REMOTE] ACK #%lld %s\n", ackId, ackStatus.c_str());
    ackId = 0;
    ackStatus = "";
    ackMessage = "";
  }
  autoClosedReportPending = false;
  offlineLogCount = 0;

  JsonVariant settings = res["settings"];
  if (!settings.isNull()) {
    int dSec = settings["door_timeout_sec"] | 30;
    int mPin = settings["max_pin_attempts"] | 3;
    PERINGATAN_PINTU_MS = (uint32_t)max(5, dSec) * 1000UL;
    TUTUP_OTOMATIS_MS   = max((uint32_t)60000UL, PERINGATAN_PINTU_MS + 30000UL);
    MAX_PIN_ATTEMPTS    = max(1, mPin);
  }

  JsonVariant pinsVar = res["offline_pins"];
  if (!pinsVar.isNull() && pinsVar.is<JsonArray>()) {
    JsonArray pinsArr = pinsVar.as<JsonArray>();
    int idx = 0;
    for (JsonObject p : pinsArr) {
      if (idx >= 15) break;
      localPins[idx].username  = String((const char*)(p["u"] | ""));
      localPins[idx].role      = String((const char*)(p["r"] | "USER"));
      localPins[idx].pinSha256 = String((const char*)(p["h"] | ""));
      idx++;
    }
    localPinCount = idx;
    saveLocalPinsToNVS();
  }

  JsonVariant cmd = res["command"];
  if (cmd.isNull()) return;

  long long id = cmd["id"].as<long long>();
  String type  = cmd["command_type"].as<String>();

  if (id <= 0 || id == lastExecutedId) return;

  lastExecutedId = id;
  bool success = false;
  String message = "";

  if (type == "LOCK") {
    if (fase == TERKUNCI) {
      gerakkanKunci(false, sekarang);
      success = true;
      message = "Kotak sudah terkunci";
    } else {
      mulaiMenutup(sekarang, false);
      success = true;
      message = "Menutup pintu & mengunci kotak";
    }
  } else if (type == "EMERGENCY_UNLOCK") {
    terimaAksesPIN(sekarang);
    tampilPesanSementara("EMERGENCY UNLOCK", "BY ADMIN WEB", 2000);
    success = true;
    message = "Emergency Unlock: kunci & pintu dibuka";
  } else if (type == "ALARM_ON") {
    remoteSecurityAlarm = true;
    tampilPesanSementara("REMOTE ALARM", "BUZZER AKTIF", 1800);
    success = true;
    message = "Alarm buzzer diaktifkan";
  } else if (type == "ALARM_OFF") {
    remoteSecurityAlarm = false;
    keypadLockout = false;
    salahPIN = 0;
    alarmGetarHingga = 0;
    sudahPeringatan = false;
    gagalMembuka = false;
    tampilPesanSementara("ALARM RESET", "BY ADMIN WEB", 1500);
    success = true;
    message = "Alarm & lockout direset";
  } else {
    message = "Perintah tidak dikenal";
  }

  ackId      = id;
  ackStatus  = success ? "SUCCESS" : "FAILED";
  ackMessage = message;
  Serial.printf("[REMOTE] #%lld %s -> %s\n", id, type.c_str(), ackStatus.c_str());
}


// ============================================================
// 13. SETUP & LOOP
// ============================================================

void setup() {
  Serial.begin(115200);
  delay(200);

  pinMode(PIN_REED, INPUT_PULLUP);
  pinMode(PIN_SW420, INPUT);
  pinMode(PIN_BUZZER, OUTPUT);
  digitalWrite(PIN_BUZZER, LOW);

  Wire.begin(PIN_LCD_SDA, PIN_LCD_SCL);
  lcd.init();
  lcd.backlight();
  tampilLCD("SMART STORAGE BOX", "Inisialisasi...");
  dht.begin();

  loadDeviceAndWifiFromNVS();
  loadLocalPinsFromNVS();
  initBLEProvisioning();

  ESP32PWM::allocateTimer(0);
  ESP32PWM::allocateTimer(1);
  servoKunci.setPeriodHertz(50);
  servoPintu.setPeriodHertz(50);
  servoKunci.attach(PIN_SERVO_KUNCI, 500, 2400);
  servoPintu.attach(PIN_SERVO_PINTU, 500, 2400);

  uint32_t sekarang = millis();
  pintuTerbuka = digitalRead(PIN_REED) == LEVEL_REED_TERBUKA;
  pembacaanReedTerakhir = pintuTerbuka;
  reedBerubahPada = sekarang;
  bacaanGetarSebelumnya = digitalRead(PIN_SW420) == LEVEL_SW420_GETAR;
  getarTerakhir = sekarang;

  if (pintuTerbuka) {
    gerakkanKunci(true, sekarang);
    gerakkanPintu(true, sekarang);
    fase = TERBUKA;
    pernahTerdeteksiTerbuka = true;
    mulaiSesiBukaPada = sekarang;
    logEvent("BOOT_WITH_OPEN_DOOR_START_TIMER_60_SECONDS");
  } else {
    gerakkanPintu(false, sekarang);
    gerakkanKunci(false, sekarang);
    fase = TERKUNCI;
    logEvent("BOOT_WITH_CLOSED_DOOR");
  }
  faseDimulaiPada = sekarang;

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(activeWifiSsid.c_str(), activeWifiPass.c_str());
  lastWiFi = sekarang;

  Serial.println("SmartBox V3 Ready (2-Servo FasePintu + SW-420 + BLE Wi-Fi + Offline NVS)");
}

void loop() {
  uint32_t sekarang = millis();
  cekSensorPintu(sekarang);
  cekKeypad(sekarang);
  prosesPintu(sekarang);
  cekGetaran(sekarang);
  cekDHT(sekarang);
  prosesBuzzer(sekarang);
  perbaruiLCD(sekarang);
  updateWiFi(sekarang);

  if ((uint32_t)(sekarang - lastTelemetry) >= TELEMETRY_MS) {
    lastTelemetry = sekarang;
    sendTelemetry(sekarang);
  }

  delay(5);
}
