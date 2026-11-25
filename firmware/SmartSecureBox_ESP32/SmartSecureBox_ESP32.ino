/*
 * ============================================================
 * SMART SECURE STORAGE BOX V3 - ESP32
 * ============================================================
 *
 * FITUR (SESUAI WEB DASHBOARD & LAPORAN):
 * 1. Autentikasi PIN 6-digit via Keypad 4x4 ke Backend (/api/verify.php).
 * 2. Autentikasi Lokal Offline (Memori NVS ESP32) saat internet/Wi-Fi terputus.
 * 3. Sinkronisasi otomatis log offline ke MySQL saat Wi-Fi kembali terhubung.
 * 4. Remote LOCK, Emergency Unlock, & Kontrol Alarm menggunakan Command ID + ACK
 *    (Remote Unlock biasa dihapus; pembukaan jarak jauh khusus Emergency Unlock).
 * 5. Auto-lock otomatis setelah pintu tertutup (default 10 detik).
 * 6. Fitur Forget to Close + Otomatis Menutup (Auto-Close):
 *    Jika pintu dibiarkan terbuka > 30 detik, buzzer berbunyi memberikan peringatan
 *    lalu motor servo otomatis menutup & mengunci kotak.
 * 7. Monitoring suhu dan kelembapan menggunakan sensor DHT11.
 * 8. Monitoring kondisi pintu menggunakan door sensor (reed switch).
 * 9. Peringatan Buzzer & Web Dashboard jika salah PIN >= 3x.
 * ============================================================
 */

#include <Arduino.h>
#include <Wire.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <LiquidCrystal_I2C.h>
#include <Keypad.h>
#include <ESP32Servo.h>
#include <DHT.h>
#include <Preferences.h>
#include "mbedtls/md.h"


// ============================================================
// 1. KONFIGURASI WIFI DAN SERVER
// ============================================================

const char* WIFI_SSID     = "Ahsan";
const char* WIFI_PASSWORD = "12345679";

// Alamat server PHP (IP Wi-Fi laptop Anda: 192.168.100.33).
const char* SERVER_BASE = "http://192.168.100.33/secure-box";

// Identitas perangkat.
const char* DEVICE_CODE    = "BOX-01";
const char* DEVICE_API_KEY = "300eb5caab196331ba736b19be3fa491114acfc1a5d4b6a1";


// ============================================================
// 2. KONFIGURASI PIN ESP32
// ============================================================

#define LCD_SDA 21
#define LCD_SCL 22

#define SERVO_PIN  15
#define DHT_PIN    18
#define BUZZER_PIN 19
#define DOOR_PIN   4

#define DHT_TYPE DHT11


// ============================================================
// 3. KONFIGURASI SISTEM
// ============================================================

const int LOCK_ANGLE   = 0;
const int UNLOCK_ANGLE = 90;

const bool DOOR_CLOSED_LOW = true;

const unsigned long TELEMETRY_MS        = 1500;
const unsigned long DHT_MS              = 2500;
const unsigned long LCD_MS              = 200;
const unsigned long WIFI_RETRY_MS       = 10000;
const unsigned long AUTO_CLOSE_DELAY_MS = 3000; // Jeda peringatan 3 detik sebelum otomatis menutup saat Forget to Close

// Pengaturan keamanan (otomatis disinkronkan dari Web Dashboard).
unsigned long AUTO_LOCK_MS  = 10000;
unsigned long DOOR_ALARM_MS = 30000;
unsigned long LOCKOUT_MS    = 30000;
int MAX_PIN_ATTEMPTS        = 3;


// ============================================================
// 4. INISIALISASI OBJEK
// ============================================================

LiquidCrystal_I2C lcd(0x27, 16, 2);
DHT dht(DHT_PIN, DHT_TYPE);
Servo servo;
Preferences prefs;


// ============================================================
// 5. KONFIGURASI KEYPAD 4X4
// ============================================================

const byte ROWS = 4;
const byte COLS = 4;

char keys[ROWS][COLS] = {
    {'1', '2', '3', 'A'},
    {'4', '5', '6', 'B'},
    {'7', '8', '9', 'C'},
    {'*', '0', '#', 'D'}
};

