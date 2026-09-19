<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$workerStatus = $pdo->query(
    '
    SELECT
        atividade_atual,
        TIMESTAMPDIFF(SECOND, ultimo_ping_at, NOW()) AS segundos_atras
    FROM worker_status
    WHERE id = 1
    '
)->fetch();

$segundosAtras = $workerStatus ? (int) $workerStatus['segundos_atras'] : null;

if ($segundosAtras === null) {
    $nivel = 'offline';
    $texto = 'Nunca visto';
} elseif ($segundosAtras < 90) {
    $nivel = 'online';
    $texto = 'Ativo agora';
} elseif ($segundosAtras < 1200) {
    $nivel = 'ocupado';
    $texto = 'Visto há ' . round($segundosAtras / 60) . ' min (pode estar numa tarefa longa)';
} else {
    $nivel = 'offline';
    $texto = 'Não detectado há ' . round($segundosAtras / 60) . ' min — provavelmente fechado';
}

$totais = $pdo->query(
    '
    SELECT
        SUM(ativo = 1) AS total_arquivos,
        SUM(ativo = 1 AND ia_transcricao IS NOT NULL AND ia_transcricao <> \'\') AS total_transcritos
    FROM capitulo_arquivos
    '
)->fetch();

$capitulosShorts = $pdo->query(
    '
    SELECT
        COUNT(*) AS elegiveis,
        SUM(
            (SELECT COUNT(DISTINCT s.tema) FROM capitulo_short_roteiros s
             WHERE s.capitulo_id = c.id
               AND s.tema IN (\'problema\',\'custo\',\'como_fizemos\',\'erro\',\'resultado\')) >= 5
        ) AS completos
    FROM capitulos c
    WHERE EXISTS (
        SELECT 1 FROM capitulo_arquivos a
        WHERE a.capitulo_id = c.id AND a.ativo = 1
          AND a.ia_transcricao IS NOT NULL AND a.ia_transcricao <> \'\'
    )
    '
)->fetch();

echo json_encode([
    'success' => true,
    'worker' => [
        'nivel' => $nivel,
        'texto' => $texto,
        'atividade' => $workerStatus['atividade_atual'] ?? null,
    ],
    'transcricao' => [
        'total' => (int) ($totais['total_arquivos'] ?? 0),
        'concluidos' => (int) ($totais['total_transcritos'] ?? 0),
    ],
    'shorts' => [
        'total' => (int) ($capitulosShorts['elegiveis'] ?? 0),
        'completos' => (int) ($capitulosShorts['completos'] ?? 0),
    ],
], JSON_UNESCAPED_UNICODE);
