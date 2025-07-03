<?php
include 'db.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Ambil user_id dari session
$user_id = $_SESSION['user_id'];

// Ambil data user dari database berdasarkan sesi user_id
$query = $conn->prepare("SELECT username, email, phone, profile_pic FROM users WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();
$user = $result->fetch_assoc();

// Default gambar profil jika user belum mengunggah foto
$profile_pic = $user['profile_pic'] ? 'data:image/jpeg;base64,' . base64_encode($user['profile_pic']) : 'default-profile.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Navbar & Sidebar</title>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #0d1117 !important; color: white !important; }
        .chart-container { position: relative; width: 100%; max-width: 100%; height: auto; aspect-ratio: 16 / 9; min-height: 300px; }
        .timeframe-card {
            padding: 12px 20px; border-radius: 10px; background: #21262d;
            color: white; cursor: pointer; transition: 0.3s; text-align: center;
            font-weight: bold; box-shadow: 0px 3px 10px rgba(0, 0, 0, 0.2);
        }
        .timeframe-card:hover, .timeframe-card.active { background: #30363d; transform: scale(1.05); }
        .alert { background: red; color: white; padding: 10px; margin-top: 10px; border-radius: 5px; display: none; }
        .dark-mode { background-color: cbd5e1; color: #333; }
        .dark-mode .bg-gray-900 { background: #ffffff; color: black; }
        .dark-mode .bg-gray-800 { background: #e2e8f0; color: black; }
        .dark-mode .timeframe-card { background: #e2e8f0; color: black; }
        .dark-mode .timeframe-card:hover, .dark-mode .timeframe-card.active { background: #cbd5e1; }
        .navbar { transition: background 0.3s !important; }
        .navbar.scrolled { background: rgba(13, 17, 23, 0.8) !important; }
        .dark-mode {
    background-color: #cbd5e1 !important;
    color: #333 !important;
}

.dark-mode .table-dark th,
.dark-mode .table-dark td {
    color: black !important; /* Pastikan teks tabel terlihat */
}

.dark-mode .btn {
    color: white !important; /* Warna teks tombol */
    background-color: #333 !important; /* Warna latar tombol */
    border-color: #333 !important;
}

.dark-mode .btn:hover {
    background-color: #555 !important; /* Warna tombol saat hover */
    border-color: #555 !important;
}
        /* Sidebar Styles */
        #sidebar {
            position: fixed !important;
            top: 0 !important;
            left: -250px !important;
            width: 250px !important;
            height: 100% !important;
            background: rgba(33, 38, 45, 0.9) !important;
            transition: left 0.3s !important;
            z-index: 1000 !important;
        }
        #sidebar.active {
            left: 0 !important;
        }
        #sidebar .nav-links {
    padding: 20px;
}

#sidebar .nav-links a {
    display: block !important;
    padding: 10px 0 !important;
    text-decoration: none !important;
    transition: color 0.3s !important, text-shadow 0.3s !important;
}

/* Hover untuk semua link kecuali Logout */
#sidebar .nav-links a:hover {
    color: #4a90e2 !important;
}

/* Logout Style */
#sidebar .nav-links a.logout {
    color: #e74c3c !important; /* Merah */
}
        /* Nonaktifkan interaksi tombol Logout secara default */
        .logout-disabled {
            pointer-events: none;
            opacity: 0.5;
        }
        /* Aktifkan interaksi tombol Logout ketika dropdown aktif */
        .logout-enabled {
            pointer-events: auto;
            opacity: 1;
        }


#sidebar .nav-links a.logout:hover {
    color: #ff6b6b !important; /* Lebih terang */
    text-shadow: 0 0 8px rgba(255, 107, 107, 0.8) !important; /* Shadow merah menyala */
}

    /* Animasi dropdown */
    .dropdown-content {
        opacity: 0 !important;
        transform: scale(0.95) !important;
        transform-origin: top right !important;
        transition: opacity 0.3s ease-out !important, transform 0.3s ease-out !important;
    }

    .dropdown-content.active {
        opacity: 1 !important;
        transform: scale(1) !important;
    }

    /* Efek hover pada tombol dan link */
    #profileBtn:hover svg {
        transform: rotate(180deg);
    }

    #profileDropdown a:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    /* Efek hover pada gambar profil */
    img:hover {
        filter: brightness(1.1);
    }

    .dropdown-menu {
        display: none !important;
    }

    .dropdown-menu.show {
        display: block !important;
    }

    /* Pastikan ini ada di style Anda */