byte rowPins[ROWS] = {13, 14, 25, 26};
byte colPins[COLS] = {27, 32, 33, 23};

Keypad keypad = Keypad(
    makeKeymap(keys),
    rowPins,
    colPins,
    ROWS,
    COLS
);


// ============================================================
// 6. VARIABEL GLOBAL & MEMORI OFFLINE (NVS)
// ============================================================

bool locked = true;
bool doorClosed = false;
bool rawClosed = false;

bool doorAlarm = false;
bool securityAlarm = false;
bool buzzerState = false;
bool autoClosePending = false;
bool autoClosedReportPending = false;

float temp = NAN;
float hum  = NAN;

String pinInput = "";
String messageTop = "";
String messageBottom = "";

unsigned long lastTelemetry = 0;
unsigned long lastDht = 0;
unsigned long lastLcd = 0;
unsigned long lastWiFi = 0;

unsigned long lastRawChange = 0;
unsigned long doorOpenedAt = 0;
unsigned long doorAlarmTriggeredAt = 0;
unsigned long unlockAt = 0;
unsigned long lockoutAt = 0;

unsigned long messageUntil = 0;
unsigned long beepUntil = 0;
unsigned long lastAlarmToggle = 0;

int wrongCount = 0;

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
    unsigned long createdMs;
};
OfflineLogEntry offlineLogs[12];
int offlineLogCount = 0;


// ============================================================
// 7. FUNGSI HASH SHA-256 & PENYIMPANAN NVS OFFLINE
// ============================================================

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


// ============================================================
// 8. FUNGSI LCD DAN BUZZER
// ============================================================

void lcdLine(byte row, String text) {
    if (text.length() > 16) {
        text = text.substring(0, 16);
    }
    while (text.length() < 16) {
        text += ' ';
    }
    lcd.setCursor(0, row);
    lcd.print(text);
}

void displayMsg(String top, String bottom, unsigned long duration) {
    messageTop = top;
    messageBottom = bottom;
    messageUntil = millis() + duration;
    lcdLine(0, top);
    lcdLine(1, bottom);
}

void beep(unsigned long duration) {
    if (doorAlarm || securityAlarm) {
        return;
    }
    digitalWrite(BUZZER_PIN, HIGH);
    buzzerState = true;
    beepUntil = millis() + duration;
}

void updateBuzzer() {
    unsigned long now = millis();

    if (doorAlarm || securityAlarm) {
        if (now - lastAlarmToggle >= 400) {
            lastAlarmToggle = now;
            buzzerState = !buzzerState;
            digitalWrite(BUZZER_PIN, buzzerState ? HIGH : LOW);
        }
    } else if (beepUntil && (long)(now - beepUntil) >= 0) {
        digitalWrite(BUZZER_PIN, LOW);
        buzzerState = false;
        beepUntil = 0;
    }
}

void clearBuzzer() {
    if (!doorAlarm && !securityAlarm) {
        digitalWrite(BUZZER_PIN, LOW);
        buzzerState = false;
        beepUntil = 0;
    }
}


// ============================================================
// 9. SISTEM LOCK, UNLOCK, & OTOMATIS MENUTUP (FORGET TO CLOSE)
// ============================================================

bool lockBox(const char* source) {
    if (!doorClosed) {
        Serial.println("[LOCK] GAGAL: pintu terbuka");
        displayMsg("CLOSE THE DOOR", "BEFORE LOCKING", 1600);
        beep(300);
        return false;
    }

    servo.write(LOCK_ANGLE);
    locked = true;

    Serial.printf("[LOCK] %s\n", source);
    displayMsg("BOX LOCKED", "", 1200);
    beep(120);
    return true;
}

