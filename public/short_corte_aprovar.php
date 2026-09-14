<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

try {

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

        throw new RuntimeException('Método inválido.');
    }


    $corteId = (int) ($_POST['corte_id'] ?? 0);

    if ($corteId <= 0) {

        throw new RuntimeException('Corte inválido.');
    }


    $aprovado = isset($_POST['aprovado'])
        ? (int) !!$_POST['aprovado']
        : null;

    $arquivoIdOverride = array_key_exists('arquivo_id', $_POST)
        ? ((string) $_POST['arquivo_id'] !== '' ? (int) $_POST['arquivo_id'] : null)
        : false; // false = não foi enviado, não mexer


    $campos = [];
    $valores = [];

    if ($aprovado !== null) {

        $campos[] = 'aprovado = ?';
        $valores[] = $aprovado;
    }

    if ($arquivoIdOverride !== false) {

        $campos[] = 'arquivo_id = ?';
        $valores[] = $arquivoIdOverride;
    }

    if (!$campos) {

        throw new RuntimeException('Nada para atualizar.');
    }

    $valores[] = $corteId;

    $stmt = $pdo->prepare(
        'UPDATE capitulo_short_cortes_sugeridos SET '
        . implode(', ', $campos)
        . ' WHERE id = ?'
    );

    $stmt->execute($valores);


    /*
    |--------------------------------------------------------------------------
    | SE TODOS OS CORTES DO SHORT ESTIVEREM APROVADOS, MARCAR O SHORT
    |--------------------------------------------------------------------------
    */

    $stmtShort = $pdo->prepare(
        'SELECT short_roteiro_id FROM capitulo_short_cortes_sugeridos WHERE id = ?'
    );

    $stmtShort->execute([$corteId]);

    $shortRoteiroId = (int) ($stmtShort->fetchColumn() ?: 0);

    if ($shortRoteiroId) {

        $stmtPendentes = $pdo->prepare(
            'SELECT COUNT(*) FROM capitulo_short_cortes_sugeridos WHERE short_roteiro_id = ? AND aprovado = 0'
        );

        $stmtPendentes->execute([$shortRoteiroId]);

        if ((int) $stmtPendentes->fetchColumn() === 0) {

            $pdo->prepare(
                "UPDATE capitulo_short_roteiros SET narracao_status = 'aprovado' WHERE id = ?"
            )->execute([$shortRoteiroId]);
        }
    }


    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);


} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
