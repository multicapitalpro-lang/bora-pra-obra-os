<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$pdo = db();

try {

    /*
    |--------------------------------------------------------------------------
    | MÉTODO
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException(
            'Método inválido.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DADOS
    |--------------------------------------------------------------------------
    */

    $corteId = (int) (
        $_POST['corte_id'] ?? 0
    );

    $capituloId = (int) (
        $_POST['capitulo_id'] ?? 0
    );

    if ($corteId <= 0) {
        throw new RuntimeException(
            'Corte inválido.'
        );
    }

    if ($capituloId <= 0) {
        throw new RuntimeException(
            'Capítulo inválido.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR SE O CORTE EXISTE
    |--------------------------------------------------------------------------
    |
    | Também verificamos o capítulo para impedir que um ID de corte
    | pertencente a outro capítulo seja excluído por engano.
    |
    |--------------------------------------------------------------------------
    */

    $stmtCorte = $pdo->prepare(
        '
        SELECT id
        FROM capitulo_cortes_curtos
        WHERE id = ?
          AND capitulo_id = ?
        LIMIT 1
        '
    );

    $stmtCorte->execute([
        $corteId,
        $capituloId
    ]);

    if (!$stmtCorte->fetchColumn()) {

        throw new RuntimeException(
            'Corte não encontrado neste capítulo.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EXCLUIR
    |--------------------------------------------------------------------------
    */

    $stmtExcluir = $pdo->prepare(
        '
        DELETE FROM capitulo_cortes_curtos
        WHERE id = ?
          AND capitulo_id = ?
        LIMIT 1
        '
    );

    $stmtExcluir->execute([
        $corteId,
        $capituloId
    ]);

    if ($stmtExcluir->rowCount() < 1) {

        throw new RuntimeException(
            'Não foi possível excluir o corte.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESPOSTA
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Corte excluído com sucesso.'
    ]);

} catch (Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}