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
