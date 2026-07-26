<?php
$candidatos = [
    "/home/u719183319/domains/firebrick-rook-290123.hostingersite.com/config_secreto/database.local.php",
    __DIR__ . "/database.local.php",
];
$cfg = null;
foreach ($candidatos as $arquivo) {
    if (is_file($arquivo)) { $cfg = require $arquivo; break; }
}
if (!$cfg) {
    http_response_code(500);
    exit("Configuracao ausente: database.local.php nao encontrado.");
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    global $cfg;
    $dsn = "mysql:host=" . $cfg["host"] . ";dbname=" . $cfg["name"] . ";charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $cfg["user"], $cfg["pass"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        exit("Falha ao conectar ao banco.");
    }
}
