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

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR SE HÁ VÍDEO APROVADO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        SELECT id
        FROM capitulo_edicao_versoes
        WHERE capitulo_id = ?
          AND status = "aprovada"
        LIMIT 1
        '
    );

    $stmt->execute([$capituloId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(
            'A thumbnail só pode ser criada após uma versão do vídeo ser aprovada.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DADOS
    |--------------------------------------------------------------------------
    */

    $conceito =
        trim($_POST['conceito'] ?? '');

    $textoThumb =
        trim($_POST['texto_thumb'] ?? '');

    $textoSecundario =
        trim($_POST['texto_secundario'] ?? '');

    $elementos =
        trim($_POST['elementos'] ?? '');

    $referenciaVisual =
        trim($_POST['referencia_visual'] ?? '');

    $promptIa =
        trim($_POST['prompt_ia'] ?? '');

    $arquivoUrl =
        trim($_POST['arquivo_url'] ?? '');

    $observacoes =
        trim($_POST['observacoes'] ?? '');

    $status =
        trim($_POST['status'] ?? 'ideia');

    /*
    |--------------------------------------------------------------------------
    | STATUS PERMITIDOS
    |--------------------------------------------------------------------------
    */

    $statusPermitidos = [
        'ideia',
        'gerada',
        'revisao',
    ];

    if (!in_array(
        $status,
        $statusPermitidos,
        true
    )) {
        throw new RuntimeException(
            'Status da thumbnail inválido.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EXIGIR ALGUM CONTEÚDO
    |--------------------------------------------------------------------------
    */

    if (
        $conceito === ''
        &&
        $textoThumb === ''
        &&
        $textoSecundario === ''
        &&
        $elementos === ''
        &&
        $referenciaVisual === ''
        &&
        $promptIa === ''
        &&
        $arquivoUrl === ''
    ) {
        throw new RuntimeException(
            'Preencha pelo menos alguma informação da thumbnail.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRÓXIMA VERSÃO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        SELECT COALESCE(MAX(versao), 0)
        FROM capitulo_thumbnails
        WHERE capitulo_id = ?
        '
    );

    $stmt->execute([$capituloId]);

    $ultimaVersao =
        (int) $stmt->fetchColumn();

    $novaVersao =
        $ultimaVersao + 1;

    /*
    |--------------------------------------------------------------------------
    | INSERIR
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        INSERT INTO capitulo_thumbnails
        (
            capitulo_id,
            versao,
            conceito,
            texto_thumb,
            texto_secundario,
            elementos,
            referencia_visual,
            prompt_ia,
            arquivo_url,
            observacoes,
            status
        )
        VALUES
        (
            :capitulo_id,
            :versao,
            :conceito,
            :texto_thumb,
            :texto_secundario,
            :elementos,
            :referencia_visual,
            :prompt_ia,
            :arquivo_url,
            :observacoes,
            :status
        )
        '
    );

    $stmt->execute([
        ':capitulo_id' =>
            $capituloId,

        ':versao' =>
            $novaVersao,

        ':conceito' =>
            $conceito !== ''
                ? $conceito
                : null,

        ':texto_thumb' =>
            $textoThumb !== ''
                ? $textoThumb
                : null,

        ':texto_secundario' =>
            $textoSecundario !== ''
                ? $textoSecundario
                : null,

        ':elementos' =>
            $elementos !== ''
                ? $elementos
                : null,

        ':referencia_visual' =>
            $referenciaVisual !== ''
                ? $referenciaVisual
                : null,

        ':prompt_ia' =>
            $promptIa !== ''
                ? $promptIa
                : null,

        ':arquivo_url' =>
            $arquivoUrl !== ''
                ? $arquivoUrl
                : null,

        ':observacoes' =>
            $observacoes !== ''
                ? $observacoes
                : null,

        ':status' =>
            $status,
    ]);

    /*
    |--------------------------------------------------------------------------
    | FASE DO CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        UPDATE capitulos
        SET fase_producao = "thumbnail"
        WHERE id = ?
        '
    );

    $stmt->execute([
        $capituloId
    ]);

    echo json_encode([
        'success' => true,
        'message' =>
            'Thumbnail V'
            . $novaVersao
            . ' salva com sucesso.',
        'versao' =>
            $novaVersao,
    ]);

} catch (Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}