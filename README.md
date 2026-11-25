# Smart Secure Storage Box — IoT Application & Hardware System

**Smart Secure Storage Box** adalah aplikasi dan perangkat penyimpanan pintar berbasis Internet of Things (IoT) yang dirancang untuk meningkatkan keamanan barang melalui sistem autentikasi PIN 6-digit, pengaturan hak akses berbasis peran (**Admin & User**), monitoring sensor (**Door Sensor & DHT11**), serta pengendalian jarak jauh (**HTTP/HTTPS API & MQTT**).

---

## Fitur Utama

1. **Autentikasi PIN 6-Digit Hybrid (Online Backend & Offline Memori NVS ESP32)**
   - Pengguna memasukkan PIN 6-digit melalui Keypad 4×4.
   - Ketika terhubung ke internet (**Online**), PIN diverifikasi oleh Backend (`POST /api/verify.php`) dan ESP32 mengaktifkan motor servo (`90°`) untuk membuka kunci kotak.
   - Ketika koneksi internet terputus (**Offline**), sistem tetap memverifikasi PIN secara lokal menggunakan kredensial pengguna (`SHA-256 + Salt`) yang tersimpan pada memori NVS ESP32.
   - Saat jaringan kembali tersedia, seluruh log akses, peringatan keamanan, dan data sensor yang tersimpan sementara pada perangkat otomatis disinkronkan ke database MySQL (`POST /api/sync.php`).

2. **Dua Jenis Pengguna (Admin & User)**
   - **Admin & User**: Keduanya dapat membuka kotak menggunakan PIN 6-digit pribadi melalui Keypad.
   - **Hak Akses Tambahan Admin di Web Dashboard**:
     - **Remote Unlock** & **Remote Lock**
     - **Manajemen Pengguna** (Tambah, edit, nonaktifkan, ubah PIN 6-digit, & sinkronisasi ke memori ESP32)
     - **Pengaturan Alarm & Sensor** (Konfigurasi batas salah PIN, durasi pintu terbuka, suhu/kelembapan DHT11, & topik MQTT)
     - **Emergency Access** (Otorisasi pembukaan darurat dari Web Dashboard maupun Master Emergency PIN dengan audit log)

3. **Door Sensor, Sensor DHT11, & Sistem Peringatan Keamanan (Buzzer + Web Dashboard)**
   - **Door Sensor (Reed Switch)**: Mendeteksi status pintu (`CLOSED` / `OPEN`) dan menghitung durasi pintu terbuka.
   - **Sensor DHT11**: Mengukur suhu (`°C`) dan kelembapan (`%RH`) secara real-time beserta grafik riwayat telemetri.
   - **Peringatan Otomatis Buzzer & Web Dashboard**:
     - Jika **PIN salah dimasukkan >= 3 kali berturut-turut** (`FAILED_ACCESS`).
     - Jika **pintu dibiarkan terbuka > 30 detik** (`FORGOT_CLOSE`).

4. **Dual Theme UI (Light & Dark Mode)**
   - **Light Mode ("Kinship & Horizon")**: Mengadopsi palet hangat *Warm Ivory*, *Charcoal*, *Terracotta*, dan *Sage Green* dari referensi desain `stitch_the_last_will_platform`.
   - **Dark Mode ("Obsidian Telemetry")**: Mengadopsi palet *Obsidian Carbon*, *Thermal Orange*, dan *Emerald* untuk pemantauan telemetri malam hari.

5. **Simulator Hardware ESP32 Interaktif Terintegrasi**
   - Dilengkapi layar **LCD 16×2 I2C**, **Keypad 4×4**, **Motor Servo**, **Door Sensor**, **Slider DHT11**, **Suara Buzzer (Web Audio API)**, serta sakelar simulasi **Wi-Fi Online / Offline NVS**.

---

## Akun Demo Login

| Peran | Username | Password Web | PIN Keypad (6 Digit) | Hak Akses |
| :--- | :--- | :--- | :--- | :--- |
| **Admin Utama** | `lathiva` | `admin123` | `123456` | Full Access (Remote Unlock/Lock, User Mgmt, Alarm, Emergency) |
| **Admin Keamanan** | `ahsan` | `admin123` | `654321` | Full Access |
| **User** | `budi` | `user123` | `882109` | Buka via PIN Keypad, Monitoring, Riwayat & Ubah PIN Sendiri |
| **User** | `karina` | `user123` | `246810` | Buka via PIN Keypad, Monitoring, Riwayat & Ubah PIN Sendiri |
| **Emergency Code** | *(Keypad)* | — | `991100` | Kode Master Darurat pada Keypad 4×4 |

---

## Cara Menjalankan Aplikasi

1. Pastikan layanan **MySQL** (dan **Apache** jika menggunakan XAMPP) sedang aktif.
2. Database MySQL (`smart_secure_box_app`) beserta seluruh tabel dan data awal akan **dibuat secara otomatis** pada saat aplikasi pertama kali diakses (atau dapat diimpor manual dari `database/smart_secure_box.sql`).
3. Anda dapat mengakses aplikasi melalui:
   - **XAMPP Apache**: `http://localhost/secure-box/login.php`
   - **PHP Built-in Server**:
     ```powershell
     C:\xampp\php\php.exe -S localhost:8080 -t "d:\Kuliah\Perkuliahan\Semester 5\secure-box"
     ```
     Lalu buka `http://localhost:8080/login.php`.
