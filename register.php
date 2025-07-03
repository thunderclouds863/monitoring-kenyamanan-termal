<?php
session_start();
include 'db.php';
require 'vendor/autoload.php'; // Include Composer's autoloader
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Handle AJAX requests for email and phone validation
if (isset($_POST['action']) && $_POST['action'] === 'check_email_phone') {
    $response = ['email_exists' => false, 'phone_exists' => false];

    if (!empty($_POST['email'])) {
        $email = $_POST['email'];
        $sql_check_email = "SELECT * FROM users WHERE email='$email'";
        $result_check_email = $conn->query($sql_check_email);
        if ($result_check_email->num_rows > 0) {
            $response['email_exists'] = true;
        }
    }

    if (!empty($_POST['phone'])) {
        $phone = $_POST['phone'];
        $sql_check_phone = "SELECT * FROM users WHERE phone='$phone'";
        $result_check_phone = $conn->query($sql_check_phone);
        if ($result_check_phone->num_rows > 0) {
            $response['phone_exists'] = true;
        }
    }

    echo json_encode($response);
    exit;
}

$errors = []; // Array untuk menyimpan pesan error

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validasi username sudah digunakan
    $sql_check_username = "SELECT * FROM users WHERE username='$username'";
    $result_check_username = $conn->query($sql_check_username);
    if ($result_check_username->num_rows > 0) {
        $errors['username'] = "Username sudah digunakan!";
    }

    // Validasi format nomor telepon
    if (!preg_match('/^\62[0-9]{9,13}$/', $phone)) {
        $errors['phone'] = "Nomor telepon harus dimulai dengan 62 (tanpa +) dan memiliki panjang 9-13 digit!";
    }

    // Validasi konfirmasi password
    if ($password !== $confirm_password) {
        $errors['confirm_password'] = "Password dan Konfirmasi Password tidak cocok!";
    }

    // Validasi email sudah digunakan
    $sql_check_email = "SELECT * FROM users WHERE email='$email'";
    $result_check_email = $conn->query($sql_check_email);
    if ($result_check_email->num_rows > 0) {
        $errors['email'] = "Email sudah digunakan!";
    }

    // Validasi nomor telepon sudah digunakan
    $sql_check_phone = "SELECT * FROM users WHERE phone='$phone'";
    $result_check_phone = $conn->query($sql_check_phone);
    if ($result_check_phone->num_rows > 0) {
        $errors['phone'] = "Nomor telepon sudah digunakan!";
    }

    // Jika tidak ada error, simpan data ke database
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $otp = rand(100000, 999999); // Generate OTP
        $sql = "INSERT INTO users (username, email, phone, password, otp, is_verified)
                VALUES ('$username', '$email', '$phone', '$hashed_password', '$otp', 0)";
        if ($conn->query($sql) === TRUE) {
            // Kirim OTP langsung dari register.php
            $_SESSION['email'] = $email;
            $emailSubject = "Account Verification OTP";
            $emailBody = "Your OTP code is: <b>$otp</b><br>This code will expire in 2 minutes.";

            if (sendEmail($email, $emailBody, $emailSubject)) {
                error_log("OTP sent to $email during registration.");
                header("Location: verify_otp.php");
                exit;
            } else {
                $errors['general'] = "Failed to send OTP. Please try again.";
                error_log("Failed to send OTP to $email during registration.");
            }
        } else {
            $errors['general'] = "Error: " . $conn->error;
        }
    }
}

