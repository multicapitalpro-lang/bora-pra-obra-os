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
    $versaoId = (int) ($_POST['versao_id'] ?? 0);
    $acao = trim($_POST['acao_revisao'] ?? '');

    if ($capituloId <= 0 || $versaoId <= 0) {
        throw new RuntimeException('Capítulo ou versão inválidos.');
    }

    if (!in_array($acao, ['ajustes', 'aprovar'], true)) {
        throw new RuntimeException('Ação de revisão inválida.');
    }

    $stmt = $pdo->prepare(
        '
        SELECT id, capitulo_id, versao, status
        FROM capitulo_edicao_versoes
        WHERE id = ?
          AND capitulo_id = ?
        LIMIT 1
        '
    );

    $stmt->execute([
        $versaoId,
        $capituloId,
    ]);

    $versao = $stmt->fetch();

    if (!$versao) {
        throw new RuntimeException('Versão não encontrada.');
    }

    if (($versao['status'] ?? '') === 'aprovada') {
        throw new RuntimeException('Esta versão já foi aprovada.');
    }

    $camposChecklist = [
        'narrativa_ok',
        'cortes_ok',
        'audio_ok',
        'musica_ok',
        'broll_ok',
        'textos_ok',
        'legendas_ok',
        'cor_ok',
    ];

    $valores = [];

    foreach ($camposChecklist as $campo) {
        $valores[$campo] = isset($_POST[$campo]) ? 1 : 0;
    }

    $comentarios = trim(
        $_POST['comentarios_revisao'] ?? ''
    );

    if ($acao === 'aprovar') {

        $pendentes = array_filter(
            $valores,
            static fn (int $valor): bool => $valor !== 1
        );

        if ($pendentes) {
            throw new RuntimeException(
                'Para aprovar a versão, marque todos os itens do checklist.'
            );
        }

        $novoStatus = 'aprovada';
        $aprovadoEm = date('Y-m-d H:i:s');

    } else {

        if ($comentarios === '') {
            throw new RuntimeException(
                'Escreva quais ajustes precisam ser feitos antes de solicitar alterações.'
            );
        }

        $novoStatus = 'alteracoes_solicitadas';
        $aprovadoEm = null;
    }

    $pdo->beginTransaction();

    $stmtUpdate = $pdo->prepare(
        '
        UPDATE capitulo_edicao_versoes
        SET
            narrativa_ok = ?,
            cortes_ok = ?,
            audio_ok = ?,
            musica_ok = ?,
            broll_ok = ?,
            textos_ok = ?,
            legendas_ok = ?,
            cor_ok = ?,
            comentarios_revisao = ?,
            status = ?,
            revisado_em = NOW(),
            aprovado_em = ?
        WHERE id = ?
          AND capitulo_id = ?
        '
    );

    $stmtUpdate->execute([
        $valores['narrativa_ok'],
        $valores['cortes_ok'],
        $valores['audio_ok'],
        $valores['musica_ok'],
        $valores['broll_ok'],
        $valores['textos_ok'],
        $valores['legendas_ok'],
        $valores['cor_ok'],
        $comentarios ?: null,
        $novoStatus,
        $aprovadoEm,
        $versaoId,
        $capituloId,
    ]);

    if ($novoStatus === 'aprovada') {

        $stmtEdicao = $pdo->prepare(
            '
            UPDATE capitulo_edicao
            SET
                status_edicao = "concluida",
                finalizado_em = NOW()
            WHERE capitulo_id = ?
            '
        );

        $stmtEdicao->execute([$capituloId]);

        $stmtCapitulo = $pdo->prepare(
            '
            UPDATE capitulos
            SET fase_producao = "thumb"
            WHERE id = ?
            '
        );

        $stmtCapitulo->execute([$capituloId]);

        $mensagem =
            'Versão V' . (int) $versao['versao'] .
            ' aprovada. O capítulo avançou para Thumbnail.';

    } else {

        $stmtEdicao = $pdo->prepare(
            '
            UPDATE capitulo_edicao
            SET
                status_edicao = "em_edicao",
                finalizado_em = NULL
            WHERE capitulo_id = ?
            '
        );

        $stmtEdicao->execute([$capituloId]);

        $stmtCapitulo = $pdo->prepare(
            '
            UPDATE capitulos
            SET fase_producao = "edicao"
            WHERE id = ?
            '
        );

        $stmtCapitulo->execute([$capituloId]);

        $mensagem =
            'Ajustes solicitados para a V' .
            (int) $versao['versao'] . '.';
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $mensagem,
        'status' => $novoStatus,
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
