<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/classes/OpenAIService.php';

$pdo = db();


function responderRoteiros(
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

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        responderRoteiros(
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

    $stmt =
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

    $stmt->execute([
        $capituloId
    ]);

    $capitulo =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$capitulo) {

        throw new RuntimeException(
            'Capítulo não encontrado.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PLANO
    |--------------------------------------------------------------------------
    */

    $stmt =
        $pdo->prepare(
            '
            SELECT
                objetivo,
                gancho,
                contexto,
                desenvolvimento,
                encerramento

            FROM capitulo_planos

            WHERE capitulo_id = ?

            LIMIT 1
            '
        );

    $stmt->execute([
        $capituloId
    ]);

    $plano =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$plano) {

        $plano = [
            'objetivo' =>
                '',

            'gancho' =>
                '',

            'contexto' =>
                '',

            'desenvolvimento' =>
                '',

            'encerramento' =>
                '',
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | BRUTOS + TRANSCRIÇÕES
    |--------------------------------------------------------------------------
    */

    $stmt =
        $pdo->prepare(
            '
            SELECT
                a.id,
                a.nome_arquivo,
                a.duracao_ms,
                a.ia_transcricao,

                tr.tipo,
                tr.importancia,
                tr.possivel_uso,
                tr.observacoes

            FROM capitulo_arquivos a

            LEFT JOIN capitulo_triagem tr
                ON tr.arquivo_id = a.id

            WHERE
                a.capitulo_id = ?

                AND a.ativo = 1

                AND a.ia_transcricao IS NOT NULL

                AND TRIM(a.ia_transcricao) <> ""

            ORDER BY
                a.ordem ASC,
                a.id ASC
            '
        );


    $stmt->execute([
        $capituloId
    ]);


    $arquivosBanco =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    if (!$arquivosBanco) {

        throw new RuntimeException(
            'Nenhum vídeo deste capítulo possui transcrição disponível.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PREPARAR BRUTOS
    |--------------------------------------------------------------------------
    */

    $arquivos = [];


    foreach (
        $arquivosBanco
        as $arquivo
    ) {

        $duracaoMs =
            (int) (
                $arquivo[
                    'duracao_ms'
                ]
                ?? 0
            );


        $duracaoSegundos =
            (int) floor(
                $duracaoMs / 1000
            );


        $duracao =
            sprintf(
                '%02d:%02d',
                floor(
                    $duracaoSegundos / 60
                ),
                $duracaoSegundos % 60
            );


        $arquivos[] = [

            'id' =>
                (int) $arquivo['id'],

            'nome_arquivo' =>
                (string) $arquivo[
                    'nome_arquivo'
                ],

            'duracao' =>
                $duracao,

            'tipo' =>
                (string) (
                    $arquivo['tipo']
                    ?? ''
                ),

            'importancia' =>
                (string) (
                    $arquivo['importancia']
                    ?? ''
                ),

            'possivel_uso' =>
                (string) (
                    $arquivo['possivel_uso']
                    ?? ''
                ),

            'observacoes' =>
                (string) (
                    $arquivo['observacoes']
                    ?? ''
                ),

            'ia_transcricao' =>
                (string) $arquivo[
                    'ia_transcricao'
                ],

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | OPENAI
    |--------------------------------------------------------------------------
    */

    $openAI =
        new OpenAIService();


    $resultado =
        $openAI->gerarRoteirosShorts(

            (string) (
                $capitulo['titulo']
                ?? ''
            ),

            (string) (
                $plano['objetivo']
                ?? ''
            ),

            (string) (
                $plano['gancho']
                ?? ''
            ),

            (string) (
                $plano['contexto']
                ?? ''
            ),

            (string) (
                $plano['desenvolvimento']
                ?? ''
            ),

            (string) (
                $plano['encerramento']
                ?? ''
            ),

            $arquivos
        );


    $dados =
        $resultado['dados']
        ?? [];


    $shorts =
        $dados['shorts']
        ?? [];


    if (!is_array($shorts)) {

        throw new RuntimeException(
            'A IA não retornou uma lista válida de Shorts.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR ROTEIROS
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    /*
     * Opcionalmente descartamos somente
     * roteiros anteriores ainda em estado "ideia".
     *
     * Não mexemos em roteiros selecionados,
     * gravados, editando, prontos ou publicados.
     */

    $stmtLimpar =
        $pdo->prepare(
            '
            DELETE FROM capitulo_short_roteiros

            WHERE capitulo_id = ?

              AND status = "ideia"
            '
        );


    $stmtLimpar->execute([
        $capituloId
    ]);


    $stmtInsert =
        $pdo->prepare(
            '
            INSERT INTO capitulo_short_roteiros
            (
                capitulo_id,
                titulo,
                tema,
                potencial,
                duracao_alvo_segundos,
                status,

                bloco_1_vinheta,
                bloco_2_assunto,
                bloco_3_custo_etapa,
                bloco_4_execucao,
                bloco_5_fechamento,
                bloco_6_custo_acumulado,
                bloco_7_cta,

                roteiro_narracao,
                instrucoes_edicao,
                sugestoes_visuais,
                arquivos_sugeridos,
                legenda,
                observacoes_ia,

                ia_modelo,
                ia_response_id,
                ia_gerado_at
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                "ideia",

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
                ?,
                NOW()
            )
            '
        );


    $idsCriados = [];


    foreach (
        $shorts
        as $short
    ) {

        $stmtInsert->execute([

            $capituloId,

            (string) (
                $short['titulo']
                ?? ''
            ),

            (string) (
                $short['tema']
                ?? ''
            ),

            (string) (
                $short['potencial']
                ?? 'medio'
            ),

            (int) (
                $short[
                    'duracao_alvo_segundos'
                ]
                ?? 0
            ),

            (string) (
                $short[
                    'bloco_1_vinheta'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'bloco_2_assunto'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'bloco_3_custo_etapa'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'bloco_4_execucao'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'bloco_5_fechamento'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'bloco_6_custo_acumulado'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'bloco_7_cta'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'roteiro_narracao'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'instrucoes_edicao'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'sugestoes_visuais'
                ]
                ?? ''
            ),

            (string) (
                $short[
                    'arquivos_sugeridos'
                ]
                ?? ''
            ),

            (string) (
                $short['legenda']
                ?? ''
            ),

            (string) (
                $short[
                    'observacoes_ia'
                ]
                ?? ''
            ),

            (string) (
                $resultado['model']
                ?? 'gpt-5-mini'
            ),

            (string) (
                $resultado['response_id']
                ?? ''
            ),
        ]);


        $idsCriados[] =
            (int) $pdo->lastInsertId();
    }


    $pdo->commit();


    responderRoteiros(
        [
            'success' =>
                true,

            'message' =>
                count($idsCriados)
                .
                ' roteiro(s) de Short gerado(s).',

            'total' =>
                count($idsCriados),

            'ids' =>
                $idsCriados,

        ]
    );


} catch (Throwable $e) {

    if (
        isset($pdo)
        &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }


    responderRoteiros(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}