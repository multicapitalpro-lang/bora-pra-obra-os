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

    $arquivoId = (int) ($_POST['arquivo_id'] ?? 0);

    if (!$arquivoId) {
        throw new RuntimeException('Arquivo inválido.');
    }

    $decisao = trim($_POST['decisao'] ?? 'pendente');
    $tipo = trim($_POST['tipo'] ?? 'nao_definido');
    $importancia = trim($_POST['importancia'] ?? 'normal');

    $possivelUso = trim($_POST['possivel_uso'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');

    $inicio = trim($_POST['inicio'] ?? '');
    $fim = trim($_POST['fim'] ?? '');

    $decisoesPermitidas = [
        'pendente',
        'usar',
        'parcial',
        'descartar'
    ];

    $tiposPermitidos = [
        'nao_definido',
        'fala',
        'execucao',
        'broll',
        'drone',
        'detalhe',
        'ambiente',
        'timelapse',
        'foto'
    ];

    $importanciasPermitidas = [
        'normal',
        'boa',
        'forte',
        'essencial'
    ];

    if (!in_array($decisao, $decisoesPermitidas, true)) {
        throw new RuntimeException('Decisão inválida.');
    }

    if (!in_array($tipo, $tiposPermitidos, true)) {
        throw new RuntimeException('Tipo inválido.');
    }

    if (!in_array($importancia, $importanciasPermitidas, true)) {
        throw new RuntimeException('Importância inválida.');
    }

    /*
    |--------------------------------------------------------------------------
    | Converter MM:SS / HH:MM:SS para milissegundos
    |--------------------------------------------------------------------------
    */

    $tempoParaMs = function (string $tempo): ?int {

        $tempo = trim($tempo);

        if ($tempo === '') {
            return null;
        }

        $partes = array_map(
            'intval',
            explode(':', $tempo)
        );

        if (count($partes) === 2) {

            [$minutos, $segundos] = $partes;

            return (($minutos * 60) + $segundos) * 1000;
        }

        if (count($partes) === 3) {

            [$horas, $minutos, $segundos] = $partes;

            return (
                ($horas * 3600)
                + ($minutos * 60)
                + $segundos
            ) * 1000;
        }

        throw new RuntimeException(
            'Use o formato MM:SS ou HH:MM:SS.'
        );
    };

    $inicioMs = $tempoParaMs($inicio);
    $fimMs = $tempoParaMs($fim);

    /*
    |--------------------------------------------------------------------------
    | Verificar arquivo
    |--------------------------------------------------------------------------
    */

    $stmtArquivo = $pdo->prepare(
        '
        SELECT id, capitulo_id, duracao_ms
        FROM capitulo_arquivos
        WHERE id = ?
          AND ativo = 1
        LIMIT 1
        '
    );

    $stmtArquivo->execute([$arquivoId]);

    $arquivo = $stmtArquivo->fetch();

    if (!$arquivo) {
        throw new RuntimeException('Arquivo não encontrado.');
    }

    /*
    |--------------------------------------------------------------------------
    | Validar cortes
    |--------------------------------------------------------------------------
    */

    $duracaoArquivo = (int) ($arquivo['duracao_ms'] ?? 0);

    if (
        $inicioMs !== null
        && $inicioMs < 0
    ) {
        throw new RuntimeException('Início inválido.');
    }

    if (
        $fimMs !== null
        && $fimMs < 0
    ) {
        throw new RuntimeException('Fim inválido.');
    }

    if (
        $inicioMs !== null
        && $fimMs !== null
        && $fimMs <= $inicioMs
    ) {
        throw new RuntimeException(
            'O fim precisa ser maior que o início.'
        );
    }

    if (
        $duracaoArquivo > 0
        && $fimMs !== null
        && $fimMs > $duracaoArquivo + 2000
    ) {
        throw new RuntimeException(
            'O fim informado ultrapassa a duração do arquivo.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Salvar
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        INSERT INTO capitulo_triagem
        (
            arquivo_id,
            decisao,
            tipo,
            inicio_ms,
            fim_ms,
            importancia,
            possivel_uso,
            observacoes
        )
        VALUES
        (
            ?,?,?,?,?,?,?,?
        )

        ON DUPLICATE KEY UPDATE

            decisao = VALUES(decisao),
            tipo = VALUES(tipo),
            inicio_ms = VALUES(inicio_ms),
            fim_ms = VALUES(fim_ms),
            importancia = VALUES(importancia),
            possivel_uso = VALUES(possivel_uso),
            observacoes = VALUES(observacoes)
        '
    );

    $stmt->execute([
        $arquivoId,
        $decisao,
        $tipo,
        $inicioMs,
        $fimMs,
        $importancia,
        $possivelUso ?: null,
        $observacoes ?: null
    ]);

    /*
    |--------------------------------------------------------------------------
    | Atualizar fase do capítulo
    |--------------------------------------------------------------------------
    */

    $capituloId = (int) $arquivo['capitulo_id'];

    $stmtResumo = $pdo->prepare(
        '
        SELECT
            COUNT(a.id) AS total,

            SUM(
                CASE
                    WHEN t.decisao <> "pendente"
                    THEN 1
                    ELSE 0
                END
            ) AS analisados

        FROM capitulo_arquivos a

        LEFT JOIN capitulo_triagem t
            ON t.arquivo_id = a.id

        WHERE a.capitulo_id = ?
          AND a.ativo = 1
        '
    );

    $stmtResumo->execute([$capituloId]);

    $resumo = $stmtResumo->fetch();

    $total = (int) ($resumo['total'] ?? 0);
    $analisados = (int) ($resumo['analisados'] ?? 0);

    if ($total > 0 && $analisados >= $total) {

        $novaFase = 'triado';

    } elseif ($analisados > 0) {

        $novaFase = 'triagem';

    } else {

        $novaFase = 'brutos';
    }

    $stmtFase = $pdo->prepare(
        '
        UPDATE capitulos
        SET
            fase_producao = ?,
            data_inicio_producao =
                COALESCE(data_inicio_producao, NOW())
        WHERE id = ?
        '
    );

    $stmtFase->execute([
        $novaFase,
        $capituloId
    ]);

    echo json_encode([
        'success' => true,
        'fase' => $novaFase,
        'total' => $total,
        'analisados' => $analisados
    ]);

} catch (Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}