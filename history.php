<?php
include 'db.php';
require_once 'includes/auth_bootstrap.php';

$user = require_authenticated_user($conn);

$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));

$activeNav = 'history';
$pageTitle = 'Data Historis Sensor';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Historis — Monitoring Kenyamanan Termal</title>
    <link rel="icon" type="image/png" href="logo.png">
    <?php render_theme_init_script(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        .summary-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .summary-tile {
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .summary-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .summary-val {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 14px;
            align-items: end;
        }
        .table-scroll-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        /* Clean DataTables theming aligned with design system */
        .dataTables_wrapper {
            color: var(--text-secondary);
            font-size: 13.5px;
        }
        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-default);
            border-radius: var(--radius-sm);
            padding: 6px 10px;
            outline: none;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--brand-primary);
        }
        table.dataTable {
            width: 100% !important;
            border-collapse: collapse !important;
            margin: 14px 0 !important;
        }
        table.dataTable thead th {
            background: var(--bg-surface-2);
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 13px 16px !important;
            border-bottom: 1px solid var(--border-default) !important;
            white-space: nowrap;
        }
        table.dataTable tbody td {
            padding: 13px 16px !important;
            border-bottom: 1px solid var(--border-subtle) !important;
            color: var(--text-primary);
            font-family: var(--font-mono);
            font-size: 13px;
            white-space: nowrap;
        }
        table.dataTable tbody tr {
            background: transparent !important;
            transition: background-color 0.12s ease;
        }
        table.dataTable tbody tr:hover {
            background: var(--bg-surface-2) !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--text-secondary) !important;
            border-radius: 6px !important;
            border: 1px solid transparent !important;
            padding: 5px 11px !important;
            margin-left: 4px !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--brand-primary) !important;
            color: #042f2e !important;
            border-color: var(--brand-primary) !important;
            font-weight: 700;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--bg-surface-3) !important;
            color: var(--text-primary) !important;
            border-color: var(--border-default) !important;
        }
        @media (max-width: 960px) {
            .summary-strip { grid-template-columns: repeat(2, 1fr); }
            .filter-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 520px) {
            .summary-strip { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include 'nav.php'; ?>

    <main class="app-content">
        <!-- Period Summary Strip -->
        <section class="summary-strip" aria-label="Ringkasan periode data">
            <div class="surface-card summary-tile">
                <span class="summary-label">Total Pembacaan</span>
                <span class="summary-val mono" id="statTotal">--</span>
            </div>
            <div class="surface-card summary-tile">
                <span class="summary-label">Rata-rata Suhu</span>
                <span class="summary-val mono" id="statTemp">-- °C</span>
            </div>
            <div class="surface-card summary-tile">
                <span class="summary-label">Rata-rata Kelembapan</span>
                <span class="summary-val mono" id="statHumid">-- %</span>
            </div>
            <div class="surface-card summary-tile">
                <span class="summary-label">Puncak CO₂</span>
                <span class="summary-val mono" id="statCo2">-- ppm</span>
            </div>
        </section>

        <!-- Filter & Export Toolbar -->
        <section class="surface-card" style="margin-bottom: 20px;">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-card-title">Filter Rentang Tanggal</h2>
                    <p class="surface-card-sub">Saring catatan sensor berdasarkan periode atau ekspor ke CSV/Excel</p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-ds btn-ds-secondary btn-ds-sm" onclick="applyPreset(1)">Hari Ini</button>
                    <button type="button" class="btn-ds btn-ds-secondary btn-ds-sm" onclick="applyPreset(7)">7 Hari</button>
                    <button type="button" class="btn-ds btn-ds-secondary btn-ds-sm" onclick="applyPreset(30)">30 Hari</button>
                    <button type="button" class="btn-ds btn-ds-ghost btn-ds-sm" onclick="resetFilter()">Reset</button>
                </div>
            </div>
            <div class="surface-card-body">
                <form id="filterForm" method="GET" class="filter-grid">
                    <div>
                        <label for="start_date" class="form-label">Dari Tanggal</label>
                        <input type="date" id="start_date" name="start_date" class="form-control-ds" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div>
                        <label for="end_date" class="form-label">Sampai Tanggal</label>
                        <input type="date" id="end_date" name="end_date" class="form-control-ds" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="submit" class="btn-ds btn-ds-primary">
                            <i class="fas fa-filter"></i> Terapkan
                        </button>
                        <button type="button" class="btn-ds btn-ds-secondary" onclick="exportCSV()">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                        <button type="button" class="btn-ds btn-ds-secondary" onclick="exportXLSX()">
                            <i class="fas fa-file-excel"></i> XLSX
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Data Table -->
        <section class="surface-card">
            <div class="surface-card-header">
                <div>
                    <h2 class="surface-card-title">Tabel Log Pembacaan Sensor</h2>
                    <p class="surface-card-sub">Klik judul kolom untuk mengurutkan data</p>
                </div>
            </div>
            <div class="surface-card-body">
                <div class="table-scroll-wrap">
                    <table id="sensorTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th>Tanggal &amp; Waktu</th>
                                <th>Suhu (°C)</th>
                                <th>Kelembapan (%)</th>
                                <th>Kecepatan Angin (m/s)</th>
                                <th>Kualitas Udara (ppm)</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <footer class="app-footer">
        <span>&copy; <?= date('Y') ?> Monitoring Kenyamanan Termal. All rights reserved.</span>
        <span>Data Historis Sensor</span>
    </footer>
</div>
</div>

<script>
let dataTable;

$(document).ready(function() {
    dataTable = $('#sensorTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: 'sensor_data.php',
            type: 'POST',
            data: function(d) {
                d.start_date = $('#start_date').val();
                d.end_date = $('#end_date').val();
            },
            dataSrc: function(json) {
                $('#statTotal').text(Number(json.recordsFiltered || 0).toLocaleString('id-ID'));
                const s = json.stats || {};
                $('#statTemp').text(s.avg_temp != null ? s.avg_temp + ' °C' : '-- °C');
                $('#statHumid').text(s.avg_humidity != null ? s.avg_humidity + ' %' : '-- %');
                $('#statCo2').text(s.max_co2 != null ? s.max_co2 + ' ppm' : '-- ppm');
                return json.data || [];
            }
        },
        columns: [
            { data: 'reading_time' },
            { data: 'temperature', render: v => Number(v).toFixed(1) + ' °C' },
            { data: 'humidity', render: v => Number(v).toFixed(1) + ' %' },
            { data: 'wind_speed', render: v => Number(v).toFixed(1) + ' m/s' },
            { data: 'air_quality', render: v => Math.round(Number(v)) + ' ppm' }
        ],
        order: [[0, 'desc']],
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
        language: {
            processing: 'Memuat data...',
            lengthMenu: 'Tampilkan _MENU_ baris',
            zeroRecords: 'Tidak ada data sensor pada rentang tanggal yang dipilih.',
            info: 'Menampilkan _START_–_END_ dari _TOTAL_ data',
            infoEmpty: 'Menampilkan 0 data',
            infoFiltered: '(disaring dari _MAX_ total data)',
            search: 'Cari:',
            paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' }
        }
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        dataTable.ajax.reload();
    });
});

