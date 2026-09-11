<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método inválido.');
    }

    $capituloId = (int) ($_POST['capitulo_id'] ?? 0);

    if ($capituloId <= 0) {
        throw new RuntimeException('Capítulo inválido.');
    }

    $status = trim(
        $_POST['status_edicao'] ?? 'nao_iniciada'
    );

    $statusPermitidos = [
        'nao_iniciada',
        'em_edicao',
        'aguardando_revisao',
        'concluida',
    ];

    if (!in_array($status, $statusPermitidos, true)) {
        throw new RuntimeException('Status de edição inválido.');
    }

    /*
    |--------------------------------------------------------------------------
    | Confirmar capítulo e plano
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        SELECT c.id, p.status_plano
        FROM capitulos c
        LEFT JOIN capitulo_planos p
            ON p.capitulo_id = c.id
        WHERE c.id = ?
        LIMIT 1
        '
    );

    $stmt->execute([$capituloId]);
    $registro = $stmt->fetch();

    if (!$registro) {
        throw new RuntimeException('Capítulo não encontrado.');
    }

    if (($registro['status_plano'] ?? '') !== 'pronto_edicao') {
        throw new RuntimeException(
            'O Plano do Episódio precisa estar marcado como Pronto para edição.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Checklist
    |--------------------------------------------------------------------------
    */

    $checklist = [
        'projeto_criado',
        'arquivos_importados',
        'corte_bruto',
        'narrativa_montada',
        'broll_aplicado',
        'audio_tratado',
        'musica_aplicada',
        'textos_aplicados',
        'legendas_aplicadas',
        'cor_ajustada',
        'revisao_editor',
        'exportacao_v1',
    ];

    $valores = [];

    foreach ($checklist as $campo) {
        $valores[$campo] = isset($_POST[$campo]) ? 1 : 0;
    }

    $nomeProjeto = trim($_POST['nome_projeto'] ?? '');
    $caminhoProjeto = trim($_POST['caminho_projeto'] ?? '');
    $linkExportacao = trim($_POST['link_exportacao'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Duração final
    |--------------------------------------------------------------------------
    */

    $duracaoFinalSegundos = null;
    $duracaoFinal = trim($_POST['duracao_final'] ?? '');

    if ($duracaoFinal !== '') {

        $partes = explode(':', $duracaoFinal);

        if (count($partes) === 2) {

            $minutos = (int) $partes[0];
            $segundos = (int) $partes[1];

            if ($segundos < 0 || $segundos > 59 || $minutos < 0) {
                throw new RuntimeException(
                    'Duração final inválida. Use MM:SS.'
                );
            }

            $duracaoFinalSegundos =
                ($minutos * 60) + $segundos;

        } elseif (count($partes) === 3) {

            $horas = (int) $partes[0];
            $minutos = (int) $partes[1];
            $segundos = (int) $partes[2];

            if (
                $horas < 0
                || $minutos < 0
                || $minutos > 59
                || $segundos < 0
                || $segundos > 59
            ) {
                throw new RuntimeException(
                    'Duração final inválida. Use HH:MM:SS.'
                );
            }

            $duracaoFinalSegundos =
                ($horas * 3600)
                + ($minutos * 60)
                + $segundos;

        } else {

            throw new RuntimeException(
                'Duração final inválida. Use MM:SS ou HH:MM:SS.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Datas
    |--------------------------------------------------------------------------
    */

    $iniciadoEm =
        $status !== 'nao_iniciada'
            ? date('Y-m-d H:i:s')
            : null;

    $finalizadoEm =
        $status === 'concluida'
            ? date('Y-m-d H:i:s')
            : null;

    /*
    |--------------------------------------------------------------------------
    | Salvar
    |--------------------------------------------------------------------------
    */

    $sql = '
        INSERT INTO capitulo_edicao
        (
            capitulo_id,
            status_edicao,
            projeto_criado,
            arquivos_importados,
            corte_bruto,
            narrativa_montada,
            broll_aplicado,
            audio_tratado,
            musica_aplicada,
            textos_aplicados,
            legendas_aplicadas,
            cor_ajustada,
            revisao_editor,
            exportacao_v1,
            nome_projeto,
            caminho_projeto,
            link_exportacao,
            duracao_final_segundos,
            observacoes,
            iniciado_em,
            finalizado_em
        )
        VALUES
        (
            :capitulo_id,
            :status_edicao,
            :projeto_criado,
            :arquivos_importados,
            :corte_bruto,
            :narrativa_montada,
            :broll_aplicado,
            :audio_tratado,
            :musica_aplicada,
            :textos_aplicados,
            :legendas_aplicadas,
            :cor_ajustada,
            :revisao_editor,
            :exportacao_v1,
            :nome_projeto,
            :caminho_projeto,
            :link_exportacao,
            :duracao_final_segundos,
            :observacoes,
            :iniciado_em,
            :finalizado_em
        )

        ON DUPLICATE KEY UPDATE

            status_edicao = VALUES(status_edicao),
            projeto_criado = VALUES(projeto_criado),
            arquivos_importados = VALUES(arquivos_importados),
            corte_bruto = VALUES(corte_bruto),
            narrativa_montada = VALUES(narrativa_montada),
            broll_aplicado = VALUES(broll_aplicado),
            audio_tratado = VALUES(audio_tratado),
            musica_aplicada = VALUES(musica_aplicada),
            textos_aplicados = VALUES(textos_aplicados),
            legendas_aplicadas = VALUES(legendas_aplicadas),
            cor_ajustada = VALUES(cor_ajustada),
            revisao_editor = VALUES(revisao_editor),
            exportacao_v1 = VALUES(exportacao_v1),
            nome_projeto = VALUES(nome_projeto),
            caminho_projeto = VALUES(caminho_projeto),
            link_exportacao = VALUES(link_exportacao),
            duracao_final_segundos = VALUES(duracao_final_segundos),
            observacoes = VALUES(observacoes),

            iniciado_em =
                CASE
                    WHEN VALUES(status_edicao) <> "nao_iniciada"
                    THEN COALESCE(
                        capitulo_edicao.iniciado_em,
                        VALUES(iniciado_em)
                    )
                    ELSE capitulo_edicao.iniciado_em
                END,

            finalizado_em =
                CASE
                    WHEN VALUES(status_edicao) = "concluida"
                    THEN VALUES(finalizado_em)
                    ELSE NULL
                END
    ';

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':capitulo_id' => $capituloId,
        ':status_edicao' => $status,
        ':projeto_criado' => $valores['projeto_criado'],
        ':arquivos_importados' => $valores['arquivos_importados'],
        ':corte_bruto' => $valores['corte_bruto'],
        ':narrativa_montada' => $valores['narrativa_montada'],
        ':broll_aplicado' => $valores['broll_aplicado'],
        ':audio_tratado' => $valores['audio_tratado'],
        ':musica_aplicada' => $valores['musica_aplicada'],
        ':textos_aplicados' => $valores['textos_aplicados'],
        ':legendas_aplicadas' => $valores['legendas_aplicadas'],
        ':cor_ajustada' => $valores['cor_ajustada'],
        ':revisao_editor' => $valores['revisao_editor'],
        ':exportacao_v1' => $valores['exportacao_v1'],
        ':nome_projeto' => $nomeProjeto ?: null,
        ':caminho_projeto' => $caminhoProjeto ?: null,
        ':link_exportacao' => $linkExportacao ?: null,
        ':duracao_final_segundos' => $duracaoFinalSegundos,
        ':observacoes' => $observacoes ?: null,
        ':iniciado_em' => $iniciadoEm,
        ':finalizado_em' => $finalizadoEm,
    ]);


    /*
|--------------------------------------------------------------------------
| GERAR NOVA VERSÃO PARA REVISÃO
|--------------------------------------------------------------------------
|
| Primeira exportação:
| V1
|
| Se V1 recebeu ajustes:
| próxima exportação vira V2
|
| Se V2 recebeu ajustes:
| próxima exportação vira V3
|
|--------------------------------------------------------------------------
*/

if (
    $status === 'aguardando_revisao'
    &&
    $linkExportacao !== ''
) {

    /*
    |--------------------------------------------------------------------------
    | Verificar se já existe versão ativa em revisão
    |--------------------------------------------------------------------------
    */

    $stmtVersaoAtiva = $pdo->prepare(
        '
        SELECT
            id,
            versao,
            status
        FROM capitulo_edicao_versoes
        WHERE capitulo_id = ?
          AND status IN (
              "gerada",
              "em_revisao"
          )
        ORDER BY versao DESC
        LIMIT 1
        '
    );

    $stmtVersaoAtiva->execute([
        $capituloId
    ]);

    $versaoAtiva =
        $stmtVersaoAtiva->fetch();


    /*
    |--------------------------------------------------------------------------
    | Se já existe uma versão EM REVISÃO,
    | apenas atualizamos o link.
    |--------------------------------------------------------------------------
    |
    | Isso evita criar V2 cada vez que o usuário
    | simplesmente clicar em Salvar edição.
    |
    */

    if ($versaoAtiva) {

        $stmtAtualizarVersao =
            $pdo->prepare(
                '
                UPDATE capitulo_edicao_versoes
                SET
                    link_arquivo = ?,
                    duracao_segundos = ?,
                    observacoes = ?,
                    tipo = "revisao",
                    updated_at = NOW()
                WHERE id = ?
                  AND capitulo_id = ?
                '
            );

        $stmtAtualizarVersao->execute([
            $linkExportacao,
            $duracaoFinalSegundos,
            $observacoes ?: null,
            (int) $versaoAtiva['id'],
            $capituloId,
        ]);

    } else {

        /*
        |--------------------------------------------------------------------------
        | Buscar última versão criada
        |--------------------------------------------------------------------------
        */

        $stmtUltimaVersao =
            $pdo->prepare(
                '
                SELECT
                    MAX(versao)
                FROM capitulo_edicao_versoes
                WHERE capitulo_id = ?
                '
            );

        $stmtUltimaVersao->execute([
            $capituloId
        ]);

        $ultimaVersao =
            (int) $stmtUltimaVersao
                ->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | Próxima versão
        |--------------------------------------------------------------------------
        */

        $novaVersao =
            $ultimaVersao + 1;

        if ($novaVersao <= 0) {
            $novaVersao = 1;
        }


        /*
        |--------------------------------------------------------------------------
        | Criar nova versão
        |--------------------------------------------------------------------------
        */

        $stmtNovaVersao =
            $pdo->prepare(
                '
                INSERT INTO capitulo_edicao_versoes
                (
                    capitulo_id,
                    versao,
                    tipo,
                    link_arquivo,
                    duracao_segundos,
                    observacoes,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    "revisao",
                    ?,
                    ?,
                    ?,
                    "em_revisao"
                )
                '
            );

        $stmtNovaVersao->execute([
            $capituloId,
            $novaVersao,
            $linkExportacao,
            $duracaoFinalSegundos,
            $observacoes ?: null,
        ]);
    }
}

    if (
        $status === 'aguardando_revisao'
        || $status === 'concluida'
    ) {
        $fase = 'revisao';

    } elseif ($status === 'em_edicao') {
        $fase = 'edicao';

    } else {
        $fase = 'plano';
    }

    $stmtFase = $pdo->prepare(
        '
        UPDATE capitulos
        SET fase_producao = ?
        WHERE id = ?
        '
    );

    $stmtFase->execute([
        $fase,
        $capituloId
    ]);

    $concluidos = array_sum($valores);
    $total = count($valores);

    $percentual =
        $total > 0
            ? round(($concluidos / $total) * 100)
            : 0;

    echo json_encode([
        'success' => true,
        'message' => 'Edição salva com sucesso.',
        'percentual' => $percentual,
        'concluidos' => $concluidos,
        'total' => $total,
        'fase' => $fase,
    ]);

} catch (Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
