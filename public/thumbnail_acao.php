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

    $thumbnailId =
        (int) ($_POST['thumbnail_id'] ?? 0);

    $acao =
        trim($_POST['acao'] ?? '');

    if ($thumbnailId <= 0) {
        throw new RuntimeException(
            'Thumbnail inválida.'
        );
    }

    if (!in_array(
        $acao,
        [
            'aprovar',
            'descartar'
        ],
        true
    )) {
        throw new RuntimeException(
            'Ação inválida.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BUSCAR THUMBNAIL
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        SELECT *
        FROM capitulo_thumbnails
        WHERE id = ?
        LIMIT 1
        '
    );

    $stmt->execute([
        $thumbnailId
    ]);

    $thumbnail =
        $stmt->fetch();

    if (!$thumbnail) {
        throw new RuntimeException(
            'Thumbnail não encontrada.'
        );
    }

    $capituloId =
        (int) $thumbnail['capitulo_id'];

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | APROVAR
    |--------------------------------------------------------------------------
    */

    if ($acao === 'aprovar') {

        /*
        |--------------------------------------------------------------------------
        | DESAPROVAR QUALQUER OUTRA
        |--------------------------------------------------------------------------
        |
        | Queremos apenas uma thumbnail aprovada por capítulo.
        |
        */

        $stmt = $pdo->prepare(
            '
            UPDATE capitulo_thumbnails

            SET
                status = CASE
                    WHEN id = ?
                        THEN "aprovada"

                    WHEN status = "aprovada"
                        THEN "descartada"

                    ELSE status
                END,

                approved_at = CASE
                    WHEN id = ?
                        THEN NOW()
                    ELSE NULL
                END

            WHERE capitulo_id = ?
            '
        );

        $stmt->execute([
            $thumbnailId,
            $thumbnailId,
            $capituloId,
        ]);


        /*
        |--------------------------------------------------------------------------
        | AVANÇAR CAPÍTULO PARA PUBLICAÇÃO
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare(
            '
            UPDATE capitulos
            SET fase_producao = "publicacao"
            WHERE id = ?
            '
        );

        $stmt->execute([
            $capituloId
        ]);


        $mensagem =
            'Thumbnail aprovada. '
            . 'O capítulo está pronto para a etapa de publicação.';


    /*
    |--------------------------------------------------------------------------
    | DESCARTAR
    |--------------------------------------------------------------------------
    */

    } else {

        /*
         * Não vamos permitir que a thumb aprovada
         * seja descartada acidentalmente.
         */

        if (
            ($thumbnail['status'] ?? '')
            === 'aprovada'
        ) {

            throw new RuntimeException(
                'A thumbnail aprovada não pode ser descartada diretamente. Aprove outra versão primeiro.'
            );
        }


        $stmt = $pdo->prepare(
            '
            UPDATE capitulo_thumbnails

            SET
                status = "descartada",
                approved_at = NULL

            WHERE id = ?
            '
        );

        $stmt->execute([
            $thumbnailId
        ]);


        $mensagem =
            'Thumbnail descartada.';
    }


    $pdo->commit();


    echo json_encode([
        'success' => true,
        'message' => $mensagem,
        'acao' => $acao,
    ]);


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}