function formatDate(d) {
    return d.toISOString().slice(0, 10);
}

function applyPreset(days) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));
    $('#start_date').val(formatDate(start));
    $('#end_date').val(formatDate(end));
    dataTable.ajax.reload();
}

function resetFilter() {
    $('#start_date').val('');
    $('#end_date').val('');
    dataTable.ajax.reload();
}

function exportCSV() {
    const rows = dataTable.rows({ search: 'applied' }).data().toArray();
    if (!rows.length) return;
    let csv = 'Tanggal & Waktu,Suhu (°C),Kelembapan (%),Kecepatan Angin (m/s),Kualitas Udara (ppm)\n';
    rows.forEach(r => {
        csv += `"${r.reading_time}",${r.temperature},${r.humidity},${r.wind_speed},${r.air_quality}\n`;
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'data_historis_termal.csv';
    link.click();
}

function exportXLSX() {
    const rows = dataTable.rows({ search: 'applied' }).data().toArray();
    if (!rows.length) return;
    const sheetData = rows.map(r => ({
        'Tanggal & Waktu': r.reading_time,
        'Suhu (°C)': r.temperature,
        'Kelembapan (%)': r.humidity,
        'Kecepatan Angin (m/s)': r.wind_speed,
        'Kualitas Udara (ppm)': r.air_quality
    }));
    const ws = XLSX.utils.json_to_sheet(sheetData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Data Historis');
    XLSX.writeFile(wb, 'data_historis_termal.xlsx');
}
</script>
</body>
</html>