// Fungsi Otomatis Menutup & Mengunci saat Forget to Close (> 30 detik)
void autoCloseBox() {
    servo.write(LOCK_ANGLE);
    locked = true;
    autoClosePending = false;
    autoClosedReportPending = true;

    Serial.println("[AUTO-CLOSE] Servo otomatis menutup & mengunci kotak (Forget to Close)");
    displayMsg("AUTO CLOSED!", "FORGOT TO CLOSE", 2200);

    // Matikan alarm pintu setelah penutupan otomatis selesai
    doorAlarm = false;
    doorOpenedAt = 0;
    clearBuzzer();
    beep(400);

    if (WiFi.status() != WL_CONNECTED) {
        pushOfflineLog("", "AUTO_CLOSE_FORGOT", "SUCCESS", "Servo otomatis menutup & mengunci kotak karena lupa ditutup (>30s)");
    }
}

void unlockBox(const char* source) {
    servo.write(UNLOCK_ANGLE);
    locked = false;
    unlockAt = millis();

    wrongCount = 0;
    securityAlarm = false;
    clearBuzzer();

    Serial.printf("[UNLOCK] %s\n", source);
    displayMsg("ACCESS GRANTED", "BOX UNLOCKED", 1600);
    beep(200);
}

void updateAutoLock() {
    if (!locked && millis() - unlockAt >= AUTO_LOCK_MS && doorClosed) {
        lockBox("AUTO");
    }
}


// ============================================================
// 10. SISTEM SENSOR PINTU & FORGET TO CLOSE (AUTO-CLOSE)
// ============================================================

bool readClosed() {
    return DOOR_CLOSED_LOW
        ? digitalRead(DOOR_PIN) == LOW
        : digitalRead(DOOR_PIN) == HIGH;
}

void updateDoor() {
    unsigned long now = millis();
    bool currentState = readClosed();

    if (currentState != rawClosed) {
        rawClosed = currentState;
        lastRawChange = now;
    }

    // Debounce sensor 50 ms.
    if (now - lastRawChange >= 50 && doorClosed != rawClosed) {
        doorClosed = rawClosed;

        Serial.println(doorClosed ? "[DOOR] CLOSED" : "[DOOR] OPEN");

        displayMsg(
            "DOOR SENSOR",
            doorClosed ? "DOOR CLOSED" : "DOOR OPEN",
            1200
        );

        if (doorClosed) {
            doorOpenedAt = 0;
            doorAlarm = false;
            autoClosePending = false;
            unlockAt = now; // Reset timer auto-lock sejak pintu ditutup kembali
            clearBuzzer();
        } else {
            doorOpenedAt = now;
            autoClosePending = false;
        }
    }

    // 1. Peringatan Forget to Close jika pintu dibiarkan terbuka >= DOOR_ALARM_MS (default 30 detik)
    if (!doorClosed && doorOpenedAt && now - doorOpenedAt >= DOOR_ALARM_MS && !doorAlarm) {
        doorAlarm = true;
        autoClosePending = true;
        doorAlarmTriggeredAt = now;
        lastAlarmToggle = now;

        displayMsg("FORGOT TO CLOSE!", "AUTO CLOSING...", 2500);
        Serial.println("[ALARM] DOOR OPEN >30s -> FORGOT TO CLOSE, AUTO CLOSING IN 3s");
    }

    // 2. Fitur Otomatis Menutup (Auto-Close) setelah peringatan Forget to Close
    if (autoClosePending && (now - doorAlarmTriggeredAt >= AUTO_CLOSE_DELAY_MS)) {
        autoCloseBox();
    }
}


// ============================================================
// 11. SENSOR SUHU DAN KELEMBAPAN (DHT11)
// ============================================================

void updateDht() {
    if (millis() - lastDht < DHT_MS) {
        return;
    }
    lastDht = millis();

    float t = dht.readTemperature();
    float h = dht.readHumidity();

    if (!isnan(t)) temp = t;
    if (!isnan(h)) hum = h;
}


// ============================================================
// 12. TAMPILAN LCD UTAMA
// ============================================================

