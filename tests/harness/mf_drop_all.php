<?php
$host = getenv('O2C_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('O2C_DB_PORT') ?: '3307');
$dbName = getenv('O2C_DB_NAME') ?: 'erpsmart_p2p_test';
$user = getenv('O2C_DB_USER') ?: 'p2p';
$pass = getenv('O2C_DB_PASS') ?: 'p2p_test';
$p = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$p->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($p->query('SHOW TABLES') as $r) {
    $t = array_values($r)[0];
    echo "drop $t\n";
    $p->exec('DROP TABLE IF EXISTS `'.$t.'`');
}
echo "DONE\n";
