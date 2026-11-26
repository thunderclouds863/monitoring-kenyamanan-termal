# Monitoring Kenyamanan Termal

Sistem Monitoring Kenyamanan Termal adalah platform web IoT untuk memantau parameter lingkungan ruangan (**suhu**, **kelembapan**, **kecepatan angin**, dan **kualitas udara / CO₂**) secara real-time menggunakan perangkat **Arduino MKR Wi-Fi 1010**.

## Fitur Utama

- **Dashboard Real-Time (`index.php`)**: Indeks Kenyamanan Termal (0–100), rekomendasi penyesuaian ruangan/AC, 4 kartu telemetri sensor dengan bar ambang batas, grafik multi-sumbu (1 jam, 24 jam, 7 hari, 30 hari, 1 tahun), dan log peringatan terbaru.
- **Data Historis (`history.php`)**: Filter rentang tanggal, preset cepat, ringkasan statistik periode, tabel DataTables server-side, serta ekspor CSV dan XLSX.
- **Pengaturan Personal (`settings.php`)**: Manajemen profil & foto profil, ubah password, konfigurasi ambang batas sensor, aturan notifikasi (*cooldown*, *delta threshold*, sensitivitas), dan interval sinkronisasi.
- **Panduan & Integrasi WhatsApp (`about.php`)**: Dokumentasi sistem dan konfigurasi API Key CallMeBot.
- **Autentikasi Lengkap**: Login dengan *Remember Me*, Registrasi + Verifikasi OTP, dan Pemulihan Password 3 langkah.

## Menjalankan Secara Lokal (Docker — Direkomendasikan)

Aplikasi ini dilengkapi `Dockerfile` siap pakai (Apache + PHP 8.2 + MariaDB + seed data 7 hari + simulasi sensor per menit):

```bash
docker build -t thermal-monitor .
docker run -d --name thermal-monitor-app -p 8080:10000 thermal-monitor
```

Buka `http://localhost:8080/login.php` dan masuk dengan akun demo:
- **Email**: `demo@demo.local`
- **Password**: `demo123`

## Variabel Lingkungan (Produksi)

| Variabel | Fungsi |
|---|---|
| `DB_PASS` | Password user MariaDB/MySQL `figflzel_user` |
| `BREVO_API_KEY` | API Key Brevo untuk pengiriman email notifikasi & reset password |
| `SMTP_PASSWORD` | Password SMTP untuk email verifikasi pendaftaran |
| `GMAIL_APP_PASSWORD` | App Password Gmail untuk pengiriman ulang OTP |
