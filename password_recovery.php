<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Start session at the very beginning
session_start();

require 'vendor/autoload.php';
include 'db.php'; // Include database connection

function sendEmail($email, $body, $subject = "Notification") {
    $mail = new PHPMailer(true);

try {
    $apiKey = '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';

    $data = [
        'sender' => [
            'name' => 'Reset Password',
            'email' => 'noreply@monitoring-kenyamanan-termal.my.id'
        ],
        'to' => [
            ['email' => $email]
        ],
        'subject' => $subject,
        'htmlContent' => nl2br($body), // ubah \n jadi <br> otomatis
        'textContent' => $body
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Content-Type: application/json',
        'accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("cURL Error: $error");
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        throw new Exception("HTTP $httpCode: $response");
    }
} catch (Exception $e) {
    error_log("Gagal kirim email reset password: " . $e->getMessage());
    return false;
}
 catch (Exception $e) {
        return false;
    }
}

// Initialize variables
$error = '';
$message = '';
$email = '';
$show_email_form = true;
$show_otp_form = false;
$show_password_form = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['send_otp'])) {
        $email = trim($_POST['email']);

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format!";
        } else {
            // Check if email exists in database
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows == 1) {
                $otp = strval(rand(100000, 999999)); // Ensure OTP is string
                $_SESSION['otp'] = $otp;
                $_SESSION['email'] = $email;
                $_SESSION['otp_expiry'] = time() + 120; // 2 minutes expiry
                $_SESSION['otp_attempts'] = 0;

                if (sendEmail($email, "Your OTP code is: <b>$otp</b>", "Reset Password OTP")) {
                    $message = "OTP has been sent to your email. It will expire in 2 minutes.";
                    $show_email_form = false;
                    $show_otp_form = true;
                } else {
                    $error = "Failed to send OTP. Please try again.";
                }
            } else {
                $error = "Email not found in our system!";
            }
            $stmt->close();
        }
    }
    elseif (isset($_POST['verify_otp'])) {
        // Gabungkan array OTP menjadi satu string
        $entered_otp = implode('', $_POST['otp']); // Menggabungkan array menjadi string

        // Debug: Check what's being compared
        error_log("Entered OTP: $entered_otp");
        error_log("Stored OTP: " . $_SESSION['otp']);

        if (!isset($_SESSION['otp'])) {
            $error = "No OTP found. Please request a new one.";
            $show_email_form = true;
            $show_otp_form = false;
        }
        elseif (time() > $_SESSION['otp_expiry']) {
            $error = "OTP has expired. Please request a new one.";
            unset($_SESSION['otp']);
            $show_email_form = true;
            $show_otp_form = false;
        }
        elseif ($entered_otp === $_SESSION['otp']) { // Strict comparison
            $_SESSION['otp_verified'] = true;
            $message = "OTP verified. You can now reset your password.";
            $show_otp_form = false;
            $show_password_form = true;
        }
        else {
            $_SESSION['otp_attempts']++;
            $remaining_attempts = 3 - $_SESSION['otp_attempts'];

            if ($_SESSION['otp_attempts'] >= 3) {
                $error = "Too many incorrect attempts. Please request a new OTP.";
                unset($_SESSION['otp']);
                $show_email_form = true;
                $show_otp_form = false;
            } else {
                $error = "Invalid OTP! You have $remaining_attempts attempts remaining.";
                $show_otp_form = true;
            }
        }
    }
    elseif (isset($_POST['resend_otp'])) {
        if (isset($_SESSION['email'])) {
            $email = $_SESSION['email'];
            $otp = strval(rand(100000, 999999)); // Ensure OTP is string
            $_SESSION['otp'] = $otp;
            $_SESSION['otp_expiry'] = time() + 120;
            $_SESSION['otp_attempts'] = 0;

            if (sendEmail($email, "Your OTP code is: <b>$otp</b>", "Reset Password OTP")) {
                $message = "A new OTP has been sent to your email. It will expire in 2 minutes.";
                $show_otp_form = true;
            } else {
                $error = "Failed to resend OTP. Please try again.";
                $show_email_form = true;
                $show_otp_form = false;
            }
        } else {
            $error = "Email not found in session. Please start over.";
            $show_email_form = true;
            $show_otp_form = false;
        }
    }
    elseif (isset($_POST['reset_password'])) {
        if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified']) {
            $new_password = trim($_POST['new_password']);
            $confirm_password = trim($_POST['confirm_password']);

            if (empty($new_password) || empty($confirm_password)) {
                $error = "Please fill in both password fields!";
                $show_password_form = true;
            }
            elseif ($new_password !== $confirm_password) {
                $error = "Passwords do not match!";
                $show_password_form = true;
            }
            elseif (strlen($new_password) < 8) {
                $error = "Password must be at least 8 characters long!";
                $show_password_form = true;
            }
            else {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $email = $_SESSION['email'];

                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashed_password, $email);

                if ($stmt->execute()) {
                    // Kirim notifikasi email
                    $subject = "Password Changed Successfully";
                    $body = "Hello,\n\nYour password has been successfully updated. If you did not make this change, please contact support immediately.\n\nThank you.";
                    sendEmail($email, $body, $subject);

                    $message = "Password has been updated successfully. Redirecting to login page...";

                    // Clear all session data
                    session_unset();
                    session_destroy();

                    // Redirect to login page after 3 seconds
                    header("Refresh: 3; url=login.php");
                    exit();
                } else {
                    $error = "Failed to update password. Please try again.";
                    $show_password_form = true;
                }
                $stmt->close();
            }
        } else {
            $error = "OTP verification required!";
            $show_email_form = true;
            $show_password_form = false;
        }
    }
}

