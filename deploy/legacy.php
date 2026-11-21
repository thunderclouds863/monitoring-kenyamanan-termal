<?php
// Kode ditulis untuk PHP/XAMPP lama: matikan exception mysqli default PHP 8.1+
mysqli_report(MYSQLI_REPORT_OFF);

// Demo portfolio: sisipkan kotak "Akun Demo" di halaman login (konfigurasi di deploy/demo_banner.php).
(function () {
    $cfgFile = '/var/www/html/deploy/demo_banner.php';
    if (getenv('DEMO_BANNER_CONFIG')) $cfgFile = getenv('DEMO_BANNER_CONFIG');
    if (!is_file($cfgFile)) return;
    $cfg = include $cfgFile;
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!in_array($page, $cfg['pages'], true)) return;

    $rows = '';
    foreach ($cfg['accounts'] as [$role, $user, $pass]) {
        $rows .= '<tr><td>' . htmlspecialchars($role) . '</td><td><code>' . htmlspecialchars($user)
               . '</code></td><td><code>' . htmlspecialchars($pass) . '</code></td></tr>';
    }
    $note = isset($cfg['note']) ? '<p style="margin:6px 0 0;font-size:12px;color:#555">' . $cfg['note'] . '</p>' : '';
    $banner = '<div id="demo-akun" style="position:fixed;right:16px;bottom:16px;z-index:99999;max-width:340px;'
        . 'background:#fffbe6;border:1px solid #f0c36d;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.18);'
        . 'padding:12px 14px;font:13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#222;text-align:left">'
        . '<button onclick="this.parentNode.remove()" style="float:right;border:0;background:none;font-size:16px;cursor:pointer;color:#888" aria-label="Tutup">&times;</button>'
        . '<strong>Akun Demo</strong><span style="color:#777"> · data contoh, reset otomatis</span>'
        . '<table style="margin-top:6px;border-collapse:collapse;width:100%;font-size:12px">'
        . '<tr style="color:#777"><td>Role</td><td>User</td><td>Password</td></tr>' . $rows . '</table>' . $note . '</div>';

    ob_start(function ($html) use ($banner) {
        if (stripos($html, '</body>') !== false) {
            return preg_replace('~</body>~i', $banner . '</body>', $html, 1);
        }
        return $html;
    });
})();
