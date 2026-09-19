<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config/database.php';

$pdo = db();

function responderWorker(array $dados, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function obterBearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/Bearer\s+(.+)/i', $header, $match)) {
        return '';
    }

    return trim((string) $match[1]);
}

try {

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        responderWorker(['success' => false, 'message' => 'Método inválido.'], 405);
    }

    /*
    |--------------------------------------------------------------------------
    | AUTENTICAÇÃO: só o worker chama isso
    |--------------------------------------------------------------------------
    */

    $configFile = dirname(__DIR__, 2) . '/storage/config/worker.php';

    if (!file_exists($configFile)) {
        throw new RuntimeException('Configuração do worker não encontrada.');
    }

    $config = require $configFile;

    $secretEsperado = trim((string) ($config['secret'] ?? ''));

    if ($secretEsperado === '' || !hash_equals($secretEsperado, obterBearerToken())) {
        responderWorker(['success' => false, 'message' => 'Não autorizado.'], 401);
    }

    $atividade = trim((string) ($_POST['atividade'] ?? ''));

    $pdo->prepare(
        '
        INSERT INTO worker_status (id, atividade_atual, ultimo_ping_at)
        VALUES (1, ?, NOW())
        ON DUPLICATE KEY UPDATE
            atividade_atual = VALUES(atividade_atual),
            ultimo_ping_at = VALUES(ultimo_ping_at)
        '
    )->execute([$atividade !== '' ? $atividade : null]);

    responderWorker(['success' => true]);

} catch (Throwable $e) {

    responderWorker(['success' => false, 'message' => $e->getMessage()], 500);
}
