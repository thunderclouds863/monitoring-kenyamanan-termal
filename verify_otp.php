<?php
session_start();
include 'db.php';
require 'vendor/autoload.php'; // Include Composer's autoloader
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Initialize variables
$error = '';
$message = '';

// Check if email is set in session
if (!isset($_SESSION['email'])) {
    header("Location: register.php");
    exit;
}

$email = $_SESSION['email']; // Initialize email from session

// Set default message if not already set
if (!isset($_SESSION['otp_message'])) {
    $_SESSION['otp_message'] = 'OTP has been sent to your email. It will expire in 2 minutes.';
}

// Update message if resend OTP is triggered
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['resend_otp'])) {
    // Generate new OTP
    $new_otp = strval(rand(100000, 999999));
    $_SESSION['otp'] = $new_otp;
    $_SESSION['otp_expiry'] = time() + 120; // Reset expiry time to 120 seconds

    // Update OTP in database
    $sql_update = "UPDATE users SET otp='$new_otp' WHERE email='$email'";
    if ($conn->query($sql_update)) {
        // Send email with new OTP
        if (sendEmail($email, "Your new OTP code is: <b>$new_otp</b>", "New Verification OTP")) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => "Failed to resend OTP. Please try again."]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "Failed to update OTP in database."]);
    }
    exit; // Stop further execution for AJAX response
}

// Retrieve the message from the session
$message = $_SESSION['otp_message'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['verify_otp'])) {
        $entered_otp = implode('', $_POST['otp']);

        // Initialize session for tracking OTP attempts
        if (!isset($_SESSION['otp_attempts'])) {
            $_SESSION['otp_attempts'] = 0;
        }

        // Check if OTP exists in database
        $sql = "SELECT * FROM users WHERE email='$email' AND is_verified=0";
        $result = $conn->query($sql);

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            // Check if OTP matches
            if ($user['otp'] === $entered_otp) {
                // Reset session attempts and verify the user
                $_SESSION['otp_attempts'] = 0;
                $sql_update = "UPDATE users SET is_verified=1, otp=NULL WHERE email='$email'";
                if ($conn->query($sql_update)) {
                    // Kirim email notifikasi bahwa akun berhasil diverifikasi
                    $emailSubject = "Account Verified Successfully";
                    $emailBody = "Dear " . htmlspecialchars($user['username']) . ",<br><br>Your account has been successfully verified. You can now log in to your account.<br><br>Thank you for verifying your email.<br><br>Best regards,<br>Sistem Informasi Kenyamanan Termal";

                    if (sendEmail($email, $emailBody, $emailSubject)) {
                        error_log("Verification email sent to $email.");
                    } else {
                        error_log("Failed to send verification email to $email.");
                    }

                    $message = "Account successfully verified. Redirecting to login page...";
                    unset($_SESSION['email']);
                    header("Refresh: 3; url=login.php");
                    exit;
                } else {
                    $error = "Error verifying account. Please try again.";
                }
            } else {
                // Increment OTP attempts in session
                $_SESSION['otp_attempts']++;

                if ($_SESSION['otp_attempts'] >= 3) {
                    // Delete the account if attempts exceed 3
                    $sql_delete = "DELETE FROM users WHERE email='$email'";
                    $conn->query($sql_delete);

                    // Redirect to register page with error message
                    unset($_SESSION['email']);
                    unset($_SESSION['otp_attempts']);
                    $_SESSION['error'] = "You have exceeded the maximum number of OTP attempts. Please register again.";
                    header("Location: register.php");
                    exit;
                } else {
                    $remaining_attempts = 3 - $_SESSION['otp_attempts'];
                    $error = "Invalid OTP! You have $remaining_attempts attempts remaining.";
                }
            }
        } else {
            $error = "Invalid OTP or OTP has expired!";
        }
    }
    elseif (isset($_POST['resend_otp'])) {
        // Generate new OTP
        $new_otp = strval(rand(100000, 999999));
        $_SESSION['otp'] = $new_otp;
        $_SESSION['otp_expiry'] = time() + 120; // Reset expiry time to 120 seconds

        // Update OTP in database
        $sql_update = "UPDATE users SET otp='$new_otp' WHERE email='$email'";
        if ($conn->query($sql_update)) {
            // Send email with new OTP
            if (sendEmail($email, "Your new OTP code is: <b>$new_otp</b>", "New Verification OTP")) {
                echo json_encode([
                    'success' => true,
                    'message' => "A new OTP has been sent to your email. It will expire in 2 minutes.",
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => "Failed to resend OTP. Please try again.",
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => "Failed to update OTP in database.",
            ]);
        }
        exit; // Stop further execution for AJAX response
    }
}

function sendEmail($email, $body, $subject = "Notification") {
    $mail = new PHPMailer(true);

    try {
        error_log("sendEmail function called for email: $email"); // Debugging log
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'si.kenyamanantermal@gmail.com';
        $mail->Password = 'lekz detd pqrk jdlu';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('si.kenyamanantermal@gmail.com', 'Account Verification');
        $mail->addAddress($email);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = nl2br($body);

        error_log("Attempting to send OTP email...");
        $mail->send();
        error_log("OTP email sent successfully.");
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Verification</title>
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

        .otp-input-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
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

        button[name="resendOtpButton"]:hover {
            text-decoration: underline !important; /* Menambahkan garis bawah saat hover */
            color: #E0E1DD; /* Ubah warna teks saat hover */
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Account Verification</h2>
    <!-- Message indicating OTP has been sent -->
    <p class="message" id="otpSentMessage"><?php echo htmlspecialchars($message); ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

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
        <button type="button" id="resendOtpButton" name="resendOtpButton" style="background: transparent; border: none; color: #B0C4DE; text-decoration: none; font-size: 14px; cursor: pointer;">
            Resend OTP
        </button>
        <!-- Loading spinner -->
        <span id="loadingSpinner" style="display: none; margin-left: 10px; color: #B0C4DE; font-size: 14px;">Sending...</span>
    </form>
</div>
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

    // Move to next input field
    function moveToNext(current) {
        if (current.value.length === 1) {
            const nextInput = current.nextElementSibling;
            if (nextInput && nextInput.classList.contains('otp-underline-input')) {
                nextInput.focus();
            }
        }
    }

    const resendOtpButton = document.getElementById('resendOtpButton');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const notificationMessage = document.getElementById('otpSentMessage');

    resendOtpButton.addEventListener('click', function (e) {
        e.preventDefault(); // Prevent default button behavior

        // Show loading spinner
        loadingSpinner.style.display = 'inline';
        resendOtpButton.disabled = true; // Disable the button to prevent multiple clicks

        fetch('verify_otp.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'resend_otp=true'
        })
        .then(response => response.json())
        .then(data => {
            // Hide loading spinner
            loadingSpinner.style.display = 'none';
            resendOtpButton.disabled = false; // Re-enable the button

            if (data.success) {
                // Update the notification message dynamically
                notificationMessage.textContent = "A new OTP has been sent to your email. It will expire in 2 minutes.";
                notificationMessage.style.color = "#B0C4DE";

                // Reset the timer
                timeLeft = 120;
                timerElement.textContent = `OTP expires in: ${timeLeft} seconds`;
                timerElement.style.color = "#FF6B6B";
            } else {
                alert(data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);

            // Hide loading spinner and re-enable button in case of error
            loadingSpinner.style.display = 'none';
            resendOtpButton.disabled = false; // Re-enable the button
        });
    });
</script>
</body>
</html>