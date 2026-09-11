<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/config/database.php';

$pdo = db();


/*
|--------------------------------------------------------------------------
| RESPONDER JSON
|--------------------------------------------------------------------------
*/

function responderWorker(
    array $dados,
    int $status = 200
): never {

    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
        |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| BEARER TOKEN
|--------------------------------------------------------------------------
*/

function obterBearerToken(): string
{
    $header =
        $_SERVER['HTTP_AUTHORIZATION']
        ?? '';


    if (
        !preg_match(
            '/Bearer\s+(.+)/i',
            $header,
            $match
        )
    ) {

        return '';
    }


    return trim(
        (string) $match[1]
    );
}


/*
|--------------------------------------------------------------------------
| EXECUÇÃO
|--------------------------------------------------------------------------
*/

try {

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        responderWorker(
            [
                'success' => false,
                'message' => 'Método inválido.',
            ],
            405
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CONFIGURAÇÃO
    |--------------------------------------------------------------------------
    */

    $configFile =
        dirname(__DIR__, 2)
        .
        '/storage/config/worker.php';


    if (!file_exists($configFile)) {

        throw new RuntimeException(
            'Configuração do worker não encontrada.'
        );
    }


    $config =
        require $configFile;


    $secretEsperado =
        trim(
            (string) (
                $config['secret']
                ?? ''
            )
        );


    if (
        $secretEsperado === ''
        ||
        $secretEsperado
            ===
            'COLOQUE_AQUI_UMA_CHAVE_ALEATORIA_LONGA'
    ) {

        throw new RuntimeException(
            'Segredo do worker não configurado.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | AUTENTICAÇÃO
    |--------------------------------------------------------------------------
    */

    $secretRecebido =
        obterBearerToken();


    if (
        $secretRecebido === ''
        ||
        !hash_equals(
            $secretEsperado,
            $secretRecebido
        )
    ) {

        responderWorker(
            [
                'success' => false,
                'message' => 'Não autorizado.',
            ],
            401
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSAÇÃO
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | PEGAR SOMENTE UMA TAREFA
    |--------------------------------------------------------------------------
    |
    | ORDER BY mantém a ordem dos vídeos.
    |
    | FOR UPDATE impede dois workers
    | de pegarem a mesma tarefa simultaneamente.
    |
    |--------------------------------------------------------------------------
    */

    $stmt =
        $pdo->query(
            '
            SELECT
                ca.id,
                ca.capitulo_id,
                ca.drive_file_id,
                ca.nome_arquivo,
                ca.mime_type,
                ca.tamanho_bytes,
                ca.duracao_ms,
                ca.ia_processamento_token,

                c.numero AS capitulo_numero,
                c.titulo AS capitulo_titulo

            FROM capitulo_arquivos ca

            INNER JOIN capitulos c
                ON c.id = ca.capitulo_id

            WHERE ca.ativo = 1
              AND ca.ia_status = "na_fila"

            ORDER BY
                ca.capitulo_id ASC,
                ca.ordem ASC,
                ca.id ASC

            LIMIT 1

            FOR UPDATE
            '
        );


    $tarefa =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    /*
    |--------------------------------------------------------------------------
    | FILA VAZIA
    |--------------------------------------------------------------------------
    */

    if (!$tarefa) {

        $pdo->commit();

        responderWorker(
            [
                'success' => true,
                'task' => null,
                'message' => 'Fila vazia.',
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TOKEN
    |--------------------------------------------------------------------------
    */

    $token =
        trim(
            (string) (
                $tarefa[
                    'ia_processamento_token'
                ]
                ?? ''
            )
        );


    if ($token === '') {

        $token =
            bin2hex(
                random_bytes(32)
            );
    }


    /*
    |--------------------------------------------------------------------------
    | MARCAR COMO PROCESSANDO
    |--------------------------------------------------------------------------
    */

    $stmtUpdate =
        $pdo->prepare(
            '
            UPDATE capitulo_arquivos
            SET
                ia_status = "processando",
                ia_processamento_token = ?,
                ia_processamento_inicio_at = NOW(),
                ia_erro = NULL
            WHERE id = ?
              AND ia_status = "na_fila"
            '
        );


    $stmtUpdate->execute([
        $token,
        (int) $tarefa['id'],
    ]);


    if (
        $stmtUpdate->rowCount()
        !== 1
    ) {

        throw new RuntimeException(
            'Não foi possível reservar a tarefa.'
        );
    }


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RETORNO
    |--------------------------------------------------------------------------
    */

    responderWorker(
        [

            'success' =>
                true,

            'task' => [

                'arquivo_id' =>
                    (int) $tarefa['id'],

                'capitulo_id' =>
                    (int) $tarefa['capitulo_id'],

                'capitulo_numero' =>
                    (int) $tarefa[
                        'capitulo_numero'
                    ],

                'capitulo_titulo' =>
                    (string) $tarefa[
                        'capitulo_titulo'
                    ],

                'drive_file_id' =>
                    (string) $tarefa[
                        'drive_file_id'
                    ],

                'nome_arquivo' =>
                    (string) $tarefa[
                        'nome_arquivo'
                    ],

                'mime_type' =>
                    (string) (
                        $tarefa[
                            'mime_type'
                        ]
                        ?? ''
                    ),

                'tamanho_bytes' =>
                    (int) (
                        $tarefa[
                            'tamanho_bytes'
                        ]
                        ?? 0
                    ),

                'duracao_ms' =>
                    (int) (
                        $tarefa[
                            'duracao_ms'
                        ]
                        ?? 0
                    ),

                'token' =>
                    $token,

            ],

        ]
    );


} catch (Throwable $e) {

    if (
        isset($pdo)
        &&
        $pdo instanceof PDO
        &&
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();
    }


    responderWorker(
        [
            'success' => false,
            'message' => $e->getMessage(),
        ],
        500
    );
}