void updateLCD() {
    unsigned long now = millis();
    if (now - lastLcd < LCD_MS) {
        return;
    }
    lastLcd = now;

    if (wrongCount >= MAX_PIN_ATTEMPTS && now - lockoutAt < LOCKOUT_MS) {
        lcdLine(0, "KEYPAD LOCKED");
        lcdLine(1, "WAIT " + String((LOCKOUT_MS - (now - lockoutAt)) / 1000 + 1) + " SEC");
        return;
    }

    if ((long)(messageUntil - now) > 0) {
        lcdLine(0, messageTop);
        lcdLine(1, messageBottom);
        return;
    }

    if (locked && pinInput.length()) {
        lcdLine(0, "ENTER PIN:");
        String mask = "";
        for (unsigned int i = 0; i < pinInput.length(); i++) {
            mask += '*';
        }
        lcdLine(1, mask);
        return;
    }

    lcdLine(0, locked ? "STATUS: LOCKED" : "STATUS: UNLOCK");

    int mode = (now / 4000) % 3;
    if (mode == 0) {
        lcdLine(1, doorClosed ? "DOOR: CLOSED" : "DOOR: OPEN");
    } else if (mode == 1) {
        lcdLine(1, isnan(temp) ? "TEMP: --" : "TEMP: " + String(temp, 1) + " C");
    } else {
        lcdLine(1, isnan(hum) ? "HUM: --" : "HUM: " + String(hum, 1) + " %");
    }
}


// ============================================================
// 13. KONEKSI WIFI
// ============================================================

void updateWiFi() {
    if (WiFi.status() == WL_CONNECTED) {
        return;
    }
    if (millis() - lastWiFi < WIFI_RETRY_MS) {
        return;
    }
    lastWiFi = millis();

    Serial.println("[WIFI] Menghubungkan ulang...");
    WiFi.disconnect();
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
}


// ============================================================
// 14. KOMUNIKASI HTTP KE SERVER
// ============================================================

bool post(
    const char* endpoint,
    JsonDocument& doc,
    JsonDocument& reply,
    int& status
) {
    status = 0;
    if (WiFi.status() != WL_CONNECTED) {
        return false;
    }

    WiFiClient client;
    HTTPClient http;
    http.setConnectTimeout(1800);
    http.setTimeout(3000);

    String url = String(SERVER_BASE) + endpoint;
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
    doc["device_code"] = DEVICE_CODE;
    doc["api_key"]     = DEVICE_API_KEY;
}


// ============================================================
// 15. VERIFIKASI PIN (ONLINE SERVER & FALLBACK OFFLINE NVS)
// ============================================================

void processWrongPinAttempt(bool isOffline) {
    wrongCount++;

    if (wrongCount == 3 && MAX_PIN_ATTEMPTS > 3) {
        displayMsg("WARNING!", "3X WRONG PIN", 3000);
        beep(3000);
        if (isOffline) {
            pushOfflineLog("", "OFFLINE_PIN_FAILED", "FAILED", "Peringatan salah PIN 3x saat offline");
        }
    } else if (wrongCount >= MAX_PIN_ATTEMPTS) {
        wrongCount = MAX_PIN_ATTEMPTS;
        lockoutAt = millis();
        securityAlarm = true;
        lastAlarmToggle = millis();

        displayMsg("SECURITY ALERT", "KEYPAD LOCKED", 1400);
        if (isOffline) {
            pushOfflineLog("", "OFFLINE_PIN_LOCKOUT", "FAILED", "Salah PIN berturut-turut saat offline (Lockout & Buzzer aktif)");
        }
    } else {
        displayMsg("ACCESS DENIED", "WRONG PIN", 1400);
        beep(350);
        if (isOffline) {
            pushOfflineLog("", "OFFLINE_PIN_FAILED", "FAILED", "Percobaan PIN salah ke-" + String(wrongCount) + " saat offline");
        }
    }
}

