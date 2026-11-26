<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

$user = require_authenticated_user($conn);
$user_id = (int)$user['id'];
$settings = get_user_settings($conn, $user_id);

$interval_update = (int)$settings['interval_update'];
$suhu_min = (float)$settings['suhu_min'];
$suhu_max = (float)$settings['suhu_max'];
$kelembapan_min = (float)$settings['kelembapan_min'];
$kelembapan_max = (float)$settings['kelembapan_max'];
$wind_speed_min = (float)$settings['wind_speed_min'];
$wind_speed_max = (float)$settings['wind_speed_max'];
$air_quality_max = (float)$settings['air_quality_max'];
$cooldown_time = (int)$settings['cooldown_time'];
$delta_threshold = (float)$settings['delta_threshold'];
$sensitivity_level = (string)$settings['sensitivity_level'];

class NotificationSystem {
    private static int $lastNotificationTime = 0;
    private const NOTIFICATION_COOLDOWN = 3600;

    public static function sendEmailNotification(string $email, string $message): bool {
        $apiKey = getenv('BREVO_API_KEY');
        if (empty($apiKey)) {
            return false; // Skip blocking external call when not configured
        }

        $currentTime = time();
        if (($currentTime - self::$lastNotificationTime) < self::NOTIFICATION_COOLDOWN) {
            return false;
        }

        $data = [
            'sender' => [
                'name' => 'Monitoring Kenyamanan Termal',
                'email' => 'noreply@monitoring-kenyamanan-termal.my.id'
            ],
            'to' => [['email' => $email]],
            'subject' => 'Peringatan Monitoring Kenyamanan Termal',
            'htmlContent' => '<p>' . nl2br(htmlspecialchars($message)) . '</p>',
            'textContent' => strip_tags($message)
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            self::$lastNotificationTime = $currentTime;
            return true;
        }
        return false;
    }

    public static function sendWhatsAppNotification(?string $phone, string $message, mysqli $conn, int $user_id): bool {
        if (empty($phone)) {
            return false;
        }
        $query = $conn->prepare("SELECT apikey FROM users WHERE id = ?");
        $query->bind_param("i", $user_id);
        $query->execute();
        $row = $query->get_result()->fetch_assoc();
        $query->close();

        $apikey = $row['apikey'] ?? null;
        if (empty($apikey)) {
            return false;
        }

        $phone = ltrim($phone, '+');
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        } elseif (!str_starts_with($phone, '62')) {
            $phone = '62' . $phone;
        }

        $apiUrl = "https://api.callmebot.com/whatsapp.php?" . http_build_query([
            'phone' => $phone,
            'text' => $message,
            'apikey' => $apikey
        ]);

        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $response = @file_get_contents($apiUrl, false, $ctx);
        return $response !== false;
    }
}

class ConditionChecker {
    private array $user;
    private array $settings;
    private mysqli $conn;
    private array $activeAlerts = [];

    public function __construct(array $user, array $settings, mysqli $conn) {
        $this->user = $user;
        $this->settings = $settings;
        $this->conn = $conn;
    }

    public function checkConditions(array $data): void {
        $params = [
            'temperature' => ['min' => $this->settings['suhu_min'], 'max' => $this->settings['suhu_max']],
            'humidity'    => ['min' => $this->settings['kelembapan_min'], 'max' => $this->settings['kelembapan_max']],
            'wind_speed'  => ['min' => $this->settings['wind_speed_min'], 'max' => $this->settings['wind_speed_max']],
            'air_quality' => ['max' => $this->settings['air_quality_max']]
        ];

        $sensorLabels = [
            'temperature' => 'Suhu',
            'humidity' => 'Kelembapan',
            'wind_speed' => 'Kecepatan Angin',
            'air_quality' => 'Kualitas Udara'
        ];

        $messages = [];

        foreach ($params as $sensor => $limits) {
            $value = (float)$data[$sensor];
            $status = $this->getStatus($value, $limits);
            $label = $sensorLabels[$sensor];

            if ($this->shouldNotify($sensor, $value, $status)) {
                $msg = $this->generateMessage($sensor, $label, $value, $limits, $status);
                if ($msg !== null) {
                    $messages[] = $msg;
                    $this->activeAlerts[$sensor] = $msg;
                }
                $this->updateLastAlert($sensor, $value, $status);
            }
        }

        if (!empty($messages)) {
            NotificationSystem::sendEmailNotification($this->user['email'], implode("\n\n", $messages));
            NotificationSystem::sendWhatsAppNotification($this->user['phone'] ?? '', "[Alert] " . implode("\n", $messages), $this->conn, (int)$this->user['id']);
            $this->tryLogAlertsToDatabase($messages, (int)$data['id']);
        }
    }

