<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

require_once dirname(__DIR__)
    . '/app/AI/OpenAIService.php';


function responderRefino(
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


try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        responderRefino(
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
    | CONTAGEM ATUAL
    |--------------------------------------------------------------------------
    */

    $pdoContagem = db(true);


    $stmtContagem =
        $pdoContagem->prepare(
            '
            SELECT
                COUNT(*) AS total,

                SUM(
                    CASE
                        WHEN refino_status = "refinado"
                        THEN 1
                        ELSE 0
                    END
                ) AS refinados,

                SUM(
                    CASE
                        WHEN refino_status = "erro"
                        THEN 1
                        ELSE 0
                    END
                ) AS erros

            FROM capitulo_edicao_clips

            WHERE capitulo_id = ?
            '
        );


    $stmtContagem->execute([
        $capituloId
    ]);


    $contagem =
        $stmtContagem->fetch(
            PDO::FETCH_ASSOC
        );


    $stmtContagem = null;
    $pdoContagem = null;


    $total =
        (int) (
            $contagem['total']
            ?? 0
        );


    $totalRefinados =
        (int) (
            $contagem['refinados']
            ?? 0
        );


    $totalErros =
        (int) (
            $contagem['erros']
            ?? 0
        );


    if ($total <= 0) {

        throw new RuntimeException(
            'Nenhum corte encontrado.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PEGAR SOMENTE O PRÓXIMO CORTE
    |--------------------------------------------------------------------------
    */

    $pdoClip = db(true);


    $stmtClip =
        $pdoClip->prepare(
            '
            SELECT
                cec.id,
                cec.ordem,
                cec.arquivo_id,
                cec.titulo_clip,
                cec.funcao,
                cec.inicio_ms,
                cec.fim_ms,

                ca.nome_arquivo,
                ca.duracao_ms

            FROM capitulo_edicao_clips cec

            INNER JOIN capitulo_arquivos ca
                ON ca.id = cec.arquivo_id

            WHERE cec.capitulo_id = ?

              AND cec.refino_status =
                    "nao_refinado"

            ORDER BY
                cec.ordem ASC,
                cec.id ASC

            LIMIT 1
            '
        );


    $stmtClip->execute([
        $capituloId
    ]);


    $clip =
        $stmtClip->fetch(
            PDO::FETCH_ASSOC
        );


    $stmtClip = null;
    $pdoClip = null;


    /*
    |--------------------------------------------------------------------------
    | TERMINOU
    |--------------------------------------------------------------------------
    */

    if (!$clip) {

        responderRefino(
            [
                'success' => true,

                'done' => true,

                'message' =>
                    'Refinamento concluído.',

                'total_clips' =>
                    $total,

                'refinados' =>
                    $totalRefinados,

                'erros' =>
                    $totalErros,
            ]
        );
    }


    $clipId =
        (int) $clip['id'];


    /*
    |--------------------------------------------------------------------------
    | MARCAR COMO REFINANDO
    |--------------------------------------------------------------------------
    */

    $pdoStatus = db(true);


    $stmtStatus =
        $pdoStatus->prepare(
            '
            UPDATE capitulo_edicao_clips

            SET
                refino_status =
                    "refinando",

                refino_motivo =
                    NULL

            WHERE id = ?
            '
        );


    $stmtStatus->execute([
        $clipId
    ]);


    $stmtStatus = null;
    $pdoStatus = null;


    /*
    |--------------------------------------------------------------------------
    | SEGMENTOS
    |--------------------------------------------------------------------------
    */

    $inicioAtual =
        (int) $clip['inicio_ms'];


    $fimAtual =
        (int) $clip['fim_ms'];


    $janelaAntes =
        max(
            0,
            $inicioAtual - 30000
        );


    $janelaDepois =
        $fimAtual + 30000;


    $pdoSegmentos = db(true);


    $stmtSegmentos =
        $pdoSegmentos->prepare(
            '
            SELECT
                ordem,
                inicio_ms,
                fim_ms,
                texto

            FROM capitulo_arquivo_segmentos

            WHERE arquivo_id = ?

              AND fim_ms >= ?

              AND inicio_ms <= ?

            ORDER BY ordem ASC
            '
        );


    $stmtSegmentos->execute([
        (int) $clip['arquivo_id'],
        $janelaAntes,
        $janelaDepois,
    ]);


    $segmentos =
        $stmtSegmentos->fetchAll(
            PDO::FETCH_ASSOC
        );


    $stmtSegmentos = null;
    $pdoSegmentos = null;


    if (!$segmentos) {

        throw new RuntimeException(
            'Nenhum segmento temporal encontrado.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MONTAR CONTEXTO
    |--------------------------------------------------------------------------
    */

    $linhas = [];


    foreach ($segmentos as $segmento) {

        $linhas[] =
            '['
            .
            (int) $segmento['inicio_ms']
            .
            ' → '
            .
            (int) $segmento['fim_ms']
            .
            '] '
            .
            trim(
                (string) $segmento['texto']
            );
    }


    $contexto =
        implode(
            "\n",
            $linhas
        );


    $titulo =
        trim(
            (string) (
                $clip['titulo_clip']
                ?? ''
            )
        );


    $funcao =
        trim(
            (string) (
                $clip['funcao']
                ?? 'principal'
            )
        );


    $nomeArquivo =
        trim(
            (string) $clip['nome_arquivo']
        );


    /*
    |--------------------------------------------------------------------------
    | PROMPT
    |--------------------------------------------------------------------------
    */

    $prompt =
        <<<PROMPT
Você é um editor profissional de vídeo.

Refine os limites deste corte para que a fala soe natural.

ARQUIVO:
{$nomeArquivo}

FUNÇÃO:
{$funcao}

TÍTULO:
{$titulo}

CORTE ATUAL:
inicio_ms = {$inicioAtual}
fim_ms = {$fimAtual}

SEGMENTOS DISPONÍVEIS:

{$contexto}

OBJETIVO:

Evitar que o vídeo:

- comece no meio de uma frase;
- termine no meio de uma frase;
- termine com uma ideia incompleta;
- termine em conectivos como "porque", "mas", "então", "e", "aí";
- fique sem contexto.

Ao mesmo tempo, mantenha o corte enxuto.

REGRAS:

1. inicio_ms deve ser exatamente um inicio_ms existente acima.
2. fim_ms deve ser exatamente um fim_ms existente acima.
3. Não invente timestamps.
4. Pode manter o corte atual se já estiver bom.
5. Pode expandir um pouco antes ou depois para completar a frase.
6. Pode reduzir partes desnecessárias.
7. fim_ms precisa ser maior que inicio_ms.
8. Retorne um motivo curto em português.
PROMPT;


    $schema = [

        'type' =>
            'object',

        'additionalProperties' =>
            false,

        'properties' => [

            'inicio_ms' => [
                'type' => 'integer',
            ],

            'fim_ms' => [
                'type' => 'integer',
            ],

            'motivo' => [
                'type' => 'string',
            ],

        ],

        'required' => [
            'inicio_ms',
            'fim_ms',
            'motivo',
        ],

    ];


    /*
    |--------------------------------------------------------------------------
    | OPENAI
    |--------------------------------------------------------------------------
    */

    $openAI =
        new OpenAIService();


    $resultado =
        $openAI
            ->responderEstruturado(
                $prompt,
                'refino_clip',
                $schema
            );


    $dadosIA =
        $resultado['dados']
        ?? [];


    $inicioRefinado =
        (int) (
            $dadosIA['inicio_ms']
            ?? -1
        );


    $fimRefinado =
        (int) (
            $dadosIA['fim_ms']
            ?? -1
        );


    $motivo =
        trim(
            (string) (
                $dadosIA['motivo']
                ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDAR TIMESTAMPS
    |--------------------------------------------------------------------------
    */

    $iniciosPermitidos = [];
    $finsPermitidos = [];


    foreach ($segmentos as $segmento) {

        $iniciosPermitidos[
            (int) $segmento['inicio_ms']
        ] = true;


        $finsPermitidos[
            (int) $segmento['fim_ms']
        ] = true;
    }


    if (
        !isset(
            $iniciosPermitidos[
                $inicioRefinado
            ]
        )
    ) {

        throw new RuntimeException(
            'A IA escolheu início fora dos segmentos reais.'
        );
    }


    if (
        !isset(
            $finsPermitidos[
                $fimRefinado
            ]
        )
    ) {

        throw new RuntimeException(
            'A IA escolheu fim fora dos segmentos reais.'
        );
    }


    if (
        $fimRefinado <=
        $inicioRefinado
    ) {

        throw new RuntimeException(
            'A IA retornou duração inválida.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PEQUENA FOLGA TÉCNICA
    |--------------------------------------------------------------------------
    */

    $inicioTecnico =
        max(
            0,
            $inicioRefinado - 250
        );


    $fimTecnico =
        $fimRefinado + 300;


    $duracaoVideo =
        (int) (
            $clip['duracao_ms']
            ?? 0
        );


    if ($duracaoVideo > 0) {

        $fimTecnico =
            min(
                $fimTecnico,
                $duracaoVideo
            );
    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    $pdoSalvar = db(true);


    $stmtSalvar =
        $pdoSalvar->prepare(
            '
            UPDATE capitulo_edicao_clips

            SET
                inicio_ms_refinado = ?,
                fim_ms_refinado = ?,
                refino_status = "refinado",
                refino_motivo = ?,
                refino_at = NOW()

            WHERE id = ?
            '
        );


    $stmtSalvar->execute([
        $inicioTecnico,
        $fimTecnico,
        $motivo,
        $clipId,
    ]);


    $stmtSalvar = null;
    $pdoSalvar = null;


    /*
    |--------------------------------------------------------------------------
    | NOVA CONTAGEM
    |--------------------------------------------------------------------------
    */

    $pdoFinal = db(true);


    $stmtFinal =
        $pdoFinal->prepare(
            '
            SELECT

                COUNT(*) AS total,

                SUM(
                    CASE
                        WHEN refino_status = "refinado"
                        THEN 1
                        ELSE 0
                    END
                ) AS refinados,

                SUM(
                    CASE
                        WHEN refino_status = "erro"
                        THEN 1
                        ELSE 0
                    END
                ) AS erros

            FROM capitulo_edicao_clips

            WHERE capitulo_id = ?
            '
        );


    $stmtFinal->execute([
        $capituloId
    ]);


    $final =
        $stmtFinal->fetch(
            PDO::FETCH_ASSOC
        );


    $stmtFinal = null;
    $pdoFinal = null;


    $refinadosAgora =
        (int) (
            $final['refinados']
            ?? 0
        );


    responderRefino(
        [
            'success' =>
                true,

            'done' =>
                (
                    $refinadosAgora
                    >=
                    (int) $final['total']
                ),

            'clip_id' =>
                $clipId,

            'ordem' =>
                (int) $clip['ordem'],

            'arquivo' =>
                $nomeArquivo,

            'titulo_clip' =>
                $titulo,

            'inicio_refinado' =>
                $inicioTecnico,

            'fim_refinado' =>
                $fimTecnico,

            'total_clips' =>
                (int) $final['total'],

            'refinados' =>
                $refinadosAgora,

            'erros' =>
                (int) (
                    $final['erros']
                    ?? 0
                ),

            'message' =>
                'Corte refinado com sucesso.',
        ]
    );


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | SE SOUBERMOS QUAL CLIP ESTAVA SENDO PROCESSADO
    |--------------------------------------------------------------------------
    */

    if (
        isset($clipId)
        &&
        $clipId > 0
    ) {

        try {

            $pdoErro = db(true);


            $stmtErro =
                $pdoErro->prepare(
                    '
                    UPDATE capitulo_edicao_clips

                    SET
                        refino_status =
                            "erro",

                        refino_motivo =
                            ?

                    WHERE id = ?
                    '
                );


            $stmtErro->execute([
                $e->getMessage(),
                $clipId,
            ]);


        } catch (Throwable $ignorado) {
        }
    }


    responderRefino(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}