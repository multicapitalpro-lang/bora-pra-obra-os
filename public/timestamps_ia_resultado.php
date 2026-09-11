<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/config/database.php';

$pdo = db();


function responderTimestampResultado(
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


function bearerTimestampResultado(): string
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

        responderTimestampResultado(
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
            bearerTimestampResultado()
        )
    ) {

        responderTimestampResultado(
            [
                'success' => false,
                'message' => 'Não autorizado.',
            ],
            401
        );
    }


    /*
    |--------------------------------------------------------------------------
    | JSON
    |--------------------------------------------------------------------------
    */

    $body =
        file_get_contents(
            'php://input'
        );


    $dados =
        json_decode(
            (string) $body,
            true
        );


    if (!is_array($dados)) {

        throw new RuntimeException(
            'JSON inválido.'
        );
    }


    $arquivoId =
        (int) (
            $dados['arquivo_id']
            ?? 0
        );


    $token =
        trim(
            (string) (
                $dados['token']
                ?? ''
            )
        );


    $sucesso =
        (bool) (
            $dados['success']
            ?? false
        );


    if (
        $arquivoId <= 0
        ||
        $token === ''
    ) {

        throw new RuntimeException(
            'Tarefa inválida.'
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
    | CONFERIR TOKEN
    |--------------------------------------------------------------------------
    */

    $stmtArquivo =
        $pdo->prepare(
            '
            SELECT
                id,
                ia_timestamps_status,
                ia_timestamps_token

            FROM capitulo_arquivos

            WHERE id = ?
              AND ia_timestamps_token = ?

            LIMIT 1
            '
        );


    $stmtArquivo->execute([
        $arquivoId,
        $token,
    ]);


    $arquivo =
        $stmtArquivo->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$arquivo) {

        throw new RuntimeException(
            'Tarefa de timestamps não encontrada ou token inválido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ERRO
    |--------------------------------------------------------------------------
    */

    if (!$sucesso) {

        $erro =
            trim(
                (string) (
                    $dados['error']
                    ?? 'Erro desconhecido.'
                )
            );


        $stmtErro =
            $pdo->prepare(
                '
                UPDATE capitulo_arquivos

                SET
                    ia_timestamps_status =
                        "erro",

                    ia_timestamps_erro =
                        ?,

                    ia_timestamps_inicio_at =
                        NULL

                WHERE id = ?
                  AND ia_timestamps_token = ?
                '
            );


        $stmtErro->execute([
            $erro,
            $arquivoId,
            $token,
        ]);


        responderTimestampResultado(
            [
                'success' =>
                    true,

                'message' =>
                    'Erro de timestamps registrado.',
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SEGMENTOS
    |--------------------------------------------------------------------------
    */

    $segmentos =
        $dados['segments']
        ?? [];


    if (!is_array($segmentos)) {

        throw new RuntimeException(
            'Segmentos inválidos.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | LIMPAR SEGMENTOS ANTIGOS
    |--------------------------------------------------------------------------
    */

    $stmtExcluir =
        $pdo->prepare(
            '
            DELETE FROM capitulo_arquivo_segmentos
            WHERE arquivo_id = ?
            '
        );


    $stmtExcluir->execute([
        $arquivoId
    ]);


    /*
    |--------------------------------------------------------------------------
    | INSERIR
    |--------------------------------------------------------------------------
    */

    $stmtInserir =
        $pdo->prepare(
            '
            INSERT INTO capitulo_arquivo_segmentos
            (
                arquivo_id,
                ordem,
                inicio_ms,
                fim_ms,
                texto
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?
            )
            '
        );


    $ordem =
        0;


    foreach (
        $segmentos
        as $segmento
    ) {

        if (!is_array($segmento)) {
            continue;
        }


        $texto =
            trim(
                (string) (
                    $segmento['text']
                    ?? ''
                )
            );


        if ($texto === '') {
            continue;
        }


        $inicioMs =
            max(
                0,
                (int) (
                    $segmento[
                        'start_ms'
                    ]
                    ?? 0
                )
            );


        $fimMs =
            max(
                $inicioMs,
                (int) (
                    $segmento[
                        'end_ms'
                    ]
                    ?? $inicioMs
                )
            );


        $ordem++;


        $stmtInserir->execute([
            $arquivoId,
            $ordem,
            $inicioMs,
            $fimMs,
            $texto,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | CONCLUIR
    |--------------------------------------------------------------------------
    */

    $stmtConcluir =
        $pdo->prepare(
            '
            UPDATE capitulo_arquivos

            SET
                ia_timestamps_status =
                    "concluido",

                ia_timestamps_at =
                    NOW(),

                ia_timestamps_inicio_at =
                    NULL,

                ia_timestamps_erro =
                    NULL

            WHERE id = ?
              AND ia_timestamps_token = ?
            '
        );


    $stmtConcluir->execute([
        $arquivoId,
        $token,
    ]);


    $pdo->commit();


    responderTimestampResultado(
        [
            'success' =>
                true,

            'message' =>
                'Timestamps salvos com sucesso.',

            'arquivo_id' =>
                $arquivoId,

            'segmentos' =>
                $ordem,
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


    responderTimestampResultado(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}