// Determine which form to show based on session state
if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified']) {
    $show_email_form = false;
    $show_otp_form = false;
    $show_password_form = true;
} elseif (isset($_SESSION['otp'])) {
    $show_email_form = false;
    $show_otp_form = true;
    $show_password_form = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
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
            background: linear-gradient(50deg, #0D1B2A, #243B55);
        }

        .container {
            width: 100%;
            max-width: 400px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0px 0px 15px rgba(255, 255, 255, 0.2);
            text-align: center;
        }

        h2 {
            font-size: 22px;
            font-weight: bold;
            color: white;
            margin-bottom: 20px;
        }

        form {
            display: flex;
            flex-direction: column;
        }

        input {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 2px solid #B0C4DE;
            color: white;
            font-size: 16px;
            outline: none;
            margin-bottom: 20px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(50deg, rgb(21, 36, 58), #E0E1DD);
            border: none;
            border-radius: 8px;
            color: black;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-bottom: 10px;
            transition: all 0.2s;
        }

        button:hover {
            transform: scale(1.02);
        }

        .message {
            color: #B0C4DE;
            margin-bottom: 20px;
        }

        .error {
            color: #FF6B6B;
            margin-bottom: 20px;
        }

        .timer {
            color: #FF6B6B;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .password-strength {
            width: 100%;
            height: 5px;
            background: #ddd;
            margin-bottom: 20px;
            border-radius: 5px;
            overflow: hidden;
        }

        .strength-meter {
            height: 100%;
            width: 0%;
            transition: width 0.3s;
        }
        .resend-link {
        display: block;
        margin-top: 10px;
        color: #B0C4DE;
        text-decoration: none;
        font-size: 14px;
    }

    .resend-link:hover {
        text-decoration: underline;
        color: #E0E1DD;
    }
    button[name="resend_otp"]:hover {
    text-decoration: underline !important; /* Menambahkan garis bawah saat hover dengan prioritas tinggi */
}

    .otp-input-container {
        display: flex;
        justify-content: space-between;
        margin-bottom: 20px;
    }

    .otp-input {
        width: 40px;
        height: 40px;
        text-align: center;
        font-size: 18px;
        border: 2px solid #B0C4DE;
        border-radius: 5px;
        background: #ffffff; /* Tambahkan warna latar belakang */
        color: #000000; /* Ubah warna teks menjadi hitam */
        outline: none;
        transition: border-color 0.3s;
    }

    .otp-input:focus {
        border-color: #E0E1DD;
    }

    .otp-input::-webkit-inner-spin-button,
    .otp-input::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    .otp-underline-input {
        width: 40px;
        height: 40px;
        text-align: center;
        font-size: 18px;
        border: none;
        border-bottom: 2px solid #B0C4DE; /* Garis bawah */
        background: transparent;
        color: white;
        outline: none;
        margin: 0 5px; /* Jarak antar input */
    }

    .otp-underline-input:focus {
        border-bottom-color: #E0E1DD; /* Warna garis bawah saat fokus */
    }

    .otp-underline-input::-webkit-inner-spin-button,
    .otp-underline-input::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    </style>
</head>
<body>
<div class="container">
    <h2>Reset Password</h2>
    <?php if (isset($message)) echo "<p class='message'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <?php if ($show_email_form): ?>
        <!-- Email Input Form -->
        <form method="POST">
            <input type="email" name="email" placeholder="Enter your email" required value="<?php echo htmlspecialchars($email); ?>">
            <button type="submit" name="send_otp">Send OTP</button>
        </form>
    <?php endif; ?>

    <?php if ($show_otp_form): ?>
        <!-- OTP Verification Form -->
        <form method="POST" id="otpForm">
            <div class="otp-input-container">
                <input type="text" name="otp[]" maxlength="1" class="otp-underline-input" oninput="moveToNext(this)">
                <input type="text" name="otp[]" maxlength="1" class="otp-underline-input" oninput="moveToNext(this)">
                <input type="text" name="otp[]" maxlength="1" class="otp-underline-input" oninput="moveToNext(this)">
                <input type="text" name="otp[]" maxlength="1" class="otp-underline-input" oninput="moveToNext(this)">
                <input type="text" name="otp[]" maxlength="1" class="otp-underline-input" oninput="moveToNext(this)">
                <input type="text" name="otp[]" maxlength="1" class="otp-underline-input" oninput="moveToNext(this)">
            </div>
            <div class="timer" id="otpTimer">OTP expires in: 120 seconds</div>
            <button type="submit" name="verify_otp">Verify OTP</button>
        </form>
        <form method="POST" style="margin-top: 10px;">
            <button type="submit" name="resend_otp" style="background: transparent; border: none; color: #B0C4DE; text-decoration: none; font-size: 14px; cursor: pointer;">Resend OTP</button>
        </form>
        <script>
            // OTP countdown timer
            let timeLeft = 120;
            const timerElement = document.getElementById('otpTimer');

            const countdown = setInterval(() => {
                timeLeft--;
                timerElement.textContent = `OTP expires in: ${timeLeft} seconds`;

                if (timeLeft <= 0) {
                    clearInterval(countdown);
                    timerElement.textContent = "OTP has expired!";
                    timerElement.style.color = "#FF6B6B";
                }
            }, 1000);
        </script>
        <script>
    document.querySelector('.resend-link').addEventListener('click', function (e) {
        e.preventDefault(); // Prevent default link behavior

        fetch('password_recovery.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'resend_otp=true'
        })
        .then(response => response.text())
        .then(data => {
            // Update the message dynamically
            const messageElement = document.querySelector('.message');
            messageElement.textContent = "A new OTP has been sent to your email. It will expire in 2 minutes.";
            messageElement.style.color = "#B0C4DE";
        })
        .catch(error => {
            console.error('Error:', error);
        });
    });
</script>
<script>
    function moveToNext(current) {
        if (current.value.length === 1) {
            const nextInput = current.nextElementSibling;
            if (nextInput && nextInput.classList.contains('otp-underline-input')) {
                nextInput.focus();
            }
        }
    }
</script>
    <?php endif; ?>

    <?php if ($show_password_form): ?>
        <!-- Password Reset Form -->
        <form method="POST" id="passwordForm">
            <input type="password" name="new_password" id="newPassword" placeholder="Enter new password" required>
            <div class="password-strength">
                <div class="strength-meter" id="strengthMeter"></div>
            </div>
            <input type="password" name="confirm_password" id="confirmPassword" placeholder="Confirm new password" required>
            <button type="submit" name="reset_password">Reset Password</button>
        </form>

        <script>
            // Password strength indicator
            const newPassword = document.getElementById('newPassword');
            const strengthMeter = document.getElementById('strengthMeter');

            newPassword.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;

                if (password.length >= 8) strength += 1;
                if (password.length >= 12) strength += 1;
                if (/[A-Z]/.test(password)) strength += 1;
                if (/[0-9]/.test(password)) strength += 1;
                if (/[^A-Za-z0-9]/.test(password)) strength += 1;

                let width = 0;
                let color = 'red';

                if (strength <= 1) {
                    width = 25;
                    color = 'red';
                } else if (strength <= 3) {
                    width = 50;
                    color = 'orange';
                } else if (strength <= 4) {
                    width = 75;
                    color = 'yellow';
                } else {
                    width = 100;
                    color = 'green';
                }

                strengthMeter.style.width = width + '%';
                strengthMeter.style.background = color;
            });
        </script>
    <?php endif; ?>
</div>
</body>
</html>