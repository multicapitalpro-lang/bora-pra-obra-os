<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$pdo = db();


try {

    /*
    |--------------------------------------------------------------------------
    | MÉTODO
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        throw new RuntimeException(
            'Método inválido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $capituloId =
        (int) (
            $_POST['capitulo_id']
            ?? 0
        );


    if ($capituloId <= 0) {

        throw new RuntimeException(
            'Capítulo inválido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CONFIRMAR CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo =
        $pdo->prepare(
            '
            SELECT
                id,
                numero,
                titulo
            FROM capitulos
            WHERE id = ?
            LIMIT 1
            '
        );


    $stmtCapitulo->execute([
        $capituloId
    ]);


    $capitulo =
        $stmtCapitulo->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$capitulo) {

        throw new RuntimeException(
            'Capítulo não encontrado.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | LOCALIZAR ARQUIVOS QUE PRECISAM DE IA
    |--------------------------------------------------------------------------
    |
    | Nesta primeira versão:
    |
    | - nao_analisado → entra na fila
    | - erro          → pode tentar novamente
    | - processando   → não duplicamos, EXCETO se travado há mais de
    |                    30 minutos (o worker processou mas o envio do
    |                    resultado falhou por timeout do servidor,
    |                    ex.: 504 -- sem isso o arquivo ficaria preso
    |                    em "processando" pra sempre)
    | - concluido     → preservamos
    |
    |--------------------------------------------------------------------------
    */

    $stmtArquivos =
        $pdo->prepare(
            '
            SELECT
                id,
                drive_file_id,
                nome_arquivo,
                ia_status
            FROM capitulo_arquivos
            WHERE capitulo_id = ?
              AND ativo = 1
              AND drive_file_id IS NOT NULL
              AND drive_file_id <> ""
              AND (
                    ia_status IN ("nao_analisado", "erro")
                    OR (
                        ia_status = "processando"
                        AND ia_processamento_inicio_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                    )
              )
            ORDER BY ordem ASC, id ASC
            '
        );


    $stmtArquivos->execute([
        $capituloId
    ]);


    $arquivos =
        $stmtArquivos->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
    |--------------------------------------------------------------------------
    | NENHUM NOVO ARQUIVO
    |--------------------------------------------------------------------------
    */

    if (!$arquivos) {


        /*
         * Descobrir situação atual
         */

        $stmtResumo =
            $pdo->prepare(
                '
                SELECT
                    COUNT(*) AS total,
                    SUM(
                        ia_status = "concluido"
                    ) AS concluidos,
                    SUM(
                        ia_status = "processando"
                    ) AS processando
                FROM capitulo_arquivos
                WHERE capitulo_id = ?
                  AND ativo = 1
                '
            );


        $stmtResumo->execute([
            $capituloId
        ]);


        $resumo =
            $stmtResumo->fetch(
                PDO::FETCH_ASSOC
            )
            ?: [];


        echo json_encode(
            [

                'success' =>
                    true,

                'message' =>
                    'Nenhum novo bruto precisa entrar na fila de IA.',

                'enfileirados' =>
                    0,

                'total' =>
                    (int) (
                        $resumo['total']
                        ?? 0
                    ),

                'concluidos' =>
                    (int) (
                        $resumo['concluidos']
                        ?? 0
                    ),

                'processando' =>
                    (int) (
                        $resumo['processando']
                        ?? 0
                    ),

            ],
            JSON_UNESCAPED_UNICODE
        );


        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | ENFILEIRAR
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    $stmtAtualizar =
        $pdo->prepare(
            '
            UPDATE capitulo_arquivos
            SET
                ia_status = "na_fila",
                ia_solicitado_at = NOW(),
                ia_processamento_token = ?,
                ia_processamento_inicio_at = NULL,
                ia_erro = NULL
            WHERE id = ?
              AND capitulo_id = ?
              AND (
                    ia_status IN ("nao_analisado", "erro")
                    OR (
                        ia_status = "processando"
                        AND ia_processamento_inicio_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                    )
              )
            '
        );


    $enfileirados = 0;

    $tarefas = [];


    foreach ($arquivos as $arquivo) {


        /*
        |--------------------------------------------------------------------------
        | TOKEN ÚNICO
        |--------------------------------------------------------------------------
        */

        $token =
            bin2hex(
                random_bytes(32)
            );


        /*
        |--------------------------------------------------------------------------
        | ATUALIZAR
        |--------------------------------------------------------------------------
        */

        $stmtAtualizar->execute(
            [

                $token,

                (int) $arquivo['id'],

                $capituloId,

            ]
        );


        /*
         * Se outra chamada alterou esse
         * arquivo antes de nós, rowCount
         * ficará zero.
         */

        if (
            $stmtAtualizar->rowCount()
            < 1
        ) {

            continue;
        }


        $enfileirados++;


        $tarefas[] = [

            'arquivo_id' =>
                (int) $arquivo['id'],

            'nome_arquivo' =>
                (string) $arquivo[
                    'nome_arquivo'
                ],

            'token' =>
                $token,

        ];

    }


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RESUMO
    |--------------------------------------------------------------------------
    */

    $stmtResumo =
        $pdo->prepare(
            '
            SELECT
                COUNT(*) AS total,

                SUM(
                    ia_status = "nao_analisado"
                ) AS nao_analisados,

                SUM(
                    ia_status = "processando"
                ) AS processando,

                SUM(
                    ia_status = "concluido"
                ) AS concluidos,

                SUM(
                    ia_status = "erro"
                ) AS erros

            FROM capitulo_arquivos

            WHERE capitulo_id = ?
              AND ativo = 1
            '
        );


    $stmtResumo->execute([
        $capituloId
    ]);


    $resumo =
        $stmtResumo->fetch(
            PDO::FETCH_ASSOC
        )
        ?: [];


    /*
    |--------------------------------------------------------------------------
    | LOG
    |--------------------------------------------------------------------------
    */

    try {

        $stmtLog =
            $pdo->prepare(
                '
                INSERT INTO atividades
                (
                    entidade,
                    entidade_id,
                    usuario,
                    acao,
                    detalhes
                )
                VALUES
                (
                    "capitulo",
                    ?,
                    NULL,
                    "Triagem com IA solicitada",
                    ?
                )
                '
            );


        $detalhesLog =
            json_encode(
                [

                    'capitulo_id' =>
                        $capituloId,

                    'enfileirados' =>
                        $enfileirados,

                    'arquivos' =>
                        array_map(
                            static function (
                                array $item
                            ): array {

                                return [

                                    'arquivo_id' =>
                                        $item[
                                            'arquivo_id'
                                        ],

                                    'nome_arquivo' =>
                                        $item[
                                            'nome_arquivo'
                                        ],

                                ];
                            },
                            $tarefas
                        ),

                ],
                JSON_UNESCAPED_UNICODE
            );


        $stmtLog->execute(
            [

                $capituloId,

                $detalhesLog,

            ]
        );


    } catch (Throwable $e) {

        /*
         * O log não pode impedir
         * a fila principal.
         */
    }


    /*
    |--------------------------------------------------------------------------
    | RETORNO
    |--------------------------------------------------------------------------
    */

    echo json_encode(
        [

            'success' =>
                true,

            'message' =>
                $enfileirados === 1
                    ? '1 bruto enviado para análise com IA.'
                    : $enfileirados
                        . ' brutos enviados para análise com IA.',

            'enfileirados' =>
                $enfileirados,

            'total' =>
                (int) (
                    $resumo['total']
                    ?? 0
                ),

            'nao_analisados' =>
                (int) (
                    $resumo[
                        'nao_analisados'
                    ]
                    ?? 0
                ),

            'processando' =>
                (int) (
                    $resumo[
                        'processando'
                    ]
                    ?? 0
                ),

            'concluidos' =>
                (int) (
                    $resumo[
                        'concluidos'
                    ]
                    ?? 0
                ),

            'erros' =>
                (int) (
                    $resumo[
                        'erros'
                    ]
                    ?? 0
                ),

        ],
        JSON_UNESCAPED_UNICODE
    );


} catch (Throwable $e) {


    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    if (
        isset($pdo)
        &&
        $pdo instanceof PDO
        &&
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();
    }


    http_response_code(422);


    echo json_encode(
        [

            'success' =>
                false,

            'message' =>
                $e->getMessage(),

        ],
        JSON_UNESCAPED_UNICODE
    );
}