<?php
// Kompatibilitas DB untuk deploy Vercel (database MySQL eksternal, mis. TiDB Cloud).
// Di-load lewat auto_prepend_file (deploy/legacy.php). Tanpa env DB_HOST semuanya berperilaku
// seperti semula (koneksi lokal), jadi Dockerfile/XAMPP lama tetap jalan.
//
// Env yang dipakai:
//   DB_HOST, DB_PORT (default 4000), DB_USER, DB_PASSWORD
//   DB_SSL      = 1 (default) -> koneksi TLS, wajib untuk TiDB Cloud
//   DEMO_DB_NAME = database utama app (dipakai untuk tabel session)

function demo_db_external(): bool
{
    return (string) getenv('DB_HOST') !== '';
}

function demo_db_cfg(): array
{
    return [
        'host' => getenv('DB_HOST'),
        'port' => (int) (getenv('DB_PORT') ?: 4000),
        'user' => (string) getenv('DB_USER'),
        'pass' => (string) getenv('DB_PASSWORD'),
        'ssl'  => getenv('DB_SSL') === false || getenv('DB_SSL') === '' || getenv('DB_SSL') === '1',
        'ca'   => getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt',
    ];
}

/** Pengganti `new mysqli(...)`: argumen host/user/pass diabaikan bila DB eksternal aktif. */
function demo_mysqli($host = null, $user = null, $pass = null, $db = null, $port = null, $socket = null): mysqli
{
    if (!demo_db_external()) {
        return new mysqli($host, $user, $pass, $db, $port ?? 3306, $socket);
    }
    $c = demo_db_cfg();
    $m = mysqli_init();
    $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $flags = 0;
    if ($c['ssl']) {
        $m->ssl_set(null, null, $c['ca'], null, null);
        $flags = MYSQLI_CLIENT_SSL;
    }
    if (@$m->real_connect($c['host'], $c['user'], $c['pass'], $db ?: null, $c['port'], null, $flags)) {
        // Kode lama ditulis untuk MySQL/MariaDB XAMPP (mode non-strict)
        $m->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    }
    return $m;
}

/** Pengganti `new PDO(...)` untuk DSN mysql. */
function demo_pdo(string $dsn, $user = null, $pass = null, array $options = []): PDO
{
    if (!demo_db_external() || stripos($dsn, 'mysql:') !== 0) {
        return new PDO($dsn, $user, $pass, $options);
    }
    $c = demo_db_cfg();
    $parts = [];
    foreach (explode(';', substr($dsn, 6)) as $kv) {
        if (strpos($kv, '=') !== false) {
            [$k, $v] = explode('=', $kv, 2);
            $parts[strtolower(trim($k))] = trim($v);
        }
    }
    $newDsn = "mysql:host={$c['host']};port={$c['port']}";
    if (!empty($parts['dbname'])) $newDsn .= ";dbname={$parts['dbname']}";
    $newDsn .= ';charset=' . ($parts['charset'] ?? 'utf8mb4');
    $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'";
    if ($c['ssl']) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $c['ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    return new PDO($newDsn, $c['user'], $c['pass'], $options);
}

/**
 * Session PHP disimpan di database: container Vercel bisa jalan di beberapa instance
 * dan filesystem-nya ephemeral, jadi session berbasis file akan sering "logout sendiri".
 */
class DemoDbSessionHandler implements SessionHandlerInterface
{
    private $db;

    private function conn(): mysqli
    {
        if (!$this->db) {
            $this->db = demo_mysqli(null, null, null, getenv('DEMO_DB_NAME') ?: null);
            if ($this->db->connect_errno) {
                throw new RuntimeException('Session DB connection failed: ' . $this->db->connect_error);
            }
            $this->db->query('CREATE TABLE IF NOT EXISTS _php_sessions (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                data MEDIUMBLOB NOT NULL,
                updated_at INT UNSIGNED NOT NULL,
                KEY idx_updated (updated_at)
            )');
        }
        return $this->db;
    }

    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }

    #[\ReturnTypeWillChange]
    public function read($id)
    {
        $st = $this->conn()->prepare('SELECT data FROM _php_sessions WHERE id = ?');
        $st->bind_param('s', $id);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        return $row ? (string) $row[0] : '';
    }

    public function write($id, $data): bool
    {
        $now = time();
        $st = $this->conn()->prepare('REPLACE INTO _php_sessions (id, data, updated_at) VALUES (?, ?, ?)');
        $st->bind_param('ssi', $id, $data, $now);
        return $st->execute();
    }

    public function destroy($id): bool
    {
        $st = $this->conn()->prepare('DELETE FROM _php_sessions WHERE id = ?');
        $st->bind_param('s', $id);
        return $st->execute();
    }

    #[\ReturnTypeWillChange]
    public function gc($max)
    {
        $limit = time() - (int) $max;
        $st = $this->conn()->prepare('DELETE FROM _php_sessions WHERE updated_at < ?');
        $st->bind_param('i', $limit);
        $st->execute();
        return $st->affected_rows;
    }
}

