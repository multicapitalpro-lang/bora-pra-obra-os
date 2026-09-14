<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';
require dirname(__DIR__) . '/app/AI/OpenAIService.php';

$pdo = db();

function responderCorte(
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


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

    responderCorte(
        ['success' => false, 'message' => 'Método inválido.'],
        405
    );
}


try {

    $shortRoteiroId =
        (int) ($_POST['short_roteiro_id'] ?? 0);


    if ($shortRoteiroId <= 0) {

        throw new RuntimeException('Short inválido.');
    }


    /*
    |--------------------------------------------------------------------------
    | BUSCAR SHORT + NARRAÇÃO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT * FROM capitulo_short_roteiros WHERE id = ? LIMIT 1'
    );

    $stmt->execute([$shortRoteiroId]);

    $short = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$short) {

        throw new RuntimeException('Short não encontrado.');
    }


    $segmentosNarracao = json_decode(
        (string) ($short['narracao_transcricao'] ?? ''),
        true
    );


    if (empty($segmentosNarracao) || !is_array($segmentosNarracao)) {

        throw new RuntimeException(
            'Este Short ainda não tem narração transcrita. Envie o áudio primeiro.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CAPÍTULO DO SHORT
    |--------------------------------------------------------------------------
    */

    $capituloId = (int) $short['capitulo_id'];


    /*
    |--------------------------------------------------------------------------
    | BRUTOS DISPONÍVEIS (com transcrição, marcados usar/parcial)
    |--------------------------------------------------------------------------
    */

    $stmtBrutos = $pdo->prepare(
        '
        SELECT
            a.id,
            a.nome_arquivo,
            a.ia_transcricao AS transcricao,
            tr.tipo,
            tr.decisao

        FROM capitulo_arquivos a

        LEFT JOIN capitulo_triagem tr
            ON tr.arquivo_id = a.id

        WHERE
            a.capitulo_id = ?
            AND a.ativo = 1
            AND a.ia_transcricao IS NOT NULL
            AND a.ia_transcricao <> \'\'
            AND (tr.decisao IS NULL OR tr.decisao IN (\'usar\', \'parcial\'))

        ORDER BY

            CASE tr.decisao
                WHEN \'usar\' THEN 0
                WHEN \'parcial\' THEN 1
                ELSE 2
            END,

            a.ordem ASC,
            a.id ASC

        LIMIT 25
        '
    );

    $stmtBrutos->execute([$capituloId]);

    $brutos = $stmtBrutos->fetchAll(PDO::FETCH_ASSOC);


    if (!$brutos) {

        throw new RuntimeException(
            'Nenhum bruto com transcrição disponível para este capítulo. '
            . 'Rode a transcrição dos brutos primeiro.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHAMAR IA
    |--------------------------------------------------------------------------
    */

    $openAI = new OpenAIService();

    $resultadoIA = $openAI->sugerirCortesNarracao(
        $segmentosNarracao,
        $brutos
    );

    $cortes = $resultadoIA['dados']['cortes'] ?? [];

    if (!is_array($cortes) || count($cortes) === 0) {

        throw new RuntimeException('A IA não retornou sugestões de corte.');
    }


    /*
    |--------------------------------------------------------------------------
    | RECONECTAR AO BANCO
    |--------------------------------------------------------------------------
    */

    $pdo = db(true);


    /*
    |--------------------------------------------------------------------------
    | SALVAR (substitui sugestões anteriores)
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    $stmtDelete = $pdo->prepare(
        'DELETE FROM capitulo_short_cortes_sugeridos WHERE short_roteiro_id = ?'
    );

    $stmtDelete->execute([$shortRoteiroId]);


    $stmtInsert = $pdo->prepare(
        '
        INSERT INTO capitulo_short_cortes_sugeridos
        (short_roteiro_id, ordem, narracao_inicio_ms, narracao_fim_ms,
         narracao_texto, arquivo_id, trecho_transcricao, justificativa)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        '
    );

    $salvos = 0;

    foreach ($cortes as $corte) {

        if (!is_array($corte)) {

            continue;
        }

        $indice = (int) ($corte['segmento_index'] ?? -1);

        if (
            $indice < 0
            ||
            !isset($segmentosNarracao[$indice])
        ) {

            continue;
        }

        $segmento = $segmentosNarracao[$indice];

        $arquivoId = $corte['arquivo_id'] ?? null;

        $stmtInsert->execute([
            $shortRoteiroId,
            $indice,
            (int) ($segmento['inicio_ms'] ?? 0),
            (int) ($segmento['fim_ms'] ?? 0),
            (string) ($segmento['texto'] ?? ''),
            $arquivoId !== null ? (int) $arquivoId : null,
            trim((string) ($corte['trecho_transcricao'] ?? '')),
            trim((string) ($corte['justificativa'] ?? '')),
        ]);

        $salvos++;
    }


    if ($salvos === 0) {

        $pdo->rollBack();

        throw new RuntimeException('Nenhuma sugestão válida foi salva.');
    }


    $stmtStatus = $pdo->prepare(
        "UPDATE capitulo_short_roteiros SET narracao_status = 'corte_sugerido' WHERE id = ?"
    );

    $stmtStatus->execute([$shortRoteiroId]);


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RETORNAR LISTA SALVA (com nome do arquivo, pra exibir)
    |--------------------------------------------------------------------------
    */

    $stmtLista = $pdo->prepare(
        '
        SELECT
            c.*,
            a.nome_arquivo

        FROM capitulo_short_cortes_sugeridos c

        LEFT JOIN capitulo_arquivos a
            ON a.id = c.arquivo_id

        WHERE c.short_roteiro_id = ?

        ORDER BY c.ordem ASC
        '
    );

    $stmtLista->execute([$shortRoteiroId]);


    responderCorte([

        'success' => true,

        'message' => $salvos . ' sugestão/sugestões de corte gerada(s).',

        'total' => $salvos,

        'cortes' => $stmtLista->fetchAll(PDO::FETCH_ASSOC),

    ]);


} catch (Throwable $e) {

    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {

        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}
