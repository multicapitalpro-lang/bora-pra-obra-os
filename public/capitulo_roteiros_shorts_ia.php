<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| DIAGNÓSTICO DE ERROS FATAIS
|--------------------------------------------------------------------------
*/

register_shutdown_function(function () {

    $erro = error_get_last();

    if ($erro !== null) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'erro_fatal' => $erro['message'] ?? 'Erro desconhecido',
            'arquivo' => $erro['file'] ?? '',
            'linha' => $erro['line'] ?? 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});


/*
|--------------------------------------------------------------------------
| AUTENTICAÇÃO: SESSÃO DO PAINEL OU WORKER (mesma chave Bearer usada
| em triagem_ia_proxima_tarefa.php) -- o worker chama este endpoint
| sozinho pra gerar os Shorts pendentes, sem sessão de navegador.
|--------------------------------------------------------------------------
*/

$autenticadoComoWorker = false;

$cabecalhoAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (preg_match('/Bearer\s+(.+)/i', $cabecalhoAuth, $matchAuth)) {

    $arquivoConfigWorker = dirname(__DIR__, 2) . '/storage/config/worker.php';

    if (file_exists($arquivoConfigWorker)) {

        $configWorker = require $arquivoConfigWorker;

        $segredoWorker = trim((string) ($configWorker['secret'] ?? ''));

        if ($segredoWorker !== '' && hash_equals($segredoWorker, trim((string) $matchAuth[1]))) {

            $autenticadoComoWorker = true;
        }
    }
}

if (!$autenticadoComoWorker) {

    require __DIR__ . '/includes/auth.php';
}


/*
|--------------------------------------------------------------------------
| CARREGAMENTOS
|--------------------------------------------------------------------------
*/

require __DIR__ . '/config/database.php';

require dirname(__DIR__) . '/app/AI/OpenAIService.php';


/*
|--------------------------------------------------------------------------
| BANCO
|--------------------------------------------------------------------------
*/

$pdo = db();

set_exception_handler(function (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
});


/*
|--------------------------------------------------------------------------
| RESPOSTA
|--------------------------------------------------------------------------
*/

function responderShorts(
    array $dados,
    int $status = 200
): never {

    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| MÉTODO
|--------------------------------------------------------------------------
*/

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    !== 'POST'
) {

    responderShorts(
        [
            'success' => false,
            'message' => 'Método inválido.'
        ],
        405
    );
}


