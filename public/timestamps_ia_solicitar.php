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


function responderTimestampsSolicitar(
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


try {

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        responderTimestampsSolicitar(
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
    | CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $capituloId =
        (int) (
            $_POST['capitulo_id']
            ?? 0
        );


    /*
     * Modo teste:
     *
     * 1 = enfileira apenas UM arquivo.
     * 0 = enfileira todos os escolhidos no plano.
     */

    $somenteUm =
        (
            (string) (
                $_POST['somente_um']
                ?? '1'
            )
        )
        === '1';


    if ($capituloId <= 0) {

        throw new RuntimeException(
            'Capítulo inválido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CONFERIR CAPÍTULO
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
    | BUSCAR ARQUIVOS DO PLANO SEM TIMESTAMPS
    |--------------------------------------------------------------------------
    */

    $sql =
        '
        SELECT DISTINCT
            ca.id,
            ca.nome_arquivo,
            ca.ia_timestamps_status,
            cpa.ordem

        FROM capitulo_plano_arquivos cpa

        INNER JOIN capitulo_arquivos ca
            ON ca.id = cpa.arquivo_id

        WHERE cpa.capitulo_id = ?

          AND ca.capitulo_id = ?

          AND ca.ativo = 1

          AND ca.ia_status = "concluido"

          AND ca.ia_timestamps_status IN (
                "nao_gerado",
                "erro"
          )

        ORDER BY
            cpa.ordem ASC,
            ca.id ASC
        ';


    if ($somenteUm) {

        $sql .=
            '
            LIMIT 1
            ';
    }


    $stmtArquivos =
        $pdo->prepare(
            $sql
        );


    $stmtArquivos->execute([
        $capituloId,
        $capituloId,
    ]);


    $arquivos =
        $stmtArquivos->fetchAll(
            PDO::FETCH_ASSOC
        );


    /*
    |--------------------------------------------------------------------------
    | NENHUM PENDENTE
    |--------------------------------------------------------------------------
    */

    if (!$arquivos) {

        responderTimestampsSolicitar(
            [
                'success' =>
                    true,

                'message' =>
                    'Nenhum arquivo do plano precisa gerar timestamps.',

                'enfileirados' =>
                    0,

                'modo_teste' =>
                    $somenteUm,
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ENFILEIRAR
    |--------------------------------------------------------------------------
    */

    $pdo =
        db(true);


    $pdo->beginTransaction();


    $stmtAtualizar =
        $pdo->prepare(
            '
            UPDATE capitulo_arquivos

            SET
                ia_timestamps_status =
                    "na_fila",

                ia_timestamps_token =
                    NULL,

                ia_timestamps_inicio_at =
                    NULL,

                ia_timestamps_erro =
                    NULL

            WHERE id = ?

              AND ia_timestamps_status IN (
                    "nao_gerado",
                    "erro"
              )
            '
        );


    $enfileirados =
        0;


    $nomes =
        [];


    foreach (
        $arquivos
        as $arquivo
    ) {

        $stmtAtualizar->execute([
            (int) $arquivo['id']
        ]);


        if (
            $stmtAtualizar->rowCount()
            < 1
        ) {

            continue;
        }


        $enfileirados++;


        $nomes[] =
            (string) $arquivo[
                'nome_arquivo'
            ];
    }


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RETORNO
    |--------------------------------------------------------------------------
    */

    responderTimestampsSolicitar(
        [
            'success' =>
                true,

            'message' =>
                $enfileirados === 1
                    ?
                    '1 arquivo enviado para geração de precisão do CapCut.'
                    :
                    $enfileirados
                    .
                    ' arquivos enviados para geração de precisão do CapCut.',

            'enfileirados' =>
                $enfileirados,

            'modo_teste' =>
                $somenteUm,

            'arquivos' =>
                $nomes,
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


    responderTimestampsSolicitar(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}