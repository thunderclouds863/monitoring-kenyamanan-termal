<?php
// Pengganti MySQL EVENT "demo_sensor_feed" (TiDB tidak mendukung EVENT).
// Dipanggil dari deploy/legacy.php pada halaman yang membaca data sensor: mengisi pembacaan sintetis
// yang "hilang" sejak pembacaan terakhir (tiap 10 menit untuk riwayat <= 7 hari, tiap menit untuk 1 jam terakhir).

function demo_sensor_row(int $ts): string
{
    $hour = (int) gmdate('G', $ts);
    $phase = sin(2 * M_PI * ($hour - 9) / 24);
    $r = static fn() => mt_rand() / mt_getrandmax();
    return sprintf("(%.1F, %.1F, %.1F, %d, FROM_UNIXTIME(%d))",
        28 + 3 * $phase + ($r() - 0.5),
        72 - 10 * $phase + ($r() - 0.5) * 4,
        max(0, 1.5 + ($r() - 0.5) * 2),
        (int) round(220 + $r() * 120),
        $ts);
}

function demo_sensor_feed(): void
{
    $conn = demo_mysqli(null, null, null, getenv('DEMO_DB_NAME') ?: 'figflzel_data');
    if ($conn->connect_errno) return;
    $res = $conn->query("SELECT UNIX_TIMESTAMP(MAX(reading_time)) AS last_ts, UNIX_TIMESTAMP() AS now_ts FROM sensor_readings");
    if (!$res) return;
    $row = $res->fetch_assoc();
    $now = (int) $row['now_ts'];
    $last = $row['last_ts'] !== null ? (int) $row['last_ts'] : $now - 7 * 86400;
    if ($now - $last < 60) { $conn->close(); return; }

    $last = max($last, $now - 7 * 86400);
    $rows = [];
    $oneHourAgo = $now - 3600;
    for ($t = $last + 600; $t < $oneHourAgo; $t += 600) $rows[] = demo_sensor_row($t);
    for ($t = max($last + 60, $oneHourAgo); $t <= $now; $t += 60) $rows[] = demo_sensor_row($t);
    foreach (array_chunk($rows, 500) as $chunk) {
        $conn->query("INSERT INTO sensor_readings (temperature, humidity, wind_speed, air_quality, reading_time) VALUES " . implode(',', $chunk));
    }
    $conn->close();
}
