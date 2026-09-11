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


/*
|--------------------------------------------------------------------------
| RESPONDER JSON
|--------------------------------------------------------------------------
*/

function responderPlanoIA(
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
| LIMITAR TEXTO
|--------------------------------------------------------------------------
|
| Evita mandar transcrições gigantescas sem necessidade.
|
*/

function limitarTextoPlanoIA(
    ?string $texto,
    int $limite = 8000
): string {

    $texto =
        trim(
            (string) $texto
        );


    if ($texto === '') {
        return '';
    }


    if (
        function_exists(
            'mb_strlen'
        )
        &&
        function_exists(
            'mb_substr'
        )
    ) {

        if (
            mb_strlen(
                $texto,
                'UTF-8'
            )
            <=
            $limite
        ) {

            return $texto;
        }


        return
            mb_substr(
                $texto,
                0,
                $limite,
                'UTF-8'
            )
            .
            "\n[TRANSCRIÇÃO RESUMIDA PELO SISTEMA]";
    }


    if (
        strlen($texto)
        <=
        $limite
    ) {

        return $texto;
    }


    return
        substr(
            $texto,
            0,
            $limite
        )
        .
        "\n[TRANSCRIÇÃO RESUMIDA PELO SISTEMA]";
}


/*
|--------------------------------------------------------------------------
| DURAÇÃO
|--------------------------------------------------------------------------
*/

function formatarDuracaoPlanoIA(
    ?int $duracaoMs
): string {

    if (
        !$duracaoMs
        ||
        $duracaoMs <= 0
    ) {

        return '';
    }


    $segundos =
        (int) floor(
            $duracaoMs
            /
            1000
        );


    $horas =
        (int) floor(
            $segundos
            /
            3600
        );


    $minutos =
        (int) floor(
            (
                $segundos
                %
                3600
            )
            /
            60
        );


    $segundosRestantes =
        $segundos
        %
        60;


    if ($horas > 0) {

        return sprintf(
            '%02d:%02d:%02d',
            $horas,
            $minutos,
            $segundosRestantes
        );
    }


    return sprintf(
        '%02d:%02d',
        $minutos,
        $segundosRestantes
    );
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
        $_SERVER[
            'REQUEST_METHOD'
        ]
        !== 'POST'
    ) {

        responderPlanoIA(
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
            $_POST[
                'capitulo_id'
            ]
            ?? 0
        );


    if (
        $capituloId
        <=
        0
    ) {

        throw new RuntimeException(
            'Capítulo inválido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BUSCAR CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo =
        $pdo->prepare(
            '
            SELECT
                c.id,
                c.numero,
                c.titulo,
                c.titulo_publico,
                c.descricao,
                c.tags,
                c.observacoes,
                c.objetivo_episodio,
                c.gancho,
                c.duracao_bruta_ms,
                c.duracao_selecionada_ms,

                t.nome AS temporada

            FROM capitulos c

            LEFT JOIN temporadas t
                ON t.id = c.temporada_id

            WHERE c.id = ?

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
    | BUSCAR MATERIAIS APROVADOS NA TRIAGEM
    |--------------------------------------------------------------------------
    |
    | Somente:
    |
    | usar
    | parcial
    |
    | E somente arquivos cuja análise IA terminou.
    |
    |--------------------------------------------------------------------------
    */

    $stmtArquivos =
        $pdo->prepare(
            '
            SELECT
                ca.id AS arquivo_id,
                ca.nome_arquivo,
                ca.duracao_ms,
                ca.ordem AS ordem_original,
                ca.ia_status,
                ca.ia_transcricao,
                ca.ia_transcricao_json,
                ca.ia_resumo,

                ct.decisao,
                ct.tipo,
                ct.inicio_ms,
                ct.fim_ms,
                ct.importancia,
                ct.possivel_uso,
                ct.observacoes

            FROM capitulo_arquivos ca

            INNER JOIN capitulo_triagem ct
                ON ct.arquivo_id = ca.id

            WHERE ca.capitulo_id = ?
              AND ca.ativo = 1
              AND ca.ia_status = "concluido"
              AND ct.decisao IN (
                    "usar",
                    "parcial"
              )

            ORDER BY
                ca.ordem ASC,
                ca.id ASC
            '
        );


    $stmtArquivos->execute([
        $capituloId
    ]);


    $arquivos =
        $stmtArquivos->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (!$arquivos) {

        throw new RuntimeException(
            'Nenhum bruto analisado e aprovado está disponível para montar o episódio.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | IDs PERMITIDOS
    |--------------------------------------------------------------------------
    */

    $idsPermitidos = [];


    foreach (
        $arquivos
        as $arquivo
    ) {

        $idsPermitidos[
            (int) $arquivo[
                'arquivo_id'
            ]
        ] = true;
    }


    /*
    |--------------------------------------------------------------------------
    | PREPARAR MATERIAL PARA IA
    |--------------------------------------------------------------------------
    */

    $materiaisIA = [];


    foreach (
        $arquivos
        as $arquivo
    ) {

        $arquivoId =
            (int) $arquivo[
                'arquivo_id'
            ];


        $transcricao =
            limitarTextoPlanoIA(
                $arquivo[
                    'ia_transcricao'
                ]
                ?? '',
                8000
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


        /*
         * Para vídeos sem fala,
         * ia_resumo e a triagem visual
         * são mais importantes que
         * transcrição.
         */

        $materiaisIA[] = [

            'arquivo_id' =>
                $arquivoId,

            'arquivo' =>
                (string) $arquivo[
                    'nome_arquivo'
                ],

            'duracao' =>
                formatarDuracaoPlanoIA(
                    isset(
                        $arquivo[
                            'duracao_ms'
                        ]
                    )
                        ?
                        (int) $arquivo[
                            'duracao_ms'
                        ]
                        :
                        null
                ),

            'ordem_gravacao' =>
                (int) (
                    $arquivo[
                        'ordem_original'
                    ]
                    ?? 0
                ),

            'triagem' => [

                'decisao' =>
                    (string) (
                        $arquivo[
                            'decisao'
                        ]
                        ?? ''
                    ),

                'tipo' =>
                    (string) (
                        $arquivo[
                            'tipo'
                        ]
                        ?? ''
                    ),

                'importancia' =>
                    (string) (
                        $arquivo[
                            'importancia'
                        ]
                        ?? ''
                    ),

                'possivel_uso' =>
                    (string) (
                        $arquivo[
                            'possivel_uso'
                        ]
                        ?? ''
                    ),

                'observacoes' =>
                    (string) (
                        $arquivo[
                            'observacoes'
                        ]
                        ?? ''
                    ),

            ],

            'resumo_ia' =>
                $resumo,

            'transcricao' =>
                $transcricao,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | JSON DOS MATERIAIS
    |--------------------------------------------------------------------------
    */

    $materiaisJson =
        json_encode(
            $materiaisIA,
            JSON_PRETTY_PRINT
            |
            JSON_UNESCAPED_UNICODE
            |
            JSON_UNESCAPED_SLASHES
        );


    if (
        $materiaisJson
        ===
        false
    ) {

        throw new RuntimeException(
            'Não foi possível preparar os materiais do capítulo.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DADOS DO CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $numeroCapitulo =
        (int) $capitulo[
            'numero'
        ];


    $tituloCapitulo =
        trim(
            (string) (
                $capitulo[
                    'titulo'
                ]
                ?? ''
            )
        );


    $temporada =
        trim(
            (string) (
                $capitulo[
                    'temporada'
                ]
                ?? ''
            )
        );


    $descricaoAtual =
        trim(
            (string) (
                $capitulo[
                    'descricao'
                ]
                ?? ''
            )
        );


    $objetivoExistente =
        trim(
            (string) (
                $capitulo[
                    'objetivo_episodio'
                ]
                ?? ''
            )
        );


    $ganchoExistente =
        trim(
            (string) (
                $capitulo[
                    'gancho'
                ]
                ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | PROMPT EDITORIAL
    |--------------------------------------------------------------------------
    */

    $instrucao =
        <<<PROMPT
Você é o editor-chefe do projeto brasileiro "Bora pra Obra".

O projeto documenta uma obra residencial real.

Sua tarefa agora NÃO é analisar vídeos isoladamente.
Esses vídeos já passaram pela triagem.

Sua tarefa é analisar O CONJUNTO DO MATERIAL APROVADO e montar
a melhor estrutura possível para um episódio de YouTube que seja
claro, interessante, natural e fácil de editar no CapCut.

CAPÍTULO:
Número: {$numeroCapitulo}
Título interno: {$tituloCapitulo}
Temporada: {$temporada}

DESCRIÇÃO ATUAL:
{$descricaoAtual}

OBJETIVO JÁ INFORMADO, SE HOUVER:
{$objetivoExistente}

GANCHO JÁ INFORMADO, SE HOUVER:
{$ganchoExistente}

MATERIAIS DISPONÍVEIS:
{$materiaisJson}


REGRAS EDITORIAIS IMPORTANTES:

1. Use SOMENTE arquivo_id que apareça na lista de materiais.

2. Não invente arquivos.

3. Não invente acontecimentos.

4. Evite repetir a mesma informação em vários vídeos.

5. Dê preferência ao material marcado como forte ou essencial
quando ele realmente ajudar a narrativa.

6. Um vídeo marcado como "usar" não precisa obrigatoriamente entrar
no episódio se ele for redundante.

7. Um vídeo "parcial" pode entrar caso tenha uma função narrativa útil.

8. Vídeos do tipo broll, execucao, detalhe ou ambiente podem ser
usados como apoio visual sobre uma fala principal.

9. O objetivo é facilitar a montagem no CapCut.

10. As instruções para cada arquivo devem ser práticas e curtas.

Exemplos:
- abrir episódio com a explicação do problema;
- usar como fala principal;
- cobrir a fala anterior com estas imagens;
- remover áudio e usar como B-roll;
- usar como transição;
- usar no encerramento;
- evitar trecho repetitivo.

11. NÃO INVENTE TIMECODES PRECISOS.

Ainda teremos uma etapa posterior específica para localizar os
cortes exatos dentro dos vídeos.

12. Portanto, a seleção atual deve definir principalmente:
- ordem narrativa;
- função;
- intenção editorial;
- uso ou não do áudio.

13. A estrutura ideal normalmente deve procurar:
GANCHO
→ CONTEXTO
→ DESENVOLVIMENTO / PROBLEMA
→ EXECUÇÃO
→ RESULTADO / APRENDIZADO
→ ENCERRAMENTO

Mas NÃO force essa estrutura se o material disponível não permitir.

14. Não transforme o episódio em algo artificial.
O canal documenta uma obra real.

15. "duracao_alvo" deve ser uma faixa simples.
Exemplos:
"6 a 8 minutos"
"8 a 10 minutos"

16. "textos_tela" deve sugerir apenas textos realmente úteis:
medidas, nomes, valores, etapas ou explicações relevantes.

17. "audio_musica" deve ser simples.
Não exagere em efeitos ou música.

18. Para cada item de "arquivos":
- arquivo_id deve ser um ID existente;
- ordem deve começar em 1;
- funcao deve ser:
  gancho
  principal
  broll
  encerramento
- usar_audio deve ser false para B-roll visual sem fala relevante;
- instrucao deve explicar exatamente como usar aquele bruto.

19. Cada arquivo deve aparecer NO MÁXIMO UMA VEZ neste primeiro plano.

20. Não inclua materiais que não contribuam para o episódio.

Monte uma proposta editorial realmente prática para alguém abrir
o CapCut e começar a edição.
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

            'objetivo' => [

                'type' =>
                    'string',

            ],

            'gancho' => [

                'type' =>
                    'string',

            ],

            'contexto' => [

                'type' =>
                    'string',

            ],

            'desenvolvimento' => [

                'type' =>
                    'string',

            ],

            'encerramento' => [

                'type' =>
                    'string',

            ],

            'broll' => [

                'type' =>
                    'string',

            ],

            'textos_tela' => [

                'type' =>
                    'string',

            ],

            'audio_musica' => [

                'type' =>
                    'string',

            ],

            'observacoes_edicao' => [

                'type' =>
                    'string',

            ],

            'duracao_alvo' => [

                'type' =>
                    'string',

            ],

            'arquivos' => [

                'type' =>
                    'array',

                'items' => [

                    'type' =>
                        'object',

                    'additionalProperties' =>
                        false,

                    'properties' => [

                        'arquivo_id' => [

                            'type' =>
                                'integer',

                        ],

                        'ordem' => [

                            'type' =>
                                'integer',

                            'minimum' =>
                                1,

                        ],

                        'funcao' => [

                            'type' =>
                                'string',

                            'enum' => [
                                'gancho',
                                'principal',
                                'broll',
                                'encerramento',
                            ],

                        ],

                        'usar_audio' => [

                            'type' =>
                                'boolean',

                        ],

                        'instrucao' => [

                            'type' =>
                                'string',

                        ],

                    ],

                    'required' => [

                        'arquivo_id',
                        'ordem',
                        'funcao',
                        'usar_audio',
                        'instrucao',

                    ],

                ],

            ],

        ],

        'required' => [

            'objetivo',
            'gancho',
            'contexto',
            'desenvolvimento',
            'encerramento',
            'broll',
            'textos_tela',
            'audio_musica',
            'observacoes_edicao',
            'duracao_alvo',
            'arquivos',

        ],

    ];


    /*
    |--------------------------------------------------------------------------
    | CHAMAR OPENAI
    |--------------------------------------------------------------------------
    */

    $inicio =
        microtime(true);


    $openAI =
        new OpenAIService();


    $resultadoIA =
        $openAI
            ->responderEstruturado(
                $instrucao,
                'plano_episodio',
                $schema
            );


    $tempo =
        round(
            microtime(true)
            -
            $inicio,
            2
        );


    $planoIA =
        $resultadoIA[
            'dados'
        ]
        ?? [];


    if (
        !$planoIA
        ||
        !is_array(
            $planoIA
        )
    ) {

        throw new RuntimeException(
            'A IA não retornou um plano válido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR ARQUIVOS RETORNADOS
    |--------------------------------------------------------------------------
    */

    $arquivosPlanoIA = [];

    $arquivosJaUsados = [];


    foreach (
        $planoIA[
            'arquivos'
        ]
        ?? []
        as $item
    ) {

        if (
            !is_array(
                $item
            )
        ) {

            continue;
        }


        $arquivoId =
            (int) (
                $item[
                    'arquivo_id'
                ]
                ?? 0
            );


        /*
         * A IA jamais pode inserir
         * ID que não enviamos.
         */

        if (
            $arquivoId <= 0
            ||
            !isset(
                $idsPermitidos[
                    $arquivoId
                ]
            )
        ) {

            continue;
        }


        /*
         * Evitar arquivo duplicado
         */

        if (
            isset(
                $arquivosJaUsados[
                    $arquivoId
                ]
            )
        ) {

            continue;
        }


        $arquivosJaUsados[
            $arquivoId
        ] = true;


        $funcao =
            trim(
                (string) (
                    $item[
                        'funcao'
                    ]
                    ?? 'principal'
                )
            );


        if (
            !in_array(
                $funcao,
                [
                    'gancho',
                    'principal',
                    'broll',
                    'encerramento',
                ],
                true
            )
        ) {

            $funcao =
                'principal';
        }


        $arquivosPlanoIA[] = [

            'arquivo_id' =>
                $arquivoId,

            'ordem' =>
                max(
                    1,
                    (int) (
                        $item[
                            'ordem'
                        ]
                        ?? 1
                    )
                ),

            'funcao' =>
                $funcao,

            'usar_audio' =>
                !empty(
                    $item[
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
                        $item[
                            'instrucao'
                        ]
                        ?? ''
                    )
                ),

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | PRECISAMOS DE PELO MENOS UM ARQUIVO
    |--------------------------------------------------------------------------
    */

    if (!$arquivosPlanoIA) {

        throw new RuntimeException(
            'A IA não selecionou nenhum arquivo válido para o episódio.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | REORDENAR
    |--------------------------------------------------------------------------
    */

    usort(
        $arquivosPlanoIA,
        static function (
            array $a,
            array $b
        ): int {

            return
                $a['ordem']
                <=>
                $b['ordem'];
        }
    );


    /*
     * Normaliza para:
     *
     * 1, 2, 3, 4...
     */

    foreach (
        $arquivosPlanoIA
        as $indice => &$item
    ) {

        $item[
            'ordem'
        ] =
            $indice
            +
            1;
    }

    unset(
        $item
    );


    /*
    |--------------------------------------------------------------------------
    | RECONECTAR AO MYSQL
    |--------------------------------------------------------------------------
    |
    | A chamada da OpenAI pode levar vários segundos.
    |
    | Em hospedagem compartilhada o MySQL pode encerrar
    | a conexão enquanto aguardamos a resposta da IA.
    |
    | Portanto, antes de salvar o plano, descartamos
    | a conexão antiga e abrimos uma nova.
    |
    |--------------------------------------------------------------------------
    */
    
    $pdo =
        db(true);
    
    
    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */
    
    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | UPSERT DO PLANO
    |--------------------------------------------------------------------------
    */

    $stmtPlano =
        $pdo->prepare(
            '
            INSERT INTO capitulo_planos
            (
                capitulo_id,
                objetivo,
                gancho,
                contexto,
                desenvolvimento,
                encerramento,
                broll,
                textos_tela,
                audio_musica,
                observacoes_edicao,
                duracao_alvo,
                status_plano
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
                "rascunho"
            )

            ON DUPLICATE KEY UPDATE

                objetivo =
                    VALUES(objetivo),

                gancho =
                    VALUES(gancho),

                contexto =
                    VALUES(contexto),

                desenvolvimento =
                    VALUES(desenvolvimento),

                encerramento =
                    VALUES(encerramento),

                broll =
                    VALUES(broll),

                textos_tela =
                    VALUES(textos_tela),

                audio_musica =
                    VALUES(audio_musica),

                observacoes_edicao =
                    VALUES(observacoes_edicao),

                duracao_alvo =
                    VALUES(duracao_alvo),

                status_plano =
                    "rascunho"
            '
        );


    $stmtPlano->execute([

        $capituloId,

        trim(
            (string) (
                $planoIA[
                    'objetivo'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'gancho'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'contexto'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'desenvolvimento'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'encerramento'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'broll'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'textos_tela'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'audio_musica'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'observacoes_edicao'
                ]
                ?? ''
            )
        ),

        trim(
            (string) (
                $planoIA[
                    'duracao_alvo'
                ]
                ?? ''
            )
        ),

    ]);


    /*
    |--------------------------------------------------------------------------
    | LIMPAR PLANO DE ARQUIVOS ANTIGO
    |--------------------------------------------------------------------------
    |
    | Ao gerar novamente com IA,
    | substituímos a proposta anterior.
    |
    |--------------------------------------------------------------------------
    */

    $stmtExcluir =
        $pdo->prepare(
            '
            DELETE FROM capitulo_plano_arquivos
            WHERE capitulo_id = ?
            '
        );


    $stmtExcluir->execute([
        $capituloId
    ]);


    /*
    |--------------------------------------------------------------------------
    | INSERIR ARQUIVOS
    |--------------------------------------------------------------------------
    */

    $stmtInserir =
        $pdo->prepare(
            '
            INSERT INTO capitulo_plano_arquivos
            (
                capitulo_id,
                arquivo_id,
                ordem,
                funcao,
                inicio_ms,
                fim_ms,
                usar_audio,
                sobrepor_em_ms,
                duracao_ms,
                instrucao
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                NULL,
                NULL,
                ?,
                NULL,
                NULL,
                ?
            )
            '
        );


    foreach (
        $arquivosPlanoIA
        as $item
    ) {

        $stmtInserir->execute([

            $capituloId,

            $item[
                'arquivo_id'
            ],

            $item[
                'ordem'
            ],

            $item[
                'funcao'
            ],

            $item[
                'usar_audio'
            ],

            $item[
                'instrucao'
            ],

        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | FASE DO CAPÍTULO
    |--------------------------------------------------------------------------
    |
    | Não aprovamos automaticamente.
    |
    | Apenas garantimos que existe
    | um rascunho de plano.
    |
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo =
        $pdo->prepare(
            '
            UPDATE capitulos

            SET
                fase_producao = "plano"

            WHERE id = ?
              AND fase_producao IN (
                    "brutos",
                    "triagem"
              )
            '
        );


    $stmtCapitulo->execute([
        $capituloId
    ]);


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | SUCESSO
    |--------------------------------------------------------------------------
    */

    responderPlanoIA(
        [

            'success' =>
                true,

            'message' =>
                'Plano do episódio montado pela IA.',

            'capitulo_id' =>
                $capituloId,

            'arquivos_selecionados' =>
                count(
                    $arquivosPlanoIA
                ),

            'materiais_disponiveis' =>
                count(
                    $arquivos
                ),

            'duracao_alvo' =>
                trim(
                    (string) (
                        $planoIA[
                            'duracao_alvo'
                        ]
                        ?? ''
                    )
                ),

            'modelo' =>
                $resultadoIA[
                    'model'
                ]
                ?? '',

            'tempo_segundos' =>
                $tempo,

        ]
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


    /*
    |--------------------------------------------------------------------------
    | ERRO
    |--------------------------------------------------------------------------
    */

    responderPlanoIA(
        [

            'success' =>
                false,

            'message' =>
                $e->getMessage(),

        ],
        500
    );
}