.dropdown-content {
    pointer-events: none; /* Nonaktifkan interaksi saat dropdown tertutup */
}

.dropdown-content.active {
    pointer-events: auto; /* Aktifkan interaksi saat dropdown terbuka */
}

#profileDropdown a {
    opacity: 0.5;
    pointer-events: none;
}

#profileDropdown.active a {
    opacity: 1;
    pointer-events: auto;
}

    </style>
</head>
<body>
    <!-- Navbar -->
    <nav id="navbar" class="navbar w-full bg-gray-900 text-white p-4 flex justify-between items-center shadow-lg fixed top-0 left-0 z-50">
        <h1 class="text-2xl font-bold">📡 Thermal Comfort Monitoring</h1>
        <button id="menuToggle" class="md:hidden text-2xl">☰</button>
        <div id="navLinks" class="hidden md:flex space-x-6 items-center">
            <a href="index.php" class="hover:text-blue-400">🏠 Home</a>
            <a href="history.php" class="hover:text-blue-400">📜 Historical Data</a>
            <a href="settings.php" class="hover:text-blue-400">⚙️ Settings</a>
            <a href="about.php" class="hover:text-blue-400">ℹ️ Help</a>

            <!-- Profile Dropdown -->
            <div class="relative">
                <button id="profileBtn" class="flex items-center space-x-2 px-3 py-2 rounded-md transition-all duration-300 transform hover:scale-105">
                    <?php if (!empty($user['profile_pic'])): ?>
                        <img src="<?= htmlspecialchars($user['profile_pic']) ?>" 
                            class="w-12 h-12 rounded-full object-cover" 
                            alt="Profile">
                    <?php else: ?>
                        <div class="w-12 h-12 rounded-full bg-gray-600 flex items-center justify-center">
                            <i class="fas fa-user text-xl"></i>
                        </div>
                    <?php endif; ?>
                    <span class="font-medium"><?php echo $user['username']; ?></span>
                    <svg class="w-4 h-4 text-gray-400 transform transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20" id="dropdownArrow">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>

                <!-- Profile Dropdown Content -->
                <div id="profileDropdown" class="dropdown-content absolute right-0 mt-2 w-56 bg-gray-800 rounded-lg shadow-2xl overflow-hidden transform opacity-0 scale-95 origin-top-right transition-all duration-300 ease-out pointer-events-none">
                    <!-- Profile Section -->
                    <div class="p-4 text-center border-b border-gray-700">
                        <?php if (!empty($user['profile_pic'])): ?>
                            <img src="<?= htmlspecialchars($user['profile_pic']) ?>" 
                                 class="w-12 h-12 rounded-full object-cover" 
                                 alt="Profile">
                        <?php else: ?>
                            <div class="w-16 h-16 rounded-full bg-gray-600 flex items-center justify-center mx-auto border-2 border-blue-400 hover:border-blue-500 transition-all duration-300">
                                <i class="fas fa-user text-white text-2xl"></i>
                            </div>
                        <?php endif; ?>
                        <p class="font-bold mt-2"><?php echo $user['username']; ?></p>
                        <p class="text-gray-400 text-sm"><?php echo $user['email']; ?></p>
                        <p class="text-gray-400 text-sm"><?php echo $user['phone']; ?></p>
                    </div>

                    <!-- Logout Section -->
                    <div class="p-2 bg-gray-750">
                        <a href="logout.php" class="flex items-center justify-center px-4 py-2 text-red-400 hover:bg-gray-700 hover:text-red-500 transition-all duration-300 opacity-50 pointer-events-none">
                            <span>Logout</span>
                            <svg class="w-5 h-5 ml-2 text-red-400 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1m0-13V4m0 8H3m14 0H7" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>

            <button id="toggleMode" class="px-3 py-1 rounded-md transition-all duration-300 transform hover:scale-105">🌙</button>
        </div>
    </nav>

    <!-- Sidebar -->
    <div id="sidebar">
        <div class="nav-links p-4">
            <a href="index.php" class="hover:text-blue-400">🏠 Beranda</a>
            <a href="history.php" class="hover:text-blue-400">📜 Data Historis</a>
            <a href="settings.php" class="hover:text-blue-400">⚙️ Pengaturan</a>
            <a href="about.php" class="hover:text-blue-400">ℹ️ Bantuan</a>
            <a href="logout.php" class="logout text-red-400 hover:text-red-500">Logout</a>
            <button id="toggleModeSidebar" class="px-3 py-1 rounded-md mt-4">🌙</button>
        </div>
    </div>

    <!-- Overlay -->
    <div id="overlay"></div>

    <script>
        const toggleModeBtn = document.getElementById("toggleMode");
        const toggleModeSidebar = document.getElementById("toggleModeSidebar");
        const body = document.body;
        const navbar = document.getElementById("navbar");
        const sidebar = document.getElementById("sidebar");
        const overlay = document.getElementById("overlay");
        const profileBtn = document.getElementById("profileBtn");
        const profileDropdown = document.getElementById("profileDropdown");
        const dropdownArrow = document.getElementById("dropdownArrow");

        document.getElementById("menuToggle").addEventListener("click", () => {
            sidebar.classList.toggle("active");
            overlay.classList.toggle("active");
        });

        overlay.addEventListener("click", () => {
            sidebar.classList.remove("active");
            overlay.classList.remove("active");
        });

        toggleModeBtn.addEventListener("click", () => {
            body.classList.toggle("dark-mode");
            toggleModeBtn.textContent = body.classList.contains("dark-mode") ? "☀️" : "🌙";
        });

        toggleModeSidebar.addEventListener("click", () => {
            body.classList.toggle("dark-mode");
            toggleModeSidebar.textContent = body.classList.contains("dark-mode") ? "☀️" : "🌙";
        });

        // Initialize dropdown state
        profileDropdown.style.display = 'none';
        profileDropdown.style.opacity = '0';
        profileDropdown.style.pointerEvents = 'none';

        profileBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            const isActive = !profileDropdown.classList.contains("active");

            if (isActive) {
                profileDropdown.classList.add("active");
                dropdownArrow.classList.add("rotate-180");
                profileDropdown.style.display = 'block';
                profileDropdown.style.opacity = '1';
                profileDropdown.style.pointerEvents = 'auto';
                document.querySelector("#profileDropdown a").style.opacity = '1';
            } else {
                profileDropdown.classList.remove("active");
                dropdownArrow.classList.remove("rotate-180");
                profileDropdown.style.display = 'none';
                profileDropdown.style.opacity = '0';
                profileDropdown.style.pointerEvents = 'none';
                document.querySelector("#profileDropdown a").style.opacity = '0.5';
            }
        });

        document.addEventListener("click", (e) => {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove("active");
                dropdownArrow.classList.remove("rotate-180");
                profileDropdown.style.display = 'none';
                profileDropdown.style.opacity = '0';
                profileDropdown.style.pointerEvents = 'none';
                document.querySelector("#profileDropdown a").style.opacity = '0.5';
            }
        });

        profileDropdown.addEventListener("click", (e) => {
            e.stopPropagation();
        });

        window.addEventListener("scroll", () => {
            navbar.classList.toggle("scrolled", window.scrollY > 50);
        });
    </script>
</body>
</html>