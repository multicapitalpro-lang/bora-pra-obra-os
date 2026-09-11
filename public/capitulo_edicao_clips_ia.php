<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

require_once dirname(__DIR__)
    . '/app/AI/OpenAIService.php';


$pdo = db();


function responderClipsIA(
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


function tempoMsTexto(
    int $ms
): string {

    $segundos =
        (int) floor(
            $ms
            /
            1000
        );

    $minutos =
        (int) floor(
            $segundos
            /
            60
        );

    $segundos =
        $segundos
        %
        60;

    $milissegundos =
        $ms
        %
        1000;

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

        responderClipsIA(
            [
                'success' =>
                    false,

                'message' =>
                    'Método inválido.',
            ],
            405
        );
    }


    $capituloId =
        (int) (
            $_POST['capitulo_id']
            ?? 0
        );


    $arquivoId =
        (int) (
            $_POST['arquivo_id']
            ?? 0
        );


    if (
        $capituloId <= 0
        ||
        $arquivoId <= 0
    ) {

        throw new RuntimeException(
            'Capítulo ou arquivo inválido.'
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
                objetivo_episodio,
                gancho

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
    | ARQUIVO + PLANO
    |--------------------------------------------------------------------------
    */

    $stmtArquivo =
        $pdo->prepare(
            '
            SELECT
                ca.id,
                ca.nome_arquivo,
                ca.duracao_ms,
                ca.ia_resumo,

                cpa.id AS plano_arquivo_id,
                cpa.ordem AS plano_ordem,
                cpa.funcao,
                cpa.usar_audio,
                cpa.instrucao

            FROM capitulo_arquivos ca

            INNER JOIN capitulo_plano_arquivos cpa
                ON cpa.arquivo_id = ca.id
               AND cpa.capitulo_id = ca.capitulo_id

            WHERE ca.id = ?
              AND ca.capitulo_id = ?
              AND ca.ia_timestamps_status = "concluido"

            LIMIT 1
            '
        );


    $stmtArquivo->execute([
        $arquivoId,
        $capituloId,
    ]);


    $arquivo =
        $stmtArquivo->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$arquivo) {

        throw new RuntimeException(
            'Arquivo não encontrado no plano ou sem timestamps concluídos.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SEGMENTOS
    |--------------------------------------------------------------------------
    */

    $stmtSegmentos =
        $pdo->prepare(
            '
            SELECT
                ordem,
                inicio_ms,
                fim_ms,
                texto

            FROM capitulo_arquivo_segmentos

            WHERE arquivo_id = ?

            ORDER BY ordem ASC
            '
        );


    $stmtSegmentos->execute([
        $arquivoId
    ]);


    $segmentos =
        $stmtSegmentos->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (!$segmentos) {

        throw new RuntimeException(
            'Nenhum segmento temporal encontrado.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TEXTO TEMPORAL
    |--------------------------------------------------------------------------
    */

    $linhas = [];


    foreach (
        $segmentos
        as $segmento
    ) {

        $inicio =
            tempoMsTexto(
                (int) $segmento[
                    'inicio_ms'
                ]
            );


        $fim =
            tempoMsTexto(
                (int) $segmento[
                    'fim_ms'
                ]
            );


        $texto =
            trim(
                (string) $segmento[
                    'texto'
                ]
            );


        $linhas[] =
            '['
            .
            $inicio
            .
            ' → '
            .
            $fim
            .
            '] '
            .
            $texto;
    }


    $transcricaoTemporal =
        implode(
            "\n",
            $linhas
        );


    /*
    |--------------------------------------------------------------------------
    | PROMPT
    |--------------------------------------------------------------------------
    */

    $tituloCapitulo =
        trim(
            (string) (
                $capitulo[
                    'titulo'
                ]
                ?? ''
            )
        );


    $objetivo =
        trim(
            (string) (
                $capitulo[
                    'objetivo_episodio'
                ]
                ?? ''
            )
        );


    $gancho =
        trim(
            (string) (
                $capitulo[
                    'gancho'
                ]
                ?? ''
            )
        );


    $nomeArquivo =
        trim(
            (string) $arquivo[
                'nome_arquivo'
            ]
        );


    $funcao =
        trim(
            (string) (
                $arquivo[
                    'funcao'
                ]
                ?? 'principal'
            )
        );


    $instrucaoPlano =
        trim(
            (string) (
                $arquivo[
                    'instrucao'
                ]
                ?? ''
            )
        );


    $resumo =
        trim(
            (string) (
                $arquivo[
                    'ia_resumo'
                ]
                ?? ''
            )
        );


    $usarAudioPlano =
        !empty(
            $arquivo[
                'usar_audio'
            ]
        )
            ?
            'sim'
            :
            'não';


    $instrucao =
        <<<PROMPT
Você é o editor-chefe do projeto "Bora pra Obra".

Sua tarefa agora é escolher os TRECHOS EXATOS de UM vídeo bruto
que devem entrar na edição.

CAPÍTULO:
{$tituloCapitulo}

OBJETIVO DO EPISÓDIO:
{$objetivo}

GANCHO DO EPISÓDIO:
{$gancho}

ARQUIVO:
{$nomeArquivo}

FUNÇÃO DEFINIDA NO PLANO:
{$funcao}

USAR ÁUDIO SEGUNDO O PLANO:
{$usarAudioPlano}

INSTRUÇÃO DO PLANO:
{$instrucaoPlano}

RESUMO DO BRUTO:
{$resumo}

TRANSCRIÇÃO COM TEMPOS:

{$transcricaoTemporal}


REGRAS:

1. Use SOMENTE tempos existentes nos segmentos acima.

2. Não invente timestamps.

3. O início de cada clip deve coincidir com algum inicio_ms existente.

4. O fim de cada clip deve coincidir com algum fim_ms existente.

5. Você pode selecionar mais de um trecho do mesmo vídeo.

6. Corte apresentações, repetições, pausas e partes que não ajudam
a função editorial definida no plano.

7. Preserve contexto suficiente para a fala não parecer cortada de forma estranha.

8. Para "gancho", priorize o trecho mais forte e direto.

9. Para "principal", escolha os trechos que realmente desenvolvem
a história ou explicação.

10. Para "encerramento", escolha conclusão, aprendizado ou gancho
para a próxima etapa.

11. Para "broll", selecione somente se a fala/transcrição tiver valor
editorial; caso contrário, pode retornar um trecho mínimo ou nenhum clip.

12. Não selecione o vídeo inteiro por padrão.

13. Seja econômico.
O objetivo é reduzir trabalho no CapCut.

14. Cada clip precisa ter:
- titulo_clip
- inicio_ms
- fim_ms
- usar_audio
- instrucao
- motivo_corte

15. inicio_ms e fim_ms devem estar em MILISSEGUNDOS.

16. fim_ms deve ser maior que inicio_ms.

17. Não crie clips sobrepostos entre si.

18. Máximo de 4 clips para este arquivo.
PROMPT;


    /*
    |--------------------------------------------------------------------------
    | SCHEMA
    |--------------------------------------------------------------------------
    */

    $schema = [

        'type' =>
            'object',

        'additionalProperties' =>
            false,

        'properties' => [

            'clips' => [

                'type' =>
                    'array',

                'maxItems' =>
                    4,

                'items' => [

                    'type' =>
                        'object',

                    'additionalProperties' =>
                        false,

                    'properties' => [

                        'titulo_clip' => [

                            'type' =>
                                'string',

                        ],

                        'inicio_ms' => [

                            'type' =>
                                'integer',

                            'minimum' =>
                                0,

                        ],

                        'fim_ms' => [

                            'type' =>
                                'integer',

                            'minimum' =>
                                1,

                        ],

                        'usar_audio' => [

                            'type' =>
                                'boolean',

                        ],

                        'instrucao' => [

                            'type' =>
                                'string',

                        ],

                        'motivo_corte' => [

                            'type' =>
                                'string',

                        ],

                    ],

                    'required' => [

                        'titulo_clip',
                        'inicio_ms',
                        'fim_ms',
                        'usar_audio',
                        'instrucao',
                        'motivo_corte',

                    ],

                ],

            ],

        ],

        'required' => [
            'clips',
        ],

    ];


    /*
    |--------------------------------------------------------------------------
    | IA
    |--------------------------------------------------------------------------
    */

    $inicioIA =
        microtime(true);


    $openAI =
        new OpenAIService();


    $resultado =
        $openAI
            ->responderEstruturado(
                $instrucao,
                'clips_edicao',
                $schema
            );


    $tempoIA =
        round(
            microtime(true)
            -
            $inicioIA,
            2
        );


    $dadosIA =
        $resultado[
            'dados'
        ]
        ?? [];


    $clipsIA =
        $dadosIA[
            'clips'
        ]
        ?? [];


    if (
        !is_array(
            $clipsIA
        )
    ) {

        throw new RuntimeException(
            'A IA retornou clips inválidos.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR CONTRA SEGMENTOS REAIS
    |--------------------------------------------------------------------------
    */

    $iniciosPermitidos = [];
    $finsPermitidos = [];


    foreach (
        $segmentos
        as $segmento
    ) {

        $iniciosPermitidos[
            (int) $segmento[
                'inicio_ms'
            ]
        ] = true;


        $finsPermitidos[
            (int) $segmento[
                'fim_ms'
            ]
        ] = true;
    }


    $clipsValidos = [];


    foreach (
        $clipsIA
        as $clip
    ) {

        if (!is_array($clip)) {
            continue;
        }


        $inicioMs =
            (int) (
                $clip[
                    'inicio_ms'
                ]
                ?? -1
            );


        $fimMs =
            (int) (
                $clip[
                    'fim_ms'
                ]
                ?? -1
            );


        if (
            !isset(
                $iniciosPermitidos[
                    $inicioMs
                ]
            )
            ||
            !isset(
                $finsPermitidos[
                    $fimMs
                ]
            )
        ) {

            continue;
        }


        if (
            $fimMs <=
            $inicioMs
        ) {

            continue;
        }


        $clipsValidos[] = [

            'titulo_clip' =>
                trim(
                    (string) (
                        $clip[
                            'titulo_clip'
                        ]
                        ?? ''
                    )
                ),

            'inicio_ms' =>
                $inicioMs,

            'fim_ms' =>
                $fimMs,

            'duracao_ms' =>
                $fimMs
                -
                $inicioMs,

            'usar_audio' =>
                !empty(
                    $clip[
                        'usar_audio'
                    ]
                )
                    ?
                    1
                    :
                    0,

            'instrucao' =>
                trim(
                    (string) (
                        $clip[
                            'instrucao'
                        ]
                        ?? ''
                    )
                ),

            'motivo_corte' =>
                trim(
                    (string) (
                        $clip[
                            'motivo_corte'
                        ]
                        ?? ''
                    )
                ),

        ];
    }


    if (!$clipsValidos) {

        throw new RuntimeException(
            'A IA não retornou nenhum clip compatível com os timestamps reais.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ORDENAR
    |--------------------------------------------------------------------------
    */

    usort(
        $clipsValidos,
        static function (
            array $a,
            array $b
        ): int {

            return
                $a['inicio_ms']
                <=>
                $b['inicio_ms'];
        }
    );


    /*
    |--------------------------------------------------------------------------
    | REMOVER SOBREPOSIÇÕES
    |--------------------------------------------------------------------------
    */

    $clipsSemSobreposicao = [];

    $ultimoFim =
        -1;


    foreach (
        $clipsValidos
        as $clip
    ) {

        if (
            $clip[
                'inicio_ms'
            ]
            <
            $ultimoFim
        ) {

            continue;
        }


        $clipsSemSobreposicao[] =
            $clip;


        $ultimoFim =
            $clip[
                'fim_ms'
            ];
    }


    if (
        !$clipsSemSobreposicao
    ) {

        throw new RuntimeException(
            'Nenhum clip válido permaneceu após validar sobreposições.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RECONEXÃO
    |--------------------------------------------------------------------------
    */

    $pdo =
        db(true);


    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | GARANTIR REGISTRO DE EDIÇÃO
    |--------------------------------------------------------------------------
    */

    $stmtEdicao =
        $pdo->prepare(
            '
            INSERT INTO capitulo_edicao
            (
                capitulo_id,
                roteiro_ia_status
            )
            VALUES
            (
                ?,
                "gerando"
            )

            ON DUPLICATE KEY UPDATE
                roteiro_ia_status =
                    "gerando",

                roteiro_ia_erro =
                    NULL
            '
        );


    $stmtEdicao->execute([
        $capituloId
    ]);


    /*
    |--------------------------------------------------------------------------
    | APAGAR SUGESTÕES ANTIGAS DESTE ARQUIVO
    |--------------------------------------------------------------------------
    */

    $stmtExcluir =
        $pdo->prepare(
            '
            DELETE FROM capitulo_edicao_clips

            WHERE capitulo_id = ?
              AND arquivo_id = ?
              AND origem = "ia"
              AND status_clip = "sugerido"
            '
        );


    $stmtExcluir->execute([
        $capituloId,
        $arquivoId,
    ]);


    /*
    |--------------------------------------------------------------------------
    | PRÓXIMA ORDEM GLOBAL
    |--------------------------------------------------------------------------
    */

    $stmtOrdem =
        $pdo->prepare(
            '
            SELECT
                COALESCE(
                    MAX(ordem),
                    0
                )

            FROM capitulo_edicao_clips

            WHERE capitulo_id = ?
            '
        );


    $stmtOrdem->execute([
        $capituloId
    ]);


    $ordemGlobal =
        (int) $stmtOrdem
            ->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | INSERIR CLIPS
    |--------------------------------------------------------------------------
    */

    $stmtInserir =
        $pdo->prepare(
            '
            INSERT INTO capitulo_edicao_clips
            (
                capitulo_id,
                arquivo_id,
                plano_arquivo_id,
                ordem,
                titulo_clip,
                funcao,
                camada,
                inicio_ms,
                fim_ms,
                duracao_ms,
                usar_audio,
                volume_audio,
                instrucao,
                motivo_corte,
                origem,
                status_clip
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                "ia",
                "sugerido"
            )
            '
        );


    foreach (
        $clipsSemSobreposicao
        as $clip
    ) {

        $ordemGlobal++;


        $camada =
            $funcao === 'broll'
                ?
                'broll'
                :
                'principal';


        $volumeAudio =
            $clip[
                'usar_audio'
            ]
                ?
                100
                :
                0;


        $stmtInserir->execute([

            $capituloId,

            $arquivoId,

            (int) $arquivo[
                'plano_arquivo_id'
            ],

            $ordemGlobal,

            $clip[
                'titulo_clip'
            ],

            $funcao,

            $camada,

            $clip[
                'inicio_ms'
            ],

            $clip[
                'fim_ms'
            ],

            $clip[
                'duracao_ms'
            ],

            $clip[
                'usar_audio'
            ],

            $volumeAudio,

            $clip[
                'instrucao'
            ],

            $clip[
                'motivo_corte'
            ],

        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    $stmtStatus =
        $pdo->prepare(
            '
            UPDATE capitulo_edicao

            SET
                roteiro_ia_status =
                    "rascunho",

                roteiro_ia_gerado_at =
                    NOW(),

                roteiro_ia_erro =
                    NULL

            WHERE capitulo_id = ?
            '
        );


    $stmtStatus->execute([
        $capituloId
    ]);


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RETORNO
    |--------------------------------------------------------------------------
    */

    responderClipsIA(
        [
            'success' =>
                true,

            'message' =>
                'Cortes sugeridos pela IA com sucesso.',

            'arquivo_id' =>
                $arquivoId,

            'arquivo' =>
                $nomeArquivo,

            'funcao' =>
                $funcao,

            'clips_gerados' =>
                count(
                    $clipsSemSobreposicao
                ),

            'tempo_ia' =>
                $tempoIA,

            'modelo' =>
                $resultado[
                    'model'
                ]
                ?? '',
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


    /*
    |--------------------------------------------------------------------------
    | TENTAR REGISTRAR ERRO
    |--------------------------------------------------------------------------
    */

    try {

        if (
            isset($capituloId)
            &&
            $capituloId > 0
        ) {

            $pdoErro =
                db(true);


            $stmtErro =
                $pdoErro->prepare(
                    '
                    INSERT INTO capitulo_edicao
                    (
                        capitulo_id,
                        roteiro_ia_status,
                        roteiro_ia_erro
                    )
                    VALUES
                    (
                        ?,
                        "erro",
                        ?
                    )

                    ON DUPLICATE KEY UPDATE
                        roteiro_ia_status =
                            "erro",

                        roteiro_ia_erro =
                            VALUES(
                                roteiro_ia_erro
                            )
                    '
                );


            $stmtErro->execute([
                $capituloId,
                $e->getMessage(),
            ]);
        }

    } catch (Throwable $ignorado) {
    }


    responderClipsIA(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}