<?php

declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$pdo = db();

$shortId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    '
    SELECT s.*, c.titulo AS capitulo_titulo, c.id AS capitulo_id
    FROM capitulo_short_roteiros s
    JOIN capitulos c ON c.id = s.capitulo_id
    WHERE s.id = ?
    LIMIT 1
    '
);

$stmt->execute([$shortId]);

$short = $stmt->fetch();

if (!$short) {

    http_response_code(404);
    exit('Short não encontrado.');
}

$segmentos = json_decode((string) ($short['narracao_transcricao'] ?? ''), true) ?: [];

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
$stmtBrutos->execute([$short['capitulo_id']]);
$brutosDisponiveis = $stmtBrutos->fetchAll();

$pageTitle = 'Narração — ' . $short['titulo'];
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <div class="small text-secondary">
            <a href="capitulo_detalhes.php?id=<?= (int) $short['capitulo_id'] ?>">
                &larr; <?= htmlspecialchars($short['capitulo_titulo']) ?>
            </a>
        </div>
        <h2 class="h5 mb-0"><?= htmlspecialchars($short['titulo']) ?></h2>
    </div>
    <span class="badge text-bg-light border" id="badge-status">
        <?= htmlspecialchars($short['narracao_status']) ?>
    </span>
</div>

<div class="panel-card mb-4">
    <h3 class="h6">1. Narração própria</h3>
    <p class="text-secondary small">
        Grave (Audacity ou similar) você lendo o roteiro deste Short e envie o áudio aqui.
        Formatos aceitos: mp3, wav, m4a, ogg, webm — até 25 MB.
    </p>

    <div class="input-group mb-2" style="max-width:520px">
        <input type="file" id="input-audio" class="form-control" accept="audio/*">
        <button class="btn btn-dark" id="btn-upload">Enviar e transcrever</button>
    </div>
    <div id="upload-resultado" class="small"></div>

    <?php if ($segmentos): ?>
        <div class="mt-3">
            <div class="small text-secondary mb-1">
                Transcrição (<?= count($segmentos) ?> trecho(s)):
            </div>
            <div class="border rounded p-2 small" style="max-height:160px;overflow:auto">
                <?php foreach ($segmentos as $s): ?>
                    <div class="mb-1">
                        <span class="text-secondary">
                            [<?= (int) round(($s['inicio_ms'] ?? 0) / 1000) ?>s]
                        </span>
                        <?= htmlspecialchars($s['texto'] ?? '') ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="panel-card mb-4">
    <h3 class="h6">2. Sugestão de corte</h3>
    <p class="text-secondary small">
        A IA aponta, pra cada trecho da narração, qual bruto do capítulo combina melhor —
        com base nas transcrições dos brutos já feitas. Ela sugere o arquivo certo,
        não o segundo exato: ajuste isso na hora de editar.
    </p>
    <button class="btn btn-outline-dark" id="btn-sugerir" <?= $segmentos ? '' : 'disabled' ?>>
        <i class="bi bi-magic"></i> Sugerir cortes com IA
    </button>
    <div id="sugerir-resultado" class="small mt-2"></div>
</div>

<div class="panel-card">
    <h3 class="h6">3. Lista de cortes (revise antes de exportar)</h3>
    <div id="lista-cortes">
        <?php if (!$cortes): ?>
            <p class="text-secondary small mb-0">Nenhuma sugestão gerada ainda.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle small">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Trecho da narração</th>
                            <th>Bruto sugerido</th>
                            <th>Justificativa</th>
                            <th>Aprovado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cortes as $c): ?>
                            <tr data-corte-id="<?= (int) $c['id'] ?>">
                                <td><?= (int) $c['ordem'] + 1 ?></td>
                                <td style="max-width:260px"><?= htmlspecialchars($c['narracao_texto']) ?></td>
                                <td>
                                    <select class="form-select form-select-sm select-arquivo">
                                        <option value="">— nenhum —</option>
                                        <?php foreach ($brutosDisponiveis as $b): ?>
                                            <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $c['arquivo_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['nome_arquivo']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="max-width:260px" class="text-secondary"><?= htmlspecialchars($c['justificativa']) ?></td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input chk-aprovado" <?= $c['aprovado'] ? 'checked' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('btn-upload').addEventListener('click', async function () {
    const input = document.getElementById('input-audio');
    const resultado = document.getElementById('upload-resultado');

    if (!input.files.length) {
        resultado.innerHTML = '<span class="text-danger">Selecione um arquivo de áudio.</span>';
        return;
    }

    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Transcrevendo...';
    resultado.innerHTML = '<span class="text-secondary">Enviando e transcrevendo (pode levar até 1 minuto)...</span>';

    const dados = new FormData();
    dados.append('short_roteiro_id', <?= (int) $shortId ?>);
    dados.append('audio', input.files[0]);

    try {
        const resposta = await fetch('short_narracao_upload.php', { method: 'POST', body: dados });
        const json = await resposta.json();

        if (!resposta.ok || !json.success) {
            throw new Error(json.message || 'Falha ao transcrever.');
        }

        resultado.innerHTML = '<span class="text-success">' + json.message + '</span>';
        setTimeout(() => window.location.reload(), 800);

    } catch (erro) {
        resultado.innerHTML = '<span class="text-danger">' + erro.message + '</span>';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Enviar e transcrever';
    }
});

document.getElementById('btn-sugerir').addEventListener('click', async function () {
    const btn = this;
    const resultado = document.getElementById('sugerir-resultado');

    btn.disabled = true;
    resultado.innerHTML = '<span class="text-secondary">A IA está lendo as transcrições dos brutos...</span>';

    const dados = new FormData();
    dados.append('short_roteiro_id', <?= (int) $shortId ?>);

    try {
        const resposta = await fetch('short_corte_sugerir_ia.php', { method: 'POST', body: dados });
        const json = await resposta.json();

        if (!resposta.ok || !json.success) {
            throw new Error(json.message || 'Falha ao gerar sugestões.');
        }

        resultado.innerHTML = '<span class="text-success">' + json.message + '</span>';
        setTimeout(() => window.location.reload(), 800);

    } catch (erro) {
        resultado.innerHTML = '<span class="text-danger">' + erro.message + '</span>';
        btn.disabled = false;
    }
});

document.querySelectorAll('#lista-cortes tr[data-corte-id]').forEach(function (linha) {
    const corteId = linha.dataset.corteId;

    linha.querySelector('.chk-aprovado').addEventListener('change', function () {
        salvarCorte(corteId, { aprovado: this.checked ? 1 : 0 });
    });

    linha.querySelector('.select-arquivo').addEventListener('change', function () {
        salvarCorte(corteId, { arquivo_id: this.value });
    });
});

async function salvarCorte(corteId, campos) {
    const dados = new FormData();
    dados.append('corte_id', corteId);
    Object.entries(campos).forEach(([k, v]) => dados.append(k, v));

    try {
        const resposta = await fetch('short_corte_aprovar.php', { method: 'POST', body: dados });
        const json = await resposta.json();
        if (!json.success) throw new Error(json.message);
    } catch (erro) {
        alert('Não foi possível salvar: ' + erro.message);
    }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
