<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| SESSÃO DO PAINEL COMO ALTERNATIVA (pro botão "gerar tudo" no
| navegador chamar isso direto, sem precisar de token de worker)
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$autenticadoComoPainel = !empty($_SESSION['admin_id']);

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
    | AUTENTICAÇÃO: sessão do painel OU chave do worker
    |--------------------------------------------------------------------------
    */

    if (!$autenticadoComoPainel) {

        $configFile = dirname(__DIR__, 2) . '/storage/config/worker.php';

        if (!file_exists($configFile)) {
            throw new RuntimeException('Configuração do worker não encontrada.');
        }

        $config = require $configFile;

        $secretEsperado = trim((string) ($config['secret'] ?? ''));

        if ($secretEsperado === '' || !hash_equals($secretEsperado, obterBearerToken())) {
            responderWorker(['success' => false, 'message' => 'Não autorizado.'], 401);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PRÓXIMO CAPÍTULO SEM OS 5 TIPOS DE SHORTS
    |--------------------------------------------------------------------------
    |
    | Só considera capítulo "pronto pra Shorts" quando a transcrição
    | não tem mais nada pendente (nao_analisado / na_fila /
    | processando) -- ou seja, cada bruto já terminou em "concluido"
    | ou "erro" -- E tem pelo menos um bruto com transcrição real
    | (alguns capítulos só têm brutos silenciosos/visuais, sem fala
    | nenhuma, e nesse caso não tem material pra narrar um Short).
    | Entre os prontos, escolhe o de menor número que ainda não tem
    | os 5 tipos fixos do template gerados.
    |
    |--------------------------------------------------------------------------
    */

    $capitulo = $pdo->query(
        '
        SELECT c.id, c.numero, c.titulo
        FROM capitulos c
        WHERE EXISTS (
                SELECT 1 FROM capitulo_arquivos a
                WHERE a.capitulo_id = c.id AND a.ativo = 1
            )
          AND NOT EXISTS (
                SELECT 1 FROM capitulo_arquivos a
                WHERE a.capitulo_id = c.id
                  AND a.ativo = 1
                  AND a.ia_status IN ("nao_analisado", "na_fila", "processando")
            )
          AND EXISTS (
                SELECT 1 FROM capitulo_arquivos a
                WHERE a.capitulo_id = c.id
                  AND a.ativo = 1
                  AND a.ia_transcricao IS NOT NULL
                  AND a.ia_transcricao <> ""
            )
          AND (
                SELECT COUNT(DISTINCT s.tema)
                FROM capitulo_short_roteiros s
                WHERE s.capitulo_id = c.id
                  AND s.tema IN ("problema", "custo", "como_fizemos", "erro", "resultado")
              ) < 5
        ORDER BY c.numero ASC
        LIMIT 1
        '
    )->fetch(PDO::FETCH_ASSOC);

    if (!$capitulo) {
        responderWorker(['success' => true, 'task' => null, 'message' => 'Nenhum capítulo pendente de Shorts.']);
    }

    responderWorker([
        'success' => true,
        'task' => [
            'capitulo_id' => (int) $capitulo['id'],
            'capitulo_numero' => (int) $capitulo['numero'],
            'capitulo_titulo' => (string) $capitulo['titulo'],
        ],
    ]);

} catch (Throwable $e) {

    responderWorker(['success' => false, 'message' => $e->getMessage()], 500);
}
