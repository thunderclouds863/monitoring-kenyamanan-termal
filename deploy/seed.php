<?php
// Seed database demo ke MySQL eksternal (sekali saja; ditandai tabel _demo_seeded).
// Dipanggil dari deploy/start-vercel.sh: php deploy/seed.php
// Env: DEMO_DB_NAME, SQL_FILES (dipisah spasi, relatif ke root app), DB_* (lihat db_compat.php)
// TiDB tidak mendukung TRIGGER/EVENT/PROCEDURE: blok DELIMITER dan CREATE EVENT dilewati,
// logikanya dipindah ke kode PHP (lihat deploy/legacy.php / file app terkait).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db_compat.php';
mysqli_report(MYSQLI_REPORT_OFF);

$db = getenv('DEMO_DB_NAME');
$files = preg_split('/\s+/', trim((string) getenv('SQL_FILES')));
$root = realpath(__DIR__ . '/..');
if (!$db || !$files) {
    fwrite(STDERR, "DEMO_DB_NAME / SQL_FILES belum di-set\n");
    exit(1);
}

$conn = demo_mysqli();
if ($conn->connect_errno) {
    fwrite(STDERR, "Koneksi DB gagal: {$conn->connect_error}\n");
    exit(1);
}
$esc = $conn->real_escape_string($db);

function seeded(mysqli $conn, string $esc): bool
{
    $r = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = '$esc' AND table_name = '_demo_seeded'");
    return $r && $r->num_rows > 0;
}

if (seeded($conn, $esc)) {
    echo "Seed: database $db sudah siap\n";
    exit(0);
}
$conn->query("SELECT GET_LOCK('seed_$esc', 120)");
if (seeded($conn, $esc)) {
    echo "Seed: database $db sudah disiapkan instance lain\n";
    exit(0);
}

$isTidb = stripos((string) $conn->query('SELECT VERSION()')->fetch_row()[0], 'tidb') !== false;
$conn->query("CREATE DATABASE IF NOT EXISTS `$db`");
$conn->select_db($db);

foreach ($files as $f) {
    $sql = file_get_contents("$root/$f");
    if ($sql === false) {
        fwrite(STDERR, "File $f tidak ditemukan\n");
        exit(1);
    }
    // Lewati trigger/procedure (blok DELIMITER) dan event — tidak didukung TiDB
    $sql = preg_replace('/^\s*DELIMITER\s+(\S+)\s*$.*?^\s*DELIMITER\s+;\s*$/ims', '', $sql);
    $sql = preg_replace('/^\s*CREATE\s+EVENT\b.*?;\s*$/ims', '', $sql);
    $sql = preg_replace('/^\s*SET\s+GLOBAL\b.*?;\s*$/im', '', $sql);
    // Dump lama memakai "CREATE DATABASE x;" tanpa IF NOT EXISTS
    $sql = preg_replace('/CREATE\s+DATABASE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE DATABASE IF NOT EXISTS ', $sql);
    if ($isTidb) {
        // Nama variabel batas rekursi CTE berbeda di MariaDB vs TiDB
        $sql = preg_replace('/\bmax_recursive_iterations\b/i', 'cte_max_recursion_depth', $sql);
    }

    echo "Import $f\n";
    if (!$conn->multi_query($sql)) {
        fwrite(STDERR, "Import $f gagal: {$conn->error}\n");
        exit(1);
    }
    $n = 0;
    do {
        $n++;
        if ($res = $conn->store_result()) $res->free();
        if ($conn->errno) break;
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->errno) {
        fwrite(STDERR, "Import $f gagal di statement #$n: {$conn->error}\n");
        exit(1);
    }
    $conn->select_db($db); // dump bisa berisi USE lain
}

$conn->query("CREATE TABLE IF NOT EXISTS `$db`.`_demo_seeded` (seeded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("INSERT INTO `$db`.`_demo_seeded` () VALUES ()");
$conn->query("SELECT RELEASE_LOCK('seed_$esc')");
echo "Seed: database $db siap\n";
