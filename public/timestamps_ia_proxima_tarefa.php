<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/config/database.php';

$pdo = db();


function responderTimestamp(
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


function bearerTimestamp(): string
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


try {

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        responderTimestamp(
            [
                'success' => false,
                'message' => 'Método inválido.',
            ],
            405
        );
    }


    /*
    |--------------------------------------------------------------------------
    | AUTENTICAÇÃO
    |--------------------------------------------------------------------------
    */

    $configFile =
        dirname(
            __DIR__,
            2
        )
        .
        '/storage/config/worker.php';


    if (!file_exists($configFile)) {

        throw new RuntimeException(
            'Configuração do worker não encontrada.'
        );
    }


    $config =
        require $configFile;


    $secret =
        trim(
            (string) (
                $config['secret']
                ?? ''
            )
        );


    if (
        $secret === ''
        ||
        !hash_equals(
            $secret,
            bearerTimestamp()
        )
    ) {

        responderTimestamp(
            [
                'success' => false,
                'message' => 'Não autorizado.',
            ],
            401
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CONEXÃO NOVA
    |--------------------------------------------------------------------------
    */

    $pdo =
        db(true);


    /*
    |--------------------------------------------------------------------------
    | RESERVAR PRÓXIMA TAREFA
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    $stmt =
        $pdo->prepare(
            '
            SELECT
                ca.id AS arquivo_id,
                ca.capitulo_id,
                ca.drive_file_id,
                ca.nome_arquivo,
                ca.mime_type,
                ca.tamanho_bytes,
                ca.duracao_ms,

                c.numero AS capitulo_numero,
                c.titulo AS capitulo_titulo

            FROM capitulo_arquivos ca

            INNER JOIN capitulos c
                ON c.id = ca.capitulo_id

            INNER JOIN capitulo_plano_arquivos cpa
                ON cpa.arquivo_id = ca.id
               AND cpa.capitulo_id = ca.capitulo_id

            WHERE ca.ativo = 1

              AND ca.ia_status = "concluido"

              AND ca.ia_timestamps_status = "na_fila"

            ORDER BY
                ca.capitulo_id ASC,
                cpa.ordem ASC,
                ca.id ASC

            LIMIT 1

            FOR UPDATE
            '
        );


    $stmt->execute();


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


        responderTimestamp(
            [
                'success' =>
                    true,

                'task' =>
                    null,
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TOKEN
    |--------------------------------------------------------------------------
    */

    $token =
        bin2hex(
            random_bytes(32)
        );


    $stmtAtualizar =
        $pdo->prepare(
            '
            UPDATE capitulo_arquivos

            SET
                ia_timestamps_status =
                    "processando",

                ia_timestamps_token =
                    ?,

                ia_timestamps_inicio_at =
                    NOW(),

                ia_timestamps_erro =
                    NULL

            WHERE id = ?
            '
        );


    $stmtAtualizar->execute([
        $token,
        (int) $tarefa['arquivo_id'],
    ]);


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RETORNO
    |--------------------------------------------------------------------------
    */

    responderTimestamp(
        [
            'success' =>
                true,

            'task' => [

                'arquivo_id' =>
                    (int) $tarefa[
                        'arquivo_id'
                    ],

                'capitulo_id' =>
                    (int) $tarefa[
                        'capitulo_id'
                    ],

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
                    isset(
                        $tarefa[
                            'tamanho_bytes'
                        ]
                    )
                        ?
                        (int) $tarefa[
                            'tamanho_bytes'
                        ]
                        :
                        null,

                'duracao_ms' =>
                    isset(
                        $tarefa[
                            'duracao_ms'
                        ]
                    )
                        ?
                        (int) $tarefa[
                            'duracao_ms'
                        ]
                        :
                        null,

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


    responderTimestamp(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}