try {

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
    | BUSCAR CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo =
        $pdo->prepare(
            '
            SELECT
                c.*,
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
    | PLANO DO EPISÓDIO
    |--------------------------------------------------------------------------
    */

    $stmtPlano =
        $pdo->prepare(
            '
            SELECT *

            FROM capitulo_planos

            WHERE capitulo_id = ?

            LIMIT 1
            '
        );


    $stmtPlano->execute([
        $capituloId
    ]);


    $plano =
        $stmtPlano->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$plano) {

        $plano = [];
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR TRANSCRIÇÃO
    |--------------------------------------------------------------------------
    */

    $colunasArquivos = [];


    $stmtColunas =
        $pdo->query(
            'SHOW COLUMNS FROM capitulo_arquivos'
        );


    foreach (
        $stmtColunas->fetchAll(
            PDO::FETCH_ASSOC
        )
        as $coluna
    ) {

        if (
            !empty(
                $coluna['Field']
            )
        ) {

            $colunasArquivos[] =
                $coluna['Field'];
        }
    }


    $temTranscricao =
        in_array(
            'ia_transcricao',
            $colunasArquivos,
            true
        );


    /*
    |--------------------------------------------------------------------------
    | ARQUIVOS
    |--------------------------------------------------------------------------
    */

    $campoTranscricao =
        $temTranscricao
            ? ', a.ia_transcricao'
            : '';


    $sqlArquivos =
        '
        SELECT
            a.id,
            a.nome_arquivo,
            a.duracao_ms,
            a.drive_url,
            a.thumbnail_url,
            a.ordem,

            tr.decisao,
            tr.tipo,
            tr.inicio_ms,
            tr.fim_ms,
            tr.importancia,
            tr.possivel_uso,
            tr.observacoes

            '
            .
            $campoTranscricao
            .
            '

        FROM capitulo_arquivos a

        LEFT JOIN capitulo_triagem tr
            ON tr.arquivo_id = a.id

        WHERE
            a.capitulo_id = ?

            AND a.ativo = 1

            AND (tr.decisao IS NULL OR tr.decisao IN (\'usar\', \'parcial\'))

        ORDER BY

            /*
            | Capítulos com muitos brutos (30-40+) deixam a chamada
            | pra OpenAI lenta o suficiente pra estourar o timeout do
            | servidor (504). Priorizamos os melhores arquivos e
            | limitamos a quantidade abaixo em vez de mandar tudo.
            | Arquivo sem transcrição não ajuda em nada a IA, então
            | nunca deve tomar a vaga de um que tem.
            */

            (a.ia_transcricao IS NOT NULL AND a.ia_transcricao <> \'\') DESC,

            CASE tr.decisao
                WHEN \'usar\' THEN 0
                WHEN \'parcial\' THEN 1
                ELSE 2
            END,

            CASE tr.importancia
                WHEN \'essencial\' THEN 0
                WHEN \'forte\' THEN 1
                WHEN \'boa\' THEN 2
                WHEN \'normal\' THEN 3
                ELSE 4
            END,

            a.ordem ASC,
            a.id ASC

        LIMIT 10
        ';


    $stmtArquivos =
        $pdo->prepare(
            $sqlArquivos
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
            'Este capítulo ainda não possui vídeos para analisar.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PREPARAR MATERIAL PARA IA
    |--------------------------------------------------------------------------
    */

    $arquivosIA = [];


    foreach (
        $arquivosBanco
        as $arquivo
    ) {

        $duracaoMs =
            (int) (
                $arquivo['duracao_ms']
                ?? 0
            );


        $duracaoSegundos =
            $duracaoMs > 0
                ? round(
                    $duracaoMs / 1000,
                    2
                )
                : 0;


        $transcricao =
            $temTranscricao
                ? trim(
                    (string) (
                        $arquivo[
                            'ia_transcricao'
                        ]
                        ?? ''
                    )
                )
                : '';


        $arquivosIA[] = [

            'id' =>
                (int) $arquivo['id'],

            'nome_arquivo' =>
                (string) (
                    $arquivo[
                        'nome_arquivo'
                    ]
                    ?? ''
                ),

            'duracao' =>
                $duracaoSegundos > 0
                    ? number_format(
                        $duracaoSegundos,
                        2,
                        '.',
                        ''
                    )
                    . ' segundos'
                    : '',

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

            'decisao' =>
                (string) (
                    $arquivo[
                        'decisao'
                    ]
                    ?? 'pendente'
                ),

            'ia_transcricao' =>
                $transcricao,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR TRANSCRIÇÕES
    |--------------------------------------------------------------------------
    */

    $totalComTranscricao = 0;


    foreach (
        $arquivosIA
        as $arquivo
    ) {

        if (
            trim(
                (string) (
                    $arquivo[
                        'ia_transcricao'
                    ]
                    ?? ''
                )
            )
            !== ''
        ) {

            $totalComTranscricao++;
        }
    }


    if (
        $totalComTranscricao === 0
    ) {

        throw new RuntimeException(
            'Nenhum vídeo deste capítulo possui transcrição da IA disponível. Primeiro conclua a transcrição dos brutos.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ROTEIROS JÁ EXISTENTES (limite e não repetir ângulo)
    |--------------------------------------------------------------------------
    |
    | Cada chamada gera só 1 Short (ver comentário em
    | OpenAIService::gerarRoteirosShorts) -- chamadas grandes demais
    | (varios roteiros de uma vez) estouravam o timeout do servidor
    | (504) em capitulos com muitos brutos.
    |
    |--------------------------------------------------------------------------
    */

    $stmtExistentes =
        $pdo->prepare(
            'SELECT titulo, tema FROM capitulo_short_roteiros WHERE capitulo_id = ?'
        );

    $stmtExistentes->execute([$capituloId]);

    $roteirosExistentes =
        $stmtExistentes->fetchAll(PDO::FETCH_ASSOC);

    /*
    | O limite "de verdade" agora é os 5 tipos fixos do template
    | (ver OpenAIService::gerarRoteirosShorts) -- essa checagem aqui é
    | só uma trava de segurança solta, pra não acumular lixo caso algo
    | saia do previsto (ex.: roteiros antigos gerados antes dessa
    | mudança, que não usam as chaves fixas de tipo).
    */

    if (count($roteirosExistentes) >= 10) {

        throw new RuntimeException(
            'Este capítulo já tem muitos roteiros de Shorts. '
            . 'Use os existentes ou apague algum antes de gerar outro.'
        );
    }

    /*
    | tema guarda a chave do tipo fixo (problema/custo/como_fizemos/
    | erro/resultado) a partir desta mudança. Roteiros antigos gerados
    | antes dela podem ter texto livre em tema -- nesse caso
    | simplesmente não batem com nenhum dos 5 tipos e não bloqueiam
    | nada (aceitável: só afeta a checagem de duplicidade, não quebra
    | a geração).
    */

    $angulosJaUsados = [];

    foreach ($roteirosExistentes as $existente) {

        $tema = trim((string) ($existente['tema'] ?? ''));

        if ($tema !== '') {
            $angulosJaUsados[] = $tema;
        }
    }


    $openAI = new OpenAIService();


    /*
    |--------------------------------------------------------------------------
    | DADOS DO CAPÍTULO
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


    $objetivoCapitulo =
        trim(
            (string) (
                $plano[
                    'objetivo'
                ]
                ?? ''
            )
        );


    $ganchoCapitulo =
        trim(
            (string) (
                $plano[
                    'gancho'
                ]
                ?? ''
            )
        );


    $contextoCapitulo =
        trim(
            (string) (
                $plano[
                    'contexto'
                ]
                ?? ''
            )
        );


    $desenvolvimentoCapitulo =
        trim(
            (string) (
                $plano[
                    'desenvolvimento'
                ]
                ?? ''
            )
        );


    $encerramentoCapitulo =
        trim(
            (string) (
                $plano[
                    'encerramento'
                ]
                ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | GERAR ROTEIROS
    |--------------------------------------------------------------------------
    */

    $inicio =
        microtime(true);


    $resultadoIA =
        $openAI->gerarRoteirosShorts(

            $tituloCapitulo,

            $objetivoCapitulo,

            $ganchoCapitulo,

            $contextoCapitulo,

            $desenvolvimentoCapitulo,

            $encerramentoCapitulo,

            $arquivosIA,

            $angulosJaUsados

        );


    /*
    |--------------------------------------------------------------------------
    | RECONECTAR AO BANCO
    |--------------------------------------------------------------------------
    |
    | A chamada acima para a OpenAI pode levar mais de 1 minuto.
    | Nesse tempo o MySQL pode derrubar a conexao original por
    | ociosidade ("MySQL server has gone away"). Por isso pedimos
    | uma conexao nova antes de salvar o resultado.
    |
    |--------------------------------------------------------------------------
    */

    $pdo = db(true);


    /*
    |--------------------------------------------------------------------------
    | DADOS RETORNADOS
    |--------------------------------------------------------------------------
    */

    $dadosIA =
        $resultadoIA['dados']
        ?? [];


    $shorts =
        $dadosIA['roteiros']
        ?? [];


    if (
        !is_array(
            $shorts
        )
        ||
        count($shorts) === 0
    ) {

        throw new RuntimeException(
            'A IA respondeu, mas não encontrou oportunidades de Shorts.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INFORMAÇÕES DA IA
    |--------------------------------------------------------------------------
    */

    $modeloIA =
        trim(
            (string) (
                $resultadoIA['model']
                ??
                ''
            )
        );


    $responseIdIA =
        trim(
            (string) (
                $resultadoIA['response_id']
                ??
                ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | TRANSAÇÃO
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | INSERT (soma ao que já existe -- não apaga roteiros anteriores)
    |--------------------------------------------------------------------------
    |
    | ATENÇÃO:
    | Estas são SOMENTE as colunas que realmente existem
    | na tabela capitulo_short_roteiros.
    |
    |--------------------------------------------------------------------------
    */

    $sqlInsert =
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
            ?,
            NOW()
        )
        ';


    $stmtInsert =
        $pdo->prepare(
            $sqlInsert
        );


    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    $numeroShort =
        0;


    $roteirosSalvos = [];


    foreach (
        $shorts
        as $short
    ) {

        if (
            !is_array(
                $short
            )
        ) {

            continue;
        }


        $numeroShort++;


        /*
        |--------------------------------------------------------------------------
        | CAMPOS PRINCIPAIS
        |--------------------------------------------------------------------------
        */

        $titulo =
            trim(
                (string) (
                    $short[
                        'titulo'
                    ]
                    ??
                    'Short '
                    .
                    $numeroShort
                )
            );


        $angulo =
            trim(
                (string) (
                    $short[
                        'angulo'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | POTENCIAL
        |--------------------------------------------------------------------------
        |
        | A versão atual da IA não possui esse campo.
        | Portanto usamos o padrão da tabela.
        |
        |--------------------------------------------------------------------------
        */

        $potencial =
            'médio';


        /*
        |--------------------------------------------------------------------------
        | DURAÇÃO
        |--------------------------------------------------------------------------
        */

        $duracao =
            (int) (
                $short[
                    'duracao_alvo_segundos'
                ]
                ?? 0
            );


        /*
        |--------------------------------------------------------------------------
        | BLOCO 1 — VINHETA
        |--------------------------------------------------------------------------
        */

        $bloco1 =
            'INSERIR VINHETA PADRÃO DO BORA PRA OBRA.';


        /*
        |--------------------------------------------------------------------------
        | BLOCO 2 — ASSUNTO
        |--------------------------------------------------------------------------
        */

        $gancho =
            trim(
                (string) (
                    $short[
                        'gancho'
                    ]
                    ?? ''
                )
            );


        $contexto =
            trim(
                (string) (
                    $short[
                        'contexto'
                    ]
                    ?? ''
                )
            );


        $bloco2 =
            trim(
                $gancho
                .
                (
                    $contexto !== ''
                        ? "\n\n" . $contexto
                        : ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | BLOCO 3 — CUSTO DA ETAPA
        |--------------------------------------------------------------------------
        */

        $bloco3 =
            trim(
                (string) (
                    $short[
                        'custo_momento'
                    ]
                    ?? ''
                )
            );


        if (
            $bloco3 === ''
        ) {

            $bloco3 =
                'INSERIR CUSTO DA ETAPA NO APLICATIVO.';
        }


        /*
        |--------------------------------------------------------------------------
        | BLOCO 4 — EXECUÇÃO
        |--------------------------------------------------------------------------
        */

        $bloco4 =
            trim(
                (string) (
                    $short[
                        'execucao'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | BLOCO 5 — FECHAMENTO
        |--------------------------------------------------------------------------
        */

        $bloco5 =
            trim(
                (string) (
                    $short[
                        'fechamento'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | BLOCO 6 — CUSTO ACUMULADO
        |--------------------------------------------------------------------------
        */

        $bloco6 =
            trim(
                (string) (
                    $short[
                        'custo_acumulado'
                    ]
                    ?? ''
                )
            );


        if (
            $bloco6 === ''
        ) {

            $bloco6 =
                'INSERIR CUSTO ACUMULADO NO APLICATIVO.';
        }


        /*
        |--------------------------------------------------------------------------
        | BLOCO 7 — CTA
        |--------------------------------------------------------------------------
        */

        $bloco7 =
            trim(
                (string) (
                    $short[
                        'cta'
                    ]
                    ?? ''
                )
            );


        if (
            $bloco7 === ''
        ) {

            $bloco7 =
                'INSERIR CTA PADRÃO DO APLICATIVO.';
        }


        /*
        |--------------------------------------------------------------------------
        | NARRAÇÃO
        |--------------------------------------------------------------------------
        */

        $roteiroNarracao =
            trim(
                (string) (
                    $short[
                        'narracao'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | ROTEIRO COMPLETO
        |--------------------------------------------------------------------------
        */

        $roteiroCompleto =
            trim(
                (string) (
                    $short[
                        'roteiro_completo'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | TEXTO NA TELA
        |--------------------------------------------------------------------------
        */

        $textoTela =
            trim(
                (string) (
                    $short[
                        'texto_tela'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | B-ROLL
        |--------------------------------------------------------------------------
        */

        $broll =
            trim(
                (string) (
                    $short[
                        'broll'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | SUGESTÕES VISUAIS
        |--------------------------------------------------------------------------
        */

        $sugestoesVisuais =
            trim(
                $broll
                .
                (
                    $textoTela !== ''
                        ? "\n\nTEXTOS NA TELA:\n"
                          .
                          $textoTela
                        : ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | ARQUIVOS SUGERIDOS
        |--------------------------------------------------------------------------
        */

        $arquivosSugeridos =
            $broll;


        /*
        |--------------------------------------------------------------------------
        | LEGENDA
        |--------------------------------------------------------------------------
        */

        $legenda =
            $textoTela;


        /*
        |--------------------------------------------------------------------------
        | OBSERVAÇÕES IA
        |--------------------------------------------------------------------------
        */

        $observacoesIA =
            trim(
                "Ângulo: "
                .
                $angulo
                .
                "\n\n"
                .
                "Gancho: "
                .
                $gancho
                .
                "\n\n"
                .
                "Custo da etapa: "
                .
                $bloco3
                .
                "\n\n"
                .
                "Custo acumulado: "
                .
                $bloco6
            );


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        $status =
            'roteiro_pronto';


        /*
        |--------------------------------------------------------------------------
        | INSERT
        |--------------------------------------------------------------------------
        */

        $stmtInsert->execute([

            $capituloId,

            $titulo,

            $angulo,

            $potencial,

            $duracao > 0
                ? $duracao
                : null,

            $status,

            $bloco1,

            $bloco2,

            $bloco3,

            $bloco4,

            $bloco5,

            $bloco6,

            $bloco7,

            $roteiroNarracao,

            $roteiroCompleto,

            $sugestoesVisuais,

            $arquivosSugeridos,

            $legenda,

            $observacoesIA,

            $modeloIA !== ''
                ? $modeloIA
                : null,

            $responseIdIA !== ''
                ? $responseIdIA
                : null,

        ]);


        $roteirosSalvos[] = [

            'id' =>
                (int) $pdo->lastInsertId(),

            'numero_short' =>
                $numeroShort,

            'titulo' =>
                $titulo,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR
    |--------------------------------------------------------------------------
    */

    if (
        count(
            $roteirosSalvos
        )
        === 0
    ) {

        throw new RuntimeException(
            'Nenhum roteiro válido foi salvo.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | TEMPO
    |--------------------------------------------------------------------------
    */

    $tempoSegundos =
        round(
            microtime(true)
            -
            $inicio,
            2
        );


    /*
    |--------------------------------------------------------------------------
    | RESPOSTA
    |--------------------------------------------------------------------------
    */

    responderShorts(

        [

            'success' =>
                true,

            'message' =>
                count(
                    $roteirosSalvos
                )
                .
                ' roteiro(s) de Shorts gerado(s) com sucesso.',

            'total' =>
                count(
                    $roteirosSalvos
                ),

            'roteiros' =>
                $roteirosSalvos,

            'tempo_segundos' =>
                $tempoSegundos,

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

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,

            'message' => $e->getMessage(),

            'erro' => $e->getMessage(),

            'arquivo' => $e->getFile(),

            'linha' => $e->getLine(),

            'trace' => $e->getTraceAsString(),
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    exit;
}