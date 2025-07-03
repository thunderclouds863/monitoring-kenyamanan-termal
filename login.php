<?php
session_start();
include 'db.php';

$error = "";
$messageType = "error";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE email=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        if ($user['is_verified'] == 0) {
            $verificationLink = "resend_verification.php?email=" . urlencode($email);
            $error = "Akun Anda belum diverifikasi! <a href='$verificationLink'>Kirim ulang email verifikasi</a>";
        } elseif (password_verify($password, $user['password'])) {
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];

            // Buat token dan simpan di cookie & database
            $token = bin2hex(random_bytes(32));
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), "/"); // 30 hari

            $update = $conn->prepare("UPDATE users SET remember_token=? WHERE id=?");
            $update->bind_param("si", $token, $user['id']);
            $update->execute();

            header("Location: index.php");
            exit;
        } else {
            $error = "Password salah!";
        }
    } else {
        $error = "Email tidak ditemukan!";
    }
}

// Notifikasi tambahan
if (isset($_GET['verified']) && $_GET['verified'] == 'true') {
    $error = "Akun Anda telah diverifikasi. Login sekarang.";
    $messageType = "success";
}
if (isset($_GET['verification_sent']) && $_GET['verification_sent'] == 'true') {
    $error = "Email verifikasi telah dikirim ke " . htmlspecialchars($_GET['email']) . ".";
    $messageType = "success";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            position: relative;
            animation: backgroundAnimation 10s infinite alternate ease-in-out;
        }

        @keyframes backgroundAnimation {
            0% { background-color: #0D1B2A; }
            50% { background-color: #243B55; }
            100% { background-color: #0D1B2A; }
        }

        /* Efek hujan */
        .rain {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            overflow: hidden;
        }

        .drop {
            position: absolute;
            width: 2px;
            height: 15px;
            background: rgba(173, 216, 230, 0.6);
            opacity: 0.7;
            animation: fall linear infinite;
        }

        @keyframes fall {
            from {
                transform: translateY(-100%);
                opacity: 0.7;
            }
            to {
                transform: translateY(100vh);
                opacity: 0;
            }
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0px 0px 15px rgba(255, 255, 255, 0.2);
            text-align: center;
            position: relative;
            z-index: 10;
            animation: fadeIn 1.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .typing-title {
            font-size: 22px;
            font-weight: bold;
            color: white;
            min-height: 60px;
            overflow: hidden;
            display: block;
            white-space: normal;
            word-wrap: break-word;
        }

        .input-group {
            position: relative;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            color: #B0C4DE;
            font-size: 18px;
        }

        input {
            width: 100%;
            padding: 12px 40px;
            background: transparent;
            border: none;
            border-bottom: 2px solid #B0C4DE;
            color: white;
            font-size: 16px;
            outline: none;
            transition: all 0.3s ease-in-out;
        }

        input:focus {
            border-bottom: 2px solid rgb(36, 37, 39);
            box-shadow: 0px 5px 15px rgba(119, 122, 129, 0.42);
        }

        label {
            position: absolute;
            left: 45px;
            top: 12px;
            color: #B0C4DE;
            font-size: 16px;
            transition: all 0.3s ease-in-out;
        }

        input:focus + label, input:valid + label {
            top: -10px;
            font-size: 14px;
            color: rgb(14, 20, 39);
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(50deg, rgb(21, 36, 58), #E0E1DD);
            border: none;
            border-radius: 8px;
            color: black;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        button:hover {
            background: linear-gradient(0deg, rgb(21, 36, 58), #E0E1DD);
            transform: scale(1.05);
        }

        .forgot-password {
            margin-top: 10px;
            color: #E0E1DD;
            cursor: pointer;
            display: inline-block;
            animation: blink 1.5s infinite alternate;
        }
        .forgot-password a {
            text-decoration: none;
            color: #E0E1DD;
            transition: color 0.3s ease-in-out;
        }

        .forgot-password a:hover {
            color: #B0C4DE;
            text-shadow: 0px 0px 5px rgba(255, 255, 255, 0.8);
        }

        .register-link {
            font-weight: bold;
            text-decoration: none;
            background: linear-gradient(135deg,rgb(194, 196, 202),rgb(102, 121, 158));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            transition: all 0.3s ease-in-out;
        }

        .register-link:hover {
            text-shadow: 0px 0px 10px rgba(24, 29, 58, 0.8);
            transform: scale(1.1);
        }

        .icon {
            position: absolute;
            font-size: 40px;
            opacity: 0.6;
            color: white;
            animation: float 10s infinite ease-in-out, rotate 20s infinite linear;
        }

        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-40px); }
            100% { transform: translateY(0); }
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-message {
            background-color: rgba(255, 0, 0, 0.1);
            color: red;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: center;
            box-shadow: 0px 0px 10px rgba(255, 0, 0, 0.2);
        }

        .success-message {
            background-color: rgba(0, 255, 0, 0.1);
            color: green;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: center;
            box-shadow: 0px 0px 10px rgba(0, 255, 0, 0.2);
        }

        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: center;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
        }

        .message.error {
            background-color: rgba(255, 0, 0, 0.1);
            color: red;
            box-shadow: 0px 0px 10px rgba(255, 0, 0, 0.2);
        }

        .message.success {
            background-color: rgba(0, 255, 0, 0.1);
            color: green;
            box-shadow: 0px 0px 10px rgba(0, 255, 0, 0.2);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2 class="typing-title"></h2>
        <!-- Notifikasi Pesan -->
        <?php if (!empty($error)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        <form action="" method="POST">
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="email" name="email" required>
                <label>Email</label>
            </div>
        <div class="input-group">
            <i class="fas fa-lock"></i>
            <input type="password" name="password" id="password" required>
            <label>Password</label>
            <i class="fas fa-eye" id="togglePassword" style="position: absolute; margin-left: 17.5rem; right: 15px; cursor: pointer;"></i>
        </div>
            <button type="submit"><i class="fas fa-sign-in-alt"></i> Login</button>
        </form>
        <p><span class="forgot-password"><a href="password_recovery.php" class="forgot-password">Lupa Password?</a></span></p>
        <p>Belum punya akun? <a href="register.php" class="register-link">Register</a></p>
    </div>
    <script>
        const titles = ["Sistem Monitoring Kenyamanan Termal", "Masuk ke Akunmu"];
        let index = 0;
        let charIndex = 0;
        let isDeleting = false;
        const speed = 100;
        const delay = 2000;

        function typeEffect() {
            const titleElement = document.querySelector(".typing-title");
            const currentTitle = titles[index];
            if (!isDeleting) {
                titleElement.innerHTML = currentTitle.substring(0, charIndex) + "<span class='cursor'>|</span>";
                charIndex++;
                if (charIndex > currentTitle.length) {
                    isDeleting = true;
                    setTimeout(typeEffect, delay);
                    return;
                }
            } else {
                charIndex--;
                if (charIndex < 0) {
                    isDeleting = false;
                    index = (index + 1) % titles.length;
                }
            }
            setTimeout(typeEffect, isDeleting ? speed / 2 : speed);
        }

        document.addEventListener("DOMContentLoaded", typeEffect);

        function createRain() {
        const rainContainer = document.createElement("div");
        rainContainer.classList.add("rain");
        document.body.appendChild(rainContainer);

        const numberOfDrops = 100; // Jumlah tetesan hujan
        for (let i = 0; i < numberOfDrops; i++) {
            const drop = document.createElement("div");
            drop.classList.add("drop");
            drop.style.left = Math.random() * 100 + "vw";
            drop.style.animationDuration = (Math.random() * 2 + 1) + "s";
            drop.style.animationDelay = Math.random() * 2 + "s";
            rainContainer.appendChild(drop);
        }
    }

    document.addEventListener("DOMContentLoaded", createRain);

    document.addEventListener("DOMContentLoaded", function () {
    const togglePassword = document.getElementById("togglePassword");
    const passwordInput = document.getElementById("password");
    
    // Periksa apakah elemen ada sebelum menambahkan event listener
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener("click", function () {
            if (passwordInput.type === "password") {
                passwordInput.type = "text";
                togglePassword.classList.remove("fa-eye");
                togglePassword.classList.add("fa-eye-slash");
            } else {
                passwordInput.type = "password";
                togglePassword.classList.remove("fa-eye-slash");
                togglePassword.classList.add("fa-eye");
            }
        });
    }
});
    </script>
</body>
</html>
