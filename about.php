<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

$user = require_authenticated_user($conn);
$current_apikey = $user['apikey'] ?? '';

$warning_message = $_SESSION['warning_message'] ?? null;
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['warning_message']);

$activeNav = 'about';
$pageTitle = 'Panduan & Integrasi Notifikasi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panduan &amp; Integrasi — Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <?php render_theme_init_script(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <style>
        .docs-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 24px;
            align-items: start;
        }
        .docs-toc {
            position: sticky;
            top: 86px;
        }
        .toc-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 4px;
            transition: background-color 0.15s ease, color 0.15s ease;
        }
        .toc-link:hover {
            background: var(--bg-surface-2);
            color: var(--text-primary);
        }
        .docs-section {
            margin-bottom: 20px;
            scroll-margin-top: 88px;
        }
        .docs-prose {
            color: var(--text-secondary);
            font-size: 14.5px;
            line-height: 1.7;
        }
        .docs-prose p { margin: 0 0 12px 0; }
        .docs-prose ul, .docs-prose ol { margin: 0 0 14px 0; padding-left: 20px; }
        .docs-prose li { margin-bottom: 6px; }
        .docs-prose code {
            font-family: var(--font-mono);
            font-size: 12.5px;
            padding: 2px 7px;
            border-radius: 5px;
            background: var(--bg-surface-3);
            color: var(--brand-primary);
        }
        @media (max-width: 1024px) {
            .docs-layout { grid-template-columns: 1fr; }
            .docs-toc { position: static; order: -1; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include 'nav.php'; ?>

    <main class="app-content">
        <?php if ($success_message): ?>
            <div class="alert-banner is-success"><i class="fas fa-circle-check" style="margin-top:2px;"></i><div><?= htmlspecialchars($success_message) ?></div></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert-banner is-error"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i><div><?= htmlspecialchars($error_message) ?></div></div>
        <?php endif; ?>
        <?php if ($warning_message): ?>
            <div class="alert-banner is-warning"><i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i><div><?= htmlspecialchars($warning_message) ?></div></div>
        <?php endif; ?>

        <div class="docs-layout">
            <div>
                <!-- WhatsApp Integration Card (Primary Actionable Feature) -->
                <section id="integrasi-whatsapp" class="surface-card docs-section">
                    <div class="surface-card-header">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span style="width: 40px; height: 40px; border-radius: 10px; background: var(--status-optimal-soft); color: var(--status-optimal); display: inline-flex; align-items: center; justify-content: center; font-size: 18px;">
                                <i class="fab fa-whatsapp"></i>
                            </span>
                            <div>
                                <h2 class="surface-card-title">Integrasi Notifikasi WhatsApp (CallMeBot)</h2>
                                <p class="surface-card-sub">Terima peringatan otomatis saat parameter termal melewati ambang batas</p>
                            </div>
                        </div>
                        <span class="badge-pill <?= !empty($current_apikey) ? 'badge-optimal' : 'badge-neutral' ?>">
                            <?= !empty($current_apikey) ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </div>
                    <div class="surface-card-body">
                        <div class="docs-prose" style="margin-bottom: 18px;">
                            <p>Untuk mengaktifkan notifikasi WhatsApp menggunakan <a href="https://www.callmebot.com/" target="_blank" rel="noopener">CallMeBot</a>:</p>
                            <ol>
                                <li>Kunjungi situs CallMeBot dan buka menu <strong>Free WhatsApp API</strong>.</li>
                                <li>Kirim pesan WhatsApp ke nomor bot CallMeBot dengan teks: <code>I allow callmebot to send me messages</code>.</li>
                                <li>Salin <strong>API Key</strong> yang dikirimkan bot dan masukkan pada kolom di bawah ini.</li>
                            </ol>
                        </div>

                        <form action="save_apikey.php" method="POST" style="max-width: 540px;">
                            <div class="form-group">
                                <label for="apikey" class="form-label">CallMeBot API Key</label>
                                <input type="text" id="apikey" name="apikey" class="form-control-ds mono" value="<?= htmlspecialchars($current_apikey) ?>" placeholder="Masukkan API Key CallMeBot Anda">
                            </div>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <?php if (!empty($current_apikey)): ?>
                                    <button type="submit" name="action" value="activate" class="btn-ds btn-ds-primary">
                                        <i class="fas fa-floppy-disk"></i> Perbarui API Key
                                    </button>
                                    <button type="submit" name="action" value="deactivate" class="btn-ds btn-ds-danger">
                                        <i class="fas fa-bell-slash"></i> Nonaktifkan WhatsApp
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="action" value="activate" class="btn-ds btn-ds-primary">
                                        <i class="fab fa-whatsapp"></i> Aktifkan Notifikasi WhatsApp
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Tentang & Latar Belakang -->
                <section id="tentang-sistem" class="surface-card docs-section">
                    <div class="surface-card-header">
                        <h2 class="surface-card-title">Tentang Sistem Monitoring Kenyamanan Termal</h2>
                    </div>
                    <div class="surface-card-body docs-prose">
                        <p>
                            Hunian ringkas (<em>compact housing</em>) di iklim tropis lembap seperti Indonesia kerap mengalami penumpukan panas, kelembapan berlebih, dan sirkulasi udara yang kurang optimal. Penggunaan pendingin ruangan (AC) tanpa mengacu pada data aktual sering mengakibatkan ketidaknyamanan sekaligus pemborosan energi.
                        </p>
                        <p>
                            Platform ini menghubungkan perangkat sensor berbasis <strong>Arduino MKR Wi-Fi 1010</strong> untuk memantau suhu udara, kelembapan relatif, kecepatan angin, dan konsentrasi CO₂ secara real-time, lengkap dengan rekomendasi penyesuaian ruangan dan pencatatan data historis.
                        </p>
                    </div>
                </section>

                <!-- Panduan Logika Notifikasi -->
                <section id="panduan-notifikasi" class="surface-card docs-section">
                    <div class="surface-card-header">
                        <h2 class="surface-card-title">Cara Kerja Ambang Batas &amp; Peringatan</h2>
                    </div>
                    <div class="surface-card-body docs-prose">
                        <ul>
                            <li><strong>Cooldown Time (detik):</strong> Jeda waktu minimum antar pengiriman peringatan agar notifikasi tidak membanjiri perangkat saat kondisi masih abnormal.</li>
                            <li><strong>Delta Threshold (%):</strong> Persentase perubahan ekstrem yang tetap memicu notifikasi ulang meskipun masih dalam masa cooldown (misalnya suhu naik signifikan dari 32°C ke 32.8°C).</li>
                            <li><strong>Tingkat Sensitivitas:</strong> Pilih <em>Normal</em> untuk penggunaan harian atau <em>Sensitif</em> untuk respons yang lebih ketat terhadap fluktuasi kecil.</li>
                        </ul>
                        <a href="settings.php?tab=preferences" class="btn-ds btn-ds-secondary btn-ds-sm" style="margin-top: 6px;">
                            <i class="fas fa-sliders"></i> Buka Pengaturan Ambang Batas
                        </a>
                    </div>
                </section>
            </div>

            <!-- Sticky Quick-Jump TOC -->
            <aside class="surface-card docs-toc">
                <div class="surface-card-header">
                    <h3 class="surface-card-title" style="font-size: 14px;">Daftar Isi</h3>
                </div>
                <div class="surface-card-body" style="padding: 12px;">
                    <a href="#integrasi-whatsapp" class="toc-link"><i class="fab fa-whatsapp"></i> Integrasi WhatsApp</a>
                    <a href="#tentang-sistem" class="toc-link"><i class="fas fa-book-open"></i> Tentang Sistem</a>
                    <a href="#panduan-notifikasi" class="toc-link"><i class="fas fa-bell"></i> Logika Notifikasi</a>
                </div>
            </aside>
        </div>
    </main>

    <footer class="app-footer">
        <span>&copy; <?= date('Y') ?> Monitoring Kenyamanan Termal. All rights reserved.</span>
        <span>Dikembangkan oleh <a href="https://www.linkedin.com/in/desi-armanda-sari" target="_blank" rel="noopener">Desi Armanda Sari</a></span>
    </footer>
</div>
</div>
</body>
</html>