if (demo_db_external() && PHP_SAPI !== 'cli') {
    session_set_save_handler(new DemoDbSessionHandler(), true);
}
/** Cek apakah nilai path sudah berupa URL absolut (mis. Vercel Blob). */
function demo_is_url(?string $value): bool
{
    return is_string($value) && preg_match('#^https?://#i', trim($value)) === 1;
}

/** Upload file ke Vercel Blob bila BLOB_READ_WRITE_TOKEN tersedia; return URL publik atau null. */
function demo_blob_upload(string $tmpFile, string $pathname, string $contentType = ''): ?string
{
    $token = (string) getenv('BLOB_READ_WRITE_TOKEN');
    if ($token === '' || !is_file($tmpFile)) {
        return null;
    }
    if ($contentType === '' && class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $detected = $fi->file($tmpFile);
        if (is_string($detected) && $detected !== '') {
            $contentType = $detected;
        }
    }
    if ($contentType === '') {
        $contentType = 'application/octet-stream';
    }
    $body = @file_get_contents($tmpFile);
    if ($body === false) {
        return null;
    }
    $cleanPath = ltrim(str_replace('\\', '/', $pathname), '/');
    $encoded = implode('/', array_map('rawurlencode', explode('/', $cleanPath)));
    $url = 'https://blob.vercel-storage.com/' . $encoded;
    $headers = [
        'Authorization: Bearer ' . $token,
        'x-api-version: 7',
        'x-add-random-suffix: 1',
        'x-access: public',
        'x-content-type: ' . $contentType,
        'Content-Type: ' . $contentType,
    ];

    $respBody = null;
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($res)) $respBody = $res;
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'PUT',
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $body,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if (is_string($res)) $respBody = $res;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
    }

    if ($status >= 200 && $status < 300 && $respBody !== null) {
        $json = json_decode($respBody, true);
        if (is_array($json) && !empty($json['url'])) {
            return (string) $json['url'];
        }
    }
    error_log("demo_blob_upload failed (HTTP $status) for $cleanPath");
    return null;
}

/** Simpan file upload: ke Vercel Blob (permanen) bila token tersedia, atau fallback lokal. */
function demo_upload_store(string $tmpFile, string $localTarget, string $blobPathname, string $localReturnPath, string $contentType = ''): ?string
{
    $blobUrl = demo_blob_upload($tmpFile, $blobPathname, $contentType);
    if ($blobUrl !== null) {
        return $blobUrl;
    }
    $dir = dirname($localTarget);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_uploaded_file($tmpFile) ? @move_uploaded_file($tmpFile, $localTarget) : @rename($tmpFile, $localTarget)) {
        return $localReturnPath;
    }
    return null;
}

/** Endpoint internal reset data demo terjadwal: POST /?__demo_reset=1 dengan secret. */
function demo_handle_reset_request(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_GET['__demo_reset'])) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    $expected = (string) getenv('DEMO_RESET_SECRET');
    if ($expected === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'DEMO_RESET_SECRET not configured']);
        exit;
    }
    $provided = (string) ($_SERVER['HTTP_X_DEMO_RESET_SECRET'] ?? '');
    if ($provided === '') {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (stripos($auth, 'Bearer ') === 0) {
            $provided = trim(substr($auth, 7));
        }
    }
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $envFile = '/var/www/html/deploy/demo.env';
    if (!is_file($envFile)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'deploy/demo.env not found']);
        exit;
    }
    $cmd = 'bash -lc ' . escapeshellarg('set -euo pipefail; source /var/www/html/deploy/demo.env; export DEMO_DB_NAME="$DB_NAME" SQL_FILES; php /var/www/html/deploy/seed.php --force');
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => $code, 'output' => implode("\n", array_slice($out, -15))]);
        exit;
    }
    echo json_encode(['ok' => true, 'reset_at' => gmdate('c'), 'output' => implode("\n", $out)]);
    exit;
}

if (PHP_SAPI !== 'cli') {
    demo_handle_reset_request();
}
