<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/config/database.php';

require_once dirname(__DIR__)
    . '/app/AI/OpenAIService.php';


$pdo = db();


function responderResultado(
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


function bearerResultado(): string
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


function validarTriagemVisual(
    array $triagem
): array {

    $decisoesPermitidas = [
        'usar',
        'parcial',
        'descartar',
    ];

    $tiposPermitidos = [
        'nao_definido',
        'fala',
        'execucao',
        'broll',
        'drone',
        'detalhe',
        'ambiente',
        'timelapse',
        'foto',
    ];

    $importanciasPermitidas = [
        'normal',
        'boa',
        'forte',
        'essencial',
    ];


    $decisao =
        (string) (
            $triagem['decisao']
            ?? 'parcial'
        );

    $tipo =
        (string) (
            $triagem['tipo']
            ?? 'nao_definido'
        );

    $importancia =
        (string) (
            $triagem['importancia']
            ?? 'normal'
        );


    if (
        !in_array(
            $decisao,
            $decisoesPermitidas,
            true
        )
    ) {
        $decisao =
            'parcial';
    }


    if (
        !in_array(
            $tipo,
            $tiposPermitidos,
            true
        )
    ) {
        $tipo =
            'nao_definido';
    }


    if (
        !in_array(
            $importancia,
            $importanciasPermitidas,
            true
        )
    ) {
        $importancia =
            'normal';
    }


    return [

        'decisao' =>
            $decisao,

        'tipo' =>
            $tipo,

        'importancia' =>
            $importancia,

        'possivel_uso' =>
            trim(
                (string) (
                    $triagem[
                        'possivel_uso'
                    ]
                    ?? ''
                )
            ),

        'observacoes' =>
            trim(
                (string) (
                    $triagem[
                        'observacoes'
                    ]
                    ?? ''
                )
            ),

        'resumo' =>
            trim(
                (string) (
                    $triagem[
                        'resumo'
                    ]
                    ?? ''
                )
            ),

        'motivo_decisao' =>
            trim(
                (string) (
                    $triagem[
                        'motivo_decisao'
                    ]
                    ?? ''
                )
            ),

    ];
}


try {

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        responderResultado(
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
                $config['secret']
                ?? ''
            )
        );


    if (
        $secret === ''
        ||
        !hash_equals(
            $secret,
            bearerResultado()
        )
    ) {

        responderResultado(
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
    | BODY
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


    $modo =
        trim(
            (string) (
                $dados['modo']
                ?? 'audio'
            )
        );


    if (
        !in_array(
            $modo,
            [
                'audio',
                'visual',
            ],
            true
        )
    ) {
        $modo =
            'audio';
    }


    if (
        $arquivoId <= 0
        ||
        $token === ''
    ) {

        throw new RuntimeException(
            'Identificação da tarefa inválida.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | LOCALIZAR TAREFA
    |--------------------------------------------------------------------------
    */

    $stmt =
        $pdo->prepare(
            '
            SELECT
                ca.*,

                c.numero
                    AS capitulo_numero,

                c.titulo
                    AS capitulo_titulo

            FROM capitulo_arquivos ca

            INNER JOIN capitulos c
                ON c.id = ca.capitulo_id

            WHERE ca.id = ?
              AND ca.ia_processamento_token = ?

            LIMIT 1
            '
        );


    $stmt->execute([
        $arquivoId,
        $token,
    ]);


    $arquivo =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$arquivo) {

        throw new RuntimeException(
            'Tarefa não encontrada ou token inválido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ERRO DO WORKER
    |--------------------------------------------------------------------------
    */

    if (!$sucesso) {

        $erro =
            trim(
                (string) (
                    $dados['error']
                    ?? 'Erro desconhecido no worker.'
                )
            );


        $stmtErro =
            $pdo->prepare(
                '
                UPDATE capitulo_arquivos

                SET
                    ia_status = "erro",
                    ia_erro = ?,
                    ia_analisado_at = NULL

                WHERE id = ?
                  AND ia_processamento_token = ?
                '
            );


        $stmtErro->execute([
            $erro,
            $arquivoId,
            $token,
        ]);


        responderResultado(
            [
                'success' =>
                    true,

                'message' =>
                    'Erro registrado.',
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TRIAGEM
    |--------------------------------------------------------------------------
    */

    $transcricao =
        null;

    $transcricaoJsonString =
        null;

    $triagem =
        [];

    $resumo =
        '';


    /*
    |--------------------------------------------------------------------------
    | MODO VISUAL
    |--------------------------------------------------------------------------
    */

    if ($modo === 'visual') {

        $triagemVisual =
            $dados[
                'triagem_visual'
            ]
            ?? [];


        if (
            !is_array(
                $triagemVisual
            )
            ||
            !$triagemVisual
        ) {

            throw new RuntimeException(
                'A análise visual não foi enviada.'
            );
        }


        $triagem =
            validarTriagemVisual(
                $triagemVisual
            );


        $resumo =
            $triagem[
                'resumo'
            ];


        $analiseVisual =
            $dados[
                'analise_visual'
            ]
            ?? [];


        $transcricaoJsonString =
            json_encode(
                [

                    'modo' =>
                        'visual',

                    'sem_fala_detectada' =>
                        true,

                    'analise_visual' =>
                        $analiseVisual,

                    'triagem_visual' =>
                        $triagemVisual,

                ],
                JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
            );

    }


    /*
    |--------------------------------------------------------------------------
    | MODO ÁUDIO
    |--------------------------------------------------------------------------
    */

    else {

        $transcricao =
            trim(
                (string) (
                    $dados[
                        'transcricao'
                    ]
                    ?? ''
                )
            );


        if ($transcricao === '') {

            throw new RuntimeException(
                'O worker não enviou nenhuma transcrição.'
            );
        }


        $transcricaoJson =
            $dados[
                'transcricao_json'
            ]
            ?? [
                'text' =>
                    $transcricao,
            ];


        $transcricaoJsonString =
            json_encode(
                $transcricaoJson,
                JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
            );


        /*
        |--------------------------------------------------------------------------
        | IA EDITORIAL DO PHP
        |--------------------------------------------------------------------------
        */

        $openAI =
            new OpenAIService();


        $duracaoSegundos =
            (int) floor(
                (
                    (int) (
                        $arquivo[
                            'duracao_ms'
                        ]
                        ?? 0
                    )
                )
                /
                1000
            );


        $duracaoFormatada =
            sprintf(
                '%02d:%02d',
                floor(
                    $duracaoSegundos
                    /
                    60
                ),
                $duracaoSegundos
                %
                60
            );


        $analise =
            $openAI
                ->analisarBrutoTriagem(
                    $transcricao,
                    [

                        'titulo_capitulo' =>
                            (string) $arquivo[
                                'capitulo_titulo'
                            ],

                        'nome_arquivo' =>
                            (string) $arquivo[
                                'nome_arquivo'
                            ],

                        'duracao' =>
                            $duracaoFormatada,

                    ]
                );


        $triagem =
            validarTriagemVisual(
                $analise[
                    'dados'
                ]
                ?? []
            );


        $resumo =
            $triagem[
                'resumo'
            ];

    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | ARQUIVO
    |--------------------------------------------------------------------------
    */

    $stmtArquivo =
        $pdo->prepare(
            '
            UPDATE capitulo_arquivos

            SET
                ia_status = "concluido",

                ia_transcricao = ?,

                ia_transcricao_json = ?,

                ia_resumo = ?,

                ia_analisado_at = NOW(),

                ia_erro = NULL

            WHERE id = ?
              AND ia_processamento_token = ?
            '
        );


    $stmtArquivo->execute([
        $transcricao,
        $transcricaoJsonString,
        $resumo,
        $arquivoId,
        $token,
    ]);


    /*
    |--------------------------------------------------------------------------
    | TRIAGEM
    |--------------------------------------------------------------------------
    */

    $stmtTriagem =
        $pdo->prepare(
            '
            UPDATE capitulo_triagem

            SET
                decisao = ?,
                tipo = ?,
                importancia = ?,
                possivel_uso = ?,
                observacoes = ?

            WHERE arquivo_id = ?
            '
        );


    $stmtTriagem->execute([

        $triagem[
            'decisao'
        ],

        $triagem[
            'tipo'
        ],

        $triagem[
            'importancia'
        ],

        $triagem[
            'possivel_uso'
        ] !== ''
            ?
            $triagem[
                'possivel_uso'
            ]
            :
            null,

        $triagem[
            'observacoes'
        ] !== ''
            ?
            $triagem[
                'observacoes'
            ]
            :
            null,

        $arquivoId,

    ]);


    $pdo->commit();


    responderResultado(
        [

            'success' =>
                true,

            'message' =>
                $modo === 'visual'
                    ?
                    'Análise visual e triagem salvas com sucesso.'
                    :
                    'Transcrição e triagem salvas com sucesso.',

            'arquivo_id' =>
                $arquivoId,

            'modo' =>
                $modo,

            'triagem' => [

                'decisao' =>
                    $triagem[
                        'decisao'
                    ],

                'tipo' =>
                    $triagem[
                        'tipo'
                    ],

                'importancia' =>
                    $triagem[
                        'importancia'
                    ],

                'possivel_uso' =>
                    $triagem[
                        'possivel_uso'
                    ],

                'resumo' =>
                    $resumo,

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


    responderResultado(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}