void checkPIN() {
    if (pinInput.length() != 6) {
        displayMsg("PIN HARUS", "6 DIGIT", 1400);
        beep(250);
        pinInput = "";
        return;
    }

    String enteredPin = pinInput;
    pinInput = "";

    JsonDocument req;
    JsonDocument res;
    addCredentials(req);
    req["pin"] = enteredPin;

    int status = 0;
    bool ok = post("/api/verify.php", req, res, status);

    Serial.printf("[PIN] HTTP %d\n", status);

    // 1. Jika terhubung ke server dan PIN benar
    if (ok && (res["authorized"] | false)) {
        unlockBox("KEYPAD");
        return;
    }

    // 2. Jika koneksi internet/server terputus (status <= 0) -> Gunakan Autentikasi Lokal NVS ESP32
    if (status <= 0) {
        String matchedUser = "";
        if (verifyPinLocally(enteredPin, matchedUser)) {
            unlockBox("OFFLINE_NVS");
            displayMsg("OFFLINE ACCESS", matchedUser, 1600);
            pushOfflineLog(matchedUser, "OFFLINE_PIN_SUCCESS", "SUCCESS", "Buka kunci via memori lokal NVS ESP32 saat offline");
        } else {
            processWrongPinAttempt(true);
        }
        return;
    }

    // 3. Jika server merespons PIN salah (HTTP 403)
    if (status == 403) {
        processWrongPinAttempt(false);
        return;
    }

    // 4. Jika server merespons Lockout (HTTP 429)
    if (status == 429) {
        wrongCount = MAX_PIN_ATTEMPTS;
        lockoutAt = millis();
        securityAlarm = true;
        lastAlarmToggle = millis();
        displayMsg("SERVER LOCKOUT", "WAIT 30 SECONDS", 1600);
        return;
    }

    displayMsg("SERVER ERROR", "ACCESS DENIED", 1600);
    beep(400);
}


// ============================================================
// 16. INPUT KEYPAD
// ============================================================

void updateKeypad() {
    unsigned long now = millis();

    if (wrongCount >= MAX_PIN_ATTEMPTS) {
        if (now - lockoutAt < LOCKOUT_MS) {
            keypad.getKey();
            return;
        }
        wrongCount = 0;
        securityAlarm = false;
        clearBuzzer();
    }

    char key = keypad.getKey();
    if (!key) {
        return;
    }

    // Tombol D = LOCK.
    if (key == 'D') {
        if (!locked) {
            lockBox("KEYPAD");
        }
        return;
    }

    if (!locked) {
        return;
    }

    // Tombol * = hapus PIN.
    if (key == '*') {
        pinInput = "";
        displayMsg("INPUT CLEARED", "ENTER PIN", 650);
        return;
    }

    // Tombol # = kirim PIN.
    if (key == '#') {
        checkPIN();
        return;
    }

    // Input angka maksimal 6 digit.
    if (key >= '0' && key <= '9' && pinInput.length() < 6) {
        pinInput += key;
        beep(35);
    }
}


// ============================================================
// 17. TELEMETRY, SINKRONISASI OFFLINE, & REMOTE COMMAND
// ============================================================

