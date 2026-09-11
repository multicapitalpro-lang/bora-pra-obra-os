<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/config/database.php';

$pdo = db();


function responderExportacaoProxima(
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


function bearerExportacao(): string
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
        !==
        'POST'
    ) {

        responderExportacaoProxima(
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
    | AUTENTICAÇÃO DO WORKER
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
            bearerExportacao()
        )
    ) {

        responderExportacaoProxima(
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
    | CONEXÃO
    |--------------------------------------------------------------------------
    */

    $pdo =
        db(true);


    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | PEGAR PRÓXIMA EXPORTAÇÃO
    |--------------------------------------------------------------------------
    */

    $stmtExportacao =
        $pdo->prepare(
            '
            SELECT
                ce.id,
                ce.capitulo_id,
                ce.total_clips,

                c.numero AS capitulo_numero,
                c.titulo AS capitulo_titulo

            FROM capitulo_exportacoes ce

            INNER JOIN capitulos c
                ON c.id = ce.capitulo_id

            WHERE ce.tipo =
                "capcut_clips"

              AND ce.status =
                "na_fila"

            ORDER BY
                ce.id ASC

            LIMIT 1

            FOR UPDATE
            '
        );


    $stmtExportacao->execute();


    $exportacao =
        $stmtExportacao->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$exportacao) {

        $pdo->commit();


        responderExportacaoProxima(
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
            random_bytes(
                32
            )
        );


    $stmtAtualizar =
        $pdo->prepare(
            '
            UPDATE capitulo_exportacoes

            SET
                status =
                    "processando",

                token =
                    ?,

                processamento_inicio_at =
                    NOW(),

                clips_processados =
                    0,

                erro =
                    NULL

            WHERE id = ?
            '
        );


    $stmtAtualizar->execute([
        $token,
        (int) $exportacao[
            'id'
        ],
    ]);


    /*
    |--------------------------------------------------------------------------
    | BUSCAR CLIPS
    |--------------------------------------------------------------------------
    */

    $stmtClips =
        $pdo->prepare(
            '
            SELECT
                cec.id AS clip_id,
                cec.ordem,
                cec.arquivo_id,
                cec.titulo_clip,
                cec.funcao,
                cec.camada,

                cec.inicio_ms,
                cec.fim_ms,
                cec.duracao_ms,

                cec.inicio_ms_refinado,
                cec.fim_ms_refinado,
                cec.refino_status,

                cec.usar_audio,

                ca.nome_arquivo,
                ca.drive_file_id,
                ca.mime_type,
                ca.tamanho_bytes

            FROM capitulo_edicao_clips cec

            INNER JOIN capitulo_arquivos ca
                ON ca.id =
                    cec.arquivo_id

            WHERE cec.capitulo_id = ?

            ORDER BY
                cec.ordem ASC,
                cec.id ASC
            '
        );


    $stmtClips->execute([
        (int) $exportacao[
            'capitulo_id'
        ]
    ]);


    $clips =
        $stmtClips->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (!$clips) {

        throw new RuntimeException(
            'A exportação não possui clips para preparar.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | AGRUPAR POR ARQUIVO
    |--------------------------------------------------------------------------
    */

    $arquivos =
        [];


    foreach (
        $clips
        as $clip
    ) {

        /*
        |--------------------------------------------------------------------------
        | ESCOLHER TEMPO EFETIVO
        |--------------------------------------------------------------------------
        */

        $usarRefinado =
            (
                (
                    $clip[
                        'refino_status'
                    ]
                    ?? ''
                )
                ===
                'refinado'
            )
            &&
            $clip[
                'inicio_ms_refinado'
            ] !== null
            &&
            $clip[
                'fim_ms_refinado'
            ] !== null;


        if ($usarRefinado) {

            $inicioEfetivo =
                (int) $clip[
                    'inicio_ms_refinado'
                ];


            $fimEfetivo =
                (int) $clip[
                    'fim_ms_refinado'
                ];

        } else {

            $inicioEfetivo =
                (int) $clip[
                    'inicio_ms'
                ];


            $fimEfetivo =
                (int) $clip[
                    'fim_ms'
                ];
        }


        /*
        |--------------------------------------------------------------------------
        | DURAÇÃO EFETIVA
        |--------------------------------------------------------------------------
        */

        $duracaoEfetiva =
            max(
                0,
                $fimEfetivo
                -
                $inicioEfetivo
            );


        if (
            $duracaoEfetiva
            <=
            0
        ) {

            throw new RuntimeException(
                'Clip '
                .
                (int) $clip[
                    'clip_id'
                ]
                .
                ' possui duração inválida.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ARQUIVO
        |--------------------------------------------------------------------------
        */

        $arquivoId =
            (int) $clip[
                'arquivo_id'
            ];


        if (
            !isset(
                $arquivos[
                    $arquivoId
                ]
            )
        ) {

            $arquivos[
                $arquivoId
            ] = [

                'arquivo_id' =>
                    $arquivoId,

                'nome_arquivo' =>
                    (string) $clip[
                        'nome_arquivo'
                    ],

                'drive_file_id' =>
                    (string) $clip[
                        'drive_file_id'
                    ],

                'mime_type' =>
                    (string) (
                        $clip[
                            'mime_type'
                        ]
                        ?? ''
                    ),

                'tamanho_bytes' =>
                    isset(
                        $clip[
                            'tamanho_bytes'
                        ]
                    )
                        ?
                        (int) $clip[
                            'tamanho_bytes'
                        ]
                        :
                        null,

                'clips' =>
                    [],
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | CLIP ENVIADO AO WORKER
        |--------------------------------------------------------------------------
        |
        | IMPORTANTE:
        |
        | O worker continua lendo:
        | inicio_ms
        | fim_ms
        | duracao_ms
        |
        | Porém agora esses valores já são os EFETIVOS.
        |
        |--------------------------------------------------------------------------
        */

        $arquivos[
            $arquivoId
        ][
            'clips'
        ][] = [

            'clip_id' =>
                (int) $clip[
                    'clip_id'
                ],

            'ordem' =>
                (int) $clip[
                    'ordem'
                ],

            'titulo_clip' =>
                (string) (
                    $clip[
                        'titulo_clip'
                    ]
                    ?? ''
                ),

            'funcao' =>
                (string) (
                    $clip[
                        'funcao'
                    ]
                    ?? 'principal'
                ),

            'camada' =>
                (string) (
                    $clip[
                        'camada'
                    ]
                    ?? 'principal'
                ),


            /*
            |--------------------------------------------------------------------------
            | TEMPOS EFETIVOS
            |--------------------------------------------------------------------------
            */

            'inicio_ms' =>
                $inicioEfetivo,

            'fim_ms' =>
                $fimEfetivo,

            'duracao_ms' =>
                $duracaoEfetiva,


            /*
            |--------------------------------------------------------------------------
            | DEBUG / CONTROLE
            |--------------------------------------------------------------------------
            */

            'refinado' =>
                $usarRefinado,


            /*
            |--------------------------------------------------------------------------
            | ÁUDIO
            |--------------------------------------------------------------------------
            */

            'usar_audio' =>
                !empty(
                    $clip[
                        'usar_audio'
                    ]
                ),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RETORNO
    |--------------------------------------------------------------------------
    */

    responderExportacaoProxima(
        [
            'success' =>
                true,

            'task' => [

                'exportacao_id' =>
                    (int) $exportacao[
                        'id'
                    ],

                'capitulo_id' =>
                    (int) $exportacao[
                        'capitulo_id'
                    ],

                'capitulo_numero' =>
                    (int) $exportacao[
                        'capitulo_numero'
                    ],

                'capitulo_titulo' =>
                    (string) $exportacao[
                        'capitulo_titulo'
                    ],

                'total_clips' =>
                    (int) $exportacao[
                        'total_clips'
                    ],

                'token' =>
                    $token,

                'arquivos' =>
                    array_values(
                        $arquivos
                    ),
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


    responderExportacaoProxima(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}