<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE remember_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
    }
}

// Jika masih belum login, redirect
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Ambil data user dari database berdasarkan sesi user_id
$query = $conn->prepare("SELECT username, email, phone, profile_pic FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();
$user = $result->fetch_assoc();

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo.png">
    <title>Data Historis Sensor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.3/xlsx.full.min.js"></script>
    <style>
                body { background-color: #0d1117; color: white; }
        .dark-mode { background-color: #cbd5e1; color: #333; }
        .dark-mode .bg-gray-900 { background: #ffffff; color: black; }
        .dark-mode .bg-gray-800 { background: #e2e8f0; color: black; }
        .dark-mode .timeframe-card { background: #e2e8f0; color: black; }
        .dark-mode .timeframe-card:hover, .dark-mode .timeframe-card.active { background: #cbd5e1; }
               /* Warna teks navbar saat dark mode */
               .dark-mode .navbar {
            color: black !important;
        }

        .dark-mode .navbar a {
            color: black !important;
        }

        .dark-mode .navbar a:hover {
            color: #555 !important; /* Warna teks saat hover */
        }
        .dataTables_wrapper .dataTables_filter {
            float: right !important;
            text-align: right !important;
            margin-bottom: 10px !important;
        }
        .dataTables_wrapper .dataTables_length {
            float: left !important;
            margin-bottom: 10px !important;
        }
        .dataTables_wrapper .dataTables_paginate {
            float: right !important;
            text-align: right !important;
        }
        .w-full.max-w-6xl {
            margin-top: 6.5rem; /* Atur margin top menjadi 6.5rem */
            margin-left: auto; /* Atur margin kiri otomatis */
            margin-right: auto; /* Atur margin kanan otomatis */
        }
        .sidebar-item:hover {
            background-color: #374151;
        }
        .sidebar-item.active {
            background-color: #1e40af;
        }
        .profile-pic {
            width: 120px !important;
            height: 120px !important;
            object-fit: cover;
        }
        .profile-pic-container {
            position: relative;
            display: inline-block;
        }
        .profile-pic-edit {
            position: absolute;
            bottom: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        footer {
            background: none; /* Tanpa latar belakang */
            color: #6b7280; /* Warna teks abu-abu */
            font-size: 0.875rem; /* Ukuran teks kecil */
        }

        footer a {
            color: #60a5fa; /* Warna link biru */
            text-decoration: none;
            transition: color 0.3s ease-in-out;
        }

        footer a:hover {
            color: #3b82f6; /* Warna lebih terang saat hover */
        }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen pt -8">
<?php include 'nav.php'; ?>

<div class="w-full max-w-6xl bg-gray-900 p-6 rounded-lg shadow-xl mt-20">
    <h2 class="text-4xl font-bold text-center mb-6">📡 Data Historis Sensor</h2>

    <form method="GET" class="row g-3">
        <div class="col-md-5">
            <label for="start_date" class="form-label">Dari Tanggal:</label>
            <input type="date" class="form-control" name="start_date" value="<?= $startDate ?>">
        </div>
        <div class="col-md-5">
            <label for="end_date" class="form-label">Sampai Tanggal:</label>
            <input type="date" class="form-control" name="end_date" value="<?= $endDate ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Cari</button>
        </div>
    </form>
        <div class="mt-3">
            <button class="btn btn-success me-2" onclick="exportCSV()">Export CSV</button>
 <button class="btn btn-info" onclick="exportXLSX()">Export XLSX</button>
        </div>
    <div class="container mt-5">
    <table id="sensorTable" class="table table-striped table-bordered table-sm">
        <thead class="table-dark">
            <tr>
                <th>Tanggal & Waktu</th>
                <th>Suhu (°C)</th>
                <th>Kelembaban (%)</th>
                <th>Kecepatan Angin (m/s)</th>
                <th>Kualitas Udara (CO2)</th>
            </tr>
        </thead>
        <tbody>
            <!-- Data akan diisi oleh DataTable -->
        </tbody>
    </table>
    </div>

    <script>
        $(document).ready(function() {
            $('#sensorTable').DataTable({
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "sensor_data.php",
                    "type": "POST",
                    "data": function(d) {
                        d.start_date = $('input[name="start_date"]').val();
                        d.end_date = $('input[name="end_date"]').val();
                    }
                },
                "columns": [
                    { "data": "reading_time" },
                    { "data": "temperature" },
                    { "data": "humidity" },
                    { "data": "wind_speed" },
                    { "data": "air_quality" }
                ],
                "order": [[0, "desc"]],
                "lengthMenu": [[10, 20, 50, 100, -1], [10, 20, 50, 100, "Semua"]],
                "language": {
                    "lengthMenu": "Tampilkan _MENU_ entri per halaman",
                    "zeroRecords": "Tidak ada data yang ditemukan",
                    "info": "Menampilkan _START_ hingga _END_ dari _TOTAL_ entri",
                    "infoEmpty": "Menampilkan 0 hingga 0 dari 0 entri",
                    "infoFiltered": "(difilter dari total _MAX_ entri)",
                    "search": "Cari:",
                    "paginate": {
                        "first": "Pertama",
                        "last": "Terakhir",
                        "next": "Berikutnya",
                        "previous": "Sebelumnya"
                    }
                }
            });
        });
            function exportCSV() {
            let csv = 'Tanggal & Waktu,Suhu (°C),Kelembaban (%),Kecepatan Angin (m/s),Kualitas Udara (CO2)\n';
            document.querySelectorAll('#sensorTable tbody tr').forEach(row => {
                let rowData = [];
                row.querySelectorAll('td').forEach(cell => rowData.push(cell.innerText));
                csv += rowData.join(',') + '\n';
            });
            let blob = new Blob([csv], { type: 'text/csv' });
            let link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'data_historis.csv';
            link.click();
        }

        function exportXLSX() {
            let table = document.querySelector("#sensorTable");
            let wb = XLSX.utils.table_to_book(table, { sheet: "Data Historis" });
            XLSX.writeFile(wb, "data_historis.xlsx");
        }

    document.addEventListener("DOMContentLoaded", function () {
        const tableBody = document.querySelector("#sensorTable tbody");
        const searchForm = document.querySelector("form");

        searchForm.addEventListener("submit", function (e) {
            if (tableBody.children.length === 0) {
                e.preventDefault(); // Mencegah pengiriman form jika tidak ada data
                showNoDataPopup(); // Tampilkan pop-up
            }
        });
    });

    function showNoDataPopup() {
        const modal = document.createElement("div");
        modal.classList.add("modal");
        modal.style.display = "flex";
        modal.style.justifyContent = "center";
        modal.style.alignItems = "center";
        modal.style.position = "fixed";
        modal.style.top = "0";
        modal.style.left = "0";
        modal.style.width = "100%";
        modal.style.height = "100%";
        modal.style.backgroundColor = "rgba(0, 0, 0, 0.8)";
        modal.innerHTML = `
            <div class="modal-content" style="background: white; padding: 20px; border-radius: 8px; text-align: center;">
                <h2 style="color: red;">⚠️ Data Tidak Ditemukan</h2>
                <p>Data pada rentang tanggal yang dipilih tidak tersedia.</p>
                <button onclick="closeModal()" style="margin-top: 10px; padding: 10px 20px; background: red; color: white; border: none; border-radius: 5px; cursor: pointer;">OK</button>
            </div>
        `;
        document.body.appendChild(modal);
    }

    function closeModal() {
        const modal = document.querySelector(".modal");
        if (modal) {
            modal.remove();
        }
    }
    </script>

    <!-- Footer -->
    <footer class="text-gray-500 text-center py-4 mt-10">
        <div class="container mx-auto px-4">
            <p class="text-sm">
                &copy; <?php echo date('Y'); ?> Monitoring Kenyamanan Termal. All rights reserved.
            </p>
            <p class="text-xs mt-2">
                Dibuat dengan ❤️ oleh
                <a href="https://www.linkedin.com/in/desi-armanda-sari" target="_blank"
                   class="text-blue-400 hover:text-blue-500 underline transition-all duration-300">
                    Desi Armanda Sari
                </a>.
            </p>
            <p class="text-xs mt-2 italic text-gray-400">
                "Kalau ada bug, itu fitur tersembunyi. Kalau lancar, itu keajaiban mahasiswa tingkat akhir." 😄
            </p>
        </div>
    </footer>
</body>
</html>