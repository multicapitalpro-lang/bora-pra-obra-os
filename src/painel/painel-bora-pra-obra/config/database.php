<?php
// Ajuste estes dados conforme o banco criado na sua hospedagem.
const DB_HOST = 'localhost';
const DB_NAME = 'bora_pra_obra';
const DB_USER = 'SEU_USUARIO_MYSQL';
const DB_PASS = 'SUA_SENHA_MYSQL';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Falha ao conectar ao banco. Confira config/database.php.');
    }
}
