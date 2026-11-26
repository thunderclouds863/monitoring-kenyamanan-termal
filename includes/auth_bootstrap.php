<?php
/**
 * Shared authentication, session, CSRF, and profile helpers.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function restore_session_from_cookie(mysqli $conn): void {
    if (!isset($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE remember_token = ? AND is_verified = 1 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $_SESSION['user_id'] = (int)$row['id'];
                $_SESSION['username'] = $row['username'];
            }
            $stmt->close();
        }
    }
}

function require_authenticated_user(mysqli $conn): array {
    restore_session_from_cookie($conn);

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }

    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT id, username, email, phone, profile_pic, is_verified, apikey FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit();
    }

    return $user;
}

function get_user_settings(mysqli $conn, int $user_id): array {
    $defaults = [
        'interval_update' => 5,
        'suhu_min' => 25.0,
        'suhu_max' => 30.0,
        'kelembapan_min' => 50.0,
        'kelembapan_max' => 90.0,
        'wind_speed_min' => 1.0,
        'wind_speed_max' => 5.0,
        'air_quality_max' => 450.0,
        'cooldown_time' => 600,
        'delta_threshold' => 10.0,
        'sensitivity_level' => 'normal',
    ];

    $stmt = $conn->prepare("SELECT interval_update, suhu_min, suhu_max, kelembapan_min, kelembapan_max, wind_speed_min, wind_speed_max, air_quality_max, cooldown_time, delta_threshold, sensitivity_level FROM settings WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return array_merge($defaults, array_filter($row, static fn($v) => $v !== null));
        }
    }
    return $defaults;
}

function resolve_profile_pic(?string $profile_pic): ?string {
    if (empty($profile_pic)) {
        return null;
    }
    // Check if it's a relative uploads path
    if (str_starts_with($profile_pic, 'uploads/')) {
        $fullPath = dirname(__DIR__) . '/' . $profile_pic;
        if (is_file($fullPath)) {
            return $profile_pic;
        }
        return null;
    }
    // If binary blob stored directly
    if (!ctype_print($profile_pic)) {
        return 'data:image/jpeg;base64,' . base64_encode($profile_pic);
    }
    return null;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function user_initials(string $username): string {
    $parts = preg_split('/\s+/', trim($username));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($username, 0, 2));
}

function render_theme_init_script(): void {
    echo "<script>(function(){var t=localStorage.getItem('thermal_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>";
}