function sendEmail($email, $body, $subject = "Notification") {
    $mail = new PHPMailer(true);

    try {
            // Server settings
        $mail->isSMTP();
        $mail->Host = 'mail.monitoring-kenyamanan-termal.my.id';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@monitoring-kenyamanan-termal.my.id';
        $mail->Password = 'Sr*MpO+){2m+'; // Password langsung di kode
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->CharSet = 'UTF-8';

        // Set email details
        $mail->setFrom('noreply@monitoring-kenyamanan-termal.my.id', 'Account Verification');
        $mail->addAddress($email);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = nl2br($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
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
            background-color: #0D1B2A;
            animation: backgroundAnimation 10s infinite alternate ease-in-out;
           overflow-y: auto; /* Tambahkan scroll jika konten terlalu panjang */
        }
        /* Kontainer angin */
        .wind-container {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        /* Ikon angin */
        .wind {
            position: absolute;
            font-size: 60px;
            color: rgba(255, 255, 255, 0.6);
            animation: wind-move linear infinite;
        }

        /* Animasi angin */
        @keyframes wind-move {
            from {
                transform: translateX(-10vw);
                opacity: 0;
            }
            to {
                transform: translateX(110vw);
                opacity: 1;
            }
        }

        /* Variasi posisi dan durasi */
        .wind:nth-child(1) { top: 10%; animation-duration: 8s; }
        .wind:nth-child(2) { top: 30%; animation-duration: 10s; }
        .wind:nth-child(3) { top: 50%; animation-duration: 12s; }
        .wind:nth-child(4) { top: 70%; animation-duration: 14s; }
        .wind:nth-child(5) { top: 90%; animation-duration: 16s; }

        @keyframes backgroundAnimation {
            0% { background-color: #0D1B2A; }
            50% { background-color: #243B55; }
            100% { background-color: #0D1B2A; }
        }

        .register-container {
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

        @keyframes typing {
            0% { width: 0; }
            50% { width: 100%; }
            100% { width: 0; }
        }

        @keyframes blink {
            50% { border-color: transparent; }
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

        .login-link {
            font-weight: bold;
            text-decoration: none;
            background: linear-gradient(135deg, rgb(194, 196, 202), rgb(102, 121, 158));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            transition: all 0.3s ease-in-out;
        }

        .login-link:hover {
            text-shadow: 0px 0px 10px rgba(24, 29, 58, 0.8);
            transform: scale(1.1);
        }

        .floating-icons {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
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
            display: flex;
            align-items: flex-start;
            margin-bottom: 25px;
            position: relative;
        }

        .input-group i {
            font-size: 18px;
            color: #B0C4DE;
            margin-right: 10px;
            flex-shrink: 0;
            margin-top: 12px; /* Align icon with input */
        }

        .input-wrapper {
            flex: 1;
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            padding: 12px 10px;
            background: transparent;
            border: none;
            border-bottom: 2px solid #B0C4DE;
            color: white;
            font-size: 16px;
            outline: none;
            transition: all 0.3s ease-in-out;
        }

        .input-wrapper input:focus {
            border-bottom: 2px solid rgb(36, 37, 39);
            box-shadow: 0px 5px 15px rgba(119, 122, 129, 0.42);
        }

        .input-wrapper label {
            position: absolute;
            left: 10px;
            top: 12px;
            color: #B0C4DE;
            font-size: 16px;
            transition: all 0.3s ease-in-out;
        }

        .input-wrapper input:focus + label,
        .input-wrapper input:valid + label {
            top: -10px;
            font-size: 14px;
            color: rgb(14, 20, 39);
        }

        .error-message {
            color: red;
            font-size: 12px;
            margin-top: 5px;
            background-color: rgba(255, 0, 0, 0.1);
            padding: 5px;
            border-radius: 5px;
            text-align: left;
            box-shadow: 0px 0px 10px rgba(255, 0, 0, 0.2);
        }

        .input-wrapper.error .error-message {
            display: block; /* Show error message when input has error */
        }

        @media (max-width: 768px) {
            .input-group {
                flex-direction: column;
                align-items: flex-start;
            }

            .input-group i {
                margin-bottom: 5px;
                margin-top: 0;
            }

            .error-message {
                position: static;
                margin-top: 5px;
            }
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
        .error-message {
            color: red;
            font-size: 12px;
            margin-top: 5px;
            background-color: rgba(255, 0, 0, 0.1);
            padding: 5px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="floating-icons">
        <i class="fas fa-wind wind"></i>
        <i class="fas fa-wind wind"></i>
        <i class="fas fa-wind wind"></i>
        <i class="fas fa-wind wind"></i>
        <i class="fas fa-wind wind"></i>
    </div>

    <div class="register-container">
        <h2 class="typing-title"></h2>
        <form action="" method="POST">
            <div class="input-group">
                <i class="fas fa-user"></i>
                <div class="input-wrapper">
                    <input type="text" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    <label>Username</label>
                    <?php if (!empty($errors['username'])): ?>
                        <div class="error-message"><?php echo $errors['username']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="input-group">
                <i class="fas fa-envelope"></i>
                <div class="input-wrapper">
                    <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    <label>Email</label>
                    <?php if (!empty($errors['email'])): ?>
                        <div class="error-message"><?php echo $errors['email']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="input-group">
                <i class="fas fa-phone"></i>
                <div class="input-wrapper">
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                    <label>Phone Number</label>
                    <?php if (!empty($errors['phone'])): ?>
                        <div class="error-message"><?php echo $errors['phone']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <div class="input-wrapper">
                    <input type="password" name="password" required>
                    <label>Password</label>
                    <?php if (!empty($errors['password'])): ?>
                        <div class="error-message"><?php echo $errors['password']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="input-group">
                <i class="fas fa-key"></i>
                <div class="input-wrapper">
                    <input type="password" name="confirm_password" required>
                    <label>Konfirmasi Password</label>
                    <?php if (!empty($errors['confirm_password'])): ?>
                        <div class="error-message"><?php echo $errors['confirm_password']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <button type="submit"><i class="fas fa-sign-in-alt"></i> Register</button>
        </form>
        <p>Sudah punya akun? <a href="login.php" class="login-link">Login</a></p>
    </div>
    <script>
        const titles = ["Sistem Monitoring Kenyamanan Termal", "Daftarkan Akun Barumu Sekarang"];
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
    </script>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const form = document.querySelector("form");
        const usernameInput = document.querySelector("input[name='username']");
        const emailInput = document.querySelector("input[name='email']");
        const phoneInput = document.querySelector("input[name='phone']");
        const passwordInput = document.querySelector("input[name='password']");
        const confirmPasswordInput = document.querySelector("input[name='confirm_password']");

        form.addEventListener("submit", function (e) {
            let isValid = true;

            // Clear previous error messages
            document.querySelectorAll(".input-wrapper").forEach(wrapper => wrapper.classList.remove("error"));

            // Validate username
            if (usernameInput.value.trim() === "") {
                showError(usernameInput, "Username tidak boleh kosong!");
                isValid = false;
            }

            // Validate email
            if (!validateEmail(emailInput.value)) {
                showError(emailInput, "Format email tidak valid!");
                isValid = false;
            }

            // Validate phone number
            if (!/^\+62[0-9]{9,13}$/.test(phoneInput.value)) {
                showError(phoneInput, "Nomor telepon harus dimulai dengan 62 (tanpa +) dan memiliki panjang 9-13 digit!");
                isValid = false;
            }

            // Validate password
            if (!validatePassword(passwordInput.value)) {
                showError(passwordInput, "Password harus memiliki huruf kapital, huruf kecil, angka, dan simbol (@, _).");
                isValid = false;
            }

            // Validate confirm password
            if (passwordInput.value !== confirmPasswordInput.value) {
                showError(confirmPasswordInput, "Password dan Konfirmasi Password tidak cocok!");
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault(); // Prevent form submission
            }
        });

        function showError(input, message) {
            const wrapper = input.parentElement;
            let errorMessage = wrapper.querySelector(".error-message");

            if (!errorMessage) {
                // Jika elemen error-message belum ada, buat elemen baru
                errorMessage = document.createElement("div");
                errorMessage.className = "error-message";
                wrapper.appendChild(errorMessage);
            }

            // Perbarui teks pesan error
            errorMessage.textContent = message;
        }

        function clearError(input) {
            const wrapper = input.parentElement;
            const errorMessage = wrapper.querySelector(".error-message");

            if (errorMessage) {
                errorMessage.remove(); // Hapus elemen error-message jika ada
            }
        }

        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        function validatePassword(password) {
            const re = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@_])[A-Za-z\d@_]{8,}$/;
            return re.test(password);
        }
    });
</script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const emailInput = document.querySelector("input[name='email']");
        const phoneInput = document.querySelector("input[name='phone']");

        emailInput.addEventListener("blur", function () {
            checkEmailPhone({ email: emailInput.value });
        });

        phoneInput.addEventListener("blur", function () {
            checkEmailPhone({ phone: phoneInput.value });
        });

        function checkEmailPhone(data) {
            fetch("", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: new URLSearchParams({
                    action: "check_email_phone",
                    ...data,
                }),
            })
                .then((response) => response.json())
                .then((result) => {
                   if (result.email_exists) {
                        showError(emailInput, "Email sudah digunakan!");
                    } else {
                        clearError(emailInput);
                    }

                    if (result.phone_exists) {
                        showError(phoneInput, "Nomor telepon sudah digunakan!");
                    } else {
                        clearError(phoneInput);
                    }
                })
                .catch((error) => console.error("Error:", error));
        }

        function showError(input, message) {
            const wrapper = input.parentElement;
            let errorMessage = wrapper.querySelector(".error-message");

            if (!errorMessage) {
                // Jika elemen error-message belum ada, buat elemen baru
                errorMessage = document.createElement("div");
                errorMessage.className = "error-message";
                wrapper.appendChild(errorMessage);
            }

            // Perbarui teks pesan error
            errorMessage.textContent = message;
        }

        function clearError(input) {
            const wrapper = input.parentElement;
            const errorMessage = wrapper.querySelector(".error-message");

            if (errorMessage) {
                errorMessage.remove(); // Hapus elemen error-message jika ada
            }
        }
    });
</script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const form = document.querySelector("form");
        const emailInput = document.querySelector("input[name='email']");
        const phoneInput = document.querySelector("input[name='phone']");

        let isEmailValid = true; // Global flag for email validation
        let isPhoneValid = true; // Global flag for phone validation

        emailInput.addEventListener("blur", function () {
            checkEmailPhone({ email: emailInput.value }, "email");
        });

        phoneInput.addEventListener("blur", function () {
            checkEmailPhone({ phone: phoneInput.value }, "phone");
        });
            // Prevent form submission if email or phone validation fails
            if (!isEmailValid || !isPhoneValid) {
                e.preventDefault();
                alert("Periksa kembali email atau nomor telepon Anda!");
            }
        });

        function checkEmailPhone(data, type) {
            fetch("", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body: new URLSearchParams({
                    action: "check_email_phone",
                    ...data,
                }),
            })
                .then((response) => response.json())
                .then((result) => {
                    console.log(result); // Debugging: Periksa respons dari server
                    if (type === "email") {
                        if (result.email_exists) {
                            showError(emailInput, "Email sudah digunakan!");
                            isEmailValid = false;
                        } else {
                            clearError(emailInput);
                            isEmailValid = true;
                        }
                    }

                    if (type === "phone") {
                        if (result.phone_exists) {
                            showError(phoneInput, "Nomor telepon sudah digunakan!");
                            isPhoneValid = false;
                        } else {
                            clearError(phoneInput);
                            isPhoneValid = true;
                        }
                    }
                })
                .catch((error) => console.error("Error:", error));
        }

        function showError(input, message) {
            const wrapper = input.parentElement;
            let errorMessage = wrapper.querySelector(".error-message");

            if (!errorMessage) {
                // Jika elemen error-message belum ada, buat elemen baru
                errorMessage = document.createElement("div");
                errorMessage.className = "error-message";
                wrapper.appendChild(errorMessage);
            }

            // Perbarui teks pesan error
            errorMessage.textContent = message;
        }

        function clearError(input) {
            const wrapper = input.parentElement;
            const errorMessage = wrapper.querySelector(".error-message");

            if (errorMessage) {
                errorMessage.remove(); // Hapus elemen error-message jika ada
            }
        }
    });
</script>
</body>
</html>
