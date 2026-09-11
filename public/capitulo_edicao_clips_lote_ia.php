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


/*
|--------------------------------------------------------------------------
| RESPONDER
|--------------------------------------------------------------------------
*/

function responderClipsLote(
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


/*
|--------------------------------------------------------------------------
| EXECUÇÃO
|--------------------------------------------------------------------------
*/

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

        responderClipsLote(
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
    | ARQUIVOS DA LINHA DE EDIÇÃO
    |--------------------------------------------------------------------------
    |
    | Aqui buscamos TODOS os arquivos escolhidos pela IA.10.
    |
    |--------------------------------------------------------------------------
    */

    $stmtArquivos =
        $pdo->prepare(
            '
            SELECT
                cpa.id AS plano_arquivo_id,
                cpa.arquivo_id,
                cpa.ordem,
                cpa.funcao,
                cpa.instrucao,

                ca.nome_arquivo,
                ca.ia_status,
                ca.ia_timestamps_status,

                (
                    SELECT COUNT(*)

                    FROM capitulo_arquivo_segmentos cas

                    WHERE cas.arquivo_id =
                        ca.id
                ) AS total_segmentos,

                (
                    SELECT COUNT(*)

                    FROM capitulo_edicao_clips cec

                    WHERE cec.capitulo_id =
                        cpa.capitulo_id

                      AND cec.arquivo_id =
                        cpa.arquivo_id

                      AND cec.origem =
                        "ia"
                ) AS total_clips_ia

            FROM capitulo_plano_arquivos cpa

            INNER JOIN capitulo_arquivos ca
                ON ca.id = cpa.arquivo_id

            WHERE cpa.capitulo_id = ?

            ORDER BY
                cpa.ordem ASC,
                cpa.id ASC
            '
        );


    $stmtArquivos->execute([
        $capituloId
    ]);


    $arquivosBanco =
        $stmtArquivos->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (!$arquivosBanco) {

        throw new RuntimeException(
            'A linha de edição deste capítulo está vazia.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CLASSIFICAR SITUAÇÃO
    |--------------------------------------------------------------------------
    */

    $prontos = [];

    $semTimestamps = [];

    $erroTriagem = [];

    $jaGerados = [];


    foreach (
        $arquivosBanco
        as $arquivo
    ) {

        $item = [

            'plano_arquivo_id' =>
                (int) $arquivo[
                    'plano_arquivo_id'
                ],

            'arquivo_id' =>
                (int) $arquivo[
                    'arquivo_id'
                ],

            'ordem' =>
                (int) $arquivo[
                    'ordem'
                ],

            'funcao' =>
                (string) $arquivo[
                    'funcao'
                ],

            'nome_arquivo' =>
                (string) $arquivo[
                    'nome_arquivo'
                ],

            'ia_status' =>
                (string) $arquivo[
                    'ia_status'
                ],

            'ia_timestamps_status' =>
                (string) $arquivo[
                    'ia_timestamps_status'
                ],

            'total_segmentos' =>
                (int) $arquivo[
                    'total_segmentos'
                ],

            'total_clips_ia' =>
                (int) $arquivo[
                    'total_clips_ia'
                ],

        ];


        /*
        |--------------------------------------------------------------------------
        | TRIAGEM COM ERRO
        |--------------------------------------------------------------------------
        */

        if (
            $item[
                'ia_status'
            ]
            !== 'concluido'
        ) {

            $erroTriagem[] =
                $item;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | SEM TIMESTAMPS
        |--------------------------------------------------------------------------
        */

        if (
            $item[
                'ia_timestamps_status'
            ]
            !== 'concluido'
            ||
            $item[
                'total_segmentos'
            ]
            <= 0
        ) {

            $semTimestamps[] =
                $item;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | JÁ TEM CLIPS
        |--------------------------------------------------------------------------
        |
        | Também devolvemos como pronto.
        |
        | O endpoint individual apaga apenas sugestões antigas
        | daquele arquivo e gera uma nova versão.
        |
        |--------------------------------------------------------------------------
        */

        if (
            $item[
                'total_clips_ia'
            ]
            > 0
        ) {

            $jaGerados[] =
                $item;
        }


        /*
        |--------------------------------------------------------------------------
        | PRONTO PARA GERAR
        |--------------------------------------------------------------------------
        */

        $prontos[] =
            $item;
    }


    /*
    |--------------------------------------------------------------------------
    | RETORNO
    |--------------------------------------------------------------------------
    */

    responderClipsLote(
        [

            'success' =>
                true,

            'message' =>
                count($prontos)
                .
                ' arquivo(s) pronto(s) para gerar cortes precisos.',

            'capitulo' => [

                'id' =>
                    (int) $capitulo[
                        'id'
                    ],

                'numero' =>
                    (int) $capitulo[
                        'numero'
                    ],

                'titulo' =>
                    (string) $capitulo[
                        'titulo'
                    ],

            ],

            'total_plano' =>
                count(
                    $arquivosBanco
                ),

            'total_prontos' =>
                count(
                    $prontos
                ),

            'total_sem_timestamps' =>
                count(
                    $semTimestamps
                ),

            'total_erro_triagem' =>
                count(
                    $erroTriagem
                ),

            'total_ja_gerados' =>
                count(
                    $jaGerados
                ),

            /*
             * Esta é a lista que o JavaScript
             * vai processar um por um.
             */

            'arquivos' =>
                $prontos,

            'sem_timestamps' =>
                $semTimestamps,

            'erro_triagem' =>
                $erroTriagem,

            'ja_gerados' =>
                $jaGerados,

        ]
    );


} catch (Throwable $e) {

    responderClipsLote(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}