    private function getStatus(float $value, array $limits): string {
        if (isset($limits['max']) && $value > $limits['max']) return 'abnormal';
        if (isset($limits['min']) && $value < $limits['min']) return 'abnormal';
        return 'normal';
    }

    private function shouldNotify(string $sensor, float $value, string $currentStatus): bool {
        $userId = (int)$this->user['id'];
        $stmt = $this->conn->prepare("SELECT last_value, last_status, last_notified_at FROM last_alerts WHERE user_id = ? AND sensor_type = ?");
        $stmt->bind_param("is", $userId, $sensor);
        $stmt->execute();
        $last = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$last) return $currentStatus === 'abnormal';

        $prevStatus = $last['last_status'];
        $lastValue = (float)$last['last_value'];
        $lastNotified = strtotime($last['last_notified_at']);

        if ($currentStatus !== $prevStatus) return true;

        if ($currentStatus === 'abnormal') {
            $percentageChange = ($lastValue != 0.0) ? (abs($value - $lastValue) / abs($lastValue)) * 100 : 100.0;
            if ($percentageChange >= (float)$this->settings['delta_threshold']) {
                return true;
            }
            if ((time() - $lastNotified) > (int)$this->settings['cooldown_time']) {
                return true;
            }
        }
        return false;
    }

    private function updateLastAlert(string $sensor, float $value, string $status): void {
        $stmt = $this->conn->prepare("
            INSERT INTO last_alerts (user_id, sensor_type, last_value, last_status, last_notified_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_value = VALUES(last_value), last_status = VALUES(last_status), last_notified_at = NOW()
        ");
        $uid = (int)$this->user['id'];
        $stmt->bind_param("isds", $uid, $sensor, $value, $status);
        $stmt->execute();
        $stmt->close();
    }

    private function generateMessage(string $sensor, string $label, float $value, array $limits, string $status): ?string {
        if ($status === 'normal') return null;
        return match ($sensor) {
            'temperature' => $value > $limits['max']
                ? "$label tinggi: {$value}°C (Maks: {$limits['max']}°C). Turunkan suhu AC atau tingkatkan ventilasi."
                : "$label rendah: {$value}°C (Min: {$limits['min']}°C). Naikkan suhu ruangan.",
            'humidity' => $value > $limits['max']
                ? "$label tinggi: {$value}% (Maks: {$limits['max']}%). Aktifkan mode dry atau buka sirkulasi."
                : "$label rendah: {$value}% (Min: {$limits['min']}%). Pertimbangkan humidifier.",
            'wind_speed' => $value > $limits['max']
                ? "Kecepatan angin tinggi: {$value} m/s (Maks: {$limits['max']} m/s)."
                : "Sirkulasi angin lemah: {$value} m/s (Min: {$limits['min']} m/s).",
            'air_quality' => "Kualitas udara menurun: {$value} ppm (Maks: {$limits['max']} ppm). Buka jendela untuk sirkulasi udara segar.",
            default => null,
        };
    }

    private function tryLogAlertsToDatabase(array $messages, int $sensorId): void {
        $stmt = $this->conn->prepare("INSERT INTO alert_logs (user_id, sensor_id, message, created_at) VALUES (?, ?, ?, NOW())");
        if ($stmt) {
            $uid = (int)$this->user['id'];
            foreach ($messages as $message) {
                $stmt->bind_param("iis", $uid, $sensorId, $message);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

// Fetch latest reading and run condition check
$latestRes = $conn->query("SELECT id, temperature, humidity, wind_speed, air_quality, reading_time FROM sensor_readings ORDER BY reading_time DESC LIMIT 1");
$sensor_data = ($latestRes && $latestRes->num_rows > 0) ? $latestRes->fetch_assoc() : null;

if ($sensor_data) {
    $checker = new ConditionChecker($user, $settings, $conn);
    $checker->checkConditions($sensor_data);
}

// Fetch recent alerts for this user
$recentAlerts = [];
$alertStmt = $conn->prepare("SELECT message, created_at FROM alert_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
if ($alertStmt) {
    $alertStmt->bind_param("i", $user_id);
    $alertStmt->execute();
    $alertRes = $alertStmt->get_result();
    while ($row = $alertRes->fetch_assoc()) {
        $recentAlerts[] = $row;
    }
    $alertStmt->close();
}

$activeNav = 'home';
$pageTitle = 'Dashboard Pemantauan Real-Time';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <?php render_theme_init_script(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/css/design-system.css">
    <style>
        .dashboard-hero-grid {
            display: grid;
            grid-template-columns: minmax(300px, 370px) 1fr;
            gap: 20px;
            margin-bottom: 22px;
        }
        .comfort-card {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 24px;
            background:
                radial-gradient(circle at 90% 10%, var(--brand-primary-soft), transparent 55%),
                var(--bg-surface);
        }
        .comfort-gauge-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin: 18px 0;
        }
        .gauge-wrap {
            position: relative;
            width: 112px;
            height: 112px;
            flex-shrink: 0;
        }
        .gauge-center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .gauge-score {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
            color: var(--text-primary);
        }
        .gauge-unit {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 3px;
        }
        .recommendation-box {
            padding: 13px 15px;
            border-radius: var(--radius-md);
            background: var(--bg-surface-2);
            border: 1px solid var(--border-subtle);
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.5;
        }
        .sensor-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .sensor-card {
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: border-color 0.2s ease, transform 0.15s ease;
        }
        .sensor-card:hover {
            border-color: var(--border-default);
        }
        .sensor-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .sensor-icon-badge {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }
        .sensor-value-row {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-top: 14px;
        }
        .sensor-value {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1.1;
            color: var(--text-primary);
        }
        .sensor-unit {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .sensor-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }
        .chart-canvas-wrap {
            position: relative;
            height: 360px;
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }
        .dashboard-hero-grid > *,
        .dashboard-bottom-grid > * {
            min-width: 0;
        }
        .dashboard-bottom-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 20px;
        }
        .alert-feed-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid var(--border-subtle);
            font-size: 13px;
        }
        .alert-feed-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .live-alert-strip {
            display: none;
            margin-bottom: 20px;
        }
        @media (max-width: 1180px) {
            .dashboard-hero-grid {
                grid-template-columns: 1fr;
            }
            .dashboard-bottom-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 640px) {
            .sensor-grid {
                grid-template-columns: 1fr;
            }
            .chart-canvas-wrap {
                height: 280px;
            }
            .sensor-value {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include 'nav.php'; ?>

    <main class="app-content">
        <!-- Live Active Alert Banner (appears only when threshold is breached) -->
        <div id="liveAlertBanner" class="alert-banner is-error live-alert-strip" role="alert">
            <i class="fas fa-triangle-exclamation" style="font-size: 17px; margin-top: 2px;"></i>
            <div style="flex: 1;">
                <strong style="display: block; margin-bottom: 2px;">Peringatan Ambang Batas Sensor</strong>
                <div id="liveAlertText"></div>
            </div>
            <button type="button" id="dismissAlertBtn" class="btn-ds btn-ds-ghost btn-ds-sm" style="color: inherit;">Tutup</button>
        </div>

        <!-- Asymmetric Hero Overview: Comfort Index + 4 Precision Telemetry Cards -->
        <section class="dashboard-hero-grid" aria-label="Ringkasan Kondisi Termal">
            <div class="surface-card comfort-card">
                <div>
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                        <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted);">
                            Indeks Kenyamanan
                        </span>
                        <span class="badge-pill badge-neutral" id="comfortBadge">Memuat...</span>
                    </div>

                    <div class="comfort-gauge-row">
                        <div class="gauge-wrap">
                            <svg viewBox="0 0 120 120" width="112" height="112" style="transform: rotate(-90deg);">
                                <circle cx="60" cy="60" r="48" fill="none" stroke="var(--bg-surface-3)" stroke-width="10"/>
                                <circle id="comfortRing" cx="60" cy="60" r="48" fill="none" stroke="var(--brand-primary)" stroke-width="10"
                                        stroke-linecap="round" stroke-dasharray="301.59" stroke-dashoffset="301.59"
                                        style="transition: stroke-dashoffset 0.6s ease, stroke 0.3s ease;"/>
                            </svg>
                            <div class="gauge-center">
                                <span class="gauge-score mono" id="comfortScore">--</span>
                                <span class="gauge-unit">/ 100</span>
                            </div>
                        </div>
                        <div>
                            <h2 id="comfortTitle" style="font-size: 19px; font-weight: 800; margin: 0 0 4px 0; color: var(--text-primary);">Menghitung...</h2>
                            <p id="comfortSubtitle" style="font-size: 13px; color: var(--text-secondary); margin: 0;">
                                Menyelaraskan data sensor dengan batas kenyamanan Anda.
                            </p>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="recommendation-box">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px; color: var(--text-primary); font-weight: 700; font-size: 12.5px;">
                            <i class="fas fa-lightbulb" style="color: var(--brand-primary);"></i>
                            <span>Rekomendasi Ruangan</span>
                        </div>
                        <div id="comfortRecommendation">Menunggu pembacaan terbaru dari perangkat sensor...</div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 12px; color: var(--text-muted);">
                        <span>Pembaruan terakhir: <strong class="mono" id="lastUpdatedAt">--:--</strong></span>
                        <span>Interval: <strong class="mono"><?= $interval_update ?>d</strong></span>
                    </div>
                </div>
            </div>

            <!-- 4 Sensor Cards -->
            <div class="sensor-grid">
                <!-- Temperature -->
                <article class="surface-card sensor-card">
                    <div class="sensor-card-top">
                        <div style="display: flex; align-items: center; gap: 11px;">
                            <span class="sensor-icon-badge" style="background: var(--sensor-temp-soft); color: var(--sensor-temp);">
                                <i class="fas fa-temperature-half"></i>
                            </span>
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);">Suhu Udara</div>
                                <div style="font-size: 12px; color: var(--text-muted);">Temperature</div>
                            </div>
                        </div>
                        <span class="badge-pill badge-neutral" id="status-temp">--</span>
                    </div>
                    <div class="sensor-value-row">
                        <span class="sensor-value mono" id="temperature">--</span>
                        <span class="sensor-unit">°C</span>
                    </div>
                    <div class="threshold-bar"><div class="threshold-bar-fill" id="bar-temp" style="width: 0%; background: var(--sensor-temp);"></div></div>
                    <div class="sensor-meta-row">
                        <span>Target: <strong class="mono"><?= $suhu_min ?>–<?= $suhu_max ?>°C</strong></span>
                        <a href="settings.php?tab=preferences" style="font-size: 11.5px;">Ubah</a>
                    </div>
                </article>

                <!-- Humidity -->
                <article class="surface-card sensor-card">
                    <div class="sensor-card-top">
                        <div style="display: flex; align-items: center; gap: 11px;">
                            <span class="sensor-icon-badge" style="background: var(--sensor-humid-soft); color: var(--sensor-humid);">
                                <i class="fas fa-droplet"></i>
                            </span>
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);">Kelembapan</div>
                                <div style="font-size: 12px; color: var(--text-muted);">Relative Humidity</div>
                            </div>
                        </div>
                        <span class="badge-pill badge-neutral" id="status-humid">--</span>
                    </div>
                    <div class="sensor-value-row">
                        <span class="sensor-value mono" id="humidity">--</span>
                        <span class="sensor-unit">%</span>
                    </div>
                    <div class="threshold-bar"><div class="threshold-bar-fill" id="bar-humid" style="width: 0%; background: var(--sensor-humid);"></div></div>
                    <div class="sensor-meta-row">
                        <span>Target: <strong class="mono"><?= $kelembapan_min ?>–<?= $kelembapan_max ?>%</strong></span>
                        <a href="settings.php?tab=preferences" style="font-size: 11.5px;">Ubah</a>
                    </div>
                </article>

                <!-- Air Quality -->
                <article class="surface-card sensor-card">
                    <div class="sensor-card-top">
                        <div style="display: flex; align-items: center; gap: 11px;">
                            <span class="sensor-icon-badge" style="background: var(--sensor-co2-soft); color: var(--sensor-co2);">
                                <i class="fas fa-lungs"></i>
                            </span>
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);">Kualitas Udara</div>
                                <div style="font-size: 12px; color: var(--text-muted);">Konsentrasi CO₂</div>
                            </div>
                        </div>
                        <span class="badge-pill badge-neutral" id="status-co2">--</span>
                    </div>
                    <div class="sensor-value-row">
                        <span class="sensor-value mono" id="CO2">--</span>
                        <span class="sensor-unit">ppm</span>
                    </div>
                    <div class="threshold-bar"><div class="threshold-bar-fill" id="bar-co2" style="width: 0%; background: var(--sensor-co2);"></div></div>
                    <div class="sensor-meta-row">
                        <span>Batas Maks: <strong class="mono"><?= $air_quality_max ?> ppm</strong></span>
                        <a href="settings.php?tab=preferences" style="font-size: 11.5px;">Ubah</a>
                    </div>
                </article>

                <!-- Wind Speed -->
                <article class="surface-card sensor-card">
                    <div class="sensor-card-top">
                        <div style="display: flex; align-items: center; gap: 11px;">
                            <span class="sensor-icon-badge" style="background: var(--sensor-wind-soft); color: var(--sensor-wind);">
                                <i class="fas fa-wind"></i>
                            </span>
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);">Kecepatan Angin</div>
                                <div style="font-size: 12px; color: var(--text-muted);">Airflow Velocity</div>
                            </div>
                        </div>
                        <span class="badge-pill badge-neutral" id="status-wind">--</span>
                    </div>
                    <div class="sensor-value-row">
                        <span class="sensor-value mono" id="wind_speed">--</span>
                        <span class="sensor-unit">m/s</span>
                    </div>
                    <div class="threshold-bar"><div class="threshold-bar-fill" id="bar-wind" style="width: 0%; background: var(--sensor-wind);"></div></div>
                    <div class="sensor-meta-row">
                        <span>Target: <strong class="mono"><?= $wind_speed_min ?>–<?= $wind_speed_max ?> m/s</strong></span>
                        <a href="settings.php?tab=preferences" style="font-size: 11.5px;">Ubah</a>
                    </div>
                </article>
            </div>
        </section>

        <!-- Main Chart + Recent Alert Log -->
        <section class="dashboard-bottom-grid">
            <div class="surface-card">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-card-title">Tren Parameter Lingkungan</h2>
                        <p class="surface-card-sub">Sumbu kiri: Suhu (°C), Kelembapan (%), Angin (m/s) · Sumbu kanan: CO₂ (ppm)</p>
                    </div>
                    <div class="segmented-control" role="tablist" aria-label="Rentang waktu grafik">
                        <button type="button" class="segmented-btn is-active" data-tf="latest">1 Jam</button>
                        <button type="button" class="segmented-btn" data-tf="daily">24 Jam</button>
                        <button type="button" class="segmented-btn" data-tf="weekly">7 Hari</button>
                        <button type="button" class="segmented-btn" data-tf="monthly">30 Hari</button>
                        <button type="button" class="segmented-btn" data-tf="yearly">1 Tahun</button>
                    </div>
                </div>
                <div class="surface-card-body">
                    <div id="chartLoading" style="text-align: center; padding: 12px 0; font-size: 13px; color: var(--text-muted);">
                        <i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i>Memuat data grafik...
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="sensorChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Alerts & Quick Actions -->
            <div class="surface-card" style="display: flex; flex-direction: column;">
                <div class="surface-card-header">
                    <div>
                        <h2 class="surface-card-title">Log Peringatan Terbaru</h2>
                        <p class="surface-card-sub">Notifikasi ambang batas tercatat</p>
                    </div>
                    <a href="settings.php?tab=preferences" class="btn-ds btn-ds-secondary btn-ds-sm">Atur Batas</a>
                </div>
                <div class="surface-card-body" style="flex: 1;">
                    <?php if (empty($recentAlerts)): ?>
                        <div style="text-align: center; padding: 36px 12px; color: var(--text-muted);">
                            <i class="fas fa-circle-check" style="font-size: 28px; color: var(--status-optimal); margin-bottom: 10px; display: block;"></i>
                            <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">Tidak ada peringatan</div>
                            <div style="font-size: 12.5px;">Semua parameter ruangan berada dalam batas normal.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentAlerts as $al): ?>
                            <div class="alert-feed-item">
                                <span style="width: 28px; height: 28px; border-radius: 8px; background: var(--status-warning-soft); color: var(--status-warning); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <i class="fas fa-bell" style="font-size: 12px;"></i>
                                </span>
                                <div style="min-width: 0;">
                                    <div style="color: var(--text-primary); line-height: 1.45;"><?= htmlspecialchars(preg_replace('/^⚠️\s*/u', '', $al['message'])) ?></div>
                                    <div class="mono" style="font-size: 11.5px; color: var(--text-muted); margin-top: 3px;"><?= htmlspecialchars($al['created_at']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <footer class="app-footer">
        <span>&copy; <?= date('Y') ?> Monitoring Kenyamanan Termal. All rights reserved.</span>
        <span>Arduino MKR Wi-Fi 1010 Telemetry Platform</span>
    </footer>
</div>
</div>

<script>
(function() {
    const CONFIG = {
        intervalMs: <?= max(2000, $interval_update * 1000) ?>,
        suhuMin: <?= $suhu_min ?>,
        suhuMax: <?= $suhu_max ?>,
        humidMin: <?= $kelembapan_min ?>,
        humidMax: <?= $kelembapan_max ?>,
        windMin: <?= $wind_speed_min ?>,
        windMax: <?= $wind_speed_max ?>,
        co2Max: <?= $air_quality_max ?>
    };

    let currentTimeframe = 'latest';
    let sensorChart = null;
    let alertDismissed = false;

    document.getElementById('dismissAlertBtn').addEventListener('click', function() {
        alertDismissed = true;
        document.getElementById('liveAlertBanner').style.display = 'none';
    });

    function getChartPalette() {
        const isLight = document.documentElement.getAttribute('data-theme') === 'light';
        return {
            grid: isLight ? 'rgba(15, 23, 42, 0.08)' : 'rgba(148, 163, 184, 0.12)',
            tick: isLight ? '#475569' : '#94a3b8',
            legend: isLight ? '#0f172a' : '#e2e8f0'
        };
    }

    function createChart() {
        const ctx = document.getElementById('sensorChart').getContext('2d');
        const p = getChartPalette();
        sensorChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    { label: 'Suhu (°C)', data: [], yAxisID: 'y', borderColor: '#f97316', backgroundColor: 'rgba(249,115,22,0.10)', borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.3, fill: true },
                    { label: 'Kelembapan (%)', data: [], yAxisID: 'y', borderColor: '#38bdf8', backgroundColor: 'rgba(56,189,248,0.08)', borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.3, fill: true },
                    { label: 'Angin (m/s)', data: [], yAxisID: 'y', borderColor: '#a78bfa', backgroundColor: 'rgba(167,139,250,0.08)', borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.3, fill: false },
                    { label: 'CO₂ (ppm)', data: [], yAxisID: 'y1', borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)', borderWidth: 2, borderDash: [4, 3], pointRadius: 0, pointHoverRadius: 4, tension: 0.3, fill: false }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'start',
                        labels: { color: p.legend, usePointStyle: true, boxWidth: 8, padding: 16, font: { family: 'Plus Jakarta Sans', size: 12, weight: 600 } }
                    }
                },
                scales: {
                    x: {
                        grid: { color: p.grid },
                        ticks: { color: p.tick, maxTicksLimit: 8, font: { family: 'JetBrains Mono', size: 11 } }
                    },
                    y: {
                        type: 'linear',
                        position: 'left',
                        grid: { color: p.grid },
                        ticks: { color: p.tick, font: { family: 'JetBrains Mono', size: 11 } }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#10b981', font: { family: 'JetBrains Mono', size: 11 } }
                    }
                }
            }
        });
    }

    window.addEventListener('thermal:themechange', function() {
        if (!sensorChart) return;
        const p = getChartPalette();
        sensorChart.options.plugins.legend.labels.color = p.legend;
        sensorChart.options.scales.x.grid.color = p.grid;
        sensorChart.options.scales.x.ticks.color = p.tick;
        sensorChart.options.scales.y.grid.color = p.grid;
        sensorChart.options.scales.y.ticks.color = p.tick;
        sensorChart.update('none');
    });

    function setBadge(id, label, type) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = label;
        el.className = 'badge-pill ' + (type === 'optimal' ? 'badge-optimal' : type === 'warning' ? 'badge-warning' : 'badge-critical');
    }

    function updateComfortSummary(d) {
        let score = 100;
        const issues = [];
        const recs = [];

        // Temperature evaluation
        if (d.temperature > CONFIG.suhuMax) {
            score -= Math.min(35, Math.round((d.temperature - CONFIG.suhuMax) * 10 + 15));
            setBadge('status-temp', 'Tinggi', 'critical');
            issues.push(`Suhu ${d.temperature}°C melebihi batas maksimal (${CONFIG.suhuMax}°C).`);
            recs.push('Turunkan suhu AC ke kisaran 25–26°C atau aktifkan kipas sirkulasi.');
        } else if (d.temperature < CONFIG.suhuMin) {
            score -= Math.min(25, Math.round((CONFIG.suhuMin - d.temperature) * 8 + 10));
            setBadge('status-temp', 'Rendah', 'warning');
            issues.push(`Suhu ${d.temperature}°C di bawah batas minimal (${CONFIG.suhuMin}°C).`);
            recs.push('Naikkan setelan suhu AC agar lebih hemat energi dan nyaman.');
        } else {
            setBadge('status-temp', 'Optimal', 'optimal');
        }

        // Humidity evaluation
        if (d.humidity > CONFIG.humidMax) {
            score -= 20;
            setBadge('status-humid', 'Lembap', 'warning');
            issues.push(`Kelembapan ${d.humidity}% melampaui batas (${CONFIG.humidMax}%).`);
            recs.push('Gunakan mode Dry pada AC atau tingkatkan ventilasi silang.');
        } else if (d.humidity < CONFIG.humidMin) {
            score -= 15;
            setBadge('status-humid', 'Kering', 'warning');
            issues.push(`Kelembapan ${d.humidity}% di bawah batas (${CONFIG.humidMin}%).`);
        } else {
            setBadge('status-humid', 'Optimal', 'optimal');
        }

        // CO2 evaluation
        if (d.air_quality > CONFIG.co2Max) {
            score -= 30;
            setBadge('status-co2', 'Buruk', 'critical');
            issues.push(`CO₂ ${d.air_quality} ppm melebihi ambang aman (${CONFIG.co2Max} ppm).`);
            recs.push('Buka jendela atau nyalakan exhaust fan untuk pasokan udara segar.');
        } else {
            setBadge('status-co2', 'Sehat', 'optimal');
        }

        // Wind speed evaluation
        if (d.wind_speed > CONFIG.windMax) {
            score -= 15;
            setBadge('status-wind', 'Kencang', 'warning');
        } else if (d.wind_speed < CONFIG.windMin) {
            score -= 10;
            setBadge('status-wind', 'Lemah', 'warning');
        } else {
            setBadge('status-wind', 'Optimal', 'optimal');
        }

        score = Math.max(0, Math.min(100, score));

        // Update SVG radial gauge
        const circumference = 301.59;
        const offset = circumference - (score / 100) * circumference;
        const ring = document.getElementById('comfortRing');
        ring.style.strokeDashoffset = offset;
        ring.style.stroke = score >= 80 ? 'var(--status-optimal)' : score >= 55 ? 'var(--status-warning)' : 'var(--status-critical)';
        document.getElementById('comfortScore').textContent = score;

        if (score >= 80) {
            setBadge('comfortBadge', 'Nyaman', 'optimal');
            document.getElementById('comfortTitle').textContent = 'Kondisi Ruangan Nyaman';
            document.getElementById('comfortSubtitle').textContent = 'Parameter termal dan kualitas udara berada di zona ideal.';
            document.getElementById('comfortRecommendation').textContent = 'Pertahankan sirkulasi udara saat ini. Tidak diperlukan penyesuaian pendingin ruangan.';
        } else if (score >= 55) {
            setBadge('comfortBadge', 'Perhatian', 'warning');
            document.getElementById('comfortTitle').textContent = 'Perlu Penyesuaian Ringan';
            document.getElementById('comfortSubtitle').textContent = 'Satu atau lebih parameter mulai menjauh dari zona target.';
            document.getElementById('comfortRecommendation').textContent = recs[0] || 'Periksa ventilasi ruangan dan pengaturan pendingin.';
        } else {
            setBadge('comfortBadge', 'Tidak Nyaman', 'critical');
            document.getElementById('comfortTitle').textContent = 'Kondisi Termal Kurang Ideal';
            document.getElementById('comfortSubtitle').textContent = 'Beberapa parameter melewati batas kenyamanan yang ditetapkan.';
            document.getElementById('comfortRecommendation').textContent = recs.join(' ') || 'Segera lakukan penyesuaian suhu dan sirkulasi udara.';
        }

        // Progress bars
        document.getElementById('bar-temp').style.width = Math.min(100, Math.max(5, ((d.temperature - 15) / 30) * 100)) + '%';
        document.getElementById('bar-humid').style.width = Math.min(100, Math.max(5, d.humidity)) + '%';
        document.getElementById('bar-co2').style.width = Math.min(100, Math.max(5, (d.air_quality / Math.max(600, CONFIG.co2Max * 1.4)) * 100)) + '%';
        document.getElementById('bar-wind').style.width = Math.min(100, Math.max(5, (d.wind_speed / Math.max(6, CONFIG.windMax * 1.4)) * 100)) + '%';

        // Live alert banner
        const banner = document.getElementById('liveAlertBanner');
        if (issues.length > 0 && !alertDismissed) {
            document.getElementById('liveAlertText').innerHTML = issues.map(i => `<div>• ${i}</div>`).join('');
            banner.style.display = 'flex';
        } else if (issues.length === 0) {
            banner.style.display = 'none';
            alertDismissed = false;
        }
    }

    async function fetchRealtimeData() {
        try {
            const res = await fetch('get_data.php?timeframe=realtime');
            if (!res.ok) return;
            const json = await res.json();
            if (json.data && json.data.length > 0) {
                const latest = json.data[0];
                document.getElementById('temperature').textContent = Number(latest.temperature).toFixed(1);
                document.getElementById('humidity').textContent = Number(latest.humidity).toFixed(1);
                document.getElementById('CO2').textContent = Math.round(Number(latest.air_quality));
                document.getElementById('wind_speed').textContent = Number(latest.wind_speed).toFixed(1);

                const dt = new Date(latest.reading_time.replace(' ', 'T'));
                document.getElementById('lastUpdatedAt').textContent = isNaN(dt) ? latest.reading_time : dt.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                updateComfortSummary(latest);
            }
        } catch (err) {
            console.error('Realtime fetch error:', err);
        }
    }

    async function fetchSensorData() {
        const loader = document.getElementById('chartLoading');
        try {
            const res = await fetch(`get_data.php?timeframe=${encodeURIComponent(currentTimeframe)}`);
            if (!res.ok) return;
            const json = await res.json();
            const rows = (json.data || []).slice().reverse();

            const labels = rows.map(entry => {
                const date = new Date(entry.reading_time.replace(' ', 'T'));
                if (isNaN(date)) return entry.reading_time;
                if (currentTimeframe === 'latest' || currentTimeframe === 'daily') {
                    return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
                }
                if (currentTimeframe === 'weekly' || currentTimeframe === 'monthly') {
                    return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' });
                }
                return date.toLocaleDateString('id-ID', { month: 'short', year: 'numeric' });
            });

            sensorChart.data.labels = labels;
            sensorChart.data.datasets[0].data = rows.map(e => e.temperature);
            sensorChart.data.datasets[1].data = rows.map(e => e.humidity);
            sensorChart.data.datasets[2].data = rows.map(e => e.wind_speed);
            sensorChart.data.datasets[3].data = rows.map(e => e.air_quality);
            sensorChart.update();

            if (loader) {
                loader.style.display = rows.length === 0 ? 'block' : 'none';
                if (rows.length === 0) loader.textContent = 'Belum ada data sensor untuk rentang waktu ini.';
            }
        } catch (err) {
            console.error('Chart fetch error:', err);
            if (loader) loader.textContent = 'Gagal memuat data grafik.';
        }
    }

    document.querySelectorAll('.segmented-btn[data-tf]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.segmented-btn[data-tf]').forEach(b => b.classList.remove('is-active'));
            this.classList.add('is-active');
            currentTimeframe = this.dataset.tf;
            fetchSensorData();
        });
    });

    createChart();
    fetchRealtimeData();
    fetchSensorData();
    setInterval(fetchRealtimeData, CONFIG.intervalMs);
    setInterval(fetchSensorData, Math.max(15000, CONFIG.intervalMs * 3));
})();
</script>
</body>
</html>
