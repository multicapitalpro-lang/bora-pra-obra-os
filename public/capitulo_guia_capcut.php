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


function responderGuia(
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


function formatarTempoGuia(
    int $ms
): string {

    $totalSegundos =
        (int) floor(
            $ms / 1000
        );

    $horas =
        (int) floor(
            $totalSegundos / 3600
        );

    $minutos =
        (int) floor(
            (
                $totalSegundos % 3600
            ) / 60
        );

    $segundos =
        $totalSegundos % 60;

    $milissegundos =
        $ms % 1000;


    if ($horas > 0) {

        return sprintf(
            '%02d:%02d:%02d.%03d',
            $horas,
            $minutos,
            $segundos,
            $milissegundos
        );
    }


    return sprintf(
        '%02d:%02d.%03d',
        $minutos,
        $segundos,
        $milissegundos
    );
}


try {

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        responderGuia(
            [
                'success' => false,
                'message' => 'Método inválido.',
            ],
            405
        );
    }


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
    | CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo =
        $pdo->prepare(
            '
            SELECT
                id,
                numero,
                titulo,
                titulo_publico

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
    | CLIPS
    |--------------------------------------------------------------------------
    */

    $stmt =
        $pdo->prepare(
            '
            SELECT
                cec.id,
                cec.ordem,
                cec.arquivo_id,
                cec.plano_arquivo_id,
                cec.titulo_clip,
                cec.funcao,
                cec.camada,

                cec.inicio_ms,
                cec.fim_ms,
                cec.duracao_ms,

                cec.inicio_ms_original,
                cec.fim_ms_original,

                cec.inicio_ms_refinado,
                cec.fim_ms_refinado,
                cec.refino_status,
                cec.refino_motivo,

                cec.timeline_inicio_ms,
                cec.usar_audio,
                cec.volume_audio,
                cec.texto_tela,
                cec.instrucao,
                cec.motivo_corte,
                cec.status_clip,

                ca.nome_arquivo,
                ca.drive_url,
                ca.drive_file_id

            FROM capitulo_edicao_clips cec

            INNER JOIN capitulo_arquivos ca
                ON ca.id = cec.arquivo_id

            WHERE cec.capitulo_id = ?

            ORDER BY
                cec.ordem ASC,
                cec.id ASC
            '
        );


    $stmt->execute([
        $capituloId
    ]);


    $clipsBanco =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (!$clipsBanco) {

        responderGuia(
            [
                'success' => true,
                'capitulo' => $capitulo,
                'clips' => [],
                'total_clips' => 0,
                'duracao_total_ms' => 0,
                'duracao_total' => '00:00.000',
                'message' =>
                    'Ainda não existem cortes preparados para este capítulo.',
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PREPARAR
    |--------------------------------------------------------------------------
    */

    $clips = [];

    $duracaoTotalMs = 0;


    foreach (
        $clipsBanco
        as $clip
    ) {

        /*
        |--------------------------------------------------------------------------
        | ESCOLHER TEMPO EFETIVO
        |--------------------------------------------------------------------------
        |
        | Se o refinamento foi concluído:
        | usa inicio_ms_refinado/fim_ms_refinado.
        |
        | Senão:
        | usa inicio_ms/fim_ms originais.
        |
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

            $inicioMs =
                (int) $clip[
                    'inicio_ms_refinado'
                ];


            $fimMs =
                (int) $clip[
                    'fim_ms_refinado'
                ];

        } else {

            $inicioMs =
                (int) $clip[
                    'inicio_ms'
                ];


            $fimMs =
                (int) $clip[
                    'fim_ms'
                ];
        }


        /*
        |--------------------------------------------------------------------------
        | DURAÇÃO REAL DO CORTE USADO
        |--------------------------------------------------------------------------
        */

        $duracaoMs =
            max(
                0,
                $fimMs
                -
                $inicioMs
            );


        $duracaoTotalMs +=
            $duracaoMs;


        $clips[] = [

            'id' =>
                (int) $clip[
                    'id'
                ],

            'ordem' =>
                (int) $clip[
                    'ordem'
                ],

            'arquivo_id' =>
                (int) $clip[
                    'arquivo_id'
                ],

            'nome_arquivo' =>
                (string) $clip[
                    'nome_arquivo'
                ],

            'drive_url' =>
                (string) (
                    $clip[
                        'drive_url'
                    ]
                    ?? ''
                ),

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
                $inicioMs,

            'fim_ms' =>
                $fimMs,

            'duracao_ms' =>
                $duracaoMs,


            /*
            |--------------------------------------------------------------------------
            | TEMPOS FORMATADOS
            |--------------------------------------------------------------------------
            */

            'inicio' =>
                formatarTempoGuia(
                    $inicioMs
                ),

            'fim' =>
                formatarTempoGuia(
                    $fimMs
                ),

            'duracao' =>
                formatarTempoGuia(
                    $duracaoMs
                ),


            /*
            |--------------------------------------------------------------------------
            | INFORMAÇÃO DO REFINAMENTO
            |--------------------------------------------------------------------------
            */

            'refinado' =>
                $usarRefinado,

            'refino_status' =>
                (string) (
                    $clip[
                        'refino_status'
                    ]
                    ?? 'nao_refinado'
                ),

            'refino_motivo' =>
                (string) (
                    $clip[
                        'refino_motivo'
                    ]
                    ?? ''
                ),

            'inicio_ms_original' =>
                $clip[
                    'inicio_ms_original'
                ] !== null
                    ?
                    (int) $clip[
                        'inicio_ms_original'
                    ]
                    :
                    (int) $clip[
                        'inicio_ms'
                    ],

            'fim_ms_original' =>
                $clip[
                    'fim_ms_original'
                ] !== null
                    ?
                    (int) $clip[
                        'fim_ms_original'
                    ]
                    :
                    (int) $clip[
                        'fim_ms'
                    ],


            /*
            |--------------------------------------------------------------------------
            | ÁUDIO / EDIÇÃO
            |--------------------------------------------------------------------------
            */

            'usar_audio' =>
                (bool) $clip[
                    'usar_audio'
                ],

            'volume_audio' =>
                $clip[
                    'volume_audio'
                ] !== null
                    ?
                    (int) $clip[
                        'volume_audio'
                    ]
                    :
                    null,

            'texto_tela' =>
                (string) (
                    $clip[
                        'texto_tela'
                    ]
                    ?? ''
                ),

            'instrucao' =>
                (string) (
                    $clip[
                        'instrucao'
                    ]
                    ?? ''
                ),

            'motivo_corte' =>
                (string) (
                    $clip[
                        'motivo_corte'
                    ]
                    ?? ''
                ),

            'status_clip' =>
                (string) (
                    $clip[
                        'status_clip'
                    ]
                    ?? 'sugerido'
                ),

        ];
    }


    responderGuia(
        [
            'success' =>
                true,

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
                    (string) (
                        $capitulo[
                            'titulo_publico'
                        ]
                        ?:
                        $capitulo[
                            'titulo'
                        ]
                    ),

            ],

            'total_clips' =>
                count(
                    $clips
                ),

            'duracao_total_ms' =>
                $duracaoTotalMs,

            'duracao_total' =>
                formatarTempoGuia(
                    $duracaoTotalMs
                ),

            'clips' =>
                $clips,
        ]
    );


} catch (Throwable $e) {

    responderGuia(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}