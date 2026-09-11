<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/config/database.php';

$pdo = db();


function responderRecuperacao(
    array $dados,
    int $status = 200
): never {

    http_response_code(
        $status
    );

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
        |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


function bearerRecuperacao(): string
{
    $header =
        $_SERVER[
            'HTTP_AUTHORIZATION'
        ]
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
        $_SERVER[
            'REQUEST_METHOD'
        ]
        !== 'POST'
    ) {

        responderRecuperacao(
            [
                'success' =>
                    false,

                'message' =>
                    'Método inválido.',
            ],
            405
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CONFIG
    |--------------------------------------------------------------------------
    */

    $configFile =
        dirname(
            __DIR__,
            2
        )
        .
        '/storage/config/worker.php';


    if (
        !file_exists(
            $configFile
        )
    ) {

        throw new RuntimeException(
            'Configuração do worker não encontrada.'
        );
    }


    $config =
        require $configFile;


    $secret =
        trim(
            (string) (
                $config[
                    'secret'
                ]
                ?? ''
            )
        );


    if (
        $secret === ''
        ||
        !hash_equals(
            $secret,
            bearerRecuperacao()
        )
    ) {

        responderRecuperacao(
            [
                'success' =>
                    false,

                'message' =>
                    'Não autorizado.',
            ],
            401
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RECUPERAR PROCESSAMENTOS ANTIGOS
    |--------------------------------------------------------------------------
    |
    | Se ficou "processando" há mais de 2 horas,
    | presumimos que worker caiu / PC desligou.
    |
    |--------------------------------------------------------------------------
    */

    $stmt =
        $pdo->prepare(
            '
            UPDATE capitulo_arquivos

            SET
                ia_status = "na_fila",
                ia_processamento_inicio_at = NULL,
                ia_erro =
                    "Processamento interrompido. Tarefa devolvida automaticamente à fila."

            WHERE ia_status = "processando"

              AND ia_processamento_inicio_at IS NOT NULL

              AND ia_processamento_inicio_at
                    <
                    DATE_SUB(
                        NOW(),
                        INTERVAL 2 HOUR
                    )
            '
        );


    $stmt->execute();


    $recuperadas =
        $stmt->rowCount();


    responderRecuperacao(
        [
            'success' =>
                true,

            'recuperadas' =>
                $recuperadas,

            'message' =>
                $recuperadas > 0
                    ?
                    $recuperadas
                    .
                    ' tarefa(s) recuperada(s).'
                    :
                    'Nenhuma tarefa travada encontrada.',
        ]
    );


} catch (Throwable $e) {

    responderRecuperacao(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}