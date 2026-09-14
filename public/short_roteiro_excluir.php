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

    $shortId = (int) ($_POST['short_roteiro_id'] ?? 0);

    if ($shortId <= 0) {
        throw new RuntimeException('Roteiro inválido.');
    }

    $stmt = $pdo->prepare(
        'SELECT capitulo_id, narracao_audio_path FROM capitulo_short_roteiros WHERE id = ?'
    );
    $stmt->execute([$shortId]);
    $roteiro = $stmt->fetch();

    if (!$roteiro) {
        throw new RuntimeException('Roteiro não encontrado.');
    }

    /*
    | capitulo_short_cortes_sugeridos tem ON DELETE CASCADE pra
    | short_roteiro_id, então as sugestões de corte somem sozinhas
    | junto com o roteiro.
    */
    $pdo->prepare('DELETE FROM capitulo_short_roteiros WHERE id = ?')
        ->execute([$shortId]);

    $audioPath = trim((string) ($roteiro['narracao_audio_path'] ?? ''));

    if ($audioPath !== '') {

        $caminhoCompleto = dirname(__DIR__, 2) . '/storage/' . $audioPath;

        if (is_file($caminhoCompleto)) {
            @unlink($caminhoCompleto);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Roteiro excluído.',
        'capitulo_id' => (int) $roteiro['capitulo_id'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
