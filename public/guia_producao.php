<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$pdo = db();

/*
|--------------------------------------------------------------------------
| CAPÍTULOS (com contagem de transcrição/triagem, prontos primeiro)
|--------------------------------------------------------------------------
*/

$capitulos = $pdo->query(
    '
    SELECT
        c.id, c.numero, c.titulo,
        COUNT(a.id) AS total_arquivos,
        SUM(a.ia_transcricao IS NOT NULL AND a.ia_transcricao <> \'\') AS total_transcritos,
        SUM(tr.decisao IS NOT NULL) AS total_triados,
        SUM(a.ia_status = \'nao_analisado\') AS total_pendente,
        SUM(a.ia_status IN (\'na_fila\', \'processando\')) AS total_em_fila,
        SUM(a.ia_status = \'erro\') AS total_erro
    FROM capitulos c
    LEFT JOIN capitulo_arquivos a ON a.capitulo_id = c.id AND a.ativo = 1
    LEFT JOIN capitulo_triagem tr ON tr.arquivo_id = a.id
    GROUP BY c.id
    ORDER BY total_transcritos DESC, c.numero ASC
    '
)->fetchAll();

/*
|--------------------------------------------------------------------------
| RESUMO GLOBAL (pro botão "processar tudo")
|--------------------------------------------------------------------------
*/

$totalCapitulosPendenteTranscricao = 0;
foreach ($capitulos as $c) {
    if ((int) $c['total_pendente'] > 0 || (int) $c['total_em_fila'] > 0) {
        $totalCapitulosPendenteTranscricao++;
    }
}

$totalCapitulosPendenteShorts = (int) $pdo->query(
    '
    SELECT COUNT(*) FROM capitulos c
    WHERE EXISTS (SELECT 1 FROM capitulo_arquivos a WHERE a.capitulo_id = c.id AND a.ativo = 1)
      AND NOT EXISTS (
            SELECT 1 FROM capitulo_arquivos a
            WHERE a.capitulo_id = c.id AND a.ativo = 1
              AND a.ia_status IN (\'nao_analisado\', \'na_fila\', \'processando\')
        )
      AND EXISTS (
            SELECT 1 FROM capitulo_arquivos a
            WHERE a.capitulo_id = c.id AND a.ativo = 1
              AND a.ia_transcricao IS NOT NULL AND a.ia_transcricao <> \'\'
        )
      AND (
            SELECT COUNT(DISTINCT s.tema) FROM capitulo_short_roteiros s
            WHERE s.capitulo_id = c.id
              AND s.tema IN (\'problema\', \'custo\', \'como_fizemos\', \'erro\', \'resultado\')
          ) < 5
    '
)->fetchColumn();

/*
|--------------------------------------------------------------------------
| STATUS DO WORKER (heartbeat) + PROGRESSO GLOBAL
|--------------------------------------------------------------------------
*/

$workerStatus = $pdo->query(
    '
    SELECT
        atividade_atual,
        ultimo_ping_at,
        TIMESTAMPDIFF(SECOND, ultimo_ping_at, NOW()) AS segundos_atras
    FROM worker_status
    WHERE id = 1
    '
)->fetch();

$totalArquivosGlobal = 0;
$totalTranscritosGlobal = 0;
foreach ($capitulos as $c) {
    $totalArquivosGlobal += (int) $c['total_arquivos'];
    $totalTranscritosGlobal += (int) $c['total_transcritos'];
}

$totalCapitulosElegiveisShorts = (int) $pdo->query(
    '
    SELECT COUNT(*) FROM capitulos c
    WHERE EXISTS (
            SELECT 1 FROM capitulo_arquivos a
            WHERE a.capitulo_id = c.id AND a.ativo = 1
              AND a.ia_transcricao IS NOT NULL AND a.ia_transcricao <> \'\'
        )
    '
)->fetchColumn();

$totalCapitulosShortsCompletos = (int) $pdo->query(
    '
    SELECT COUNT(*) FROM capitulos c
    WHERE (
            SELECT COUNT(DISTINCT s.tema) FROM capitulo_short_roteiros s
            WHERE s.capitulo_id = c.id
              AND s.tema IN (\'problema\', \'custo\', \'como_fizemos\', \'erro\', \'resultado\')
          ) >= 5
    '
)->fetchColumn();

$segundosAtras = $workerStatus ? (int) $workerStatus['segundos_atras'] : null;

if ($segundosAtras === null) {
    $workerNivel = 'offline';
    $workerTexto = 'Nunca visto';
} elseif ($segundosAtras < 90) {
    $workerNivel = 'online';
    $workerTexto = 'Ativo agora';
} elseif ($segundosAtras < 1200) {
    $workerNivel = 'ocupado';
    $workerTexto = 'Visto há ' . round($segundosAtras / 60) . ' min (pode estar numa tarefa longa)';
} else {
    $workerNivel = 'offline';
    $workerTexto = 'Não detectado há ' . round($segundosAtras / 60) . ' min — provavelmente fechado';
}

$capituloId = (int) ($_GET['capitulo_id'] ?? 0);

if ($capituloId <= 0 && $capitulos) {
    $capituloId = (int) $capitulos[0]['id'];
}

$capituloAtual = null;
foreach ($capitulos as $c) {
    if ((int) $c['id'] === $capituloId) {
        $capituloAtual = $c;
        break;
    }
}

if (!$capituloAtual) {
    http_response_code(404);
    exit('Nenhum capítulo encontrado.');
}

$totalArquivos = (int) $capituloAtual['total_arquivos'];
$totalTranscritos = (int) $capituloAtual['total_transcritos'];
$totalTriados = (int) $capituloAtual['total_triados'];
$totalPendente = (int) $capituloAtual['total_pendente'];
$totalEmFila = (int) $capituloAtual['total_em_fila'];
$totalErro = (int) $capituloAtual['total_erro'];

/*
|--------------------------------------------------------------------------
| ROTEIROS DE SHORTS DO CAPÍTULO
|--------------------------------------------------------------------------
*/

$stmtRoteiros = $pdo->prepare(
    '
    SELECT id, titulo, tema, duracao_alvo_segundos, narracao_status, roteiro_narracao,
           bloco_2_assunto, bloco_5_fechamento
    FROM capitulo_short_roteiros
    WHERE capitulo_id = ?
    ORDER BY id ASC
    '
);
$stmtRoteiros->execute([$capituloId]);
$roteiros = $stmtRoteiros->fetchAll();

/*
| Os 5 tipos fixos do template oficial (ver OpenAIService::
| gerarRoteirosShorts). tema guarda a chave de cada um; roteiros
| gerados antes dessa mudança podem ter texto livre em tema e
| simplesmente não contam pra essa checagem.
*/
$tiposCanonicosChaves = ['problema', 'custo', 'como_fizemos', 'erro', 'resultado'];
$tiposUsados = array_intersect(
    $tiposCanonicosChaves,
    array_map(fn($r) => trim((string) ($r['tema'] ?? '')), $roteiros)
);
$todosTiposUsados = count($tiposUsados) >= count($tiposCanonicosChaves);

$shortId = (int) ($_GET['short_id'] ?? 0);

$shortAtual = null;
foreach ($roteiros as $r) {
    if ((int) $r['id'] === $shortId) {
        $shortAtual = $r;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| TEXTO COMPLETO PRA NARRAR
|--------------------------------------------------------------------------
|
| roteiro_narracao guarda só o trecho do meio (bloco 4 - execução).
| O que a pessoa realmente fala no vídeo é abertura (bloco 2) + meio
| (roteiro_narracao) + fechamento (bloco 5) -- vinheta e CTA não
| entram na narração (são inseridos depois) e os blocos de custo são
| só um placeholder até o valor real existir no app.
|--------------------------------------------------------------------------
*/

$textoParaNarrar = '';

if ($shortAtual) {
    $partes = array_filter([
        trim((string) ($shortAtual['bloco_2_assunto'] ?? '')),
        trim((string) ($shortAtual['roteiro_narracao'] ?? '')),
        trim((string) ($shortAtual['bloco_5_fechamento'] ?? '')),
    ], fn($p) => $p !== '');

    $textoParaNarrar = implode("\n\n", $partes);
}

/*
|--------------------------------------------------------------------------
| DADOS DO SHORT SELECIONADO (narração + cortes)
|--------------------------------------------------------------------------
*/

$segmentos = [];
$cortes = [];
$brutosDisponiveis = [];

if ($shortAtual) {

    $segmentos = json_decode((string) ($shortAtual['narracao_transcricao'] ?? ''), true) ?: [];

    $stmtCortes = $pdo->prepare(
        '
        SELECT c.*, a.nome_arquivo
        FROM capitulo_short_cortes_sugeridos c
        LEFT JOIN capitulo_arquivos a ON a.id = c.arquivo_id
        WHERE c.short_roteiro_id = ?
        ORDER BY c.ordem ASC
        '
    );
    $stmtCortes->execute([$shortId]);
    $cortes = $stmtCortes->fetchAll();

    $stmtBrutos = $pdo->prepare(
        '
        SELECT a.id, a.nome_arquivo
        FROM capitulo_arquivos a
        LEFT JOIN capitulo_triagem tr ON tr.arquivo_id = a.id
        WHERE a.capitulo_id = ? AND a.ativo = 1
          AND a.ia_transcricao IS NOT NULL AND a.ia_transcricao <> \'\'
          AND (tr.decisao IS NULL OR tr.decisao IN (\'usar\',\'parcial\'))
        ORDER BY a.ordem ASC
        '
    );
    $stmtBrutos->execute([$capituloId]);
    $brutosDisponiveis = $stmtBrutos->fetchAll();
}

/*
|--------------------------------------------------------------------------
| STATUS DE CADA PASSO
|--------------------------------------------------------------------------
*/

$passo1Ok = $totalArquivos > 0 && $totalTranscritos >= $totalArquivos;
$passo1Parcial = $totalTranscritos > 0 && !$passo1Ok;

$passo2Ok = $totalArquivos > 0 && $totalTriados >= $totalArquivos;

$passo3Ok = count($roteiros) > 0;

$passo4Ok = $shortAtual && !empty($segmentos);

$totalCortesAprovados = 0;
foreach ($cortes as $c) {
    if ((int) $c['aprovado'] === 1) {
        $totalCortesAprovados++;
    }
}
$passo5Ok = count($cortes) > 0;
$passo6Ok = $passo5Ok && $totalCortesAprovados === count($cortes);

$pageTitle = 'Guia de Produção';
require __DIR__ . '/includes/header.php';
?>

<style>
    .passo { border: 1px solid var(--bs-border-color); border-radius: 10px; padding: 18px 20px; margin-bottom: 16px; }
    .passo.bloqueado { opacity: .5; pointer-events: none; }
    .passo-cabecalho { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
    .passo-numero { width: 30px; height: 30px; border-radius: 50%; background: #e9ecef; color: #495057; display: flex; align-items: center; justify-content: center; font-weight: 600; flex: none; }
    .passo-numero.ok { background: #198754; color: #fff; }
    .passo-numero.atual { background: #111315; color: #fff; }
    .passo-titulo { font-weight: 600; }
    .roteiro-mini { border: 1px solid var(--bs-border-color); border-radius: 8px; padding: 10px 12px; }
    .roteiro-mini.selecionado { border-color: #111315; background: #f8f9fa; }
    .btn-excluir-roteiro {
        position: absolute; top: 6px; right: 6px; z-index: 2;
        border: none; background: transparent; color: #adb5bd;
        padding: 2px 6px; border-radius: 4px; line-height: 1;
    }
    .btn-excluir-roteiro:hover { color: #dc3545; background: #f8d7da; }
    .status-pill { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 13px; }
    .status-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
    .status-dot.online { background: #198754; box-shadow: 0 0 0 3px rgba(25,135,84,.2); }
    .status-dot.ocupado { background: #ffc107; box-shadow: 0 0 0 3px rgba(255,193,7,.25); }
    .status-dot.offline { background: #adb5bd; }
    .progresso-mini { height: 8px; border-radius: 5px; background: #e9ecef; overflow: hidden; }
    .progresso-mini > div { height: 100%; background: #111315; }
</style>

<div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">Guia de Produção — passo a passo</h2>
        <div class="small text-secondary">Do capítulo até o corte pronto pra editar.</div>
    </div>

    <form method="get" class="d-flex align-items-center gap-2">
        <label class="small text-secondary mb-0">Capítulo</label>
        <select name="capitulo_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <?php foreach ($capitulos as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $capituloId ? 'selected' : '' ?>>
                    #<?= (int) $c['numero'] ?> — <?= htmlspecialchars($c['titulo']) ?>
                    (<?= (int) $c['total_transcritos'] ?>/<?= (int) $c['total_arquivos'] ?> transcritos)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<!-- STATUS DO WORKER + PROGRESSO GLOBAL -->
<div class="passo mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div class="status-pill">
            <span class="status-dot <?= $workerNivel ?>" id="workerDot"></span>
            <span id="workerTexto">Worker: <?= htmlspecialchars($workerTexto) ?></span>
        </div>
        <div class="small text-secondary" id="workerAtividade">
            <?= $workerStatus && $workerStatus['atividade_atual'] ? htmlspecialchars($workerStatus['atividade_atual']) : '—' ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="d-flex justify-content-between small mb-1">
                <span>Transcrição dos brutos</span>
                <span id="progTranscricaoTexto"><?= $totalTranscritosGlobal ?> / <?= $totalArquivosGlobal ?></span>
            </div>
            <div class="progresso-mini">
                <div id="progTranscricaoBarra" style="width:<?= $totalArquivosGlobal > 0 ? round($totalTranscritosGlobal / $totalArquivosGlobal * 100) : 0 ?>%"></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="d-flex justify-content-between small mb-1">
                <span>Capítulos com os 5 Shorts completos</span>
                <span id="progShortsTexto"><?= $totalCapitulosShortsCompletos ?> / <?= $totalCapitulosElegiveisShorts ?></span>
            </div>
            <div class="progresso-mini">
                <div id="progShortsBarra" style="width:<?= $totalCapitulosElegiveisShorts > 0 ? round($totalCapitulosShortsCompletos / $totalCapitulosElegiveisShorts * 100) : 0 ?>%"></div>
            </div>
        </div>
    </div>
</div>

<!-- PROCESSAR TUDO AUTOMATICAMENTE -->
<div class="passo mb-4" style="border-color:#111315">
    <div class="passo-cabecalho">
        <div class="passo-numero atual"><i class="bi bi-lightning-charge-fill"></i></div>
        <div class="passo-titulo">Processar tudo automaticamente (todos os capítulos)</div>
    </div>

    <p class="small text-secondary mb-2">
        Um clique só, sem escolher capítulo. Gera os Shorts que faltam em qualquer capítulo já
        transcrito, capítulo por capítulo, até não sobrar nenhum pendente.
    </p>

    <?php if ($totalCapitulosPendenteTranscricao > 0): ?>
        <div class="alert alert-warning small mb-2">
            <i class="bi bi-exclamation-triangle"></i>
            <?= $totalCapitulosPendenteTranscricao ?> capítulo(s) ainda têm brutos não transcritos.
            Isso só o <strong>worker</strong> no seu PC resolve (baixa e transcreve os vídeos) —
            abra <code>C:\bora-pra-obra-worker\INICIAR BORA PRA OBRA IA.bat</code> e deixe rodando;
            ele mesmo enfileira e processa qualquer capítulo pendente, sem precisar clicar em nada
            aqui no painel.
        </div>
    <?php endif; ?>

    <button class="btn btn-dark" id="btnProcessarTudo" <?= $totalCapitulosPendenteShorts === 0 ? 'disabled' : '' ?>>
        <i class="bi bi-stars"></i>
        <?= $totalCapitulosPendenteShorts > 0
            ? "Gerar Shorts pendentes em {$totalCapitulosPendenteShorts} capítulo(s)"
            : 'Nenhum Short pendente agora' ?>
    </button>
    <div id="resultadoProcessarTudo" class="small mt-2"></div>
</div>

<!-- PASSO 1 — TRANSCRIÇÃO -->
<div class="passo">
    <div class="passo-cabecalho">
        <div class="passo-numero <?= $passo1Ok ? 'ok' : 'atual' ?>">
            <?= $passo1Ok ? '<i class="bi bi-check-lg"></i>' : '1' ?>
        </div>
        <div class="passo-titulo">Transcrição dos brutos</div>
    </div>

    <?php if ($totalArquivos === 0): ?>
        <div class="alert alert-secondary small mb-0">Este capítulo ainda não tem arquivos sincronizados do Drive.</div>
    <?php else: ?>

        <?php if ($totalTranscritos === 0): ?>
            <div class="alert alert-warning small mb-2">Nenhum bruto transcrito ainda.</div>
        <?php elseif ($passo1Parcial): ?>
            <div class="alert alert-info small mb-2">
                <?= $totalTranscritos ?> de <?= $totalArquivos ?> brutos já transcritos — dá pra continuar,
                o resto completa em segundo plano.
            </div>
        <?php else: ?>
            <div class="alert alert-success small mb-2">Todos os <?= $totalArquivos ?> brutos já estão transcritos.</div>
        <?php endif; ?>

        <?php if ($totalPendente > 0): ?>
            <button class="btn btn-sm btn-outline-dark mb-2" id="btnEnfileirar" data-capitulo-id="<?= $capituloId ?>">
                <i class="bi bi-cloud-arrow-up"></i> Enviar <?= $totalPendente ?> bruto(s) pra fila de transcrição
            </button>
            <div id="resultadoEnfileirar" class="small mb-2"></div>
        <?php endif; ?>

        <?php if ($totalEmFila > 0 || $totalPendente > 0): ?>
            <div class="small text-secondary mb-0">
                <?php if ($totalEmFila > 0): ?>
                    <?= $totalEmFila ?> na fila aguardando o worker processar.
                <?php endif; ?>
                Abra o worker no seu PC
                (<code>C:\bora-pra-obra-worker\INICIAR BORA PRA OBRA IA.bat</code>) e deixe rodando —
                ele processa a fila sozinho, de todos os capítulos.
            </div>
        <?php endif; ?>

        <?php if ($totalErro > 0): ?>
            <div class="small text-danger mt-1"><?= $totalErro ?> bruto(s) com erro no processamento anterior.</div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- PASSO 2 — TRIAGEM -->
<div class="passo <?= $totalTranscritos === 0 ? 'bloqueado' : '' ?>">
    <div class="passo-cabecalho">
        <div class="passo-numero <?= $passo2Ok ? 'ok' : 'atual' ?>">
            <?= $passo2Ok ? '<i class="bi bi-check-lg"></i>' : '2' ?>
        </div>
        <div class="passo-titulo">Triagem dos brutos <span class="text-secondary fw-normal small">(recomendado, não obrigatório)</span></div>
    </div>
    <p class="small text-secondary mb-2"><?= $totalTriados ?> de <?= $totalArquivos ?> já classificados (usar / parcial / descartar).</p>
    <a href="capitulo_detalhes.php?id=<?= $capituloId ?>" class="btn btn-sm btn-outline-dark">Abrir triagem deste capítulo</a>
</div>

<!-- PASSO 3 — GERAR ROTEIROS -->
<div class="passo <?= $totalTranscritos === 0 ? 'bloqueado' : '' ?>" id="passo3">
    <div class="passo-cabecalho">
        <div class="passo-numero <?= $passo3Ok ? 'ok' : 'atual' ?>">
            <?= $passo3Ok ? '<i class="bi bi-check-lg"></i>' : '3' ?>
        </div>
        <div class="passo-titulo">Gerar roteiros de Shorts</div>
    </div>

    <?php if ($totalTranscritos === 0): ?>
        <div class="alert alert-secondary small mb-3">
            <i class="bi bi-lock"></i>
            Bloqueado: este capítulo ainda não tem nenhum bruto transcrito (passo 1 acima).
            A IA precisa da transcrição pra saber o que está sendo mostrado em cada vídeo.
        </div>
    <?php endif; ?>

    <button class="btn btn-dark btn-sm mb-3" id="btnGerarRoteiros" data-capitulo-id="<?= $capituloId ?>" <?= $todosTiposUsados ? 'disabled' : '' ?>>
        <i class="bi bi-stars"></i> <?= $roteiros ? 'Gerar mais um roteiro' : 'Gerar primeiro roteiro' ?> com IA
    </button>
    <?php if (!$todosTiposUsados && count($tiposCanonicosChaves) - count($tiposUsados) > 1): ?>
        <button class="btn btn-outline-dark btn-sm mb-3 ms-1" id="btnGerarTodos" data-capitulo-id="<?= $capituloId ?>">
            <i class="bi bi-stars"></i> Gerar os <?= count($tiposCanonicosChaves) - count($tiposUsados) ?> restantes de uma vez
        </button>
    <?php endif; ?>
    <?php if ($todosTiposUsados): ?>
        <div class="small text-secondary mb-2">Os 5 tipos de Short do template já foram gerados neste capítulo.</div>
    <?php else: ?>
        <div class="small text-secondary mb-2">
            Tipos já gerados: <?= $tiposUsados ? htmlspecialchars(implode(', ', $tiposUsados)) : 'nenhum ainda' ?>
            (faltam <?= count($tiposCanonicosChaves) - count($tiposUsados) ?> de 5)
        </div>
    <?php endif; ?>
    <div id="resultadoGerarRoteiros" class="small mb-2"></div>

    <?php if ($roteiros): ?>
        <div class="small text-secondary mb-2">Escolha um roteiro pra continuar:</div>
        <div class="row g-2">
            <?php foreach ($roteiros as $r): ?>
                <div class="col-md-6">
                    <div class="roteiro-mini position-relative <?= (int) $r['id'] === $shortId ? 'selecionado' : '' ?>">
                        <button type="button" class="btn-excluir-roteiro" title="Excluir roteiro" data-short-id="<?= (int) $r['id'] ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                        <a class="text-decoration-none text-reset d-block" href="?capitulo_id=<?= $capituloId ?>&short_id=<?= (int) $r['id'] ?>#passo4">
                            <div class="d-flex justify-content-between pe-4">
                                <strong class="small"><?= htmlspecialchars($r['titulo']) ?></strong>
                                <span class="badge text-bg-light border"><?= (int) $r['duracao_alvo_segundos'] ?>s</span>
                            </div>
                            <div class="small text-secondary mt-1">
                                narração: <?= htmlspecialchars($r['narracao_status'] ?: 'sem_narracao') ?>
                            </div>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- PASSO 4 — NARRAÇÃO -->
<div class="passo <?= !$shortAtual ? 'bloqueado' : '' ?>" id="passo4">
    <div class="passo-cabecalho">
        <div class="passo-numero <?= $passo4Ok ? 'ok' : 'atual' ?>">
            <?= $passo4Ok ? '<i class="bi bi-check-lg"></i>' : '4' ?>
        </div>
        <div class="passo-titulo">Sua narração</div>
    </div>

    <?php if ($shortAtual): ?>
        <div class="border rounded p-2 small mb-3 bg-body-tertiary">
            <div class="text-secondary mb-1">
                Roteiro pra ler (abertura + meio + fechamento —
                alvo de <?= (int) ($shortAtual['duracao_alvo_segundos'] ?? 0) ?>s):
            </div>
            <?= nl2br(htmlspecialchars($textoParaNarrar)) ?>
        </div>

        <div class="input-group mb-2" style="max-width:520px">
            <input type="file" id="inputAudio" class="form-control" accept="audio/*">
            <button class="btn btn-dark" id="btnUpload">Enviar e transcrever</button>
        </div>
        <div id="resultadoUpload" class="small"></div>

        <?php if ($segmentos): ?>
            <div class="mt-2 small">
                <?php foreach ($segmentos as $s): ?>
                    <div><span class="text-secondary">[<?= (int) round(($s['inicio_ms'] ?? 0) / 1000) ?>s]</span> <?= htmlspecialchars($s['texto'] ?? '') ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="small text-secondary mb-0">Escolha um roteiro no passo 3 primeiro.</p>
    <?php endif; ?>
</div>

<!-- PASSO 5 — SUGERIR CORTES -->
<div class="passo <?= !$passo4Ok ? 'bloqueado' : '' ?>" id="passo5">
    <div class="passo-cabecalho">
        <div class="passo-numero <?= $passo5Ok ? 'ok' : 'atual' ?>">
            <?= $passo5Ok ? '<i class="bi bi-check-lg"></i>' : '5' ?>
        </div>
        <div class="passo-titulo">Sugestão de corte</div>
    </div>

    <?php if (!$passo4Ok): ?>
        <div class="alert alert-secondary small mb-2">
            <i class="bi bi-lock"></i> Bloqueado: envie sua narração no passo 4 primeiro.
        </div>
    <?php endif; ?>

    <button class="btn btn-outline-dark btn-sm mb-2" id="btnSugerir">
        <i class="bi bi-magic"></i> Sugerir cortes com IA
    </button>
    <div id="resultadoSugerir" class="small mb-2"></div>

    <div id="listaCortes">
        <?php if ($cortes): ?>
            <div class="table-responsive">
                <table class="table align-middle small">
                    <thead><tr><th>#</th><th>Trecho da narração</th><th>Bruto sugerido</th><th>Por quê</th><th>Aprovado</th></tr></thead>
                    <tbody>
                        <?php foreach ($cortes as $c): ?>
                            <tr data-corte-id="<?= (int) $c['id'] ?>">
                                <td><?= (int) $c['ordem'] + 1 ?></td>
                                <td style="max-width:220px"><?= htmlspecialchars($c['narracao_texto']) ?></td>
                                <td>
                                    <select class="form-select form-select-sm selectArquivo">
                                        <option value="">— nenhum —</option>
                                        <?php foreach ($brutosDisponiveis as $b): ?>
                                            <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $c['arquivo_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['nome_arquivo']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="max-width:220px" class="text-secondary"><?= htmlspecialchars($c['justificativa']) ?></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input chkAprovado" <?= $c['aprovado'] ? 'checked' : '' ?>></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- PASSO 6 — PRONTO -->
<div class="passo <?= !$passo5Ok ? 'bloqueado' : '' ?>">
    <div class="passo-cabecalho">
        <div class="passo-numero <?= $passo6Ok ? 'ok' : 'atual' ?>">
            <?= $passo6Ok ? '<i class="bi bi-check-lg"></i>' : '6' ?>
        </div>
        <div class="passo-titulo">Editar no CapCut</div>
    </div>
    <p class="small text-secondary mb-0">
        <?= $totalCortesAprovados ?> de <?= count($cortes) ?> cortes aprovados.
        Abra o CapCut, importe os brutos indicados na tabela do passo 5 (na ordem da tabela) e monte por cima
        do áudio da sua narração.
    </p>
</div>

<script>
async function atualizarStatusWorker() {
    try {
        const resp = await fetch('worker_status_resumo.php');
        const json = await resp.json();
        if (!json.success) return;

        const dot = document.getElementById('workerDot');
        const texto = document.getElementById('workerTexto');
        const atividade = document.getElementById('workerAtividade');

        dot.className = 'status-dot ' + json.worker.nivel;
        texto.textContent = 'Worker: ' + json.worker.texto;
        atividade.textContent = json.worker.atividade || '—';

        const pctTranscricao = json.transcricao.total > 0
            ? Math.round(json.transcricao.concluidos / json.transcricao.total * 100) : 0;
        document.getElementById('progTranscricaoTexto').textContent =
            json.transcricao.concluidos + ' / ' + json.transcricao.total;
        document.getElementById('progTranscricaoBarra').style.width = pctTranscricao + '%';

        const pctShorts = json.shorts.total > 0
            ? Math.round(json.shorts.completos / json.shorts.total * 100) : 0;
        document.getElementById('progShortsTexto').textContent =
            json.shorts.completos + ' / ' + json.shorts.total;
        document.getElementById('progShortsBarra').style.width = pctShorts + '%';

    } catch (e) {
        // silencioso -- so um refresh de status, nao deve incomodar
    }
}

setInterval(atualizarStatusWorker, 15000);

const btnProcessarTudo = document.getElementById('btnProcessarTudo');
if (btnProcessarTudo) {
    btnProcessarTudo.addEventListener('click', async function () {
        const out = document.getElementById('resultadoProcessarTudo');
        this.disabled = true;
        let gerados = 0;

        try {
            while (true) {
                out.innerHTML = '<span class="text-secondary">Procurando o próximo capítulo pendente de Shorts'
                    + (gerados > 0 ? ' (' + gerados + ' roteiro(s) gerado(s) até agora)' : '') + '...</span>';

                const respTarefa = await fetch('capitulo_roteiros_shorts_proxima_tarefa.php', { method: 'POST' });
                const jsonTarefa = await respTarefa.json();
                if (!respTarefa.ok || !jsonTarefa.success) throw new Error(jsonTarefa.message || 'Falha ao buscar capítulo.');

                if (!jsonTarefa.task) {
                    out.innerHTML = '<span class="text-success">Pronto! ' + gerados
                        + ' roteiro(s) gerado(s). Nenhum capítulo com Short pendente agora.</span>';
                    break;
                }

                const tarefa = jsonTarefa.task;
                out.innerHTML = '<span class="text-secondary">Gerando Short pro capítulo #'
                    + tarefa.capitulo_numero + ' — ' + tarefa.capitulo_titulo + ' (~40-50s)...</span>';

                const dados = new FormData();
                dados.append('capitulo_id', tarefa.capitulo_id);
                const respGerar = await fetch('capitulo_roteiros_shorts_ia.php', { method: 'POST', body: dados });
                const jsonGerar = await respGerar.json();
                if (!respGerar.ok || !jsonGerar.success) throw new Error(jsonGerar.message || 'Falha ao gerar roteiro.');

                gerados++;
            }
        } catch (e) {
            out.innerHTML = '<span class="text-danger">Parou depois de ' + gerados + ' roteiro(s): ' + e.message + '</span>';
        } finally {
            this.disabled = false;
        }
    });
}

const capituloId = <?= $capituloId ?>;
const shortId = <?= $shortId ?: 'null' ?>;

document.querySelectorAll('.btn-excluir-roteiro').forEach(function (botao) {
    botao.addEventListener('click', async function (evento) {
        evento.preventDefault();
        evento.stopPropagation();

        if (!confirm('Excluir este roteiro de Short? Isso apaga também a narração e as sugestões de corte dele, se houver.')) {
            return;
        }

        const idParaExcluir = this.dataset.shortId;
        this.disabled = true;

        const dados = new FormData();
        dados.append('short_roteiro_id', idParaExcluir);

        try {
            const resp = await fetch('short_roteiro_excluir.php', { method: 'POST', body: dados });
            const json = await resp.json();
            if (!resp.ok || !json.success) throw new Error(json.message || 'Falha ao excluir.');

            if (String(shortId) === String(idParaExcluir)) {
                window.location.href = '?capitulo_id=' + capituloId;
            } else {
                window.location.reload();
            }
        } catch (e) {
            alert('Não foi possível excluir: ' + e.message);
            this.disabled = false;
        }
    });
});

const btnEnfileirar = document.getElementById('btnEnfileirar');
if (btnEnfileirar) {
    btnEnfileirar.addEventListener('click', async function () {
        const out = document.getElementById('resultadoEnfileirar');
        this.disabled = true;
        out.innerHTML = '<span class="text-secondary">Colocando na fila...</span>';
        const dados = new FormData();
        dados.append('capitulo_id', capituloId);
        try {
            const resp = await fetch('triagem_ia_solicitar.php', { method: 'POST', body: dados });
            const json = await resp.json();
            if (!resp.ok || !json.success) throw new Error(json.message || 'Falha ao enfileirar.');
            out.innerHTML = '<span class="text-success">' + json.message + ' Deixe o worker rodando pra processar.</span>';
            setTimeout(() => window.location.reload(), 1200);
        } catch (e) {
            out.innerHTML = '<span class="text-danger">' + e.message + '</span>';
            this.disabled = false;
        }
    });
}

async function gerarUmRoteiro() {
    const dados = new FormData();
    dados.append('capitulo_id', capituloId);
    const resp = await fetch('capitulo_roteiros_shorts_ia.php', { method: 'POST', body: dados });
    const json = await resp.json();
    if (!resp.ok || !json.success) throw new Error(json.message || 'Falha ao gerar.');
    return json;
}

const btnGerar = document.getElementById('btnGerarRoteiros');
if (btnGerar) {
    btnGerar.addEventListener('click', async function () {
        const out = document.getElementById('resultadoGerarRoteiros');
        this.disabled = true;
        out.innerHTML = '<span class="text-secondary">Analisando brutos e gerando roteiro...</span>';
        try {
            const json = await gerarUmRoteiro();
            out.innerHTML = '<span class="text-success">' + json.message + '</span>';
            setTimeout(() => window.location.reload(), 800);
        } catch (e) {
            out.innerHTML = '<span class="text-danger">' + e.message + '</span>';
            this.disabled = false;
        }
    });
}

const btnGerarTodos = document.getElementById('btnGerarTodos');
if (btnGerarTodos) {
    btnGerarTodos.addEventListener('click', async function () {
        const out = document.getElementById('resultadoGerarRoteiros');
        const total = <?= count($tiposCanonicosChaves) - count($tiposUsados) ?>;
        this.disabled = true;
        if (btnGerar) btnGerar.disabled = true;

        let feitos = 0;
        try {
            for (feitos = 0; feitos < total; feitos++) {
                out.innerHTML = '<span class="text-secondary">Gerando roteiro '
                    + (feitos + 1) + ' de ' + total + '... (~40-50s cada, não feche a página)</span>';
                await gerarUmRoteiro();
            }
            out.innerHTML = '<span class="text-success">' + total + ' roteiro(s) gerado(s) com sucesso.</span>';
            setTimeout(() => window.location.reload(), 800);
        } catch (e) {
            out.innerHTML = '<span class="text-danger">Gerou ' + feitos + ' de ' + total
                + ' antes de falhar: ' + e.message + ' (os que já foram gerados foram salvos)</span>';
            this.disabled = false;
            if (btnGerar) btnGerar.disabled = false;
        }
    });
}

const btnUpload = document.getElementById('btnUpload');
if (btnUpload) {
    btnUpload.addEventListener('click', async function () {
        const input = document.getElementById('inputAudio');
        const out = document.getElementById('resultadoUpload');
        if (!input.files.length) { out.innerHTML = '<span class="text-danger">Selecione um áudio.</span>'; return; }
        this.disabled = true;
        out.innerHTML = '<span class="text-secondary">Enviando e transcrevendo...</span>';
        const dados = new FormData();
        dados.append('short_roteiro_id', shortId);
        dados.append('audio', input.files[0]);
        try {
            const resp = await fetch('short_narracao_upload.php', { method: 'POST', body: dados });
            const json = await resp.json();
            if (!resp.ok || !json.success) throw new Error(json.message || 'Falha.');
            out.innerHTML = '<span class="text-success">' + json.message + '</span>';
            setTimeout(() => window.location.reload(), 800);
        } catch (e) {
            out.innerHTML = '<span class="text-danger">' + e.message + '</span>';
            this.disabled = false;
        }
    });
}

const btnSugerir = document.getElementById('btnSugerir');
if (btnSugerir) {
    btnSugerir.addEventListener('click', async function () {
        const out = document.getElementById('resultadoSugerir');
        this.disabled = true;
        out.innerHTML = '<span class="text-secondary">Comparando sua narração com os brutos...</span>';
        const dados = new FormData();
        dados.append('short_roteiro_id', shortId);
        try {
            const resp = await fetch('short_corte_sugerir_ia.php', { method: 'POST', body: dados });
            const json = await resp.json();
            if (!resp.ok || !json.success) throw new Error(json.message || 'Falha.');
            out.innerHTML = '<span class="text-success">' + json.message + '</span>';
            setTimeout(() => window.location.reload(), 800);
        } catch (e) {
            out.innerHTML = '<span class="text-danger">' + e.message + '</span>';
            this.disabled = false;
        }
    });
}

document.querySelectorAll('#listaCortes tr[data-corte-id]').forEach(function (linha) {
    const corteId = linha.dataset.corteId;
    linha.querySelector('.chkAprovado').addEventListener('change', function () {
        salvarCorte(corteId, { aprovado: this.checked ? 1 : 0 });
    });
    linha.querySelector('.selectArquivo').addEventListener('change', function () {
        salvarCorte(corteId, { arquivo_id: this.value });
    });
});

async function salvarCorte(corteId, campos) {
    const dados = new FormData();
    dados.append('corte_id', corteId);
    Object.entries(campos).forEach(([k, v]) => dados.append(k, v));
    try {
        const resp = await fetch('short_corte_aprovar.php', { method: 'POST', body: dados });
        const json = await resp.json();
        if (!json.success) throw new Error(json.message);
    } catch (e) {
        alert('Não foi possível salvar: ' + e.message);
    }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