void sendTelemetry() {
    if (WiFi.status() != WL_CONNECTED) {
        return;
    }

    JsonDocument req;
    JsonDocument res;

    addCredentials(req);

    req["locked"]         = locked;
    req["door_closed"]    = doorClosed;
    req["door_alarm"]     = doorAlarm;
    req["auto_closed"]    = autoClosedReportPending;
    req["security_alarm"] = securityAlarm;
    req["door_open_sec"]  = (!doorClosed && doorOpenedAt > 0) ? (int)((millis() - doorOpenedAt) / 1000) : 0;

    if (isnan(temp)) req["temperature"] = nullptr;
    else req["temperature"] = temp;

    if (isnan(hum)) req["humidity"] = nullptr;
    else req["humidity"] = hum;

    req["rssi"] = WiFi.RSSI();

    if (ackId > 0) {
        req["ack"]["id"]      = ackId;
        req["ack"]["status"]  = ackStatus;
        req["ack"]["message"] = ackMessage;
    }

    if (offlineLogCount > 0) {
        JsonArray logsArr = req["offline_logs"].to<JsonArray>();
        unsigned long nowMs = millis();
        for (int i = 0; i < offlineLogCount; i++) {
            JsonObject item = logsArr.add<JsonObject>();
            item["username"] = offlineLogs[i].username;
            item["action"]   = offlineLogs[i].action;
            item["result"]   = offlineLogs[i].result;
            item["details"]  = offlineLogs[i].details;
            item["ago_sec"]  = (int)((nowMs - offlineLogs[i].createdMs) / 1000);
        }
    }

    int status = 0;
    bool ok = post("/api/telemetry.php", req, res, status);

    Serial.printf("[TELEMETRY] HTTP %d %s\n", status, ok ? "OK" : "FAILED");

    if (!ok) {
        return;
    }

    if (ackId > 0) {
        Serial.printf("[REMOTE] ACK #%lld %s\n", ackId, ackStatus.c_str());
        ackId = 0;
        ackStatus = "";
        ackMessage = "";
    }
    autoClosedReportPending = false;
    offlineLogCount = 0;

    // Update pengaturan dari Web Dashboard.
    JsonVariant settings = res["settings"];
    if (!settings.isNull()) {
        int dSec = settings["door_timeout_sec"] | 30;
        int aSec = settings["auto_lock_sec"] | 10;
        int mPin = settings["max_pin_attempts"] | 3;
        DOOR_ALARM_MS    = (unsigned long)max(5, dSec) * 1000UL;
        AUTO_LOCK_MS     = (unsigned long)max(3, aSec) * 1000UL;
        MAX_PIN_ATTEMPTS = max(1, mPin);
    }

    // Simpan daftar hash PIN dari server ke NVS untuk mode Offline.
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

    // Periksa remote command (Hanya LOCK, EMERGENCY_UNLOCK, ALARM_ON, ALARM_OFF).
    JsonVariant cmd = res["command"];
    if (cmd.isNull()) {
        return;
    }

    long long id = cmd["id"].as<long long>();
    String type  = cmd["command_type"].as<String>();

    if (id <= 0 || id == lastExecutedId) {
        return;
    }

    lastExecutedId = id;
    bool success = false;
    String message = "";

    if (type == "LOCK") {
        success = lockBox("REMOTE");
        message = success ? "Servo LOCK diperintahkan" : "Pintu masih terbuka";
    } else if (type == "EMERGENCY_UNLOCK") {
        unlockBox("EMERGENCY");
        displayMsg("EMERGENCY UNLOCK", "BY ADMIN WEB", 2000);
        success = true;
        message = "Emergency Unlock berhasil dijalankan";
    } else if (type == "ALARM_ON") {
        securityAlarm = true;
        lastAlarmToggle = millis();
        displayMsg("REMOTE ALARM", "BUZZER ACTIVE", 1800);
        success = true;
        message = "Alarm buzzer diaktifkan";
    } else if (type == "ALARM_OFF") {
        securityAlarm = false;
        doorAlarm = false;
        wrongCount = 0;
        clearBuzzer();
        displayMsg("ALARM RESET", "BY ADMIN WEB", 1600);
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
// 18. SETUP
// ============================================================

void setup() {
    Serial.begin(115200);

    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(BUZZER_PIN, LOW);

    pinMode(DOOR_PIN, INPUT_PULLUP);

    Wire.begin(LCD_SDA, LCD_SCL);
    lcd.init();
    lcd.backlight();

    lcdLine(0, "SMART SECURE");
    lcdLine(1, "STORAGE BOX");

    dht.begin();

    loadLocalPinsFromNVS();

    servo.setPeriodHertz(50);
    servo.attach(SERVO_PIN, 500, 2400);
    servo.write(LOCK_ANGLE);
    locked = true;

    doorClosed = readClosed();
    rawClosed = doorClosed;
    lastRawChange = millis();

    if (!doorClosed) {
        doorOpenedAt = millis();
    }

    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(true);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    lastWiFi = millis();

    Serial.println("SmartBox V3 Ready (Online HTTP + Offline NVS + Auto-Close)");
    displayMsg("SYSTEM READY", "ENTER YOUR PIN", 2000);
}


// ============================================================
// 19. LOOP UTAMA
// ============================================================

void loop() {
    updateDoor();
    updateKeypad();
    updateDht();
    updateAutoLock();
    updateBuzzer();
    updateLCD();
    updateWiFi();

    if (millis() - lastTelemetry >= TELEMETRY_MS) {
        lastTelemetry = millis();
        sendTelemetry();
    }
}
