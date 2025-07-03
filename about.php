<?php
session_start();
include 'db.php';

// Cek apakah user sudah login
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

// Ambil API Key dari database
$apikey_query = $conn->prepare("SELECT apikey FROM users WHERE id = ?");
$apikey_query->bind_param("i", $_SESSION['user_id']);
$apikey_query->execute();
$apikey_result = $apikey_query->get_result();
$apikey_row = $apikey_result->fetch_assoc();
$current_apikey = $apikey_row['apikey'] ?? "";

// Proses pesan sukses/gagal
$warning_message = $_SESSION['warning_message'] ?? null;
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['warning_message']);


$query = $conn->prepare("SELECT username, email, phone, profile_pic FROM users WHERE id = ?");
$query->bind_param("i", $_SESSION['user_id']);
$query->execute();
$result = $query->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("Error: Data pengguna tidak ditemukan di database.");
}
$username = $user['username'] ?? 'Pengguna';
$email = $user['email'] ?? 'Tidak tersedia';
$profile_pic = !empty($user['profile_pic']) ? 'data:image/jpeg;base64,' . base64_encode($user['profile_pic']) : 'default-profile.png';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo.png">
    <title>Bantuan Sistem</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .sidebar-item:hover {
            background-color: #374151;
        }
        .sidebar-item.active {
            background-color: #1e40af;
        }
        .content-section {
            background-color: #1f2937;
            border-left: 4px solid #3b82f6;
        }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen pt-8">
    <?php include 'nav.php'; ?>

    <div class="flex pt-16">
        <!-- Sidebar -->
        <div class="w-64 bg-gray-800 min-h-screen p-4 hidden md:block fixed top-24 left-0">
            <div class="flex items-center space-x-4 p-4 border-b border-gray-700">
                <?php if (!empty($user['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($user['profile_pic']) ?>" 
     class="w-12 h-12 rounded-full object-cover" 
     alt="Profile">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center">
                        <i class="fas fa-user text-xl"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h3 class="font-bold"><?= $username ?></h3>
                    <p class="text-sm text-gray-400">Bantuan Sistem</p>
                </div>
            </div>

            <nav class="mt-6">
                <a href="#latar-belakang" class="block px-4 py-3 rounded-lg mb-2 sidebar-item">
                    <i class="fas fa-book-open mr-3"></i> Latar Belakang
                </a>
                <a href="#tujuan-sistem" class="block px-4 py-3 rounded-lg mb-2 sidebar-item">
                    <i class="fas fa-bullseye mr-3"></i> Tujuan Sistem
                </a>
                <a href="#fitur-utama" class="block px-4 py-3 rounded-lg mb-2 sidebar-item">
                    <i class="fas fa-sliders-h mr-3"></i> Fitur Utama
                </a>
                <a href="#panduan-penggunaan" class="block px-4 py-3 rounded-lg mb-2 sidebar-item">
                    <i class="fas fa-question-circle mr-3"></i> Panduan Penggunaan
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1 md:ml-60 mt-8">
            <div class="max-w-4xl mx-auto">
                <!-- Notifikasi -->
                <?php if ($success_message): ?>
                    <div class="bg-green-500 text-white p-4 rounded-lg mb-6">
                        <?= htmlspecialchars($success_message); ?>
                    </div>
                <?php elseif ($error_message): ?>
                    <div class="bg-red-500 text-white p-4 rounded-lg mb-6">
                        <?= htmlspecialchars($error_message); ?>
                    </div>
                <?php elseif ($warning_message): ?>
                    <div class="bg-yellow-500 text-white p-4 rounded-lg mb-6">
                        <?= htmlspecialchars($warning_message); ?>
                    </div>
                <?php endif; ?>

                <h2 class="text-3xl font-bold mb-6 flex items-center">
                    <i class="fas fa-info-circle mr-3 text-blue-400"></i> Bantuan Sistem Pemantauan Termal
                </h2>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="bg-green-500 text-white p-4 rounded-lg mb-6">
                        <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    </div>
                <?php elseif (isset($_SESSION['error_message'])): ?>
                    <div class="bg-red-500 text-white p-4 rounded-lg mb-6">
                        <?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>

                <!-- Latar Belakang Section -->
                <section id="latar-belakang" class="mb-12 content-section p-6 rounded-lg shadow-lg">
                    <h3 class="text-2xl font-bold mb-4 text-blue-300">
                        <i class="fas fa-book-open mr-2"></i> 1. Latar Belakang
                    </h3>
                    <div class="space-y-4 text-gray-300">
                        <p>
                            Generasi Z menghadapi kesulitan memiliki rumah karena pendapatan rendah dan harga properti tinggi. 
                            Mereka cenderung memilih hunian kecil (compact housing) yang lebih terjangkau dan praktis. 
                            Namun, hunian kecil sering tidak nyaman secara termal, terutama di iklim tropis lembap seperti Indonesia. 
                            Kondisi iklim yang semakin ekstrem akibat perubahan iklim global memperparah masalah ini. 
                            Penggunaan AC yang tidak tepat juga sering kali tidak memperhatikan keseimbangan suhu, kelembapan, dan kualitas udara, sehingga menimbulkan ketidaknyamanan dan pemborosan energi.
                        </p>
                        <p>
                            Diperlukan solusi berbasis teknologi berupa sistem pemantauan suhu, kelembapan, kecepatan angin, dan kualitas udara secara real-time berbasis website yang dapat diakses melalui smartphone/komputer. 
                            Sistem ini membantu menjaga kenyamanan termal, menghemat energi, dan menyediakan data historis untuk analisis dan penelitian ke depan.
                        </p>
                        <p>
                            Sistem ini dikembangkan untuk membantu penghuni compact housing untuk memantau dan mengelola kondisi termal
                            (suhu, kelembapan, kecepatan angin, dan kualitas udara) secara real-time melalui antarmuka berbasis web.
                        </p>
                    </div>
                </section>

                <!-- Tujuan Sistem Section -->
                <section id="tujuan-sistem" class="mb-12 content-section p-6 rounded-lg shadow-lg">
                    <h3 class="text-2xl font-bold mb-4 text-blue-300">
                        <i class="fas fa-bullseye mr-2"></i> 2. Tujuan Sistem
                    </h3>
                    <div class="space-y-4 text-gray-300">
                        <ul class="list-disc pl-6 space-y-2">
                            <li>Memantau kondisi termal ruangan secara real-time (suhu, kelembapan, kecepatan angin, kualitas udara)</li>
                            <li>Memberikan rekomendasi pengaturan AC berdasarkan kondisi aktual ruangan</li>
                            <li>Menyediakan data historis untuk analisis pola penggunaan dan kondisi termal</li>
                            <li>Mengurangi dampak lingkungan dan langkah efisiensi energi melalui penggunaan pendingin ruangan yang lebih bijak</li>
                        </ul>
                    </div>
                </section>

                <!-- Fitur Utama Section -->
                <section id="fitur-utama" class="mb-12 content-section p-6 rounded-lg shadow-lg">
                    <h3 class="text-2xl font-bold mb-4 text-blue-300">
                        <i class="fas fa-sliders-h mr-2"></i> 3. Fitur Utama
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-gray-300">
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                                <i class="fas fa-thermometer-half mr-2"></i> Pemantauan Real-time
                            </h4>
                            <p>
                                Menampilkan data suhu, kelembapan, kecepatan angin, dan kualitas udara secara langsung
                                dengan pembaruan setiap 1 detik (dapat disesuaikan).
                            </p>
                        </div>
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                                <i class="fas fa-bell mr-2"></i> Notifikasi
                            </h4>
                            <p>
                                Memberikan peringatan ketika parameter termal melebihi batas yang ditentukan,
                                membantu pengguna mengambil tindakan tepat waktu.
                            </p>
                        </div>
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                                <i class="fas fa-chart-line mr-2"></i> Analisis Data
                            </h4>
                            <p>
                                Menyajikan grafik dan statistik data historis untuk memahami pola perubahan
                                kondisi termal dari waktu ke waktu.
                            </p>
                        </div>
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                                <i class="fas fa-cog mr-2"></i> Pengaturan Personal
                            </h4>
                            <p>
                                Memungkinkan pengguna menyesuaikan batas parameter termal sesuai preferensi
                                pribadi untuk kenyamanan optimal.
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Panduan Penggunaan Section -->
<section id="panduan-penggunaan" class="content-section p-6 rounded-lg shadow-lg">
    <h3 class="text-2xl font-bold mb-4 text-blue-300">
        <i class="fas fa-question-circle mr-2"></i> 4. Panduan Penggunaan
    </h3>
    <div class="space-y-6 text-gray-300">
        <div class="bg-gray-700 p-4 rounded-lg">
            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
            </h4>
            <p>
                Dashboard menampilkan ringkasan kondisi termal terkini. Setiap parameter ditampilkan
                dengan nilai aktual dan indikator visual.
            </p>
        </div>
        <div class="bg-gray-700 p-4 rounded-lg">
            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                <i class="fas fa-sliders-h mr-2"></i> Pengaturan Preferensi
            </h4>
            <p>
                Di halaman Pengaturan > Preferensi Sensor, Anda dapat menyesuaikan batas minimum dan
                maksimum untuk setiap parameter sesuai kenyamanan pribadi.
            </p>
        </div>
        <div class="bg-gray-700 p-4 rounded-lg">
            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                <i class="fas fa-history mr-2"></i> Data Historis
            </h4>
            <p>
                Data historis dapat diakses untuk melihat tren perubahan kondisi termal.
                Gunakan filter tanggal untuk melihat data periode tertentu.
            </p>
        </div>
        <!-- Notification Settings Section -->
        <div class="bg-gray-700 p-4 rounded-lg">
            <h4 class="text-lg font-semibold mb-2 text-blue-200">
                <i class="fas fa-bell mr-2"></i> Manajemen Notifikasi
            </h4>
            <p>
                Pengaturan notifikasi memungkinkan Anda mengatur bagaimana dan kapan sistem akan memberi tahu Anda. 
                Beberapa parameter dapat memicu notifikasi jika melewati ambang batas yang ditentukan. 
                Anda dapat mengonfigurasi waktu tunda antara notifikasi, perubahan minimal yang harus terdeteksi, serta tingkat sensitivitas sistem.
            </p>

            <h4 class="text-lg font-semibold mb-2 text-blue-200 mt-4">Cooldown Time (detik)</h4>
            <p class="text-gray-400 text-sm mb-4">
                Mengatur waktu tunda minimum antara notifikasi yang diterima. Ini mencegah notifikasi terlalu sering muncul jika parameter terus berubah dengan cepat. Misalnya, jika suhu atau kelembapan terus berubah, sistem akan menunggu beberapa detik sebelum mengirimkan notifikasi berikutnya.
            </p>

<h4 class="text-lg font-semibold mb-2 text-blue-200 mt-4">Delta Threshold (%)</h4>
<p class="text-gray-400 text-sm mb-4">
    <strong>Delta Threshold</strong> adalah ambang batas perubahan nilai (dalam persen) yang digunakan untuk menentukan apakah sistem perlu mengirimkan notifikasi ulang <strong>meskipun waktu cooldown belum selesai</strong>. 
    <br><br>
    Fitur ini aktif ketika parameter (seperti suhu atau kelembapan) sudah berada dalam kondisi <strong>abnormal</strong>. Jika nilai tersebut kemudian berubah semakin ekstrim (semakin tinggi atau rendah dari sebelumnya) melebihi persentase yang ditentukan, maka sistem akan langsung mengirim notifikasi ulang.
    
    <br><br>
    Contoh:
    <ul class="list-disc pl-6 mt-2 text-sm text-gray-400">
        <li>Jika batas atas suhu adalah <strong>30°C</strong>, dan sebelumnya sudah menyentuh <strong>32°C</strong>, lalu naik lagi menjadi <strong>32.7°C</strong> (kenaikan ≥ 2%), maka sistem akan mengirim notifikasi ulang.</li>
        <li>Jika batas bawah suhu adalah <strong>20°C</strong>, dan suhu sebelumnya sudah turun menjadi <strong>18°C</strong>, lalu turun lagi menjadi <strong>17.6°C</strong> (penurunan ≥ 2%), sistem juga akan mengirim notifikasi ulang.</li>
    </ul>
    Mekanisme ini membuat sistem lebih tanggap terhadap perubahan mendadak dan ekstrem, walaupun notifikasi sebelumnya belum lama dikirim.
</p>



            <h4 class="text-lg font-semibold mb-2 text-blue-200">Tingkat Sensitivitas</h4>
            <p class="text-gray-400 text-sm mb-4">
                Menyesuaikan kepekaan sistem terhadap perubahan parameter. Pilih "Normal" untuk pengaturan default, atau "Sensitif" untuk lebih cepat memberi respons terhadap perubahan kecil.
            </p>

            <h4 class="text-lg font-semibold mb-2 text-blue-200 mt-4">Notifikasi WhatsApp dengan CallMeBot</h4>
            <p>
                Sistem ini mendukung pengiriman notifikasi melalui WhatsApp menggunakan layanan <a href="https://www.callmebot.com/" target="_blank" class="text-blue-400 underline">CallMeBot</a>.
                Untuk mengaktifkan fitur ini, Anda perlu mendapatkan API key dari CallMeBot dan memasukkannya ke dalam sistem.
            </p>
            <h4 class="text-lg font-semibold mb-2 text-blue-200">Langkah-langkah:</h4>
            <ol class="list-decimal pl-6 space-y-2">
                <li>Kunjungi <a href="https://www.callmebot.com/" target="_blank" class="text-blue-400 underline">situs CallMeBot</a>.</li>
                <li>Pada halaman utama, klik menu <strong>"Free WhatsApp API"</strong>.</li>
                <li>Pilih opsi <strong>"Send WhatsApp Messages"</strong> untuk melanjutkan.</li>
                <li>Buka aplikasi WhatsApp Anda dan kirim pesan ke nomor yang diberikan oleh CallMeBot dengan teks berikut:
                    <code>I allow callmebot to send me messages</code>.
                </li>
                <li>Ikuti instruksi yang diberikan di situs CallMeBot untuk memastikan proses berjalan dengan lancar.</li>
                <li>Tunggu beberapa saat hingga Anda menerima balasan otomatis dari CallMeBot yang berisi API Key unik Anda.</li>
                <li>Salin API Key tersebut dan masukkan ke dalam form di bawah ini untuk mengaktifkan notifikasi WhatsApp.</li>
            </ol>

            <!-- Form Input API Key -->
            <div class="bg-gray-700 p-4 rounded-lg mt-6">
                <h4 class="text-lg font-semibold mb-2 text-blue-200">
                    <i class="fas fa-key mr-2"></i> Status Notifikasi WhatsApp
                </h4>
                <p class="text-sm text-gray-300 mb-4">
                    <?php if (!empty($current_apikey)): ?>
                        Notifikasi WhatsApp saat ini <span class="text-green-400 font-bold">aktif</span>.
                    <?php else: ?>
                        Notifikasi WhatsApp saat ini <span class="text-red-400 font-bold">nonaktif</span>.
                    <?php endif; ?>
                </p>

                <form action="save_apikey.php" method="POST">
                    <label for="apikey" class="block text-sm font-medium text-gray-300 mb-2">API Key:</label>
                    <input type="text" id="apikey" name="apikey" value="<?= htmlspecialchars($current_apikey) ?>"
                           class="w-full p-2 rounded-lg bg-gray-800 text-gray-300 border border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-400"
                           placeholder="Masukkan API Key Anda">
                    <div class="mt-4">
                        <?php if ($current_apikey): ?>
                            <button type="submit" name="action" value="deactivate"
                                    class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-all duration-300">
                                Nonaktifkan Notifikasi WhatsApp
                            </button>
                        <?php else: ?>
                            <button type="submit" name="action" value="activate"
                                    class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all duration-300">
                                Aktifkan Notifikasi WhatsApp
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

            </div>
        </div>
    </div>

    <script>
        // Smooth scrolling for anchor links with offset adjustment
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();

                const target = document.querySelector(this.getAttribute('href'));
                const offset = 100; // Adjust this value based on your fixed header height
                const targetPosition = target.offsetTop - offset;

                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });

                // Update active state in sidebar
                document.querySelectorAll('.sidebar-item').forEach(item => {
                    item.classList.remove('active');
                });
                this.classList.add('active');
            });
        });

        // Set active sidebar item based on scroll position
        window.addEventListener('scroll', function() {
            const sections = document.querySelectorAll('section');
            let currentSection = '';

            sections.forEach(section => {
                const sectionTop = section.offsetTop - 110; // Adjust this value to match the offset
                const sectionHeight = section.clientHeight;

                if (pageYOffset >= sectionTop && pageYOffset < sectionTop + sectionHeight) {
                    currentSection = section.getAttribute('id');
                }
            });

            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.classList.remove('active');
                if (item.getAttribute('href') === `#${currentSection}`) {
                    item.classList.add('active');
                }
            });
        });
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