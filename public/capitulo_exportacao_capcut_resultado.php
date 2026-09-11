<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/config/database.php';

$pdo = db();


function responderExportacaoResultado(
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


function bearerExportacaoResultado(): string
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

        responderExportacaoResultado(
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
            bearerExportacaoResultado()
        )
    ) {

        responderExportacaoResultado(
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


    $exportacaoId =
        (int) (
            $dados[
                'exportacao_id'
            ]
            ?? 0
        );


    $token =
        trim(
            (string) (
                $dados[
                    'token'
                ]
                ?? ''
            )
        );


    $sucesso =
        (bool) (
            $dados[
                'success'
            ]
            ?? false
        );


    $clipsProcessados =
        max(
            0,
            (int) (
                $dados[
                    'clips_processados'
                ]
                ?? 0
            )
        );


    $caminhoSaida =
        trim(
            (string) (
                $dados[
                    'caminho_saida'
                ]
                ?? ''
            )
        );


    if (
        $exportacaoId <= 0
        ||
        $token === ''
    ) {

        throw new RuntimeException(
            'Exportação inválida.'
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

    $stmt =
        $pdo->prepare(
            '
            SELECT
                id,
                status,
                token,
                total_clips

            FROM capitulo_exportacoes

            WHERE id = ?
              AND token = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $exportacaoId,
        $token,
    ]);


    $exportacao =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$exportacao) {

        throw new RuntimeException(
            'Exportação não encontrada ou token inválido.'
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
                    $dados[
                        'error'
                    ]
                    ?? 'Erro desconhecido.'
                )
            );


        $stmtErro =
            $pdo->prepare(
                '
                UPDATE capitulo_exportacoes

                SET
                    status =
                        "erro",

                    clips_processados =
                        ?,

                    erro =
                        ?,

                    concluido_at =
                        NOW()

                WHERE id = ?
                  AND token = ?
                '
            );


        $stmtErro->execute([
            $clipsProcessados,
            $erro,
            $exportacaoId,
            $token,
        ]);


        responderExportacaoResultado(
            [
                'success' =>
                    true,

                'message' =>
                    'Erro da exportação registrado.',
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SUCESSO
    |--------------------------------------------------------------------------
    */

    $stmtSucesso =
        $pdo->prepare(
            '
            UPDATE capitulo_exportacoes

            SET
                status =
                    "concluido",

                clips_processados =
                    ?,

                caminho_saida =
                    ?,

                erro =
                    NULL,

                concluido_at =
                    NOW()

            WHERE id = ?
              AND token = ?
            '
        );


    $stmtSucesso->execute([
        $clipsProcessados,
        $caminhoSaida,
        $exportacaoId,
        $token,
    ]);


    /*
    |--------------------------------------------------------------------------
    | MARCAR CLIPS COMO PREPARADOS
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo =
        $pdo->prepare(
            '
            SELECT
                capitulo_id

            FROM capitulo_exportacoes

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmtCapitulo->execute([
        $exportacaoId
    ]);


    $capituloId =
        (int) $stmtCapitulo
            ->fetchColumn();


    if ($capituloId > 0) {

        $stmtClips =
            $pdo->prepare(
                '
                UPDATE capitulo_edicao_clips

                SET
                    status_clip =
                        "preparado"

                WHERE capitulo_id = ?
                  AND status_clip IN (
                        "sugerido",
                        "aprovado"
                  )
                '
            );


        $stmtClips->execute([
            $capituloId
        ]);
    }


    responderExportacaoResultado(
        [
            'success' =>
                true,

            'message' =>
                'Exportação CapCut concluída.',

            'exportacao_id' =>
                $exportacaoId,

            'clips_processados' =>
                $clipsProcessados,

            'caminho_saida' =>
                $caminhoSaida,
        ]
    );


} catch (Throwable $e) {

    responderExportacaoResultado(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}