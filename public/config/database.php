<?php
/**
 * Conexão com o banco (PDO).
 *
 * As credenciais NÃO ficam neste arquivo (ele é versionado no Git).
 * Elas vêm de config/database.local.php, que é ignorado pelo Git.
 * Copie config/database.local.php.example para config/database.local.php
 * e preencha com os dados do seu banco.
 */

$localConfig = __DIR__ . '/database.local.php';
if (!is_file($localConfig)) {
    http_response_code(500);
    exit('Configuração ausente: crie config/database.local.php a partir do arquivo .example.');
}
$cfg = require $localConfig;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $cfg;
    $dsn = 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Falha ao conectar ao banco. Confira config/database.local.php.');
    }
}