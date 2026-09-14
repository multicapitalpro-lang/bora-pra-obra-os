<?php

declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$pdo = db();

$id = (int) ($_GET['id'] ?? 0);

if (!$id) {
    header('Location: catalogo.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CAPÍTULO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    '
    SELECT
        c.*,
        t.nome AS temporada
    FROM capitulos c
    LEFT JOIN temporadas t
        ON t.id = c.temporada_id
    WHERE c.id = ?
    LIMIT 1
    '
);

$stmt->execute([$id]);

$capitulo = $stmt->fetch();

if (!$capitulo) {
    http_response_code(404);
    exit('Capítulo não encontrado.');
}


/*
|--------------------------------------------------------------------------
| ARQUIVOS INDEXADOS DO GOOGLE DRIVE
|--------------------------------------------------------------------------
*/

$stmtArquivos = $pdo->prepare(
    '
    SELECT
        a.id,
        a.drive_file_id,
        a.nome_arquivo,
        a.mime_type,
        a.tamanho_bytes,
        a.duracao_ms,
        a.drive_url,
        a.thumbnail_url,
        a.ordem,

        tr.decisao,
        tr.tipo,
        tr.inicio_ms,
        tr.fim_ms,
        tr.importancia,
        tr.possivel_uso,
        tr.observacoes

    FROM capitulo_arquivos a

    LEFT JOIN capitulo_triagem tr
        ON tr.arquivo_id = a.id

    WHERE a.capitulo_id = ?
      AND a.ativo = 1

    ORDER BY
        a.ordem ASC,
        a.nome_arquivo ASC
    '
);

$stmtArquivos->execute([$id]);

$arquivos = $stmtArquivos->fetchAll();

/*
|--------------------------------------------------------------------------
| PLANO DO EPISÓDIO
|--------------------------------------------------------------------------
*/

$stmtPlano = $pdo->prepare(
    '
    SELECT *
    FROM capitulo_planos
    WHERE capitulo_id = ?
    LIMIT 1
    '
);

$stmtPlano->execute([$id]);

$plano = $stmtPlano->fetch();

if (!$plano) {

    $plano = [
        'objetivo' => '',
        'gancho' => '',
        'contexto' => '',
        'desenvolvimento' => '',
        'encerramento' => '',
        'broll' => '',
        'textos_tela' => '',
        'audio_musica' => '',
        'observacoes_edicao' => '',
        'duracao_alvo' => '',
        'status_plano' => 'nao_iniciado',
    ];
}


/*
|--------------------------------------------------------------------------
| ARQUIVOS SELECIONADOS PARA O PLANO
|--------------------------------------------------------------------------
|
| Entram aqui somente os vídeos classificados na triagem
| como "usar" ou "parcial".
|
|--------------------------------------------------------------------------
*/

$stmtArquivosPlano = $pdo->prepare(
    '
    SELECT
        a.id,
        a.drive_file_id,
        a.nome_arquivo,
        a.thumbnail_url,
        a.drive_url,
        a.duracao_ms,

        tr.decisao,
        tr.tipo,
        tr.inicio_ms,
        tr.fim_ms,
        tr.importancia,
        tr.possivel_uso,
        tr.observacoes,

        pa.id AS plano_arquivo_id,
        pa.ordem AS plano_ordem,
        pa.funcao AS plano_funcao,
        pa.instrucao AS plano_instrucao

    FROM capitulo_arquivos a

    INNER JOIN capitulo_triagem tr
        ON tr.arquivo_id = a.id

    LEFT JOIN capitulo_plano_arquivos pa
        ON pa.arquivo_id = a.id
        AND pa.capitulo_id = a.capitulo_id

    WHERE a.capitulo_id = ?
      AND a.ativo = 1
      AND tr.decisao IN ("usar", "parcial")

    ORDER BY
        CASE
            WHEN pa.ordem IS NULL THEN 1
            ELSE 0
        END ASC,
        pa.ordem ASC,
        a.ordem ASC,
        a.nome_arquivo ASC
    '
);

$stmtArquivosPlano->execute([$id]);

$arquivosPlano = $stmtArquivosPlano->fetchAll();

$totalArquivosPlano = count($arquivosPlano);

/*
|--------------------------------------------------------------------------
| EDIÇÃO
|--------------------------------------------------------------------------
*/

$stmtEdicao = $pdo->prepare(
    '
    SELECT *
    FROM capitulo_edicao
    WHERE capitulo_id = ?
    LIMIT 1
    '
);

$stmtEdicao->execute([$id]);

$edicao = $stmtEdicao->fetch();

if (!$edicao) {
    $edicao = [
        'status_edicao' => 'nao_iniciada',
        'projeto_criado' => 0,
        'arquivos_importados' => 0,
        'corte_bruto' => 0,
        'narrativa_montada' => 0,
        'broll_aplicado' => 0,
        'audio_tratado' => 0,
        'musica_aplicada' => 0,
        'textos_aplicados' => 0,
        'legendas_aplicadas' => 0,
        'cor_ajustada' => 0,
        'revisao_editor' => 0,
        'exportacao_v1' => 0,
        'nome_projeto' => '',
        'caminho_projeto' => '',
        'link_exportacao' => '',
        'duracao_final_segundos' => null,
        'observacoes' => '',
    ];
}

/*
|--------------------------------------------------------------------------
| PROGRESSO DA EDIÇÃO
|--------------------------------------------------------------------------
*/

$camposChecklistEdicao = [
    'projeto_criado',
    'arquivos_importados',
    'corte_bruto',
    'narrativa_montada',
    'broll_aplicado',
    'audio_tratado',
    'musica_aplicada',
    'textos_aplicados',
    'legendas_aplicadas',
    'cor_ajustada',
    'revisao_editor',
    'exportacao_v1',
];

$totalEtapasEdicao = count($camposChecklistEdicao);
$totalConcluidasEdicao = 0;

foreach ($camposChecklistEdicao as $campoEdicao) {
    if (!empty($edicao[$campoEdicao])) {
        $totalConcluidasEdicao++;
    }
}

$percentualEdicao = $totalEtapasEdicao > 0
    ? round(($totalConcluidasEdicao / $totalEtapasEdicao) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| REVISÃO / VERSÕES DE EDIÇÃO
|--------------------------------------------------------------------------
*/

$stmtVersoes = $pdo->prepare(
    '
    SELECT *
    FROM capitulo_edicao_versoes
    WHERE capitulo_id = ?
    ORDER BY versao DESC
    '
);

$stmtVersoes->execute([$id]);
$versoesEdicao = $stmtVersoes->fetchAll();

$totalVersoesEdicao = count($versoesEdicao);
$ultimaVersaoEdicao = $totalVersoesEdicao > 0
    ? $versoesEdicao[0]
    : null;

$versaoAprovada = null;

foreach ($versoesEdicao as $versaoItem) {
    if (($versaoItem['status'] ?? '') === 'aprovada') {
        $versaoAprovada = $versaoItem;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| THUMBNAILS DO CAPÍTULO
|--------------------------------------------------------------------------
| Carrega todas as versões de thumbnail criadas para este capítulo.
|--------------------------------------------------------------------------
*/

$stmtThumbnails = $pdo->prepare(
    '
    SELECT *
    FROM capitulo_thumbnails
    WHERE capitulo_id = ?
    ORDER BY versao DESC, id DESC
    '
);

$stmtThumbnails->execute([$id]);

$thumbnails = $stmtThumbnails->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| THUMBNAIL ATUAL / APROVADA
|--------------------------------------------------------------------------
*/

$thumbnailAprovada = null;
$thumbnailAtual = null;

foreach ($thumbnails as $thumbnailItem) {

    // Primeira encontrada = versão mais recente
    if ($thumbnailAtual === null) {
        $thumbnailAtual = $thumbnailItem;
    }

    // Procura especificamente a aprovada
    if (($thumbnailItem['status'] ?? '') === 'aprovada') {
        $thumbnailAprovada = $thumbnailItem;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| PUBLICAÇÃO / YOUTUBE
|--------------------------------------------------------------------------
*/

$stmtPublicacao = $pdo->prepare(
    '
    SELECT *
    FROM capitulo_publicacao
    WHERE capitulo_id = ?
    LIMIT 1
    '
);

$stmtPublicacao->execute([$id]);

$publicacao = $stmtPublicacao->fetch();

if (!$publicacao) {

    $publicacao = [
        'status_publicacao' => 'nao_iniciada',
        'titulo_revisado' => 0,
        'descricao_revisada' => 0,
        'thumbnail_aplicada' => 0,
        'tags_revisadas' => 0,
        'cards_conferidos' => 0,
        'tela_final_conferida' => 0,
        'publicacao_conferida' => 0,
        'youtube_video_id' => '',
        'data_agendada' => null,
        'publicado_em' => null,
        'observacoes' => '',
    ];
}


    /*
    |--------------------------------------------------------------------------
    | PUBLICAÇÃO LIBERADA?
    |--------------------------------------------------------------------------
    |
    | Só libera quando existe uma thumbnail aprovada.
    |
    */
    
    $publicacaoLiberada =
        !empty($thumbnailAprovada);
    
    
    /*
    |--------------------------------------------------------------------------
    | CHECKLIST DA PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */
    
    $camposChecklistPublicacao = [
        'titulo_revisado',
        'descricao_revisada',
        'thumbnail_aplicada',
        'tags_revisadas',
        'cards_conferidos',
        'tela_final_conferida',
        'publicacao_conferida',
    ];
    
    $totalEtapasPublicacao =
        count($camposChecklistPublicacao);
    
    $totalConcluidasPublicacao = 0;
    
    foreach (
        $camposChecklistPublicacao
        as $campoPublicacao
    ) {
    
        if (!empty($publicacao[$campoPublicacao])) {
            $totalConcluidasPublicacao++;
        }
    }
    
    $percentualPublicacao =
        $totalEtapasPublicacao > 0
            ? round(
                (
                    $totalConcluidasPublicacao
                    /
                    $totalEtapasPublicacao
                )
                * 100
            )
            : 0;
        
    /*
    |--------------------------------------------------------------------------
    | SHORTS / REELS
    |--------------------------------------------------------------------------
    */
    
    $stmtCortesCurtos = $pdo->prepare(
        '
        SELECT
            cc.*,
            ca.nome_arquivo AS arquivo_origem_nome,
            ca.drive_file_id,
            ca.drive_url,
            ca.thumbnail_url
    
        FROM capitulo_cortes_curtos cc
    
        LEFT JOIN capitulo_arquivos ca
            ON ca.id = cc.arquivo_origem_id
    
        WHERE cc.capitulo_id = ?
    
        ORDER BY
            cc.id DESC
        '
    );
    
    $stmtCortesCurtos->execute([$id]);
    
    $cortesCurtos =
        $stmtCortesCurtos->fetchAll(PDO::FETCH_ASSOC);
    
    
    /*
|--------------------------------------------------------------------------
| RESUMO DOS CORTES
|--------------------------------------------------------------------------
*/

$totalCortesCurtos =
    count($cortesCurtos);

$cortesPublicados = 0;
$cortesProntos = 0;
$cortesEmEdicao = 0;

$publicadosYoutube = 0;
$publicadosInstagram = 0;
$publicadosTiktok = 0;

foreach ($cortesCurtos as $corteItem) {

    $statusCorte =
        $corteItem['status'] ?? 'ideia';

    /*
    |--------------------------------------------------------------------------
    | STATUS DO CORTE
    |--------------------------------------------------------------------------
    */

    if ($statusCorte === 'publicado') {
        $cortesPublicados++;
    }

    if ($statusCorte === 'pronto') {
        $cortesProntos++;
    }

    if ($statusCorte === 'editando') {
        $cortesEmEdicao++;
    }

    /*
    |--------------------------------------------------------------------------
    | DISTRIBUIÇÃO POR PLATAFORMA
    |--------------------------------------------------------------------------
    */

    if (!empty($corteItem['youtube_url'])) {
        $publicadosYoutube++;
    }

    if (!empty($corteItem['instagram_url'])) {
        $publicadosInstagram++;
    }

    if (!empty($corteItem['tiktok_url'])) {
        $publicadosTiktok++;
    }
}

/*
|--------------------------------------------------------------------------
| RESUMO DOS ARQUIVOS
|--------------------------------------------------------------------------
*/

$totalArquivos = count($arquivos);

$duracaoTotalMs = 0;
$tamanhoTotalBytes = 0;

$totalAnalisados = 0;
$totalUsar = 0;
$totalParcial = 0;
$totalDescartar = 0;
$totalPendente = 0;

foreach ($arquivos as $arquivo) {

    $duracaoTotalMs +=
        (int) ($arquivo['duracao_ms'] ?? 0);

    $tamanhoTotalBytes +=
        (int) ($arquivo['tamanho_bytes'] ?? 0);

    $decisao =
        $arquivo['decisao']
        ?? 'pendente';

    switch ($decisao) {

        case 'usar':

            $totalUsar++;
            $totalAnalisados++;

            break;

        case 'parcial':

            $totalParcial++;
            $totalAnalisados++;

            break;

        case 'descartar':

            $totalDescartar++;
            $totalAnalisados++;

            break;

        default:

            $totalPendente++;

            break;
    }
}


/*
|--------------------------------------------------------------------------
| PROGRESSO DA TRIAGEM
|--------------------------------------------------------------------------
*/

$percentualTriagem = 0;

if ($totalArquivos > 0) {

    $percentualTriagem = round(
        ($totalAnalisados / $totalArquivos) * 100
    );
}


/*
|--------------------------------------------------------------------------
| FASE ATUAL
|--------------------------------------------------------------------------
*/

$faseAtual =
    $capitulo['fase_producao']
    ?? 'brutos';


/*
|--------------------------------------------------------------------------
| PRÓXIMA AÇÃO
|--------------------------------------------------------------------------
*/

$statusPlanoAtual = $plano['status_plano'] ?? 'nao_iniciado';
$statusEdicaoAtual = $edicao['status_edicao'] ?? 'nao_iniciada';

if ($totalArquivos === 0) {

    $proximaAcaoTitulo = 'Aguardando arquivos';
    $proximaAcaoDescricao =
        'Este capítulo ainda não possui arquivos sincronizados do Google Drive.';
    $proximaAcaoIcone = 'bi-cloud-arrow-down';
    $proximaAcaoCor = 'secondary';

} elseif ($totalAnalisados === 0) {

    $proximaAcaoTitulo = 'Começar triagem';
    $proximaAcaoDescricao =
        'Assista aos vídeos e classifique cada bruto como usar, parcial ou descartar.';
    $proximaAcaoIcone = 'bi-search';
    $proximaAcaoCor = 'warning';

} elseif ($totalAnalisados < $totalArquivos) {

    $restantes = $totalArquivos - $totalAnalisados;

    $proximaAcaoTitulo = 'Continuar triagem';
    $proximaAcaoDescricao =
        'Ainda existem ' . $restantes . ' arquivo(s) pendente(s) de análise.';
    $proximaAcaoIcone = 'bi-search';
    $proximaAcaoCor = 'warning';

} elseif ($statusPlanoAtual !== 'pronto_edicao') {

    $proximaAcaoTitulo = 'Finalizar plano do episódio';
    $proximaAcaoDescricao =
        'A triagem terminou. Organize a narrativa e marque o plano como Pronto para edição.';
    $proximaAcaoIcone = 'bi-journal-text';
    $proximaAcaoCor = 'success';

} elseif ($statusEdicaoAtual === 'nao_iniciada') {

    $proximaAcaoTitulo = 'Iniciar edição no CapCut';
    $proximaAcaoDescricao =
        'O plano está pronto. Crie o projeto no CapCut e siga o checklist de edição.';
    $proximaAcaoIcone = 'bi-scissors';
    $proximaAcaoCor = 'warning';

} elseif ($statusEdicaoAtual === 'em_edicao') {

    $proximaAcaoTitulo = 'Continuar edição';
    $proximaAcaoDescricao =
        'Continue o checklist da edição até exportar a versão V1.';
    $proximaAcaoIcone = 'bi-scissors';
    $proximaAcaoCor = 'warning';

} elseif ($statusEdicaoAtual === 'aguardando_revisao') {

    $proximaAcaoTitulo = 'Revisar versão V1';
    $proximaAcaoDescricao =
        'A edição foi exportada e está aguardando a etapa de revisão.';
    $proximaAcaoIcone = 'bi-eye';
    $proximaAcaoCor = 'info';

} else {

    $proximaAcaoTitulo = 'Abrir revisão';
    $proximaAcaoDescricao =
        'A edição foi concluída. O próximo passo é revisar e aprovar a versão final.';
    $proximaAcaoIcone = 'bi-eye';
    $proximaAcaoCor = 'success';
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function formatarDuracao(int $ms): string
{
    if ($ms <= 0) {
        return '—';
    }

    $segundos =
        (int) floor($ms / 1000);

    $horas =
        (int) floor($segundos / 3600);

    $minutos =
        (int) floor(
            ($segundos % 3600) / 60
        );

    $segundosRestantes =
        $segundos % 60;

    if ($horas > 0) {

        return sprintf(
            '%02d:%02d:%02d',
            $horas,
            $minutos,
            $segundosRestantes
        );
    }

    return sprintf(
        '%02d:%02d',
        $minutos,
        $segundosRestantes
    );
}


function formatarBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '—';
    }

    $unidades = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB'
    ];

    $valor = $bytes;
    $indice = 0;

    while (
        $valor >= 1024
        &&
        $indice < count($unidades) - 1
    ) {

        $valor /= 1024;
        $indice++;
    }

    return number_format(
        $valor,
        2,
        ',',
        '.'
    ) .
    ' ' .
    $unidades[$indice];
}


function msParaTempo(?int $ms): string
{
    if ($ms === null || $ms < 0) {
        return '';
    }

    $segundos =
        (int) floor($ms / 1000);

    $horas =
        (int) floor(
            $segundos / 3600
        );

    $minutos =
        (int) floor(
            ($segundos % 3600) / 60
        );

    $segundosRestantes =
        $segundos % 60;

    if ($horas > 0) {

        return sprintf(
            '%02d:%02d:%02d',
            $horas,
            $minutos,
            $segundosRestantes
        );
    }

    return sprintf(
        '%02d:%02d',
        $minutos,
        $segundosRestantes
    );
}


function labelFase(string $fase): string
{
    $labels = [
        'brutos' => 'Brutos',
        'triagem' => 'Em triagem',
        'triado' => 'Triado',
        'plano' => 'Plano',
        'edicao' => 'Em edição',
        'revisao' => 'Em revisão',
        'pronto' => 'Pronto',
        'agendado' => 'Agendado',
        'publicado' => 'Publicado',
    ];

    return $labels[$fase]
        ?? ucfirst($fase);
}


$pageTitle =
    'Capítulo ' .
    (int) $capitulo['numero'];

require __DIR__ . '/includes/header.php';

?>


<style>

    .workflow-step {
        min-width: 100px;
        text-align: center;
        position: relative;
    }

    .workflow-circle {
        width: 32px;
        height: 32px;
        margin: 0 auto 6px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #d9dde2;
        background: #fff;
        font-size: 13px;
        font-weight: 700;
    }

    .workflow-step.done .workflow-circle {
        background: #198754;
        border-color: #198754;
        color: #fff;
    }

    .workflow-step.current .workflow-circle {
        background: #ffc107;
        border-color: #ffc107;
        color: #111;
    }

    .workflow-label {
        font-size: 11px;
        color: #6c757d;
        white-space: nowrap;
    }

    .workflow-step.done .workflow-label,
    .workflow-step.current .workflow-label {
        color: #111;
        font-weight: 600;
    }

    .triagem-card {
        border: 1px solid #e1e4e8;
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 14px;
        background: #fff;
    }

    .triagem-card.salvando {
        opacity: .6;
        pointer-events: none;
    }

    .triagem-thumbnail {
        width: 110px;
        height: 70px;
        object-fit: cover;
        border-radius: 8px;
        background: #eee;
    }

    .decisao-btn.active {
        font-weight: 700;
    }

    .accordion-button:not(.collapsed) {
        background: #f8f9fa;
        color: #111;
    }

    .progress-thin {
        height: 7px;
    }

    .status-dot {
        width: 9px;
        height: 9px;
        display: inline-block;
        border-radius: 50%;
        margin-right: 5px;
    }

</style>


<!-- =========================================================
     CABEÇALHO
========================================================= -->

<div class="d-flex justify-content-between align-items-start mb-4">

    <div>

        <div class="small text-secondary">

            Capítulo
            <?= (int) $capitulo['numero'] ?>

        </div>

        <h1 class="h4 mb-1">

            <?= htmlspecialchars(
                $capitulo['titulo']
            ) ?>

        </h1>

        <div class="text-secondary">

            <?= htmlspecialchars(
                $capitulo['temporada']
                ?? 'Temporada não definida'
            ) ?>

        </div>

    </div>


    <div class="d-flex gap-2">

        <a
            href="catalogo.php"
            class="btn btn-outline-dark"
        >

            <i class="bi bi-arrow-left"></i>
            Catálogo

        </a>

    </div>

</div>


<!-- =========================================================
     WORKFLOW
========================================================= -->

<div class="panel-card mb-4">

    <div class="d-flex justify-content-between align-items-center mb-3">

        <div>

            <h2 class="h6 mb-1">
                Fluxo de produção
            </h2>

            <div class="small text-secondary">

                Fase atual:

                <strong id="faseAtualTexto">

                    <?= htmlspecialchars(
                        labelFase($faseAtual)
                    ) ?>

                </strong>

            </div>

        </div>

    </div>


    <div class="d-flex flex-wrap gap-3">

        <?php

        $workflow = [

            'brutos' =>
                'Brutos',

            'triagem' =>
                'Triagem',

            'plano' =>
                'Plano',

            'edicao' =>
                'Edição',

            'revisao' =>
                'Revisão',

            'thumb' =>
                'Thumbnail',

            'publicacao' =>
                'Publicação',

            'distribuicao' =>
                'Distribuição'
        ];

        $indiceAtual = 0;

        if ($totalArquivos > 0) {
            $indiceAtual = 1;
        }

        if (
            $totalArquivos > 0
            &&
            $totalAnalisados >= $totalArquivos
        ) {
            $indiceAtual = 2;
        }

        if (
            ($plano['status_plano'] ?? '')
            === 'pronto_edicao'
        ) {
            $indiceAtual = 3;
        }

        if (
            ($edicao['status_edicao'] ?? '')
            === 'aguardando_revisao'
            ||
            ($edicao['status_edicao'] ?? '')
            === 'concluida'
        ) {
            $indiceAtual = 4;
        }

        $i = 0;

        ?>


        <?php foreach ($workflow as $codigo => $label): ?>

            <?php

            $classeWorkflow = '';

            if ($i < $indiceAtual) {

                $classeWorkflow = 'done';

            } elseif ($i === $indiceAtual) {

                $classeWorkflow = 'current';
            }

            ?>

            <div
                class="
                    workflow-step
                    <?= $classeWorkflow ?>
                "
            >

                <div class="workflow-circle">

                    <?php if ($i < $indiceAtual): ?>

                        <i class="bi bi-check-lg"></i>

                    <?php else: ?>

                        <?= $i + 1 ?>

                    <?php endif; ?>

                </div>

                <div class="workflow-label">

                    <?= htmlspecialchars($label) ?>

                </div>

            </div>

            <?php $i++; ?>

        <?php endforeach; ?>

    </div>

</div>


<!-- =========================================================
     PRÓXIMA AÇÃO
========================================================= -->

<div
    class="alert alert-<?= $proximaAcaoCor ?> mb-4"
    id="proximaAcao"
>

    <div class="d-flex align-items-start gap-3">

        <div class="fs-3">

            <i
                class="
                    bi
                    <?= $proximaAcaoIcone ?>
                "
            ></i>

        </div>


        <div class="flex-grow-1">

            <div class="fw-bold">

                Próxima ação:
                <?= htmlspecialchars(
                    $proximaAcaoTitulo
                ) ?>

            </div>

            <div class="small mt-1">

                <?= htmlspecialchars(
                    $proximaAcaoDescricao
                ) ?>

            </div>


            <?php if (
                $totalArquivos > 0
                &&
                $totalAnalisados < $totalArquivos
            ): ?>

                <button
                    type="button"
                    class="btn btn-dark btn-sm mt-3"
                    onclick="abrirTriagem()"
                >

                    <i class="bi bi-search"></i>

                    <?= $totalAnalisados > 0
                        ? 'Continuar triagem'
                        : 'Começar triagem'
                    ?>

                </button>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- =========================================================
     GOOGLE DRIVE - RESUMO
========================================================= -->

<div class="panel-card mb-4">

    <div
        class="
            d-flex
            justify-content-between
            align-items-center
            mb-3
        "
    >

        <div>

            <h2 class="h5 mb-1">

                <i class="bi bi-google"></i>

                Google Drive

            </h2>

            <div class="small text-secondary">

                <?php if (
                    !empty(
                        $capitulo['drive_sync_at']
                    )
                ): ?>

                    Última sincronização:

                    <?= date(
                        'd/m/Y H:i',
                        strtotime(
                            $capitulo['drive_sync_at']
                        )
                    ) ?>

                <?php else: ?>

                    Ainda não sincronizado.

                <?php endif; ?>

            </div>

        </div>


        <div class="d-flex gap-2">

            <?php if (
                !empty(
                    $capitulo['drive_folder_url']
                )
            ): ?>

                <a
                    href="<?= htmlspecialchars(
                        $capitulo[
                            'drive_folder_url'
                        ]
                    ) ?>"
                    target="_blank"
                    class="btn btn-outline-dark btn-sm"
                >

                    <i class="bi bi-folder2-open"></i>

                    Abrir pasta

                </a>

            <?php endif; ?>

        </div>

    </div>


    <div class="row g-3">

        <div class="col-md-3">

            <div class="border rounded p-3">

                <div class="small text-secondary">
                    Arquivos
                </div>

                <div class="fs-3 fw-bold">

                    <?= $totalArquivos ?>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="border rounded p-3">

                <div class="small text-secondary">
                    Duração bruta
                </div>

                <div class="fs-3 fw-bold">

                    <?= formatarDuracao(
                        $duracaoTotalMs
                    ) ?>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="border rounded p-3">

                <div class="small text-secondary">
                    Armazenamento
                </div>

                <div class="fs-3 fw-bold">

                    <?= formatarBytes(
                        $tamanhoTotalBytes
                    ) ?>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="border rounded p-3">

                <div class="small text-secondary">
                    Triagem
                </div>

                <div
                    class="fs-3 fw-bold"
                    id="resumoTriagemTopo"
                >

                    <?= $totalAnalisados ?>
                    /
                    <?= $totalArquivos ?>

                </div>

                <div class="progress progress-thin mt-2">

                    <div
                        id="barraTriagemTopo"
                        class="progress-bar bg-success"
                        style="
                            width:
                            <?= $percentualTriagem ?>%
                        "
                    ></div>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     ACCORDION PRINCIPAL
========================================================= -->

<div
    class="accordion"
    id="accordionProducao"
>


<!-- =========================================================
     1. BRUTOS
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoBrutos"
        >

            <strong>
                1. Brutos do Drive
            </strong>

            <span class="ms-2 text-secondary small">

                <?= $totalArquivos ?>
                arquivo(s)

            </span>

        </button>

    </h2>


    <div
        id="secaoBrutos"
        class="accordion-collapse collapse"
        data-bs-parent="#accordionProducao"
    >

        <div class="accordion-body">


            <?php if ($arquivos): ?>


                <div class="table-responsive">

                    <table class="table align-middle">

                        <thead>

                            <tr>

                                <th style="width:90px;">
                                    Preview
                                </th>

                                <th>
                                    Arquivo
                                </th>

                                <th>
                                    Duração
                                </th>

                                <th>
                                    Tamanho
                                </th>

                                <th>
                                    Triagem
                                </th>

                                <th></th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach (
                            $arquivos
                            as $arquivo
                        ): ?>


                            <?php

                            $decisao =
                                $arquivo['decisao']
                                ?? 'pendente';

                            ?>


                            <tr>

                                <td>

                                    <?php if (
                                        !empty(
                                            $arquivo[
                                                'thumbnail_url'
                                            ]
                                        )
                                    ): ?>

                                        <img
                                            src="thumbnail_drive.php?id=<?= urlencode(
                                                $arquivo['drive_file_id']
                                            ) ?>"
                                            style="
                                                width:70px;
                                                height:45px;
                                                object-fit:cover;
                                                border-radius:6px;
                                            "
                                            loading="lazy"
                                            alt="<?= htmlspecialchars(
                                                $arquivo['nome_arquivo']
                                            ) ?>"
                                        >

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $arquivo[
                                                'nome_arquivo'
                                            ]
                                        ) ?>

                                    </strong>

                                </td>


                                <td>

                                    <?= formatarDuracao(
                                        (int)
                                        $arquivo[
                                            'duracao_ms'
                                        ]
                                    ) ?>

                                </td>


                                <td>

                                    <?= formatarBytes(
                                        (int)
                                        $arquivo[
                                            'tamanho_bytes'
                                        ]
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        $decisao === 'usar'
                                    ): ?>

                                        <span class="badge text-bg-success">
                                            Usar
                                        </span>

                                    <?php elseif (
                                        $decisao === 'parcial'
                                    ): ?>

                                        <span class="badge text-bg-warning">
                                            Parcial
                                        </span>

                                    <?php elseif (
                                        $decisao === 'descartar'
                                    ): ?>

                                        <span class="badge text-bg-danger">
                                            Descartar
                                        </span>

                                    <?php else: ?>

                                        <span class="badge text-bg-light border">
                                            Pendente
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td class="text-end">

                                    <?php if (
                                        !empty(
                                            $arquivo['drive_url']
                                        )
                                    ): ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                $arquivo[
                                                    'drive_url'
                                                ]
                                            ) ?>"
                                            target="_blank"
                                            class="btn btn-sm btn-outline-dark"
                                        >

                                            <i class="bi bi-play-fill"></i>

                                            Abrir

                                        </a>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


            <?php else: ?>

                <div class="text-secondary">

                    Nenhum arquivo sincronizado.

                </div>

            <?php endif; ?>


        </div>

    </div>

</div>


<!-- =========================================================
     2. TRIAGEM DOS BRUTOS — UX 2.0
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoTriagem"
        >

            <strong>
                2. Triagem dos Brutos
            </strong>

            <span
                class="ms-2 text-secondary small"
                id="textoProgressoTriagem"
            >
                <?= $totalAnalisados ?>
                /
                <?= $totalArquivos ?>
                analisados
            </span>

        </button>

    </h2>


    <div
        id="secaoTriagem"
        class="accordion-collapse collapse show"
    >

        <div class="accordion-body">


            <!-- =====================================================
                 CABEÇALHO
            ====================================================== -->

            <div
                class="
                    d-flex
                    justify-content-between
                    align-items-center
                    flex-wrap
                    gap-3
                    mb-3
                "
            >

                <div>

                    <div class="fw-semibold">
                        Selecione o que realmente vale usar
                    </div>

                    <div class="small text-secondary">
                        A decisão é salva automaticamente.
                        Abra os detalhes somente quando precisar
                        ajustar a análise.
                    </div>

                </div>


                <div class="text-end">

                    <button
                        type="button"
                        class="btn btn-dark btn-sm"
                        id="btnAnalisarBrutosIA"
                        data-capitulo-id="<?= (int) $capitulo['id'] ?>"
                    >
                
                        <i class="bi bi-stars"></i>
                
                        Analisar brutos com IA
                
                    </button>
                
                
                    <div
                        id="resultadoTriagemIA"
                        class="small mt-2"
                    ></div>
                
                </div>

            </div>


            <!-- =====================================================
                 RESUMO
            ====================================================== -->

            <div class="row g-2 mb-3">

                <div class="col-6 col-md-3">

                    <div class="border rounded p-3 h-100">

                        <div class="small text-secondary">
                            Usar
                        </div>

                        <div
                            class="fs-5 fw-bold text-success"
                            id="contadorUsar"
                        >
                            <?= $totalUsar ?>
                        </div>

                    </div>

                </div>


                <div class="col-6 col-md-3">

                    <div class="border rounded p-3 h-100">

                        <div class="small text-secondary">
                            Parcial
                        </div>

                        <div
                            class="fs-5 fw-bold text-warning"
                            id="contadorParcial"
                        >
                            <?= $totalParcial ?>
                        </div>

                    </div>

                </div>


                <div class="col-6 col-md-3">

                    <div class="border rounded p-3 h-100">

                        <div class="small text-secondary">
                            Descartar
                        </div>

                        <div
                            class="fs-5 fw-bold text-danger"
                            id="contadorDescartar"
                        >
                            <?= $totalDescartar ?>
                        </div>

                    </div>

                </div>


                <div class="col-6 col-md-3">

                    <div class="border rounded p-3 h-100">

                        <div class="small text-secondary">
                            Pendentes
                        </div>

                        <div
                            class="fs-5 fw-bold"
                            id="contadorPendente"
                        >
                            <?= $totalPendente ?>
                        </div>

                    </div>

                </div>

            </div>


            <!-- =====================================================
                 PROGRESSO
            ====================================================== -->

            <div
                class="progress mb-4"
                style="height:8px;"
            >

                <div
                    id="barraTriagem"
                    class="progress-bar bg-success"
                    style="width:<?= $percentualTriagem ?>%;"
                ></div>

            </div>


            <!-- =====================================================
                 ARQUIVOS
            ====================================================== -->

            <div class="d-flex flex-column gap-3">


                <?php foreach ($arquivos as $arquivo): ?>


                    <?php

                    $arquivoId =
                        (int) $arquivo['id'];

                    $decisaoAtual =
                        $arquivo['decisao']
                        ?? 'pendente';

                    $tipoAtual =
                        $arquivo['tipo']
                        ?? 'nao_definido';

                    $importanciaAtual =
                        $arquivo['importancia']
                        ?? 'normal';


                    $tipos = [

                        'nao_definido' => 'Não definido',
                        'fala' => 'Fala',
                        'execucao' => 'Execução',
                        'broll' => 'B-roll',
                        'drone' => 'Drone',
                        'detalhe' => 'Detalhe',
                        'ambiente' => 'Ambiente',
                        'timelapse' => 'Timelapse',
                        'foto' => 'Foto'

                    ];


                    $importancias = [

                        'normal' => 'Normal',
                        'boa' => 'Boa',
                        'forte' => 'Forte',
                        'essencial' => 'Essencial'

                    ];


                    $tipoLabel =
                        $tipos[$tipoAtual]
                        ?? 'Não definido';

                    $importanciaLabel =
                        $importancias[$importanciaAtual]
                        ?? 'Normal';

                    ?>


                    <form
                        class="
                            triagem-card
                            form-triagem
                            border
                            rounded
                            p-3
                        "
                        data-arquivo-id="<?= $arquivoId ?>"
                        data-decisao-atual="<?= htmlspecialchars(
                            $decisaoAtual
                        ) ?>"
                    >


                        <input
                            type="hidden"
                            name="arquivo_id"
                            value="<?= $arquivoId ?>"
                        >


                        <input
                            type="hidden"
                            name="decisao"
                            class="input-decisao"
                            value="<?= htmlspecialchars(
                                $decisaoAtual
                            ) ?>"
                        >


                        <!-- =========================================
                             CABEÇALHO
                        ========================================== -->

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-start
                                gap-3
                                flex-wrap
                            "
                        >


                            <div
                                class="
                                    d-flex
                                    align-items-start
                                    gap-3
                                "
                            >


                                <?php if (
                                    !empty(
                                        $arquivo['thumbnail_url']
                                    )
                                ): ?>

                                    <img
                                        src="thumbnail_drive.php?id=<?= urlencode(
                                            $arquivo['drive_file_id']
                                        ) ?>"
                                        class="triagem-thumbnail"
                                        loading="lazy"
                                        alt="<?= htmlspecialchars(
                                            $arquivo['nome_arquivo']
                                        ) ?>"
                                    >

                                <?php endif; ?>


                                <div>

                                    <div class="fw-bold">

                                        <?= htmlspecialchars(
                                            $arquivo['nome_arquivo']
                                        ) ?>

                                    </div>


                                    <div
                                        class="
                                            small
                                            text-secondary
                                            mt-1
                                        "
                                    >

                                        <i class="bi bi-clock"></i>

                                        <?= formatarDuracao(
                                            (int)
                                            $arquivo['duracao_ms']
                                        ) ?>

                                        <span class="mx-1">
                                            •
                                        </span>

                                        <?= formatarBytes(
                                            (int)
                                            $arquivo['tamanho_bytes']
                                        ) ?>

                                    </div>


                                    <div
                                        class="
                                            d-flex
                                            align-items-center
                                            flex-wrap
                                            gap-2
                                            mt-2
                                        "
                                    >


                                        <?php if (
                                            !empty(
                                                $arquivo['drive_url']
                                            )
                                        ): ?>

                                            <a
                                                href="<?= htmlspecialchars(
                                                    $arquivo['drive_url']
                                                ) ?>"
                                                target="_blank"
                                                class="
                                                    btn
                                                    btn-sm
                                                    btn-outline-dark
                                                "
                                            >

                                                <i class="bi bi-play-fill"></i>

                                                Assistir

                                            </a>

                                        <?php endif; ?>


                                        <?php if (
                                            $tipoAtual !== 'nao_definido'
                                        ): ?>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-light
                                                    border
                                                "
                                            >
                                                <?= htmlspecialchars(
                                                    $tipoLabel
                                                ) ?>
                                            </span>

                                        <?php endif; ?>


                                        <?php if (
                                            $importanciaAtual !== 'normal'
                                        ): ?>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-light
                                                    border
                                                "
                                            >
                                                <?= htmlspecialchars(
                                                    $importanciaLabel
                                                ) ?>
                                            </span>

                                        <?php endif; ?>


                                    </div>

                                </div>

                            </div>


                            <!-- STATUS DO AUTOSAVE -->

                            <div
                                class="
                                    resultado-salvamento
                                    small
                                "
                            ></div>


                        </div>


                        <!-- =========================================
                             DECISÃO
                        ========================================== -->

                        <div class="mt-3">

                            <div
                                class="
                                    small
                                    fw-semibold
                                    mb-2
                                "
                            >
                                O que fazer com este vídeo?
                            </div>


                            <div
                                class="
                                    d-flex
                                    gap-2
                                    flex-wrap
                                "
                            >


                                <!-- USAR -->

                                <button
                                    type="button"
                                    class="
                                        btn
                                        btn-sm
                                        decisao-btn
                                        <?= $decisaoAtual === 'usar'
                                            ? 'btn-success active'
                                            : 'btn-outline-success'
                                        ?>
                                    "
                                    data-decisao="usar"
                                >

                                    <i class="bi bi-check-lg"></i>

                                    Usar

                                </button>


                                <!-- PARCIAL -->

                                <button
                                    type="button"
                                    class="
                                        btn
                                        btn-sm
                                        decisao-btn
                                        <?= $decisaoAtual === 'parcial'
                                            ? 'btn-warning active'
                                            : 'btn-outline-warning'
                                        ?>
                                    "
                                    data-decisao="parcial"
                                >

                                    <i class="bi bi-scissors"></i>

                                    Usar uma parte

                                </button>


                                <!-- DESCARTAR -->

                                <button
                                    type="button"
                                    class="
                                        btn
                                        btn-sm
                                        decisao-btn
                                        <?= $decisaoAtual === 'descartar'
                                            ? 'btn-danger active'
                                            : 'btn-outline-danger'
                                        ?>
                                    "
                                    data-decisao="descartar"
                                >

                                    <i class="bi bi-x-lg"></i>

                                    Descartar

                                </button>


                                <!-- DETALHES -->

                                <button
                                    type="button"
                                    class="
                                        btn
                                        btn-sm
                                        btn-outline-secondary
                                    "
                                    data-bs-toggle="collapse"
                                    data-bs-target="#detalhesTriagem<?= $arquivoId ?>"
                                    aria-expanded="false"
                                >

                                    <i class="bi bi-sliders"></i>

                                    Detalhes da análise

                                </button>


                            </div>

                        </div>


                        <!-- =========================================
                             DETALHES DA ANÁLISE
                        ========================================== -->

                        <div
                            class="collapse mt-3"
                            id="detalhesTriagem<?= $arquivoId ?>"
                        >

                            <div class="border-top pt-3">

                                <div
                                    class="
                                        small
                                        text-secondary
                                        mb-3
                                    "
                                >
                                    Ajustes opcionais. A decisão
                                    Usar / Parcial / Descartar já é
                                    salva automaticamente.
                                </div>


                                <div class="row g-3">


                                    <!-- TIPO -->

                                    <div class="col-md-3">

                                        <label class="form-label">
                                            Tipo do material
                                        </label>

                                        <select
                                            name="tipo"
                                            class="form-select"
                                        >

                                            <?php foreach (
                                                $tipos
                                                as $valor => $label
                                            ): ?>

                                                <option
                                                    value="<?= $valor ?>"
                                                    <?= $tipoAtual === $valor
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    <?= htmlspecialchars(
                                                        $label
                                                    ) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>


                                    <!-- IMPORTÂNCIA -->

                                    <div class="col-md-3">

                                        <label class="form-label">
                                            Importância
                                        </label>

                                        <select
                                            name="importancia"
                                            class="form-select"
                                        >

                                            <?php foreach (
                                                $importancias
                                                as $valor => $label
                                            ): ?>

                                                <option
                                                    value="<?= $valor ?>"
                                                    <?= $importanciaAtual === $valor
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    <?= htmlspecialchars(
                                                        $label
                                                    ) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>


                                    <!-- INÍCIO -->

                                    <div class="col-md-3">

                                        <label class="form-label">
                                            Início útil
                                        </label>

                                        <input
                                            type="text"
                                            name="inicio"
                                            class="form-control"
                                            placeholder="00:00"
                                            value="<?= htmlspecialchars(
                                                msParaTempo(
                                                    $arquivo['inicio_ms']
                                                    !== null
                                                        ? (int)
                                                        $arquivo['inicio_ms']
                                                        : null
                                                )
                                            ) ?>"
                                        >

                                    </div>


                                    <!-- FIM -->

                                    <div class="col-md-3">

                                        <label class="form-label">
                                            Fim útil
                                        </label>

                                        <input
                                            type="text"
                                            name="fim"
                                            class="form-control"
                                            placeholder="<?= htmlspecialchars(
                                                formatarDuracao(
                                                    (int)
                                                    $arquivo['duracao_ms']
                                                )
                                            ) ?>"
                                            value="<?= htmlspecialchars(
                                                msParaTempo(
                                                    $arquivo['fim_ms']
                                                    !== null
                                                        ? (int)
                                                        $arquivo['fim_ms']
                                                        : null
                                                )
                                            ) ?>"
                                        >

                                    </div>


                                    <!-- POSSÍVEL USO -->

                                    <div class="col-md-4">

                                        <label class="form-label">
                                            Onde pode entrar?
                                        </label>

                                        <input
                                            type="text"
                                            name="possivel_uso"
                                            class="form-control"
                                            placeholder="Ex.: abertura, explicação, apoio..."
                                            value="<?= htmlspecialchars(
                                                $arquivo['possivel_uso']
                                                ?? ''
                                            ) ?>"
                                        >

                                    </div>


                                    <!-- OBSERVAÇÕES -->

                                    <div class="col-md-8">

                                        <label class="form-label">
                                            Observações
                                        </label>

                                        <textarea
                                            name="observacoes"
                                            class="form-control"
                                            rows="2"
                                            placeholder="Algo importante sobre este vídeo?"
                                        ><?= htmlspecialchars(
                                            $arquivo['observacoes']
                                            ?? ''
                                        ) ?></textarea>

                                    </div>


                                </div>


                                <!-- SALVAR SOMENTE DETALHES -->

                                <div
                                    class="
                                        d-flex
                                        justify-content-end
                                        mt-3
                                    "
                                >

                                    <button
                                        type="submit"
                                        class="
                                            btn
                                            btn-outline-dark
                                            btn-sm
                                            btn-salvar-detalhes
                                        "
                                    >

                                        <i class="bi bi-floppy"></i>

                                        Salvar detalhes

                                    </button>

                                </div>


                            </div>

                        </div>


                    </form>


                <?php endforeach; ?>


            </div>


        </div>

    </div>

</div>
<!-- =========================================================
     3. PLANO DO EPISÓDIO — UX 2.0
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoPlano"
        >

            <strong>
                3. Plano do Episódio
            </strong>

            <span class="ms-2">

                <?php if (
                    ($plano['status_plano'] ?? 'nao_iniciado')
                    === 'pronto_edicao'
                ): ?>

                    <span class="badge text-bg-success">
                        Plano aprovado
                    </span>

                <?php elseif (
                    ($plano['status_plano'] ?? 'nao_iniciado')
                    === 'rascunho'
                ): ?>

                    <span class="badge text-bg-warning">
                        Em preparação
                    </span>

                <?php else: ?>

                    <span class="badge text-bg-light border">
                        Não iniciado
                    </span>

                <?php endif; ?>

            </span>

        </button>

    </h2>


    <div
        id="secaoPlano"
        class="accordion-collapse collapse"
        data-bs-parent="#accordionProducao"
    >

        <div class="accordion-body">


            <?php if (
                $totalArquivos === 0
                ||
                $totalAnalisados < $totalArquivos
            ): ?>

                <div class="alert alert-light border mb-0">

                    <i class="bi bi-lock me-1"></i>

                    <strong>
                        Plano bloqueado.
                    </strong>

                    Termine a triagem dos brutos antes
                    de montar o episódio.

                </div>


            <?php else: ?>


                <form id="formPlanoEpisodio">

                    <input
                        type="hidden"
                        name="capitulo_id"
                        value="<?= (int) $capitulo['id'] ?>"
                    >

                    <input
                        type="hidden"
                        name="status_plano"
                        id="statusPlano"
                        value="<?= htmlspecialchars(
                            $plano['status_plano']
                            ?? 'nao_iniciado'
                        ) ?>"
                    >


                    <!-- =====================================================
                         CABEÇALHO
                    ====================================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-start
                            flex-wrap
                            gap-3
                            mb-4
                        "
                    >

                        <div>

                            <h3 class="h6 mb-1">
                                Estrutura do episódio
                            </h3>

                            <div class="small text-secondary">
                                Monte apenas a direção principal.
                                Os detalhes técnicos ficam escondidos
                                e serão cada vez mais automatizados.
                            </div>

                        </div>


                        <div class="text-end">

                            <!-- MONTAR EPISÓDIO COM IA -->
                        
                            <button
                                type="button"
                                class="btn btn-dark btn-sm"
                                id="btnMontarPlanoIA"
                                data-capitulo-id="<?= (int) $capitulo['id'] ?>"
                            >
                        
                                <i class="bi bi-stars me-1"></i>
                        
                                Montar episódio com IA
                        
                            </button>
                        
                        
                            <div
                                id="resultadoPlanoIA"
                                class="small mt-2"
                            ></div>
                        
                        
                            <!-- PRECISÃO PARA CAPCUT -->
                        
                            <div class="mt-2">

                                <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm"
                                    id="btnGerarPrecisaoCapCut"
                                    data-capitulo-id="<?= (int) $capitulo['id'] ?>"
                                >
                            
                                    <i class="bi bi-bullseye me-1"></i>
                            
                                    Gerar precisão para CapCut
                            
                                </button>
                            
                            
                                <div
                                    id="resultadoPrecisaoCapCut"
                                    class="small mt-2"
                                ></div>
                            
                            
                                <div
                                    id="progressoPrecisaoCapCut"
                                    class="mt-2 d-none"
                                    style="min-width:260px;"
                                >
                            
                                    <div
                                        class="progress"
                                        style="height:6px;"
                                    >
                            
                                        <div
                                            class="progress-bar progress-bar-striped progress-bar-animated"
                                            id="barraPrecisaoCapCut"
                                            role="progressbar"
                                            style="width:0%;"
                                        ></div>
                            
                                    </div>
                            
                            
                                    <div
                                        class="small text-secondary mt-1"
                                        id="textoProgressoPrecisaoCapCut"
                                    >
                                        Preparando...
                                    </div>
                            
                                </div>
                            
                            </div>
                            
                            <!-- GUIA CAPCUT -->

                            <div class="mt-2">
                            
                                <button
                                    type="button"
                                    class="btn btn-success btn-sm"
                                    id="btnAbrirGuiaCapCut"
                                    data-capitulo-id="<?= (int) $capitulo['id'] ?>"
                                >
                            
                                    <i class="bi bi-film me-1"></i>
                            
                                    Abrir Guia CapCut
                            
                                </button>
                            
                            </div>
                        
                        </div>

                    </div>
                    
                    <div class="mt-2">

                        <button
                            type="button"
                            class="btn btn-outline-warning btn-sm"
                            id="btnRefinarCortesFala"
                            data-capitulo-id="<?= (int) $capitulo['id'] ?>"
                        >
                    
                            <i class="bi bi-magic me-1"></i>
                    
                            Refinar cortes de fala
                    
                        </button>
                    
                    
                        <div
                            id="resultadoRefinoCortes"
                            class="small mt-2"
                        ></div>
                    
                    </div>


                    <!-- =====================================================
                         RESUMO PRINCIPAL
                    ====================================================== -->

                    <div class="row g-3">

                        <!-- OBJETIVO -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Objetivo do episódio
                            </label>

                            <textarea
                                name="objetivo"
                                class="form-control"
                                rows="3"
                                placeholder="O que este episódio precisa contar?"
                            ><?= htmlspecialchars(
                                $plano['objetivo'] ?? ''
                            ) ?></textarea>

                        </div>


                        <!-- GANCHO -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Gancho / abertura
                            </label>

                            <textarea
                                name="gancho"
                                class="form-control"
                                rows="3"
                                placeholder="Como o episódio começa?"
                            ><?= htmlspecialchars(
                                $plano['gancho'] ?? ''
                            ) ?></textarea>

                        </div>


                        <!-- DESENVOLVIMENTO -->

                        <div class="col-md-8">

                            <label class="form-label fw-semibold">
                                Desenvolvimento
                            </label>

                            <textarea
                                name="desenvolvimento"
                                class="form-control"
                                rows="4"
                                placeholder="Qual é a sequência principal da história?"
                            ><?= htmlspecialchars(
                                $plano['desenvolvimento'] ?? ''
                            ) ?></textarea>

                        </div>


                        <!-- DURAÇÃO -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Duração alvo
                            </label>

                            <input
                                type="text"
                                name="duracao_alvo"
                                class="form-control"
                                placeholder="Ex.: 6 a 8 minutos"
                                value="<?= htmlspecialchars(
                                    $plano['duracao_alvo']
                                    ?? ''
                                ) ?>"
                            >

                            <div class="form-text">
                                Futuramente será sugerida automaticamente
                                com base no material aproveitável.
                            </div>

                        </div>

                    </div>


                    <!-- =====================================================
                         DETALHES AVANÇADOS
                    ====================================================== -->

                    <div class="mt-4">

                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-bs-toggle="collapse"
                            data-bs-target="#detalhesPlano"
                        >

                            <i class="bi bi-sliders"></i>

                            Detalhes avançados

                        </button>

                    </div>


                    <div
                        id="detalhesPlano"
                        class="collapse mt-3"
                    >

                        <div class="border rounded p-3">

                            <div class="small text-secondary mb-3">
                                Estes campos são complementares.
                                Não precisam ser preenchidos em todos
                                os episódios.
                            </div>


                            <div class="row g-3">

                                <!-- CONTEXTO -->

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Contexto
                                    </label>

                                    <textarea
                                        name="contexto"
                                        class="form-control"
                                        rows="3"
                                        placeholder="O que precisa ser explicado antes da parte principal?"
                                    ><?= htmlspecialchars(
                                        $plano['contexto'] ?? ''
                                    ) ?></textarea>

                                </div>


                                <!-- ENCERRAMENTO -->

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Encerramento
                                    </label>

                                    <textarea
                                        name="encerramento"
                                        class="form-control"
                                        rows="3"
                                        placeholder="Como o episódio deve terminar?"
                                    ><?= htmlspecialchars(
                                        $plano['encerramento'] ?? ''
                                    ) ?></textarea>

                                </div>


                                <!-- B-ROLL -->

                                <div class="col-md-6">

                                    <label class="form-label">
                                        B-roll / imagens de apoio
                                    </label>

                                    <textarea
                                        name="broll"
                                        class="form-control"
                                        rows="3"
                                        placeholder="Imagens ou cenas importantes de apoio."
                                    ><?= htmlspecialchars(
                                        $plano['broll'] ?? ''
                                    ) ?></textarea>

                                </div>


                                <!-- TEXTOS -->

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Textos na tela
                                    </label>

                                    <textarea
                                        name="textos_tela"
                                        class="form-control"
                                        rows="3"
                                        placeholder="Medidas, nomes, explicações ou destaques."
                                    ><?= htmlspecialchars(
                                        $plano['textos_tela'] ?? ''
                                    ) ?></textarea>

                                </div>


                                <!-- ÁUDIO -->

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Áudio / música
                                    </label>

                                    <textarea
                                        name="audio_musica"
                                        class="form-control"
                                        rows="3"
                                        placeholder="Somente se houver alguma orientação específica."
                                    ><?= htmlspecialchars(
                                        $plano['audio_musica'] ?? ''
                                    ) ?></textarea>

                                </div>


                                <!-- OBSERVAÇÕES -->

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Observações gerais
                                    </label>

                                    <textarea
                                        name="observacoes_edicao"
                                        class="form-control"
                                        rows="3"
                                        placeholder="Algum cuidado especial para a edição?"
                                    ><?= htmlspecialchars(
                                        $plano['observacoes_edicao']
                                        ?? ''
                                    ) ?></textarea>

                                </div>

                            </div>

                        </div>

                    </div>


                    <hr class="my-4">


                    <!-- =====================================================
                         LINHA DE EDIÇÃO
                    ====================================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-start
                            flex-wrap
                            gap-3
                            mb-3
                        "
                    >

                        <div>

                            <h3 class="h6 mb-1">
                                Linha de edição
                            </h3>

                            <div class="small text-secondary">
                                Material selecionado na triagem.
                                Ajuste somente a ordem, função e
                                instrução quando necessário.
                            </div>

                        </div>

                    </div>


                    <?php if ($arquivosPlano): ?>

                        <div class="table-responsive">

                            <table
                                class="
                                    table
                                    table-hover
                                    align-middle
                                "
                            >

                                <thead>

                                    <tr>

                                        <th style="width:70px;">
                                            Ordem
                                        </th>

                                        <th>
                                            Arquivo
                                        </th>

                                        <th style="width:120px;">
                                            Trecho
                                        </th>

                                        <th style="width:130px;">
                                            Tipo
                                        </th>

                                        <th style="width:140px;">
                                            Função
                                        </th>

                                        <th>
                                            Instrução
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                <?php foreach (
                                    $arquivosPlano
                                    as $indice => $arquivoPlano
                                ): ?>

                                    <?php

                                    $ordemPlano =
                                        $arquivoPlano['plano_ordem']
                                        ?? ($indice + 1);

                                    $funcaoPlano =
                                        $arquivoPlano['plano_funcao']
                                        ?? 'principal';

                                    ?>

                                    <tr>

                                        <!-- ORDEM -->

                                        <td>

                                            <input
                                                type="number"
                                                class="form-control form-control-sm"
                                                name="arquivos[<?= (int) $arquivoPlano['id'] ?>][ordem]"
                                                min="1"
                                                value="<?= (int) $ordemPlano ?>"
                                            >

                                        </td>


                                        <!-- ARQUIVO -->

                                        <td>

                                            <div
                                                class="
                                                    d-flex
                                                    gap-2
                                                    align-items-center
                                                "
                                            >

                                                <?php if (
                                                    !empty(
                                                        $arquivoPlano[
                                                            'thumbnail_url'
                                                        ]
                                                    )
                                                ): ?>

                                                    <img
                                                        src="thumbnail_drive.php?id=<?= urlencode(
                                                            $arquivoPlano[
                                                                'drive_file_id'
                                                            ]
                                                        ) ?>"
                                                        style="
                                                            width:60px;
                                                            height:40px;
                                                            object-fit:cover;
                                                            border-radius:5px;
                                                        "
                                                        loading="lazy"
                                                        alt="<?= htmlspecialchars(
                                                            $arquivoPlano[
                                                                'nome_arquivo'
                                                            ]
                                                        ) ?>"
                                                    >

                                                <?php endif; ?>


                                                <div>

                                                    <div class="fw-semibold small">

                                                        <?= htmlspecialchars(
                                                            $arquivoPlano[
                                                                'nome_arquivo'
                                                            ]
                                                        ) ?>

                                                    </div>


                                                    <?php if (
                                                        !empty(
                                                            $arquivoPlano[
                                                                'drive_url'
                                                            ]
                                                        )
                                                    ): ?>

                                                        <a
                                                            href="<?= htmlspecialchars(
                                                                $arquivoPlano[
                                                                    'drive_url'
                                                                ]
                                                            ) ?>"
                                                            target="_blank"
                                                            class="small"
                                                        >
                                                            Assistir
                                                        </a>

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        </td>


                                        <!-- TRECHO -->

                                        <td class="small">

                                            <?php

                                            $inicioPlano =
                                                msParaTempo(
                                                    $arquivoPlano[
                                                        'inicio_ms'
                                                    ] !== null
                                                        ? (int)
                                                        $arquivoPlano[
                                                            'inicio_ms'
                                                        ]
                                                        : null
                                                );

                                            $fimPlano =
                                                msParaTempo(
                                                    $arquivoPlano[
                                                        'fim_ms'
                                                    ] !== null
                                                        ? (int)
                                                        $arquivoPlano[
                                                            'fim_ms'
                                                        ]
                                                        : null
                                                );

                                            ?>

                                            <?php if (
                                                $inicioPlano !== ''
                                                ||
                                                $fimPlano !== ''
                                            ): ?>

                                                <?= htmlspecialchars(
                                                    $inicioPlano ?: '00:00'
                                                ) ?>

                                                →

                                                <?= htmlspecialchars(
                                                    $fimPlano ?: 'fim'
                                                ) ?>

                                            <?php else: ?>

                                                Completo

                                            <?php endif; ?>

                                        </td>


                                        <!-- TIPO -->

                                        <td>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-light
                                                    border
                                                "
                                            >

                                                <?= htmlspecialchars(
                                                    ucfirst(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            $arquivoPlano[
                                                                'tipo'
                                                            ]
                                                            ?? 'não definido'
                                                        )
                                                    )
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- FUNÇÃO -->

                                        <td>

                                            <select
                                                class="form-select form-select-sm"
                                                name="arquivos[<?= (int) $arquivoPlano['id'] ?>][funcao]"
                                            >

                                                <option
                                                    value="gancho"
                                                    <?= $funcaoPlano === 'gancho'
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    Gancho
                                                </option>

                                                <option
                                                    value="principal"
                                                    <?= $funcaoPlano === 'principal'
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    Principal
                                                </option>

                                                <option
                                                    value="broll"
                                                    <?= $funcaoPlano === 'broll'
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    B-roll
                                                </option>

                                                <option
                                                    value="encerramento"
                                                    <?= $funcaoPlano === 'encerramento'
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    Encerramento
                                                </option>

                                            </select>

                                        </td>


                                        <!-- INSTRUÇÃO -->

                                        <td>

                                            <textarea
                                                class="form-control form-control-sm"
                                                rows="2"
                                                name="arquivos[<?= (int) $arquivoPlano['id'] ?>][instrucao]"
                                                placeholder="Ex.: cortar pausa, usar imagem de apoio..."
                                            ><?= htmlspecialchars(
                                                $arquivoPlano[
                                                    'plano_instrucao'
                                                ]
                                                ?? ''
                                            ) ?></textarea>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>


                    <?php else: ?>

                        <div class="alert alert-light border">

                            Nenhum arquivo foi marcado
                            como <strong>Usar</strong>
                            ou <strong>Parcial</strong>
                            durante a triagem.

                        </div>

                    <?php endif; ?>


                    <!-- =====================================================
                         AÇÕES
                    ====================================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            flex-wrap
                            gap-3
                            mt-4
                        "
                    >

                        <div
                            id="resultadoPlano"
                            class="small"
                        ></div>


                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-outline-dark"
                                id="btnSalvarRascunhoPlano"
                                data-status="rascunho"
                            >

                                <i class="bi bi-floppy"></i>

                                Salvar rascunho

                            </button>


                            <button
                                type="submit"
                                class="btn btn-success"
                                id="btnAprovarPlano"
                                data-status="pronto_edicao"
                            >

                                <i class="bi bi-check-circle"></i>

                                Aprovar plano

                            </button>

                        </div>

                    </div>


                </form>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- =========================================================
     MODAL — GUIA CAPCUT
========================================================= -->

<div
    class="modal fade"
    id="modalGuiaCapCut"
    tabindex="-1"
    aria-hidden="true"
>

    <div
        class="
            modal-dialog
            modal-xl
            modal-dialog-scrollable
        "
    >

        <div class="modal-content">


            <div class="modal-header">

                <div>

                    <h5 class="modal-title mb-1">

                        <i class="bi bi-film me-1"></i>

                        Guia de Edição — CapCut

                    </h5>


                    <div
                        class="small text-secondary"
                        id="resumoGuiaCapCut"
                    >
                        Carregando...
                    </div>

                </div>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Fechar"
                ></button>

            </div>


            <div class="modal-body">

                <div
                    id="loadingGuiaCapCut"
                    class="text-center py-5"
                >

                    <div
                        class="spinner-border"
                        role="status"
                    ></div>

                    <div class="mt-2 text-secondary">
                        Carregando guia...
                    </div>

                </div>


                <div
                    id="conteudoGuiaCapCut"
                    class="d-none"
                ></div>

            </div>
            
            <div
                id="statusPreparacaoCapCut"
                class="px-3 pb-3 small"
            ></div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-success"
                    id="btnPrepararArquivosCapCut"
                    data-capitulo-id="<?= (int) $capitulo['id'] ?>"
                >
            
                    <i class="bi bi-folder-check me-1"></i>
            
                    Preparar arquivos para CapCut
            
                </button>
            
            
                <button
                    type="button"
                    class="btn btn-outline-dark"
                    id="btnCopiarGuiaCapCut"
                >
            
                    <i class="bi bi-clipboard me-1"></i>
            
                    Copiar guia
            
                </button>
            
            
                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
            
                    Fechar
            
                </button>
            
            </div>

        </div>

    </div>

</div>
<!-- =========================================================
     4. EDIÇÃO — UX 2.0
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoEdicao"
        >

            <strong>
                4. Edição
            </strong>

            <span class="ms-2">

                <?php
                $statusEdicaoAtual =
                    $edicao['status_edicao']
                    ?? 'nao_iniciada';
                ?>

                <?php if ($statusEdicaoAtual === 'concluida'): ?>

                    <span class="badge text-bg-success">
                        Concluída
                    </span>

                <?php elseif ($statusEdicaoAtual === 'aguardando_revisao'): ?>

                    <span class="badge text-bg-info">
                        V1 enviada
                    </span>

                <?php elseif ($statusEdicaoAtual === 'em_edicao'): ?>

                    <span class="badge text-bg-warning">
                        Em edição
                    </span>

                <?php else: ?>

                    <span class="badge text-bg-light border">
                        Não iniciada
                    </span>

                <?php endif; ?>

            </span>

        </button>

    </h2>


    <div
        id="secaoEdicao"
        class="accordion-collapse collapse"
        data-bs-parent="#accordionProducao"
    >

        <div class="accordion-body">

            <?php if (
                ($plano['status_plano'] ?? '')
                !== 'pronto_edicao'
            ): ?>

                <!-- =============================================
                     BLOQUEADA
                ============================================== -->

                <div class="alert alert-light border mb-0">

                    <i class="bi bi-lock me-1"></i>

                    <strong>
                        Edição bloqueada.
                    </strong>

                    Aprove o Plano do Episódio
                    antes de iniciar a edição.

                </div>


            <?php else: ?>


                <form id="formEdicao">

                    <input
                        type="hidden"
                        name="capitulo_id"
                        value="<?= (int) $capitulo['id'] ?>"
                    >


                    <!-- =============================================
                         CABEÇALHO
                    ============================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-start
                            flex-wrap
                            gap-3
                            mb-4
                        "
                    >

                        <div>

                            <h3 class="h6 mb-1">
                                Produção do vídeo
                            </h3>

                            <div class="small text-secondary">
                                Edite normalmente no CapCut.
                                Aqui controlamos apenas o que realmente
                                importa para o fluxo do episódio.
                            </div>

                        </div>


                        <?php if ($statusEdicaoAtual === 'aguardando_revisao'): ?>

                            <span class="badge text-bg-info fs-6">

                                <i class="bi bi-hourglass-split me-1"></i>

                                Aguardando revisão

                            </span>

                        <?php elseif ($statusEdicaoAtual === 'concluida'): ?>

                            <span class="badge text-bg-success fs-6">

                                <i class="bi bi-check-circle me-1"></i>

                                Edição concluída

                            </span>

                        <?php endif; ?>

                    </div>


                    <!-- =============================================
                         PLANO APROVADO
                    ============================================== -->

                    <div class="alert alert-success border mb-4">

                        <div class="d-flex align-items-start gap-2">

                            <i class="bi bi-check-circle-fill mt-1"></i>

                            <div>

                                <div class="fw-semibold">
                                    Plano do episódio aprovado
                                </div>

                                <div class="small mt-1">

                                    A estrutura definida na Etapa 3
                                    está pronta para ser usada na edição.

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =============================================
                         PROJETO
                    ============================================== -->

                    <div class="row g-3 mb-4">


                        <!-- NOME DO PROJETO -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Nome do projeto no CapCut
                            </label>

                            <input
                                type="text"
                                name="nome_projeto"
                                class="form-control"
                                placeholder="Ex.: BPO_EP001_POSTE_SANEPAR"
                                value="<?= htmlspecialchars(
                                    $edicao['nome_projeto']
                                    ?? ''
                                ) ?>"
                            >

                            <div class="form-text">
                                Futuramente este nome poderá ser
                                gerado automaticamente.
                            </div>

                        </div>


                        <!-- STATUS -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Status
                            </label>

                            <select
                                name="status_edicao"
                                id="statusEdicao"
                                class="form-select"
                            >

                                <option
                                    value="nao_iniciada"
                                    <?= $statusEdicaoAtual === 'nao_iniciada'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Não iniciada
                                </option>

                                <option
                                    value="em_edicao"
                                    <?= $statusEdicaoAtual === 'em_edicao'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Em edição
                                </option>

                                <option
                                    value="aguardando_revisao"
                                    <?= $statusEdicaoAtual === 'aguardando_revisao'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    V1 enviada para revisão
                                </option>

                                <option
                                    value="concluida"
                                    <?= $statusEdicaoAtual === 'concluida'
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    Concluída
                                </option>

                            </select>

                        </div>


                    </div>


                    <!-- =============================================
                         PLANO DE EDIÇÃO
                    ============================================== -->

                    <div class="border rounded p-3 mb-4">

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-center
                                flex-wrap
                                gap-2
                            "
                        >

                            <div>

                                <div class="fw-semibold">
                                    Plano de edição
                                </div>

                                <div class="small text-secondary">
                                    Consulte a ordem dos arquivos,
                                    trechos e instruções definidas
                                    anteriormente.
                                </div>

                            </div>


                            <button
                                type="button"
                                class="btn btn-outline-dark btn-sm"
                                data-bs-toggle="collapse"
                                data-bs-target="#planoEdicaoResumo"
                            >

                                <i class="bi bi-list-ol"></i>

                                Ver plano de edição

                            </button>

                        </div>


                        <div
                            id="planoEdicaoResumo"
                            class="collapse mt-3"
                        >

                            <?php if (!empty($arquivosPlano)): ?>

                                <div class="list-group list-group-flush">

                                    <?php foreach (
                                        $arquivosPlano
                                        as $indice => $arquivoPlanoEdicao
                                    ): ?>

                                        <?php

                                        $inicioEdicao =
                                            msParaTempo(
                                                $arquivoPlanoEdicao['inicio_ms']
                                                !== null
                                                    ? (int) $arquivoPlanoEdicao['inicio_ms']
                                                    : null
                                            );

                                        $fimEdicao =
                                            msParaTempo(
                                                $arquivoPlanoEdicao['fim_ms']
                                                !== null
                                                    ? (int) $arquivoPlanoEdicao['fim_ms']
                                                    : null
                                            );

                                        $funcaoEdicao =
                                            $arquivoPlanoEdicao['plano_funcao']
                                            ?? 'principal';

                                        ?>

                                        <div
                                            class="
                                                list-group-item
                                                px-0
                                                py-3
                                            "
                                        >

                                            <div
                                                class="
                                                    d-flex
                                                    align-items-start
                                                    gap-3
                                                "
                                            >

                                                <div
                                                    class="
                                                        rounded-circle
                                                        bg-dark
                                                        text-white
                                                        d-flex
                                                        align-items-center
                                                        justify-content-center
                                                        flex-shrink-0
                                                    "
                                                    style="
                                                        width:32px;
                                                        height:32px;
                                                    "
                                                >
                                                    <?= $indice + 1 ?>
                                                </div>


                                                <div class="flex-grow-1">

                                                    <div class="fw-semibold">

                                                        <?= htmlspecialchars(
                                                            $arquivoPlanoEdicao[
                                                                'nome_arquivo'
                                                            ]
                                                        ) ?>

                                                    </div>


                                                    <div
                                                        class="
                                                            small
                                                            text-secondary
                                                            mt-1
                                                        "
                                                    >

                                                        <?php if (
                                                            $inicioEdicao !== ''
                                                            ||
                                                            $fimEdicao !== ''
                                                        ): ?>

                                                            <i class="bi bi-clock"></i>

                                                            <?= htmlspecialchars(
                                                                $inicioEdicao ?: '00:00'
                                                            ) ?>

                                                            →

                                                            <?= htmlspecialchars(
                                                                $fimEdicao ?: 'fim'
                                                            ) ?>

                                                            <span class="mx-1">
                                                                •
                                                            </span>

                                                        <?php endif; ?>


                                                        <?= htmlspecialchars(
                                                            ucfirst(
                                                                str_replace(
                                                                    '_',
                                                                    ' ',
                                                                    $funcaoEdicao
                                                                )
                                                            )
                                                        ) ?>

                                                    </div>


                                                    <?php if (
                                                        !empty(
                                                            $arquivoPlanoEdicao[
                                                                'plano_instrucao'
                                                            ]
                                                        )
                                                    ): ?>

                                                        <div class="small mt-2">

                                                            <?= nl2br(
                                                                htmlspecialchars(
                                                                    $arquivoPlanoEdicao[
                                                                        'plano_instrucao'
                                                                    ]
                                                                )
                                                            ) ?>

                                                        </div>

                                                    <?php endif; ?>


                                                    <?php if (
                                                        !empty(
                                                            $arquivoPlanoEdicao[
                                                                'drive_url'
                                                            ]
                                                        )
                                                    ): ?>

                                                        <div class="mt-2">

                                                            <a
                                                                href="<?= htmlspecialchars(
                                                                    $arquivoPlanoEdicao[
                                                                        'drive_url'
                                                                    ]
                                                                ) ?>"
                                                                target="_blank"
                                                                class="
                                                                    btn
                                                                    btn-outline-secondary
                                                                    btn-sm
                                                                "
                                                            >

                                                                <i class="bi bi-play-fill"></i>

                                                                Abrir bruto

                                                            </a>

                                                        </div>

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            <?php else: ?>

                                <div class="small text-secondary">
                                    Nenhum arquivo disponível no
                                    plano de edição.
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>


                    <!-- =============================================
                         GUIA DE EDIÇÃO
                    ============================================== -->

                    <div class="mb-4">

                        <button
                            type="button"
                            class="
                                btn
                                btn-outline-secondary
                                btn-sm
                            "
                            data-bs-toggle="collapse"
                            data-bs-target="#guiaEdicao"
                        >

                            <i class="bi bi-journal-check"></i>

                            Ver guia de edição

                        </button>


                        <div
                            id="guiaEdicao"
                            class="collapse mt-3"
                        >

                            <div class="border rounded p-3">

                                <div class="fw-semibold mb-2">
                                    Padrão de edição do canal
                                </div>

                                <div class="small text-secondary">

                                    Este guia é apenas uma referência.
                                    Você não precisa marcar cada item.

                                </div>


                                <div class="row g-2 mt-2">

                                    <div class="col-md-6">
                                        ✓ Remover erros, pausas e repetições desnecessárias
                                    </div>

                                    <div class="col-md-6">
                                        ✓ Manter ritmo e narrativa claros
                                    </div>

                                    <div class="col-md-6">
                                        ✓ Usar B-roll quando ajudar a explicação
                                    </div>

                                    <div class="col-md-6">
                                        ✓ Priorizar voz clara e volume consistente
                                    </div>

                                    <div class="col-md-6">
                                        ✓ Usar música sem competir com a voz
                                    </div>

                                    <div class="col-md-6">
                                        ✓ Revisar textos e legendas
                                    </div>

                                    <div class="col-md-6">
                                        ✓ Manter imagem e cor consistentes
                                    </div>

                                    <div class="col-md-6">
                                        ✓ Exportar uma V1 para revisão
                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =============================================
                         VERSÃO V1
                    ============================================== -->

                    <div class="border rounded p-3 mb-4">

                        <h3 class="h6 mb-1">
                            Versão para revisão
                        </h3>

                        <div class="small text-secondary mb-3">
                            Quando terminar a edição no CapCut,
                            informe a versão exportada para seguir
                            para a Etapa 5.
                        </div>


                        <div class="row g-3">


                            <!-- LINK -->

                            <div class="col-md-8">

                                <label class="form-label">
                                    Link da V1 / exportação
                                </label>

                                <input
                                    type="url"
                                    name="link_exportacao"
                                    class="form-control"
                                    placeholder="https://drive.google.com/..."
                                    value="<?= htmlspecialchars(
                                        $edicao['link_exportacao']
                                        ?? ''
                                    ) ?>"
                                >

                                <div class="form-text">
                                    Futuramente poderemos detectar
                                    a exportação automaticamente pelo Drive.
                                </div>

                            </div>


                            <!-- DURAÇÃO -->

                            <div class="col-md-4">

                                <label class="form-label">
                                    Duração final
                                </label>

                                <input
                                    type="text"
                                    name="duracao_final"
                                    class="form-control"
                                    placeholder="Ex.: 08:42"
                                    value="<?php

                                        $duracaoFinalSegundos =
                                            $edicao[
                                                'duracao_final_segundos'
                                            ]
                                            ?? null;

                                        if (
                                            $duracaoFinalSegundos !== null
                                        ) {

                                            $min =
                                                floor(
                                                    $duracaoFinalSegundos
                                                    /
                                                    60
                                                );

                                            $seg =
                                                $duracaoFinalSegundos
                                                %
                                                60;

                                            echo sprintf(
                                                '%02d:%02d',
                                                $min,
                                                $seg
                                            );
                                        }

                                    ?>"
                                >

                            </div>


                            <!-- OBSERVAÇÕES -->

                            <div class="col-md-12">

                                <label class="form-label">
                                    Observações
                                </label>

                                <textarea
                                    name="observacoes"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Opcional: algo importante sobre esta versão?"
                                ><?= htmlspecialchars(
                                    $edicao['observacoes']
                                    ?? ''
                                ) ?></textarea>

                            </div>


                        </div>

                    </div>


                    <!-- =============================================
                         AVANÇADO
                    ============================================== -->

                    <div class="mb-4">

                        <button
                            type="button"
                            class="
                                btn
                                btn-link
                                btn-sm
                                text-secondary
                                p-0
                                text-decoration-none
                            "
                            data-bs-toggle="collapse"
                            data-bs-target="#detalhesAvancadosEdicao"
                        >

                            <i class="bi bi-chevron-down"></i>

                            Informações avançadas

                        </button>


                        <div
                            id="detalhesAvancadosEdicao"
                            class="collapse mt-3"
                        >

                            <div class="row">

                                <div class="col-md-6">

                                    <label class="form-label">
                                        Caminho / pasta do projeto
                                    </label>

                                    <input
                                        type="text"
                                        name="caminho_projeto"
                                        class="form-control"
                                        placeholder="Ex.: D:\BoraPraObra\EP001"
                                        value="<?= htmlspecialchars(
                                            $edicao['caminho_projeto']
                                            ?? ''
                                        ) ?>"
                                    >

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =============================================
                         CAMPOS ANTIGOS OCULTOS
                    ==============================================
                    
                    Mantemos estes campos para compatibilidade
                    com edicao_salvar.php e banco atual.

                    Eles não precisam mais aparecer para o usuário.
                    ============================================== -->

                    <?php

                    $camposChecklistAntigo = [

                        'projeto_criado',
                        'arquivos_importados',
                        'corte_bruto',
                        'narrativa_montada',
                        'broll_aplicado',
                        'audio_tratado',
                        'musica_aplicada',
                        'textos_aplicados',
                        'legendas_aplicadas',
                        'cor_ajustada',
                        'revisao_editor',
                        'exportacao_v1'

                    ];

                    ?>

                    <?php foreach (
                        $camposChecklistAntigo
                        as $campoChecklistAntigo
                    ): ?>

                        <input
                            type="hidden"
                            name="<?= $campoChecklistAntigo ?>"
                            value="<?= !empty(
                                $edicao[$campoChecklistAntigo]
                            )
                                ? '1'
                                : '0'
                            ?>"
                        >

                    <?php endforeach; ?>


                    <!-- =============================================
                         AÇÕES
                    ============================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            flex-wrap
                            gap-3
                            mt-4
                        "
                    >

                        <div
                            id="resultadoEdicao"
                            class="small"
                        ></div>


                        <div class="d-flex gap-2 flex-wrap">

                            <button
                                type="submit"
                                class="btn btn-outline-dark"
                                id="btnSalvarEdicao"
                                data-acao="salvar"
                            >

                                <i class="bi bi-floppy"></i>

                                Salvar

                            </button>


                            <?php if (
                                $statusEdicaoAtual !== 'concluida'
                            ): ?>

                                <button
                                    type="submit"
                                    class="btn btn-dark"
                                    id="btnEnviarRevisao"
                                    data-acao="revisao"
                                >

                                    <i class="bi bi-send"></i>

                                    Enviar V1 para revisão

                                </button>

                            <?php endif; ?>

                        </div>

                    </div>


                </form>


            <?php endif; ?>

        </div>

    </div>

</div>

<!-- =========================================================
     5. REVISÃO — UX 2.0
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoRevisao"
        >

            <strong>
                5. Revisão
            </strong>

            <span class="ms-2">

                <?php if ($versaoAprovada): ?>

                    <span class="badge text-bg-success">

                        <i class="bi bi-check-circle me-1"></i>

                        V<?= (int) $versaoAprovada['versao'] ?>
                        aprovada

                    </span>


                <?php elseif ($totalVersoesEdicao > 0): ?>

                    <?php

                    $statusUltimaVersao =
                        $ultimaVersaoEdicao['status']
                        ?? 'gerada';

                    ?>

                    <?php if (
                        $statusUltimaVersao
                        === 'alteracoes_solicitadas'
                    ): ?>

                        <span class="badge text-bg-warning">

                            <i class="bi bi-arrow-counterclockwise me-1"></i>

                            Ajustes solicitados

                        </span>


                    <?php elseif (
                        $statusUltimaVersao
                        === 'em_revisao'
                    ): ?>

                        <span class="badge text-bg-info">

                            V<?= (int) $ultimaVersaoEdicao['versao'] ?>
                            em revisão

                        </span>


                    <?php else: ?>

                        <span class="badge text-bg-light border">

                            V<?= (int) $ultimaVersaoEdicao['versao'] ?>
                            aguardando revisão

                        </span>

                    <?php endif; ?>


                <?php else: ?>

                    <span class="badge text-bg-light border">
                        Aguardando V1
                    </span>

                <?php endif; ?>

            </span>

        </button>

    </h2>


    <div
        id="secaoRevisao"
        class="accordion-collapse collapse"
        data-bs-parent="#accordionProducao"
    >

        <div class="accordion-body">


            <?php if ($totalVersoesEdicao === 0): ?>


                <!-- =============================================
                     SEM VERSÃO
                ============================================== -->

                <div class="alert alert-light border mb-0">

                    <i class="bi bi-lock me-1"></i>

                    <strong>
                        Nenhuma versão para revisar.
                    </strong>

                    Termine a edição e use o botão

                    <strong>
                        Enviar V1 para revisão
                    </strong>

                    na Etapa 4.

                </div>


            <?php else: ?>


                <!-- =============================================
                     CABEÇALHO
                ============================================== -->

                <div
                    class="
                        d-flex
                        justify-content-between
                        align-items-start
                        flex-wrap
                        gap-3
                        mb-4
                    "
                >

                    <div>

                        <h3 class="h6 mb-1">
                            Revisão das versões
                        </h3>

                        <div class="small text-secondary">
                            Assista à versão completa e escolha:
                            aprovar ou solicitar ajustes.
                        </div>

                    </div>


                    <div class="small text-secondary">

                        <?= (int) $totalVersoesEdicao ?>

                        <?= $totalVersoesEdicao === 1
                            ? 'versão'
                            : 'versões'
                        ?>

                    </div>

                </div>


                <!-- =============================================
                     VERSÕES
                ============================================== -->

                <?php foreach (
                    $versoesEdicao
                    as $indiceVersao => $versao
                ): ?>


                    <?php

                    $versaoNumero =
                        (int) $versao['versao'];

                    $statusVersao =
                        $versao['status']
                        ?? 'gerada';

                    $versaoEstaAprovada =
                        $statusVersao
                        === 'aprovada';

                    ?>


                    <form
                        class="
                            border
                            rounded
                            p-3
                            mb-3
                            form-revisao
                        "
                        data-versao="<?= $versaoNumero ?>"
                    >


                        <input
                            type="hidden"
                            name="capitulo_id"
                            value="<?= (int) $capitulo['id'] ?>"
                        >


                        <input
                            type="hidden"
                            name="versao_id"
                            value="<?= (int) $versao['id'] ?>"
                        >


                        <!-- =====================================
                             CAMPOS ANTIGOS OCULTOS
                        ====================================== -->

                        <?php

                        $itensRevisaoOcultos = [

                            'narrativa_ok',
                            'cortes_ok',
                            'audio_ok',
                            'musica_ok',
                            'broll_ok',
                            'textos_ok',
                            'legendas_ok',
                            'cor_ok'

                        ];

                        ?>


                        <?php foreach (
                            $itensRevisaoOcultos
                            as $campoOculto
                        ): ?>

                            <input
                                type="hidden"
                                name="<?= $campoOculto ?>"
                                class="check-revisao-oculto"
                                value="<?= !empty(
                                    $versao[$campoOculto]
                                )
                                    ? '1'
                                    : '0'
                                ?>"
                            >

                        <?php endforeach; ?>


                        <!-- =====================================
                             CABEÇALHO DA VERSÃO
                        ====================================== -->

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-start
                                flex-wrap
                                gap-3
                                mb-3
                            "
                        >


                            <div>


                                <div
                                    class="
                                        d-flex
                                        align-items-center
                                        gap-2
                                        flex-wrap
                                    "
                                >

                                    <h4 class="h6 mb-0">

                                        Versão
                                        V<?= $versaoNumero ?>

                                    </h4>


                                    <?php if (
                                        $statusVersao
                                        === 'aprovada'
                                    ): ?>

                                        <span
                                            class="
                                                badge
                                                text-bg-success
                                            "
                                        >

                                            <i
                                                class="
                                                    bi
                                                    bi-check-lg
                                                "
                                            ></i>

                                            Aprovada

                                        </span>


                                    <?php elseif (
                                        $statusVersao
                                        === 'alteracoes_solicitadas'
                                    ): ?>

                                        <span
                                            class="
                                                badge
                                                text-bg-warning
                                            "
                                        >

                                            Ajustes solicitados

                                        </span>


                                    <?php elseif (
                                        $statusVersao
                                        === 'em_revisao'
                                    ): ?>

                                        <span
                                            class="
                                                badge
                                                text-bg-info
                                            "
                                        >

                                            Em revisão

                                        </span>


                                    <?php else: ?>

                                        <span
                                            class="
                                                badge
                                                text-bg-light
                                                border
                                            "
                                        >

                                            Aguardando revisão

                                        </span>

                                    <?php endif; ?>


                                </div>


                                <div
                                    class="
                                        small
                                        text-secondary
                                        mt-1
                                    "
                                >

                                    Gerada em

                                    <?= date(
                                        'd/m/Y H:i',
                                        strtotime(
                                            $versao['created_at']
                                        )
                                    ) ?>

                                </div>


                            </div>


                            <?php if (
                                !empty(
                                    $versao['link_arquivo']
                                )
                            ): ?>

                                <a
                                    href="<?= htmlspecialchars(
                                        $versao['link_arquivo']
                                    ) ?>"
                                    target="_blank"
                                    class="btn btn-dark btn-sm"
                                >

                                    <i class="bi bi-play-fill"></i>

                                    Assistir V<?= $versaoNumero ?>

                                </a>

                            <?php endif; ?>


                        </div>


                        <!-- =====================================
                             ORIENTAÇÃO
                        ====================================== -->

                        <?php if (!$versaoEstaAprovada): ?>

                            <div
                                class="
                                    alert
                                    alert-light
                                    border
                                    py-2
                                    mb-3
                                "
                            >

                                <div class="small">

                                    <i
                                        class="
                                            bi
                                            bi-eye
                                            me-1
                                        "
                                    ></i>

                                    Assista ao vídeo do início ao fim.

                                    Se estiver bom, aprove.

                                    Se encontrar algum problema,
                                    anote apenas o que precisa mudar.

                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- =====================================
                             COMENTÁRIOS / AJUSTES
                        ====================================== -->

                        <div class="mb-3">

                            <label
                                class="
                                    form-label
                                    fw-semibold
                                "
                            >

                                <?php if (
                                    $versaoEstaAprovada
                                ): ?>

                                    Observações da revisão

                                <?php else: ?>

                                    O que precisa ser ajustado?

                                <?php endif; ?>

                            </label>


                            <textarea
                                name="comentarios_revisao"
                                class="form-control"
                                rows="4"
                                placeholder="Ex.: 00:32 cortar pausa; 01:18 baixar música; 03:42 inserir imagem da perfuração..."
                                <?= $versaoEstaAprovada
                                    ? 'readonly'
                                    : ''
                                ?>
                            ><?= htmlspecialchars(
                                $versao[
                                    'comentarios_revisao'
                                ]
                                ?? ''
                            ) ?></textarea>


                            <?php if (
                                !$versaoEstaAprovada
                            ): ?>

                                <div class="form-text">
                                    Se a versão estiver boa,
                                    pode deixar este campo vazio
                                    e simplesmente aprovar.
                                </div>

                            <?php endif; ?>


                        </div>


                        <!-- =====================================
                             OBSERVAÇÃO DA EXPORTAÇÃO
                        ====================================== -->

                        <?php if (
                            !empty(
                                $versao['observacoes']
                            )
                        ): ?>

                            <div
                                class="
                                    border
                                    rounded
                                    p-2
                                    small
                                    text-secondary
                                    mb-3
                                "
                            >

                                <strong>
                                    Observação da edição:
                                </strong>

                                <?= nl2br(
                                    htmlspecialchars(
                                        $versao['observacoes']
                                    )
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <!-- =====================================
                             AÇÕES
                        ====================================== -->

                        <?php if (
                            !$versaoEstaAprovada
                        ): ?>

                            <div
                                class="
                                    d-flex
                                    justify-content-between
                                    align-items-center
                                    flex-wrap
                                    gap-3
                                "
                            >


                                <div
                                    class="
                                        resultado-revisao
                                        small
                                    "
                                ></div>


                                <div
                                    class="
                                        d-flex
                                        gap-2
                                        flex-wrap
                                    "
                                >


                                    <button
                                        type="submit"
                                        class="
                                            btn
                                            btn-outline-warning
                                        "
                                        name="acao_revisao"
                                        value="ajustes"
                                        data-acao-revisao="ajustes"
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-arrow-counterclockwise
                                            "
                                        ></i>

                                        Solicitar ajustes

                                    </button>


                                    <button
                                        type="submit"
                                        class="
                                            btn
                                            btn-success
                                        "
                                        name="acao_revisao"
                                        value="aprovar"
                                        data-acao-revisao="aprovar"
                                    >

                                        <i class="bi bi-check-lg"></i>

                                        Aprovar versão

                                    </button>


                                </div>

                            </div>


                        <?php else: ?>


                            <div
                                class="
                                    alert
                                    alert-success
                                    py-2
                                    mb-0
                                "
                            >

                                <i
                                    class="
                                        bi
                                        bi-check-circle
                                        me-1
                                    "
                                ></i>

                                Esta versão foi aprovada

                                <?php if (
                                    !empty(
                                        $versao['aprovado_em']
                                    )
                                ): ?>

                                    em

                                    <?= date(
                                        'd/m/Y H:i',
                                        strtotime(
                                            $versao[
                                                'aprovado_em'
                                            ]
                                        )
                                    ) ?>

                                <?php endif; ?>.

                            </div>


                        <?php endif; ?>


                    </form>


                <?php endforeach; ?>


            <?php endif; ?>


        </div>

    </div>

</div>

<!-- =========================================================
     6. THUMBNAIL — UX 2.0
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoThumbnail"
        >

            <strong>
                6. Thumbnail
            </strong>

            <span class="ms-2">

                <?php if ($thumbnailAprovada): ?>

                    <span class="badge text-bg-success">
                        <i class="bi bi-check-circle me-1"></i>
                        Aprovada
                    </span>

                <?php elseif (!empty($thumbnails)): ?>

                    <span class="badge text-bg-warning">
                        Em produção
                    </span>

                <?php else: ?>

                    <span class="badge text-bg-light border">
                        Não iniciada
                    </span>

                <?php endif; ?>

            </span>

        </button>

    </h2>


    <div
        id="secaoThumbnail"
        class="accordion-collapse collapse"
        data-bs-parent="#accordionProducao"
    >

        <div class="accordion-body">


            <?php if (!$versaoAprovada): ?>

                <!-- =============================================
                     BLOQUEADA
                ============================================== -->

                <div class="alert alert-light border mb-0">

                    <i class="bi bi-lock me-1"></i>

                    <strong>
                        Thumbnail bloqueada.
                    </strong>

                    Aprove uma versão do vídeo na etapa

                    <strong>
                        5. Revisão
                    </strong>

                    antes de produzir a thumbnail.

                </div>


            <?php else: ?>


                <!-- =============================================
                     CABEÇALHO
                ============================================== -->

                <div
                    class="
                        d-flex
                        justify-content-between
                        align-items-start
                        flex-wrap
                        gap-3
                        mb-4
                    "
                >

                    <div>

                        <h3 class="h6 mb-1">
                            Thumbnail do episódio
                        </h3>

                        <div class="small text-secondary">
                            A ideia é deixar a IA preparar as propostas
                            e você apenas escolher, ajustar e aprovar.
                        </div>

                    </div>


                    <button
                        type="button"
                        class="btn btn-dark"
                        id="btnGerarIdeiasThumbnail"
                        disabled
                        title="Integração com IA será adicionada na próxima fase"
                    >

                        <i class="bi bi-stars"></i>

                        Gerar ideias com IA

                        <span class="badge text-bg-light ms-1">
                            Em breve
                        </span>

                    </button>

                </div>


                <!-- =============================================
                     CONTEXTO AUTOMÁTICO
                ============================================== -->

                <div class="border rounded p-3 mb-4">

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            flex-wrap
                            gap-2
                        "
                    >

                        <div>

                            <div class="fw-semibold">
                                Contexto que será usado pela IA
                            </div>

                            <div class="small text-secondary">
                                Estas informações já existem no episódio
                                e não precisam ser digitadas novamente.
                            </div>

                        </div>


                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-bs-toggle="collapse"
                            data-bs-target="#contextoThumbnail"
                        >

                            <i class="bi bi-eye"></i>

                            Ver contexto

                        </button>

                    </div>


                    <div
                        id="contextoThumbnail"
                        class="collapse mt-3"
                    >

                        <div class="row g-3">


                            <div class="col-md-6">

                                <div class="small text-secondary">
                                    Episódio
                                </div>

                                <div class="fw-semibold">

                                    #<?= (int) $capitulo['numero'] ?>

                                    —

                                    <?= htmlspecialchars(
                                        $capitulo['titulo']
                                        ?? ''
                                    ) ?>

                                </div>

                            </div>


                            <?php if (
                                !empty(
                                    $plano['objetivo']
                                )
                            ): ?>

                                <div class="col-md-6">

                                    <div class="small text-secondary">
                                        Objetivo
                                    </div>

                                    <div class="small">

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $plano['objetivo']
                                            )
                                        ) ?>

                                    </div>

                                </div>

                            <?php endif; ?>


                            <?php if (
                                !empty(
                                    $plano['gancho']
                                )
                            ): ?>

                                <div class="col-md-6">

                                    <div class="small text-secondary">
                                        Gancho
                                    </div>

                                    <div class="small">

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $plano['gancho']
                                            )
                                        ) ?>

                                    </div>

                                </div>

                            <?php endif; ?>


                            <?php if (
                                !empty(
                                    $plano['desenvolvimento']
                                )
                            ): ?>

                                <div class="col-md-6">

                                    <div class="small text-secondary">
                                        Desenvolvimento
                                    </div>

                                    <div class="small">

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $plano[
                                                    'desenvolvimento'
                                                ]
                                            )
                                        ) ?>

                                    </div>

                                </div>

                            <?php endif; ?>


                        </div>

                    </div>

                </div>


                <!-- =============================================
                     CRIAR PROPOSTA MANUAL
                     Fica simples enquanto a IA não está ligada.
                ============================================== -->

                <?php if (!$thumbnailAprovada): ?>

                    <form id="formThumbnail">

                        <input
                            type="hidden"
                            name="capitulo_id"
                            value="<?= (int) $capitulo['id'] ?>"
                        >


                        <input
                            type="hidden"
                            name="status"
                            value="gerada"
                        >


                        <div class="border rounded p-3 mb-4">

                            <div
                                class="
                                    d-flex
                                    justify-content-between
                                    align-items-start
                                    flex-wrap
                                    gap-3
                                    mb-3
                                "
                            >

                                <div>

                                    <h3 class="h6 mb-1">
                                        Adicionar proposta
                                    </h3>

                                    <div class="small text-secondary">
                                        Enquanto a geração por IA não está
                                        integrada, basta registrar a ideia
                                        e a imagem produzida.
                                    </div>

                                </div>

                            </div>


                            <div class="row g-3">


                                <!-- CONCEITO -->

                                <div class="col-md-8">

                                    <label class="form-label fw-semibold">
                                        Ideia da thumbnail
                                    </label>

                                    <textarea
                                        name="conceito"
                                        class="form-control"
                                        rows="3"
                                        placeholder="Ex.: terreno no início da obra, poste ao fundo e sensação de começo."
                                    ></textarea>

                                </div>


                                <!-- TEXTO -->

                                <div class="col-md-4">

                                    <label class="form-label fw-semibold">
                                        Texto da thumbnail
                                    </label>

                                    <input
                                        type="text"
                                        name="texto_thumb"
                                        class="form-control"
                                        maxlength="190"
                                        placeholder="Ex.: COMEÇAMOS!"
                                    >

                                    <div class="form-text">
                                        Opcional.
                                    </div>

                                </div>


                                <!-- URL -->

                                <div class="col-md-8">

                                    <label class="form-label fw-semibold">
                                        Imagem produzida
                                    </label>

                                    <input
                                        type="url"
                                        name="arquivo_url"
                                        class="form-control"
                                        placeholder="https://..."
                                    >

                                    <div class="form-text">
                                        Link da imagem no Drive ou outra URL.
                                    </div>

                                </div>


                                <!-- OBSERVAÇÃO -->

                                <div class="col-md-4">

                                    <label class="form-label">
                                        Observação
                                    </label>

                                    <input
                                        type="text"
                                        name="observacoes"
                                        class="form-control"
                                        placeholder="Opcional"
                                    >

                                </div>


                            </div>


                            <!-- =====================================
                                 CAMPOS ANTIGOS OCULTOS
                            ====================================== -->

                            <input
                                type="hidden"
                                name="texto_secundario"
                                value=""
                            >

                            <input
                                type="hidden"
                                name="elementos"
                                value=""
                            >

                            <input
                                type="hidden"
                                name="referencia_visual"
                                value=""
                            >

                            <input
                                type="hidden"
                                name="prompt_ia"
                                id="promptThumb"
                                value=""
                            >


                            <div
                                class="
                                    d-flex
                                    justify-content-between
                                    align-items-center
                                    flex-wrap
                                    gap-3
                                    mt-3
                                "
                            >

                                <div
                                    id="resultadoThumbnail"
                                    class="small"
                                ></div>


                                <button
                                    type="submit"
                                    class="btn btn-dark"
                                >

                                    <i class="bi bi-plus-lg"></i>

                                    Salvar proposta

                                </button>

                            </div>

                        </div>

                    </form>

                <?php endif; ?>


                <!-- =============================================
                     VERSÕES
                ============================================== -->

                <div>

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            flex-wrap
                            gap-2
                            mb-3
                        "
                    >

                        <div>

                            <h3 class="h6 mb-1">
                                Propostas
                            </h3>

                            <div class="small text-secondary">
                                Escolha a melhor thumbnail.
                                As anteriores continuam no histórico.
                            </div>

                        </div>


                        <?php if (!empty($thumbnails)): ?>

                            <div class="small text-secondary">

                                <?= count($thumbnails) ?>

                                <?= count($thumbnails) === 1
                                    ? 'proposta'
                                    : 'propostas'
                                ?>

                            </div>

                        <?php endif; ?>

                    </div>


                    <?php if (!$thumbnails): ?>


                        <div class="alert alert-light border mb-0">

                            <i class="bi bi-image me-1"></i>

                            Nenhuma proposta criada ainda.

                        </div>


                    <?php else: ?>


                        <div class="row g-3">


                            <?php foreach (
                                $thumbnails
                                as $thumb
                            ): ?>


                                <?php

                                $statusThumb =
                                    $thumb['status']
                                    ?? 'ideia';

                                ?>


                                <div class="col-md-6 col-xl-4">

                                    <div
                                        class="
                                            border
                                            rounded
                                            p-3
                                            h-100
                                            <?= $statusThumb === 'aprovada'
                                                ? 'border-success'
                                                : ''
                                            ?>
                                        "
                                    >


                                        <!-- CABEÇALHO -->

                                        <div
                                            class="
                                                d-flex
                                                justify-content-between
                                                align-items-start
                                                gap-2
                                                mb-3
                                            "
                                        >

                                            <div>

                                                <div class="fw-bold">

                                                    V<?= (int) $thumb['versao'] ?>

                                                </div>


                                                <div
                                                    class="
                                                        small
                                                        text-secondary
                                                    "
                                                >

                                                    <?= date(
                                                        'd/m/Y H:i',
                                                        strtotime(
                                                            $thumb[
                                                                'created_at'
                                                            ]
                                                        )
                                                    ) ?>

                                                </div>

                                            </div>


                                            <?php if (
                                                $statusThumb === 'aprovada'
                                            ): ?>

                                                <span
                                                    class="
                                                        badge
                                                        text-bg-success
                                                    "
                                                >

                                                    <i class="bi bi-check-lg"></i>

                                                    Aprovada

                                                </span>


                                            <?php elseif (
                                                $statusThumb === 'descartada'
                                            ): ?>

                                                <span
                                                    class="
                                                        badge
                                                        text-bg-secondary
                                                    "
                                                >
                                                    Descartada
                                                </span>


                                            <?php elseif (
                                                $statusThumb === 'revisao'
                                            ): ?>

                                                <span
                                                    class="
                                                        badge
                                                        text-bg-warning
                                                    "
                                                >
                                                    Revisão
                                                </span>


                                            <?php elseif (
                                                $statusThumb === 'gerada'
                                            ): ?>

                                                <span
                                                    class="
                                                        badge
                                                        text-bg-info
                                                    "
                                                >
                                                    Gerada
                                                </span>


                                            <?php else: ?>

                                                <span
                                                    class="
                                                        badge
                                                        text-bg-light
                                                        border
                                                    "
                                                >
                                                    Ideia
                                                </span>

                                            <?php endif; ?>


                                        </div>


                                        <!-- IMAGEM -->

                                        <?php if (
                                            !empty(
                                                $thumb['arquivo_url']
                                            )
                                        ): ?>

                                            <div class="mb-3">

                                                <img
                                                    src="<?= htmlspecialchars(
                                                        $thumb[
                                                            'arquivo_url'
                                                        ]
                                                    ) ?>"
                                                    class="
                                                        img-fluid
                                                        rounded
                                                        border
                                                    "
                                                    style="
                                                        width:100%;
                                                        aspect-ratio:16/9;
                                                        object-fit:cover;
                                                    "
                                                    alt="Thumbnail V<?= (int) $thumb['versao'] ?>"
                                                    onerror="this.style.display='none'"
                                                >


                                                <a
                                                    href="<?= htmlspecialchars(
                                                        $thumb[
                                                            'arquivo_url'
                                                        ]
                                                    ) ?>"
                                                    target="_blank"
                                                    class="
                                                        btn
                                                        btn-outline-dark
                                                        btn-sm
                                                        mt-2
                                                    "
                                                >

                                                    <i
                                                        class="
                                                            bi
                                                            bi-box-arrow-up-right
                                                        "
                                                    ></i>

                                                    Abrir imagem

                                                </a>

                                            </div>

                                        <?php endif; ?>


                                        <!-- TEXTO -->

                                        <?php if (
                                            !empty(
                                                $thumb['texto_thumb']
                                            )
                                        ): ?>

                                            <div class="mb-2">

                                                <div
                                                    class="
                                                        small
                                                        text-secondary
                                                    "
                                                >
                                                    Texto
                                                </div>

                                                <div class="fw-semibold">

                                                    <?= htmlspecialchars(
                                                        $thumb[
                                                            'texto_thumb'
                                                        ]
                                                    ) ?>

                                                </div>

                                            </div>

                                        <?php endif; ?>


                                        <!-- CONCEITO -->

                                        <?php if (
                                            !empty(
                                                $thumb['conceito']
                                            )
                                        ): ?>

                                            <div class="small mb-3">

                                                <?= nl2br(
                                                    htmlspecialchars(
                                                        $thumb[
                                                            'conceito'
                                                        ]
                                                    )
                                                ) ?>

                                            </div>

                                        <?php endif; ?>


                                        <!-- OBSERVAÇÕES -->

                                        <?php if (
                                            !empty(
                                                $thumb['observacoes']
                                            )
                                        ): ?>

                                            <div
                                                class="
                                                    small
                                                    text-secondary
                                                    border-top
                                                    pt-2
                                                    mt-2
                                                "
                                            >

                                                <?= nl2br(
                                                    htmlspecialchars(
                                                        $thumb[
                                                            'observacoes'
                                                        ]
                                                    )
                                                ) ?>

                                            </div>

                                        <?php endif; ?>


                                        <!-- AÇÕES -->

                                        <?php if (
                                            $statusThumb !== 'aprovada'
                                        ): ?>

                                            <div
                                                class="
                                                    d-flex
                                                    gap-2
                                                    mt-3
                                                    flex-wrap
                                                "
                                            >

                                                <button
                                                    type="button"
                                                    class="
                                                        btn
                                                        btn-success
                                                        btn-sm
                                                        btn-thumb-acao
                                                    "
                                                    data-id="<?= (int) $thumb['id'] ?>"
                                                    data-acao="aprovar"
                                                >

                                                    <i class="bi bi-check-lg"></i>

                                                    Aprovar

                                                </button>


                                                <?php if (
                                                    $statusThumb !== 'descartada'
                                                ): ?>

                                                    <button
                                                        type="button"
                                                        class="
                                                            btn
                                                            btn-outline-secondary
                                                            btn-sm
                                                            btn-thumb-acao
                                                        "
                                                        data-id="<?= (int) $thumb['id'] ?>"
                                                        data-acao="descartar"
                                                    >

                                                        <i class="bi bi-x-lg"></i>

                                                        Descartar

                                                    </button>

                                                <?php endif; ?>


                                            </div>

                                        <?php endif; ?>


                                    </div>

                                </div>


                            <?php endforeach; ?>


                        </div>


                    <?php endif; ?>


                </div>


            <?php endif; ?>


        </div>

    </div>

</div>
<!-- =========================================================
     7. PUBLICAÇÃO / YOUTUBE — UX 2.0
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoPublicacao"
        >

            <strong>
                7. Publicação / YouTube
            </strong>

            <span class="ms-2">

                <?php

                $statusPublicacaoAtual =
                    $publicacao['status_publicacao']
                    ?? 'nao_iniciada';

                ?>

                <?php if (
                    $statusPublicacaoAtual === 'publicada'
                ): ?>

                    <span class="badge text-bg-success">
                        <i class="bi bi-check-circle me-1"></i>
                        Publicada
                    </span>

                <?php elseif (
                    $statusPublicacaoAtual === 'agendada'
                ): ?>

                    <span class="badge text-bg-info">
                        <i class="bi bi-calendar-check me-1"></i>
                        Agendada
                    </span>

                <?php elseif (
                    $statusPublicacaoAtual === 'preparando'
                ): ?>

                    <span class="badge text-bg-warning">
                        Preparando
                    </span>

                <?php else: ?>

                    <span class="badge text-bg-light border">
                        Não iniciada
                    </span>

                <?php endif; ?>

            </span>

        </button>

    </h2>


    <div
        id="secaoPublicacao"
        class="accordion-collapse collapse"
        data-bs-parent="#accordionProducao"
    >

        <div class="accordion-body">


            <?php if (!$publicacaoLiberada): ?>

                <!-- =============================================
                     BLOQUEADA
                ============================================== -->

                <div class="alert alert-light border mb-0">

                    <i class="bi bi-lock me-1"></i>

                    <strong>
                        Publicação bloqueada.
                    </strong>

                    Aprove a thumbnail na etapa

                    <strong>
                        6. Thumbnail
                    </strong>

                    antes de preparar o episódio para o YouTube.

                </div>


            <?php else: ?>


                <form id="formPublicacao">

                    <input
                        type="hidden"
                        name="capitulo_id"
                        value="<?= (int) $capitulo['id'] ?>"
                    >


                    <!-- =============================================
                         CABEÇALHO
                    ============================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-start
                            flex-wrap
                            gap-3
                            mb-4
                        "
                    >

                        <div>

                            <h3 class="h6 mb-1">
                                Preparar publicação
                            </h3>

                            <div class="small text-secondary">
                                Vídeo e thumbnail estão aprovados.
                                Agora prepare apenas as informações
                                necessárias para publicar.
                            </div>

                        </div>


                        <button
                            type="button"
                            class="btn btn-outline-dark btn-sm"
                            id="btnGerarMetadadosIA"
                            disabled
                            title="Integração com IA será adicionada depois"
                        >

                            <i class="bi bi-stars"></i>

                            Preparar com IA

                            <span class="badge text-bg-light ms-1">
                                Em breve
                            </span>

                        </button>

                    </div>


                    <!-- =============================================
                         PACOTE PRONTO
                    ============================================== -->

                    <div class="border rounded p-3 mb-4">

                        <div class="fw-semibold mb-3">
                            Pacote do episódio
                        </div>


                        <div class="row g-3">


                            <!-- VÍDEO -->

                            <div class="col-md-6">

                                <div
                                    class="
                                        d-flex
                                        align-items-center
                                        gap-3
                                    "
                                >

                                    <div
                                        class="
                                            rounded-circle
                                            bg-success-subtle
                                            text-success
                                            d-flex
                                            align-items-center
                                            justify-content-center
                                            flex-shrink-0
                                        "
                                        style="
                                            width:42px;
                                            height:42px;
                                        "
                                    >

                                        <i class="bi bi-play-fill"></i>

                                    </div>


                                    <div>

                                        <div class="small text-secondary">
                                            Vídeo
                                        </div>

                                        <div class="fw-semibold">

                                            V<?= (int) $versaoAprovada['versao'] ?>
                                            aprovada

                                        </div>


                                        <?php if (
                                            !empty(
                                                $versaoAprovada[
                                                    'link_arquivo'
                                                ]
                                            )
                                        ): ?>

                                            <a
                                                href="<?= htmlspecialchars(
                                                    $versaoAprovada[
                                                        'link_arquivo'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                                class="small"
                                            >
                                                Assistir vídeo
                                            </a>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>


                            <!-- THUMBNAIL -->

                            <div class="col-md-6">

                                <div
                                    class="
                                        d-flex
                                        align-items-center
                                        gap-3
                                    "
                                >

                                    <div
                                        class="
                                            rounded-circle
                                            bg-success-subtle
                                            text-success
                                            d-flex
                                            align-items-center
                                            justify-content-center
                                            flex-shrink-0
                                        "
                                        style="
                                            width:42px;
                                            height:42px;
                                        "
                                    >

                                        <i class="bi bi-image"></i>

                                    </div>


                                    <div>

                                        <div class="small text-secondary">
                                            Thumbnail
                                        </div>

                                        <div class="fw-semibold">

                                            V<?= (int) $thumbnailAprovada['versao'] ?>
                                            aprovada

                                        </div>


                                        <?php if (
                                            !empty(
                                                $thumbnailAprovada[
                                                    'arquivo_url'
                                                ]
                                            )
                                        ): ?>

                                            <a
                                                href="<?= htmlspecialchars(
                                                    $thumbnailAprovada[
                                                        'arquivo_url'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                                class="small"
                                            >
                                                Abrir thumbnail
                                            </a>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>


                        </div>

                    </div>


                    <!-- =============================================
                         METADADOS
                    ============================================== -->

                    <div class="mb-4">

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-center
                                flex-wrap
                                gap-2
                                border-bottom
                                pb-2
                                mb-3
                            "
                        >

                            <h3 class="h6 mb-0">
                                Informações do vídeo
                            </h3>


                            <span class="small text-secondary">

                                <i class="bi bi-stars me-1"></i>

                                Futuramente preenchidas pela IA

                            </span>

                        </div>


                        <div class="row g-3">


                            <!-- TÍTULO -->

                            <div class="col-md-12">

                                <label class="form-label fw-semibold">
                                    Título
                                </label>

                                <input
                                    type="text"
                                    name="titulo_publico"
                                    class="form-control"
                                    maxlength="255"
                                    value="<?= htmlspecialchars(
                                        $capitulo[
                                            'titulo_publico'
                                        ]
                                        ?? ''
                                    ) ?>"
                                    placeholder="Título que aparecerá no YouTube"
                                >

                            </div>


                            <!-- DESCRIÇÃO -->

                            <div class="col-md-12">

                                <label class="form-label fw-semibold">
                                    Descrição
                                </label>

                                <textarea
                                    name="descricao"
                                    class="form-control"
                                    rows="6"
                                    placeholder="Descrição do episódio"
                                ><?= htmlspecialchars(
                                    $capitulo['descricao']
                                    ?? ''
                                ) ?></textarea>

                            </div>


                            <!-- TAGS -->

                            <div class="col-md-12">

                                <label class="form-label">
                                    Tags / palavras-chave
                                </label>

                                <textarea
                                    name="tags"
                                    class="form-control"
                                    rows="2"
                                    placeholder="obra, construção, casa..."
                                ><?= htmlspecialchars(
                                    $capitulo['tags']
                                    ?? ''
                                ) ?></textarea>

                            </div>


                        </div>

                    </div>


                    <!-- =============================================
                         PUBLICAR
                    ============================================== -->

                    <div class="border rounded p-3 mb-4">

                        <h3 class="h6 mb-1">
                            Publicação
                        </h3>

                        <div class="small text-secondary mb-3">
                            Depois de subir o vídeo no YouTube,
                            registre aqui o estado da publicação.
                        </div>


                        <div class="row g-3">


                            <!-- STATUS -->

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">
                                    Status
                                </label>

                                <select
                                    name="status_publicacao"
                                    id="statusPublicacao"
                                    class="form-select"
                                >

                                    <option
                                        value="nao_iniciada"
                                        <?= $statusPublicacaoAtual
                                            === 'nao_iniciada'
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Não iniciada
                                    </option>


                                    <option
                                        value="preparando"
                                        <?= $statusPublicacaoAtual
                                            === 'preparando'
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Preparando
                                    </option>


                                    <option
                                        value="agendada"
                                        <?= $statusPublicacaoAtual
                                            === 'agendada'
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Agendada
                                    </option>


                                    <option
                                        value="publicada"
                                        <?= $statusPublicacaoAtual
                                            === 'publicada'
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        Publicada
                                    </option>

                                </select>

                            </div>


                            <!-- DATA -->

                            <div class="col-md-4">

                                <label class="form-label">
                                    Data/hora
                                </label>

                                <input
                                    type="datetime-local"
                                    name="data_agendada"
                                    class="form-control"
                                    value="<?php

                                        if (
                                            !empty(
                                                $publicacao[
                                                    'data_agendada'
                                                ]
                                            )
                                        ) {

                                            echo date(
                                                'Y-m-d\TH:i',
                                                strtotime(
                                                    $publicacao[
                                                        'data_agendada'
                                                    ]
                                                )
                                            );
                                        }

                                    ?>"
                                >

                            </div>


                            <!-- URL -->

                            <div class="col-md-4">

                                <label class="form-label">
                                    Link do YouTube
                                </label>

                                <input
                                    type="url"
                                    name="youtube_url"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                        $capitulo[
                                            'youtube_url'
                                        ]
                                        ?? ''
                                    ) ?>"
                                    placeholder="https://youtube.com/watch?v=..."
                                >

                            </div>


                        </div>

                    </div>


                    <!-- =============================================
                         DETALHES AVANÇADOS
                    ============================================== -->

                    <div class="mb-4">

                        <button
                            type="button"
                            class="
                                btn
                                btn-link
                                btn-sm
                                text-secondary
                                p-0
                                text-decoration-none
                            "
                            data-bs-toggle="collapse"
                            data-bs-target="#detalhesPublicacao"
                        >

                            <i class="bi bi-chevron-down"></i>

                            Informações avançadas

                        </button>


                        <div
                            id="detalhesPublicacao"
                            class="collapse mt-3"
                        >

                            <div class="border rounded p-3">

                                <div class="row g-3">


                                    <!-- VIDEO ID -->

                                    <div class="col-md-6">

                                        <label class="form-label">
                                            YouTube Video ID
                                        </label>

                                        <input
                                            type="text"
                                            name="youtube_video_id"
                                            class="form-control"
                                            value="<?= htmlspecialchars(
                                                $publicacao[
                                                    'youtube_video_id'
                                                ]
                                                ?? ''
                                            ) ?>"
                                            placeholder="Ex.: dQw4w9WgXcQ"
                                        >

                                        <div class="form-text">
                                            Futuramente será identificado
                                            automaticamente.
                                        </div>

                                    </div>


                                    <!-- OBSERVAÇÕES -->

                                    <div class="col-md-6">

                                        <label class="form-label">
                                            Observações
                                        </label>

                                        <textarea
                                            name="observacoes"
                                            class="form-control"
                                            rows="2"
                                            placeholder="Somente se houver algo importante."
                                        ><?= htmlspecialchars(
                                            $publicacao[
                                                'observacoes'
                                            ]
                                            ?? ''
                                        ) ?></textarea>

                                    </div>


                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- =============================================
                         CHECKLIST ANTIGO OCULTO
                         Mantém compatibilidade com o backend.
                    ============================================== -->

                    <?php

                    $tarefasPublicacaoOcultas = [

                        'titulo_revisado',
                        'descricao_revisada',
                        'thumbnail_aplicada',
                        'tags_revisadas',
                        'cards_conferidos',
                        'tela_final_conferida',
                        'publicacao_conferida'

                    ];

                    ?>


                    <?php foreach (
                        $tarefasPublicacaoOcultas
                        as $campoPublicacaoOculto
                    ): ?>

                        <input
                            type="hidden"
                            name="<?= $campoPublicacaoOculto ?>"
                            value="<?= !empty(
                                $publicacao[
                                    $campoPublicacaoOculto
                                ]
                            )
                                ? '1'
                                : '0'
                            ?>"
                        >

                    <?php endforeach; ?>


                    <!-- =============================================
                         AÇÃO
                    ============================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            flex-wrap
                            gap-3
                        "
                    >

                        <div
                            id="resultadoPublicacao"
                            class="small"
                        ></div>


                        <button
                            type="submit"
                            class="btn btn-dark"
                        >

                            <i class="bi bi-floppy"></i>

                            Salvar publicação

                        </button>

                    </div>


                </form>


            <?php endif; ?>


        </div>

    </div>

</div>
<!-- =========================================================
     8. SHORTS / REELS — UX 2.0
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

        <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#secaoShorts"
        >

            <strong>
                8. Shorts / Reels
            </strong>

            <span class="ms-2">

                <?php if ($cortesPublicados > 0): ?>

                    <span class="badge text-bg-success">

                        <?= $cortesPublicados ?>
                        publicado(s)

                    </span>

                <?php elseif ($totalCortesCurtos > 0): ?>

                    <span class="badge text-bg-warning">

                        <?= $totalCortesCurtos ?>
                        corte(s)

                    </span>

                <?php else: ?>

                    <span class="badge text-bg-light border">
                        Não iniciado
                    </span>

                <?php endif; ?>

            </span>

        </button>

    </h2>


    <div
        id="secaoShorts"
        class="accordion-collapse collapse"
        data-bs-parent="#accordionProducao"
    >

        <div class="accordion-body">


            <!-- =====================================================
                 CABEÇALHO
            ====================================================== -->

            <div
                class="
                    d-flex
                    justify-content-between
                    align-items-start
                    flex-wrap
                    gap-3
                    mb-4
                "
            >

                <div>

                    <h3 class="h6 mb-1">
                        Conteúdos curtos
                    </h3>

                    <div class="small text-secondary">
                        Transforme os melhores momentos do episódio
                        em Shorts, Reels e TikTok.
                    </div>

                </div>


                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    id="btnGerarRoteirosShortsIA"
                    data-capitulo-id="<?= (int) $capitulo['id'] ?>"
                >
                
                    <i class="bi bi-stars me-1"></i>
                
                    Gerar roteiros de Shorts com IA
                
                </button>

            </div>


            <!-- =====================================================
                 RESUMO SIMPLES
            ====================================================== -->

            <div class="row g-3 mb-4">


                <div class="col-md-4">

                    <div class="border rounded p-3 h-100">

                        <div class="small text-secondary">
                            Cortes
                        </div>

                        <div class="fs-4 fw-bold">
                            <?= $totalCortesCurtos ?>
                        </div>

                        <div class="small text-secondary">
                            cadastrados
                        </div>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3 h-100">

                        <div class="small text-secondary">
                            Em produção
                        </div>

                        <div class="fs-4 fw-bold">

                            <?= $cortesEmEdicao ?>

                        </div>

                        <div class="small text-secondary">
                            em edição
                        </div>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3 h-100">

                        <div class="small text-secondary">
                            Publicados
                        </div>

                        <div class="fs-4 fw-bold text-success">

                            <?= $cortesPublicados ?>

                        </div>

                        <div class="small text-secondary">

                            <?= $publicadosYoutube ?> YouTube

                            •

                            <?= $publicadosInstagram ?> Instagram

                            •

                            <?= $publicadosTiktok ?> TikTok

                        </div>

                    </div>

                </div>


            </div>


            <!-- =====================================================
                 NOVO CORTE
            ====================================================== -->

            <form id="formCorteCurto">

                <input
                    type="hidden"
                    name="capitulo_id"
                    value="<?= (int) $capitulo['id'] ?>"
                >
                
                <input
                    type="hidden"
                    name="corte_id"
                    id="corteId"
                    value=""
                >
                
                <input
                    type="hidden"
                    name="youtube_url"
                    id="corteYoutubeUrl"
                    value=""
                >
                
                <input
                    type="hidden"
                    name="instagram_url"
                    id="corteInstagramUrl"
                    value=""
                >
                
                <input
                    type="hidden"
                    name="tiktok_url"
                    id="corteTiktokUrl"
                    value=""
                >


                <!-- =================================================
                     DADOS PRINCIPAIS
                ================================================== -->

                <div class="border rounded p-3 mb-4">

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-start
                            flex-wrap
                            gap-3
                            mb-3
                        "
                    >

                        <div>

                            <h3
                                class="h6 mb-1"
                                id="tituloFormCorte"
                            >
                                Novo corte
                            </h3>

                            <div class="small text-secondary">
                                Marque o trecho que pode virar
                                conteúdo curto.
                            </div>

                        </div>

                    </div>


                    <div class="row g-3">


                        <!-- ARQUIVO -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Vídeo de origem
                            </label>

                            <select
                                name="arquivo_origem_id"
                                class="form-select"
                            >

                                <option value="">
                                    Selecione um vídeo
                                </option>


                                <?php foreach (
                                    $arquivos
                                    as $arquivoCorte
                                ): ?>

                                    <option
                                        value="<?= (int) $arquivoCorte['id'] ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $arquivoCorte[
                                                'nome_arquivo'
                                            ]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- TÍTULO -->

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Ideia do corte
                            </label>

                            <input
                                type="text"
                                name="titulo"
                                class="form-control"
                                maxlength="255"
                                placeholder="Ex.: O erro que quase atrasou a obra"
                            >

                        </div>


                        <!-- INÍCIO -->

                        <div class="col-md-3">

                            <label class="form-label fw-semibold">
                                Início
                            </label>

                            <input
                                type="text"
                                name="inicio"
                                class="form-control"
                                placeholder="00:00"
                            >

                        </div>


                        <!-- FIM -->

                        <div class="col-md-3">

                            <label class="form-label fw-semibold">
                                Fim
                            </label>

                            <input
                                type="text"
                                name="fim"
                                class="form-control"
                                placeholder="00:45"
                            >

                        </div>


                        <!-- STATUS -->

                        <div class="col-md-3">

                            <label class="form-label">
                                Status
                            </label>

                            <select
                                name="status"
                                class="form-select"
                            >

                                <option value="ideia">
                                    Ideia
                                </option>

                                <option value="selecionado">
                                    Selecionado
                                </option>

                                <option value="editando">
                                    Em edição
                                </option>

                                <option value="pronto">
                                    Pronto
                                </option>

                                <option value="publicado">
                                    Publicado
                                </option>

                            </select>

                        </div>


                        <!-- FORMATO OCULTO/DEFAULT -->

                        <div class="col-md-3">

                            <label class="form-label">
                                Formato
                            </label>

                            <select
                                name="formato"
                                class="form-select"
                            >

                                <option value="9:16">
                                    Vertical 9:16
                                </option>

                                <option value="1:1">
                                    Quadrado 1:1
                                </option>

                                <option value="16:9">
                                    Horizontal 16:9
                                </option>

                            </select>

                        </div>


                    </div>


                    <!-- =================================================
                         DETALHES OPCIONAIS
                    ================================================== -->

                    <div class="mt-3">

                        <button
                            type="button"
                            class="
                                btn
                                btn-link
                                btn-sm
                                text-secondary
                                text-decoration-none
                                p-0
                            "
                            data-bs-toggle="collapse"
                            data-bs-target="#detalhesCorteCurto"
                        >

                            <i class="bi bi-chevron-down"></i>

                            Detalhes opcionais

                        </button>

                    </div>


                    <div
                        id="detalhesCorteCurto"
                        class="collapse mt-3"
                    >

                        <div class="row g-3">


                            <!-- GANCHO -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    Gancho
                                </label>

                                <textarea
                                    name="gancho"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Primeira fala ou imagem forte do corte."
                                ></textarea>

                            </div>


                            <!-- LEGENDA -->

                            <div class="col-md-6">

                                <label class="form-label">
                                    Legenda / copy
                                </label>

                                <textarea
                                    name="legenda"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Futuramente poderá ser criada automaticamente pela IA."
                                ></textarea>

                            </div>


                            <!-- INSTRUÇÃO -->

                            <div class="col-md-8">

                                <label class="form-label">
                                    Instrução de edição
                                </label>

                                <textarea
                                    name="instrucao_edicao"
                                    class="form-control"
                                    rows="2"
                                    placeholder="Somente se houver alguma orientação especial."
                                ></textarea>

                            </div>


                            <!-- DURAÇÃO -->

                            <div class="col-md-4">

                                <label class="form-label">
                                    Duração final
                                </label>

                                <input
                                    type="text"
                                    name="duracao_final"
                                    class="form-control"
                                    placeholder="00:40"
                                >

                            </div>


                        </div>

                    </div>


                    <!-- =================================================
                         AÇÕES
                    ================================================== -->

                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            flex-wrap
                            gap-3
                            mt-4
                        "
                    >

                        <div
                            id="resultadoCorteCurto"
                            class="small me-auto"
                        ></div>


                        <div class="d-flex gap-2">


                            <button
                                type="button"
                                class="
                                    btn
                                    btn-outline-secondary
                                    d-none
                                "
                                id="btnCancelarEdicaoCorte"
                            >
                                Cancelar edição
                            </button>


                            <button
                                type="submit"
                                class="btn btn-dark"
                                id="btnSalvarCorte"
                            >

                                <i class="bi bi-plus-lg"></i>

                                Salvar corte

                            </button>


                        </div>

                    </div>

                </div>

            </form>
            
            <!-- =====================================================
                 EXPLICAÇÃO
            ====================================================== -->

            <div class="alert alert-light border mb-4">

                <div class="fw-semibold mb-1">

                    Como vai funcionar

                </div>

                <div class="small text-secondary">

                    A IA não vai simplesmente resumir o episódio.
                    Ela vai procurar assuntos reais dentro das
                    transcrições e criar oportunidades independentes
                    de Shorts.

                    <br><br>

                    Cada roteiro seguirá a estrutura:

                    <strong>
                        Vinheta → Assunto → Custo da etapa →
                        Execução → Fechamento → Custo acumulado → CTA.
                    </strong>

                </div>

            </div>
            
<!-- =====================================================
     RESULTADO DA IA
===================================================== -->
            <div
                id="resultadoRoteirosShortsIA"
                class="mb-3"
            ></div>


            <!-- =====================================================
                 RESULTADOS
            ====================================================== -->

            <?php
            $stmtShortsCap = $pdo->prepare(
                '
                SELECT id, titulo, tema, duracao_alvo_segundos,
                       status, narracao_status, roteiro_narracao
                FROM capitulo_short_roteiros
                WHERE capitulo_id = ?
                ORDER BY id ASC
                '
            );
            $stmtShortsCap->execute([(int) $capitulo['id']]);
            $shortsDoCapitulo = $stmtShortsCap->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div
                id="listaRoteirosShorts"
            >

                <?php if (!$shortsDoCapitulo): ?>

                <div class="border rounded p-4 text-center">

                    <div class="text-secondary">

                        <i
                            class="
                                bi
                                bi-stars
                                fs-2
                                d-block
                                mb-2
                            "
                        ></i>

                        Nenhum roteiro gerado ainda.

                    </div>

                    <div class="small text-secondary mt-1">

                        Clique em
                        <strong>
                            Gerar roteiros com IA
                        </strong>
                        para analisar este capítulo.

                    </div>

                </div>

                <?php else: ?>

                <div class="row g-3">
                    <?php foreach ($shortsDoCapitulo as $s): ?>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100 d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <strong class="small"><?= htmlspecialchars($s['titulo']) ?></strong>
                                    <span class="badge text-bg-light border text-nowrap">
                                        <?= (int) ($s['duracao_alvo_segundos'] ?? 0) ?>s
                                    </span>
                                </div>
                                <?php if (!empty($s['tema'])): ?>
                                    <div class="small text-secondary mt-1"><?= htmlspecialchars($s['tema']) ?></div>
                                <?php endif; ?>
                                <p class="small mt-2 mb-2 flex-grow-1">
                                    <?= htmlspecialchars(mb_substr((string) $s['roteiro_narracao'], 0, 140)) ?><?= mb_strlen((string) $s['roteiro_narracao']) > 140 ? '…' : '' ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge text-bg-light border">
                                        narração: <?= htmlspecialchars($s['narracao_status'] ?: 'sem_narracao') ?>
                                    </span>
                                    <a href="short_narracao.php?id=<?= (int) $s['id'] ?>" class="btn btn-sm btn-outline-dark">
                                        Narração e corte
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>

            </div>

        


            <!-- =====================================================
                 CORTES
            ====================================================== -->

            <div>


                <div
                    class="
                        d-flex
                        justify-content-between
                        align-items-center
                        flex-wrap
                        gap-2
                        mb-3
                    "
                >

                    <div>

                        <h3 class="h6 mb-1">
                            Cortes do episódio
                        </h3>

                        <div class="small text-secondary">
                            Edite, publique e acompanhe cada conteúdo.
                        </div>

                    </div>

                </div>


                <?php if (!$cortesCurtos): ?>


                    <div class="alert alert-light border mb-0">

                        <i class="bi bi-scissors me-1"></i>

                        Nenhum corte cadastrado ainda.

                    </div>


                <?php else: ?>


                    <div class="row g-3">


                        <?php foreach (
                            $cortesCurtos
                            as $corte
                        ): ?>


                            <?php

                            $inicioMs =
                                (int) (
                                    $corte['inicio_ms']
                                    ?? 0
                                );

                            $fimMs =
                                (int) (
                                    $corte['fim_ms']
                                    ?? 0
                                );


                            $formatarTempoCorte =
                                function ($ms) {

                                    if ($ms <= 0) {
                                        return '00:00';
                                    }

                                    $seg =
                                        (int) floor(
                                            $ms / 1000
                                        );

                                    return sprintf(
                                        '%02d:%02d',
                                        floor($seg / 60),
                                        $seg % 60
                                    );
                                };


                            $statusCorte =
                                $corte['status']
                                ?? 'ideia';

                            ?>


                            <div class="col-md-6 col-xl-4">


                                <div
                                    class="
                                        border
                                        rounded
                                        p-3
                                        h-100
                                    "
                                >


                                    <!-- CABEÇALHO -->

                                    <div
                                        class="
                                            d-flex
                                            justify-content-between
                                            align-items-start
                                            gap-2
                                            mb-3
                                        "
                                    >


                                        <div>

                                            <div class="fw-semibold">

                                                <?= htmlspecialchars(
                                                    $corte['titulo']
                                                    ?: 'Corte sem título'
                                                ) ?>

                                            </div>


                                            <div
                                                class="
                                                    small
                                                    text-secondary
                                                    mt-1
                                                "
                                            >

                                                <?= $formatarTempoCorte(
                                                    $inicioMs
                                                ) ?>

                                                →

                                                <?= $formatarTempoCorte(
                                                    $fimMs
                                                ) ?>

                                                <span class="mx-1">
                                                    •
                                                </span>

                                                <?= htmlspecialchars(
                                                    $corte['formato']
                                                    ?: '9:16'
                                                ) ?>

                                            </div>

                                        </div>


                                        <?php if (
                                            $statusCorte === 'publicado'
                                        ): ?>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-success
                                                "
                                            >
                                                Publicado
                                            </span>


                                        <?php elseif (
                                            $statusCorte === 'pronto'
                                        ): ?>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-info
                                                "
                                            >
                                                Pronto
                                            </span>


                                        <?php elseif (
                                            $statusCorte === 'editando'
                                        ): ?>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-warning
                                                "
                                            >
                                                Em edição
                                            </span>


                                        <?php elseif (
                                            $statusCorte === 'selecionado'
                                        ): ?>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-primary
                                                "
                                            >
                                                Selecionado
                                            </span>


                                        <?php else: ?>

                                            <span
                                                class="
                                                    badge
                                                    text-bg-light
                                                    border
                                                "
                                            >
                                                Ideia
                                            </span>

                                        <?php endif; ?>


                                    </div>


                                    <!-- ORIGEM -->

                                    <?php if (
                                        !empty(
                                            $corte[
                                                'arquivo_origem_nome'
                                            ]
                                        )
                                    ): ?>

                                        <div
                                            class="
                                                small
                                                text-secondary
                                                mb-3
                                            "
                                        >

                                            <i
                                                class="
                                                    bi
                                                    bi-camera-video
                                                    me-1
                                                "
                                            ></i>

                                            <?= htmlspecialchars(
                                                $corte[
                                                    'arquivo_origem_nome'
                                                ]
                                            ) ?>


                                            <?php if (
                                                !empty(
                                                    $corte[
                                                        'drive_url'
                                                    ]
                                                )
                                            ): ?>

                                                <a
                                                    href="<?= htmlspecialchars(
                                                        $corte[
                                                            'drive_url'
                                                        ]
                                                    ) ?>"
                                                    target="_blank"
                                                    class="ms-1"
                                                >
                                                    Assistir
                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    <?php endif; ?>


                                    <!-- GANCHO -->

                                    <?php if (
                                        !empty(
                                            $corte['gancho']
                                        )
                                    ): ?>

                                        <div class="small mb-3">

                                            <?= htmlspecialchars(
                                                mb_strimwidth(
                                                    $corte['gancho'],
                                                    0,
                                                    130,
                                                    '...'
                                                )
                                            ) ?>

                                        </div>

                                    <?php endif; ?>


                                    <!-- PLATAFORMAS -->

                                    <div
                                        class="
                                            d-flex
                                            gap-2
                                            flex-wrap
                                            mb-3
                                        "
                                    >


                                        <?php if (
                                            !empty(
                                                $corte['youtube_url']
                                            )
                                        ): ?>

                                            <a
                                                href="<?= htmlspecialchars(
                                                    $corte[
                                                        'youtube_url'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                                class="
                                                    btn
                                                    btn-outline-danger
                                                    btn-sm
                                                "
                                                title="YouTube Shorts"
                                            >

                                                <i class="bi bi-youtube"></i>

                                            </a>

                                        <?php endif; ?>


                                        <?php if (
                                            !empty(
                                                $corte['instagram_url']
                                            )
                                        ): ?>

                                            <a
                                                href="<?= htmlspecialchars(
                                                    $corte[
                                                        'instagram_url'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                                class="
                                                    btn
                                                    btn-outline-dark
                                                    btn-sm
                                                "
                                                title="Instagram Reels"
                                            >

                                                <i class="bi bi-instagram"></i>

                                            </a>

                                        <?php endif; ?>


                                        <?php if (
                                            !empty(
                                                $corte['tiktok_url']
                                            )
                                        ): ?>

                                            <a
                                                href="<?= htmlspecialchars(
                                                    $corte[
                                                        'tiktok_url'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                                class="
                                                    btn
                                                    btn-outline-dark
                                                    btn-sm
                                                "
                                                title="TikTok"
                                            >

                                                <i
                                                    class="
                                                        bi
                                                        bi-music-note-beamed
                                                    "
                                                ></i>

                                            </a>

                                        <?php endif; ?>


                                        <?php if (
                                            empty(
                                                $corte['youtube_url']
                                            )
                                            &&
                                            empty(
                                                $corte['instagram_url']
                                            )
                                            &&
                                            empty(
                                                $corte['tiktok_url']
                                            )
                                        ): ?>

                                            <span
                                                class="
                                                    small
                                                    text-secondary
                                                "
                                            >
                                                Ainda não publicado
                                            </span>

                                        <?php endif; ?>


                                    </div>


                                    <!-- AÇÕES -->

                                    <div
                                        class="
                                            d-flex
                                            justify-content-between
                                            align-items-center
                                            gap-2
                                            mt-auto
                                        "
                                    >


                                        <button
                                            type="button"
                                            class="
                                                btn
                                                btn-outline-dark
                                                btn-sm
                                                btn-editar-corte
                                            "

                                            data-id="<?= (int) $corte['id'] ?>"

                                            data-titulo="<?= htmlspecialchars(
                                                $corte['titulo']
                                                ?? '',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-arquivo="<?= (int) (
                                                $corte[
                                                    'arquivo_origem_id'
                                                ]
                                                ?? 0
                                            ) ?>"

                                            data-inicio="<?= htmlspecialchars(
                                                $formatarTempoCorte(
                                                    (int) (
                                                        $corte[
                                                            'inicio_ms'
                                                        ]
                                                        ?? 0
                                                    )
                                                ),
                                                ENT_QUOTES
                                            ) ?>"

                                            data-fim="<?= htmlspecialchars(
                                                $formatarTempoCorte(
                                                    (int) (
                                                        $corte[
                                                            'fim_ms'
                                                        ]
                                                        ?? 0
                                                    )
                                                ),
                                                ENT_QUOTES
                                            ) ?>"

                                            data-formato="<?= htmlspecialchars(
                                                $corte['formato']
                                                ?? '9:16',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-status="<?= htmlspecialchars(
                                                $corte['status']
                                                ?? 'ideia',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-duracao="<?= htmlspecialchars(
                                                $formatarTempoCorte(
                                                    (int) (
                                                        $corte[
                                                            'duracao_final_ms'
                                                        ]
                                                        ?? 0
                                                    )
                                                ),
                                                ENT_QUOTES
                                            ) ?>"

                                            data-gancho="<?= htmlspecialchars(
                                                $corte['gancho']
                                                ?? '',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-legenda="<?= htmlspecialchars(
                                                $corte['legenda']
                                                ?? '',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-instrucao="<?= htmlspecialchars(
                                                $corte[
                                                    'instrucao_edicao'
                                                ]
                                                ?? '',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-youtube="<?= htmlspecialchars(
                                                $corte['youtube_url']
                                                ?? '',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-instagram="<?= htmlspecialchars(
                                                $corte['instagram_url']
                                                ?? '',
                                                ENT_QUOTES
                                            ) ?>"

                                            data-tiktok="<?= htmlspecialchars(
                                                $corte['tiktok_url']
                                                ?? '',
                                                ENT_QUOTES
                                            ) ?>"
                                        >

                                            <i class="bi bi-pencil"></i>

                                            Editar

                                        </button>


                                        <button
                                            type="button"
                                            class="
                                                btn
                                                btn-outline-danger
                                                btn-sm
                                                btn-excluir-corte
                                            "
                                            data-id="<?= (int) $corte['id'] ?>"
                                            data-capitulo="<?= (int) $capitulo['id'] ?>"
                                            data-titulo="<?= htmlspecialchars(
                                                $corte['titulo']
                                                ?? 'Corte',
                                                ENT_QUOTES
                                            ) ?>"
                                            title="Excluir corte"
                                        >

                                            <i class="bi bi-trash"></i>

                                        </button>


                                    </div>


                                </div>

                            </div>


                        <?php endforeach; ?>


                    </div>


                <?php endif; ?>


            </div>


        </div>

    </div>

</div>


<!-- =========================================================
     9. INFORMAÇÕES
========================================================= -->

<div class="accordion-item mb-3 border rounded">

    <h2 class="accordion-header">

    <button
        class="accordion-button collapsed"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#secaoInformacoes"
    >

        <strong>
            9. Informações do Capítulo
        </strong>

    </button>

</h2>

<div
    id="secaoInformacoes"
    class="accordion-collapse collapse"
    data-bs-parent="#accordionProducao"
>

    <div class="accordion-body">

        <?php

        /*
        |--------------------------------------------------------------------------
        | FASE ATUAL DO CAPÍTULO
        |--------------------------------------------------------------------------
        */

        $faseAtualInfo =
            $capitulo['fase_producao']
            ?? 'brutos';

        $nomesFaseInfo = [
            'brutos' => 'Brutos',
            'triagem' => 'Triagem',
            'triado' => 'Triagem',
            'plano' => 'Plano do episódio',
            'edicao' => 'Edição',
            'revisao' => 'Revisão',
            'thumbnail' => 'Thumbnail',
            'publicacao' => 'Publicação',
            'distribuicao' => 'Distribuição'
        ];

        $nomeFaseInfo =
            $nomesFaseInfo[$faseAtualInfo]
            ?? ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $faseAtualInfo
                )
            );

        ?>

        <div class="row g-3">

            <!-- NÚMERO -->

            <div class="col-md-2">

                <label class="form-label">
                    Número
                </label>

                <input
                    class="form-control"
                    value="<?= (int) (
                        $capitulo['numero']
                        ?? 0
                    ) ?>"
                    readonly
                >

            </div>


            <!-- TÍTULO INTERNO -->

            <div class="col-md-5">

                <label class="form-label">
                    Título interno
                </label>

                <input
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $capitulo['titulo']
                        ?? ''
                    ) ?>"
                    readonly
                >

            </div>


            <!-- TEMPORADA -->

            <div class="col-md-5">

                <label class="form-label">
                    Temporada
                </label>

                <input
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $capitulo['temporada']
                        ?? ''
                    ) ?>"
                    readonly
                >

            </div>


            <!-- PASTA GOOGLE DRIVE -->

            <div class="col-md-5">

                <label class="form-label">
                    Pasta Google Drive
                </label>

                <input
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $capitulo['drive_folder_url']
                        ?? ''
                    ) ?>"
                    readonly
                >

            </div>


            <!-- ÚLTIMA SINCRONIZAÇÃO -->

            <div class="col-md-2">

                <label class="form-label">
                    Última sincronização
                </label>

                <input
                    class="form-control"
                    value="<?= !empty(
                        $capitulo['drive_sync_at']
                    )
                        ? date(
                            'd/m/Y H:i',
                            strtotime(
                                $capitulo['drive_sync_at']
                            )
                        )
                        : 'Nunca'
                    ?>"
                    readonly
                >

            </div>


            <!-- FASE ATUAL -->

            <div class="col-md-2">

                <label class="form-label">
                    Fase atual
                </label>

                <input
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $nomeFaseInfo
                    ) ?>"
                    readonly
                >

            </div>


                    <!-- STATUS DA PUBLICAÇÃO -->

        <div class="col-md-3">

            <label class="form-label">
                Status da publicação
            </label>

            <input
                class="form-control"
                value="<?= htmlspecialchars(
                    ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $publicacao[
                                'status_publicacao'
                            ]
                            ?? 'nao_iniciada'
                        )
                    )
                ) ?>"
                readonly
            >

        </div>


        <!-- INÍCIO DA PRODUÇÃO -->

        <div class="col-md-3">

            <label class="form-label">
                Início da produção
            </label>

            <input
                class="form-control"
                value="<?= !empty(
                    $capitulo['data_inicio_producao']
                )
                    ? date(
                        'd/m/Y H:i',
                        strtotime(
                            $capitulo['data_inicio_producao']
                        )
                    )
                    : 'Não iniciado'
                ?>"
                readonly
            >

        </div>


        <!-- CONCLUSÃO DA PRODUÇÃO -->

        <div class="col-md-3">

            <label class="form-label">
                Conclusão da produção
            </label>

            <input
                class="form-control"
                value="<?= !empty(
                    $capitulo['data_conclusao_producao']
                )
                    ? date(
                        'd/m/Y H:i',
                        strtotime(
                            $capitulo['data_conclusao_producao']
                        )
                    )
                    : 'Em andamento'
                ?>"
                readonly
            >

        </div>


        <!-- DATA DE PUBLICAÇÃO -->

        <div class="col-md-3">

            <label class="form-label">
                Data de publicação
            </label>

            <input
                class="form-control"
                value="<?= !empty(
                    $capitulo['data_publicacao']
                )
                    ? date(
                        'd/m/Y H:i',
                        strtotime(
                            $capitulo['data_publicacao']
                        )
                    )
                    : 'Não definida'
                ?>"
                readonly
            >

        </div>


        <!-- ÚLTIMA ATUALIZAÇÃO -->

        <div class="col-md-3">

            <label class="form-label">
                Última atualização
            </label>

            <input
                class="form-control"
                value="<?= !empty(
                    $capitulo['updated_at']
                )
                    ? date(
                        'd/m/Y H:i',
                        strtotime(
                            $capitulo['updated_at']
                        )
                    )
                    : ''
                ?>"
                readonly
            >

        </div>

    </div>


    <!-- AÇÕES -->
        <div class="mt-3">

            <a
                href="capitulo_form.php?id=<?= (int) (
                    $capitulo['id']
                    ?? 0
                ) ?>"
                class="btn btn-outline-dark btn-sm"
            >

                <i class="bi bi-pencil"></i>

                Edição administrativa

            </a>

        </div>

    </div>

</div>



<!-- =========================================================
     JAVASCRIPT TRIAGEM — UX 2.0 / AUTOSAVE
========================================================= -->

<script>

function abrirTriagem() {

    const elemento =
        document.getElementById(
            'secaoTriagem'
        );

    if (!elemento) {
        return;
    }

    const collapse =
        bootstrap.Collapse.getOrCreateInstance(
            elemento,
            {
                toggle: false
            }
        );

    collapse.show();

    elemento.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
}


/*
|--------------------------------------------------------------------------
| ALTERAR VISUAL DO BOTÃO
|--------------------------------------------------------------------------
*/

function aplicarVisualDecisao(
    form,
    decisao
) {

    form
        .querySelectorAll('.decisao-btn')
        .forEach(function (botao) {

            botao.classList.remove(
                'active',
                'btn-success',
                'btn-warning',
                'btn-danger',
                'btn-outline-success',
                'btn-outline-warning',
                'btn-outline-danger'
            );


            const tipo =
                botao.dataset.decisao;


            if (tipo === decisao) {

                if (tipo === 'usar') {

                    botao.classList.add(
                        'btn-success',
                        'active'
                    );

                } else if (tipo === 'parcial') {

                    botao.classList.add(
                        'btn-warning',
                        'active'
                    );

                } else if (tipo === 'descartar') {

                    botao.classList.add(
                        'btn-danger',
                        'active'
                    );
                }

            } else {

                if (tipo === 'usar') {

                    botao.classList.add(
                        'btn-outline-success'
                    );

                } else if (tipo === 'parcial') {

                    botao.classList.add(
                        'btn-outline-warning'
                    );

                } else if (tipo === 'descartar') {

                    botao.classList.add(
                        'btn-outline-danger'
                    );
                }
            }

        });
}


/*
|--------------------------------------------------------------------------
| CONTADORES
|--------------------------------------------------------------------------
*/

function alterarContador(
    id,
    diferenca
) {

    const elemento =
        document.getElementById(id);

    if (!elemento) {
        return;
    }

    let valor =
        parseInt(
            elemento.textContent || '0',
            10
        );

    valor += diferenca;

    if (valor < 0) {
        valor = 0;
    }

    elemento.textContent =
        valor;
}


function atualizarContadoresDecisao(
    anterior,
    nova
) {

    const mapa = {

        usar:
            'contadorUsar',

        parcial:
            'contadorParcial',

        descartar:
            'contadorDescartar',

        pendente:
            'contadorPendente'
    };


    if (
        anterior
        &&
        mapa[anterior]
        &&
        anterior !== nova
    ) {

        alterarContador(
            mapa[anterior],
            -1
        );
    }


    if (
        nova
        &&
        mapa[nova]
        &&
        anterior !== nova
    ) {

        alterarContador(
            mapa[nova],
            1
        );
    }
}


/*
|--------------------------------------------------------------------------
| SALVAR TRIAGEM
|--------------------------------------------------------------------------
*/

async function salvarTriagem(
    form,
    modo = 'automatico'
) {

    const resultado =
        form.querySelector(
            '.resultado-salvamento'
        );

    const botoesDecisao =
        form.querySelectorAll(
            '.decisao-btn'
        );

    const botaoDetalhes =
        form.querySelector(
            '.btn-salvar-detalhes'
        );


    form.classList.add(
        'salvando'
    );


    botoesDecisao.forEach(
        function (botao) {

            botao.disabled = true;

        }
    );


    if (botaoDetalhes) {

        botaoDetalhes.disabled = true;
    }


    if (resultado) {

        resultado.innerHTML =
            modo === 'automatico'
                ? `
                    <span class="text-secondary">
                        <span
                            class="
                                spinner-border
                                spinner-border-sm
                                me-1
                            "
                        ></span>
                        Salvando...
                    </span>
                `
                : `
                    <span class="text-secondary">
                        Salvando detalhes...
                    </span>
                `;
    }


    try {

        const dados =
            new FormData(form);


        const resposta =
            await fetch(
                'triagem_salvar.php',
                {
                    method: 'POST',
                    body: dados
                }
            );


        const texto =
            await resposta.text();


        let json;


        try {

            json =
                JSON.parse(texto);

        } catch (erroJson) {

            console.error(
                'Resposta recebida:',
                texto
            );

            throw new Error(
                'O servidor retornou uma resposta inválida.'
            );
        }


        if (!json.success) {

            throw new Error(
                json.message
                ||
                'Erro ao salvar a triagem.'
            );
        }


        if (resultado) {

            resultado.innerHTML = `
                <span class="text-success">
                    <i
                        class="
                            bi
                            bi-check-circle
                        "
                    ></i>
                    Salvo
                </span>
            `;
        }


        atualizarProgresso(
            json.analisados,
            json.total,
            json.fase
        );


        /*
        |--------------------------------------------------------------------------
        | SOMENTE QUANDO A TRIAGEM ACABAR PELA PRIMEIRA VEZ
        |--------------------------------------------------------------------------
        |
        | Se o vídeo era pendente e a última decisão completou
        | toda a triagem, recarregamos para o PHP liberar a
        | próxima etapa.
        |
        */

        const decisaoAnterior =
            form.dataset.decisaoAntesSalvar
            ||
            form.dataset.decisaoAtual
            ||
            'pendente';


        if (
            modo === 'automatico'
            &&
            decisaoAnterior === 'pendente'
            &&
            parseInt(json.total || 0, 10) > 0
            &&
            parseInt(json.analisados || 0, 10)
                >=
                parseInt(json.total || 0, 10)
        ) {

            setTimeout(
                function () {

                    window.location.reload();

                },
                650
            );
        }


        return json;


    } catch (erro) {

        console.error(erro);


        if (resultado) {

            resultado.innerHTML = `
                <span class="text-danger">
                    <i
                        class="
                            bi
                            bi-exclamation-circle
                        "
                    ></i>
                    ${erro.message}
                </span>
            `;
        }


        throw erro;


    } finally {

        form.classList.remove(
            'salvando'
        );


        botoesDecisao.forEach(
            function (botao) {

                botao.disabled = false;

            }
        );


        if (botaoDetalhes) {

            botaoDetalhes.disabled = false;
        }
    }
}


/*
|--------------------------------------------------------------------------
| FORMULÁRIOS
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.form-triagem')
    .forEach(function (form) {


        /*
        |--------------------------------------------------------------------------
        | DECISÃO AUTOMÁTICA
        |--------------------------------------------------------------------------
        */

        form
            .querySelectorAll('.decisao-btn')
            .forEach(function (botao) {

                botao.addEventListener(
                    'click',
                    async function () {


                        if (
                            form.classList.contains(
                                'salvando'
                            )
                        ) {
                            return;
                        }


                        const novaDecisao =
                            botao.dataset.decisao;


                        const input =
                            form.querySelector(
                                '.input-decisao'
                            );


                        const decisaoAnterior =
                            form.dataset.decisaoAtual
                            ||
                            input.value
                            ||
                            'pendente';


                        /*
                         * Clicou novamente na mesma
                         * decisão: não precisamos salvar.
                         */

                        if (
                            novaDecisao
                            ===
                            decisaoAnterior
                        ) {
                            return;
                        }


                        form.dataset.decisaoAntesSalvar =
                            decisaoAnterior;


                        /*
                         * Atualiza visual imediatamente.
                         */

                        input.value =
                            novaDecisao;


                        aplicarVisualDecisao(
                            form,
                            novaDecisao
                        );


                        atualizarContadoresDecisao(
                            decisaoAnterior,
                            novaDecisao
                        );


                        try {

                            await salvarTriagem(
                                form,
                                'automatico'
                            );


                            /*
                             * Salvo com sucesso.
                             */

                            form.dataset.decisaoAtual =
                                novaDecisao;


                        } catch (erro) {

                            /*
                             * Deu erro:
                             * devolve tudo ao estado anterior.
                             */

                            input.value =
                                decisaoAnterior;


                            aplicarVisualDecisao(
                                form,
                                decisaoAnterior
                            );


                            atualizarContadoresDecisao(
                                novaDecisao,
                                decisaoAnterior
                            );


                            form.dataset.decisaoAtual =
                                decisaoAnterior;
                        }

                    }
                );

            });


        /*
        |--------------------------------------------------------------------------
        | SALVAR DETALHES
        |--------------------------------------------------------------------------
        */

        form.addEventListener(
            'submit',
            async function (event) {

                event.preventDefault();


                if (
                    form.classList.contains(
                        'salvando'
                    )
                ) {
                    return;
                }


                try {

                    await salvarTriagem(
                        form,
                        'detalhes'
                    );

                } catch (erro) {

                    /*
                     * A mensagem já é exibida
                     * por salvarTriagem().
                     */

                }

            }
        );

    });


/*
|--------------------------------------------------------------------------
| PROGRESSO
|--------------------------------------------------------------------------
*/

function atualizarProgresso(
    analisados,
    total,
    fase
) {

    analisados =
        parseInt(
            analisados || 0,
            10
        );

    total =
        parseInt(
            total || 0,
            10
        );


    let percentual = 0;


    if (total > 0) {

        percentual =
            Math.round(
                (
                    analisados
                    /
                    total
                )
                *
                100
            );
    }


    /*
    |--------------------------------------------------------------------------
    | BARRA DA TRIAGEM
    |--------------------------------------------------------------------------
    */

    const barra =
        document.getElementById(
            'barraTriagem'
        );


    if (barra) {

        barra.style.width =
            percentual + '%';
    }


    /*
    |--------------------------------------------------------------------------
    | BARRA DO TOPO
    |--------------------------------------------------------------------------
    */

    const barraTopo =
        document.getElementById(
            'barraTriagemTopo'
        );


    if (barraTopo) {

        barraTopo.style.width =
            percentual + '%';
    }


    /*
    |--------------------------------------------------------------------------
    | RESUMO DO TOPO
    |--------------------------------------------------------------------------
    */

    const resumoTopo =
        document.getElementById(
            'resumoTriagemTopo'
        );


    if (resumoTopo) {

        resumoTopo.textContent =
            analisados
            +
            '/'
            +
            total;
    }


    /*
    |--------------------------------------------------------------------------
    | TEXTO DA ETAPA
    |--------------------------------------------------------------------------
    */

    const textoProgresso =
        document.getElementById(
            'textoProgressoTriagem'
        );


    if (textoProgresso) {

        textoProgresso.textContent =
            analisados
            +
            '/'
            +
            total
            +
            ' analisados';
    }


    /*
    |--------------------------------------------------------------------------
    | FASE DO CAPÍTULO
    |--------------------------------------------------------------------------
    */

    const faseTexto =
        document.getElementById(
            'faseAtualTexto'
        );


    if (faseTexto) {

        if (fase === 'triado') {

            faseTexto.textContent =
                'Triado';

        } else if (fase === 'triagem') {

            faseTexto.textContent =
                'Em triagem';

        } else {

            faseTexto.textContent =
                'Brutos';
        }
    }
}

</script>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.getElementById(
            'formPlanoEpisodio'
        );

    if (!form) {
        return;
    }

    const resultado =
        document.getElementById(
            'resultadoPlano'
        );

    const statusPlano =
        document.getElementById(
            'statusPlano'
        );


    form.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();

            const botao =
                event.submitter;

            if (!botao) {
                return;
            }

            const novoStatus =
                botao.dataset.status
                || 'rascunho';

            statusPlano.value =
                novoStatus;

            const textoOriginal =
                botao.innerHTML;

            form
                .querySelectorAll(
                    'button[type="submit"]'
                )
                .forEach(function (b) {
                    b.disabled = true;
                });

            botao.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

            if (resultado) {
                resultado.innerHTML =
                    '<span class="text-secondary">Salvando plano...</span>';
            }

            try {

                const dados =
                    new FormData(form);

                const resposta =
                    await fetch(
                        'plano_salvar.php',
                        {
                            method: 'POST',
                            body: dados
                        }
                    );

                const texto =
                    await resposta.text();

                let json;

                try {

                    json =
                        JSON.parse(texto);

                } catch (erroJson) {

                    console.error(
                        'Resposta recebida:',
                        texto
                    );

                    throw new Error(
                        'O servidor retornou uma resposta inválida.'
                    );
                }

                if (!json.sucesso) {

                    throw new Error(
                        json.mensagem
                        ||
                        'Não foi possível salvar o plano.'
                    );
                }

                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-success fw-semibold">'
                        +
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        json.mensagem
                        +
                        '</span>';
                }

                setTimeout(
                    function () {
                        window.location.reload();
                    },
                    700
                );

            } catch (erro) {

                console.error(erro);

                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-danger fw-semibold">'
                        +
                        '<i class="bi bi-exclamation-triangle me-1"></i>'
                        +
                        erro.message
                        +
                        '</span>';
                }

                form
                    .querySelectorAll(
                        'button[type="submit"]'
                    )
                    .forEach(function (b) {
                        b.disabled = false;
                    });

                botao.innerHTML =
                    textoOriginal;
            }

        }
    );

});
</script>

<!-- =========================================================
     JAVASCRIPT EDIÇÃO — UX 2.0
========================================================= -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.getElementById(
            'formEdicao'
        );

    if (!form) {
        return;
    }

    const resultado =
        document.getElementById(
            'resultadoEdicao'
        );

    const statusEdicao =
        document.getElementById(
            'statusEdicao'
        );

    const linkExportacao =
        form.querySelector(
            '[name="link_exportacao"]'
        );


    /*
    |--------------------------------------------------------------------------
    | SUBMIT
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();

            /*
            |--------------------------------------------------------------------------
            | IDENTIFICAR QUAL BOTÃO FOI CLICADO
            |--------------------------------------------------------------------------
            */

            const botao =
                event.submitter;

            if (!botao) {
                return;
            }

            const acao =
                botao.dataset.acao
                || 'salvar';


            /*
            |--------------------------------------------------------------------------
            | ENVIAR PARA REVISÃO
            |--------------------------------------------------------------------------
            */

            if (acao === 'revisao') {

                /*
                 * Para existir uma V1 para revisão,
                 * precisamos pelo menos do link.
                 */

                if (
                    !linkExportacao
                    ||
                    linkExportacao.value.trim() === ''
                ) {

                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-danger fw-semibold">'
                            +
                            '<i class="bi bi-exclamation-triangle me-1"></i>'
                            +
                            'Informe o link da V1 antes de enviar para revisão.'
                            +
                            '</span>';
                    }

                    if (linkExportacao) {
                        linkExportacao.focus();
                    }

                    return;
                }

                /*
                 * O usuário não precisa escolher o status.
                 * O botão já determina isso.
                 */

                if (statusEdicao) {

                    statusEdicao.value =
                        'aguardando_revisao';
                }
            }


            /*
            |--------------------------------------------------------------------------
            | SALVAR NORMAL
            |--------------------------------------------------------------------------
            */

            if (
                acao === 'salvar'
                &&
                statusEdicao
                &&
                statusEdicao.value === ''
            ) {

                statusEdicao.value =
                    'em_edicao';
            }


            /*
            |--------------------------------------------------------------------------
            | INTERFACE DE CARREGAMENTO
            |--------------------------------------------------------------------------
            */

            const textoOriginal =
                botao.innerHTML;


            form
                .querySelectorAll(
                    'button[type="submit"]'
                )
                .forEach(function (btn) {

                    btn.disabled = true;

                });


            botao.innerHTML =
                '<span '
                +
                'class="spinner-border spinner-border-sm me-1"'
                +
                '></span>'
                +
                (
                    acao === 'revisao'
                        ? 'Enviando...'
                        : 'Salvando...'
                );


            if (resultado) {

                resultado.innerHTML =
                    '<span class="text-secondary">'
                    +
                    (
                        acao === 'revisao'
                            ? 'Enviando V1 para revisão...'
                            : 'Salvando edição...'
                    )
                    +
                    '</span>';
            }


            try {

                /*
                |--------------------------------------------------------------------------
                | ENVIAR
                |--------------------------------------------------------------------------
                */

                const dados =
                    new FormData(form);


                const resposta =
                    await fetch(
                        'edicao_salvar.php',
                        {
                            method: 'POST',
                            body: dados
                        }
                    );


                /*
                |--------------------------------------------------------------------------
                | LER RESPOSTA
                |--------------------------------------------------------------------------
                */

                const texto =
                    await resposta.text();


                let json;


                try {

                    json =
                        JSON.parse(texto);

                } catch (erroJson) {

                    console.error(
                        'Resposta recebida:',
                        texto
                    );

                    throw new Error(
                        'O servidor retornou uma resposta inválida.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | ERRO DO BACKEND
                |--------------------------------------------------------------------------
                */

                if (!json.success) {

                    throw new Error(
                        json.message
                        ||
                        'Erro ao salvar edição.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | SUCESSO
                |--------------------------------------------------------------------------
                */

                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-success fw-semibold">'
                        +
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        (
                            acao === 'revisao'
                                ? 'V1 enviada para revisão com sucesso.'
                                : (
                                    json.message
                                    ||
                                    'Edição salva com sucesso.'
                                )
                        )
                        +
                        '</span>';
                }


                /*
                |--------------------------------------------------------------------------
                | RECARREGAR
                |--------------------------------------------------------------------------
                */

                setTimeout(
                    function () {

                        window.location.reload();

                    },
                    700
                );


            } catch (erro) {

                /*
                |--------------------------------------------------------------------------
                | ERRO
                |--------------------------------------------------------------------------
                */

                console.error(erro);


                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-danger fw-semibold">'
                        +
                        '<i class="bi bi-exclamation-triangle me-1"></i>'
                        +
                        erro.message
                        +
                        '</span>';
                }


                form
                    .querySelectorAll(
                        'button[type="submit"]'
                    )
                    .forEach(function (btn) {

                        btn.disabled = false;

                    });


                botao.innerHTML =
                    textoOriginal;
            }

        }
    );

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.form-revisao').forEach(function (form) {

        form.addEventListener('submit', async function (event) {

            event.preventDefault();

            const botao = event.submitter;

            if (!botao) {
                return;
            }

            const resultado = form.querySelector('.resultado-revisao');
            const textoOriginal = botao.innerHTML;

            form.querySelectorAll('button[type="submit"]').forEach(function (b) {
                b.disabled = true;
            });

            botao.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

            resultado.innerHTML =
                '<span class="text-secondary">Salvando revisão...</span>';

            try {

                const dados = new FormData(form);
                dados.set('acao_revisao', botao.value);

                const resposta = await fetch(
                    'revisao_salvar.php',
                    {
                        method: 'POST',
                        body: dados
                    }
                );

                const texto = await resposta.text();
                let json;

                try {
                    json = JSON.parse(texto);
                } catch (e) {
                    console.error('Resposta recebida:', texto);
                    throw new Error('O servidor retornou uma resposta inválida.');
                }

                if (!json.success) {
                    throw new Error(json.message || 'Erro ao salvar revisão.');
                }

                resultado.innerHTML =
                    '<span class="text-success fw-semibold">'
                    + '<i class="bi bi-check-circle me-1"></i>'
                    + json.message
                    + '</span>';

                setTimeout(function () {
                    window.location.reload();
                }, 700);

            } catch (erro) {

                console.error(erro);

                resultado.innerHTML =
                    '<span class="text-danger fw-semibold">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i>'
                    + erro.message
                    + '</span>';

                form.querySelectorAll('button[type="submit"]').forEach(function (b) {
                    b.disabled = false;
                });

                botao.innerHTML = textoOriginal;
            }
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const formThumbnail =
        document.getElementById(
            'formThumbnail'
        );

    if (!formThumbnail) {
        return;
    }

    const resultado =
        document.getElementById(
            'resultadoThumbnail'
        );

    /*
    |--------------------------------------------------------------------------
    | MONTAR PROMPT
    |--------------------------------------------------------------------------
    */

    const btnPrompt =
        document.getElementById(
            'btnMontarPromptThumb'
        );

    if (btnPrompt) {

        btnPrompt.addEventListener(
            'click',
            function () {

                const conceito =
                    formThumbnail
                        .querySelector(
                            '[name="conceito"]'
                        )
                        .value
                        .trim();

                const texto =
                    formThumbnail
                        .querySelector(
                            '[name="texto_thumb"]'
                        )
                        .value
                        .trim();

                const secundario =
                    formThumbnail
                        .querySelector(
                            '[name="texto_secundario"]'
                        )
                        .value
                        .trim();

                const elementos =
                    formThumbnail
                        .querySelector(
                            '[name="elementos"]'
                        )
                        .value
                        .trim();

                const referencia =
                    formThumbnail
                        .querySelector(
                            '[name="referencia_visual"]'
                        )
                        .value
                        .trim();

                const prompt =
`Crie uma thumbnail profissional para YouTube relacionada a uma obra residencial real.

CONCEITO:
${conceito || 'Não definido'}

TEXTO PRINCIPAL:
${texto || 'Sem texto principal'}

TEXTO SECUNDÁRIO:
${secundario || 'Nenhum'}

ELEMENTOS VISUAIS:
${elementos || 'Definir conforme o episódio'}

DIREÇÃO VISUAL:
${referencia || 'Visual limpo e forte'}

A imagem deve ter proporção 16:9, leitura fácil em telas pequenas, composição simples, poucos elementos e foco visual evidente.`;

                document
                    .getElementById(
                        'promptThumb'
                    )
                    .value =
                    prompt;
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    formThumbnail.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();

            const botao =
                formThumbnail.querySelector(
                    'button[type="submit"]'
                );

            const original =
                botao.innerHTML;

            botao.disabled = true;

            botao.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

            resultado.innerHTML =
                '<span class="text-secondary">Salvando thumbnail...</span>';

            try {

                const resposta =
                    await fetch(
                        'thumbnail_salvar.php',
                        {
                            method: 'POST',
                            body:
                                new FormData(
                                    formThumbnail
                                )
                        }
                    );

                const texto =
                    await resposta.text();

                let json;

                try {

                    json =
                        JSON.parse(texto);

                } catch (erroJson) {

                    console.error(
                        'Resposta recebida:',
                        texto
                    );

                    throw new Error(
                        'O servidor retornou uma resposta inválida.'
                    );
                }

                if (!json.success) {

                    throw new Error(
                        json.message
                        ||
                        'Erro ao salvar thumbnail.'
                    );
                }

                resultado.innerHTML =
                    '<span class="text-success fw-semibold">'
                    +
                    '<i class="bi bi-check-circle me-1"></i>'
                    +
                    json.message
                    +
                    '</span>';

                setTimeout(
                    function () {

                        window.location.reload();

                    },
                    700
                );

            } catch (erro) {

                console.error(erro);

                resultado.innerHTML =
                    '<span class="text-danger fw-semibold">'
                    +
                    '<i class="bi bi-exclamation-triangle me-1"></i>'
                    +
                    erro.message
                    +
                    '</span>';

                botao.disabled =
                    false;

                botao.innerHTML =
                    original;
            }

        }
    );

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    document
        .querySelectorAll('.btn-thumb-acao')
        .forEach(function (botao) {

            botao.addEventListener(
                'click',
                async function () {

                    const thumbnailId =
                        botao.dataset.id;

                    const acao =
                        botao.dataset.acao;


                    /*
                    |--------------------------------------------------------------------------
                    | CONFIRMAÇÃO
                    |--------------------------------------------------------------------------
                    */

                    if (acao === 'aprovar') {

                        const confirmar =
                            confirm(
                                'Aprovar esta thumbnail como a versão oficial deste capítulo?'
                            );

                        if (!confirmar) {
                            return;
                        }

                    } else {

                        const confirmar =
                            confirm(
                                'Descartar esta versão da thumbnail?'
                            );

                        if (!confirmar) {
                            return;
                        }
                    }


                    const textoOriginal =
                        botao.innerHTML;


                    botao.disabled = true;


                    if (acao === 'aprovar') {

                        botao.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-1"></span> Aprovando...';

                    } else {

                        botao.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-1"></span> Descartando...';
                    }


                    try {

                        const dados =
                            new FormData();

                        dados.append(
                            'thumbnail_id',
                            thumbnailId
                        );

                        dados.append(
                            'acao',
                            acao
                        );


                        const resposta =
                            await fetch(
                                'thumbnail_acao.php',
                                {
                                    method: 'POST',
                                    body: dados
                                }
                            );


                        const texto =
                            await resposta.text();

                        let json;


                        try {

                            json =
                                JSON.parse(texto);

                        } catch (erroJson) {

                            console.error(
                                'Resposta recebida:',
                                texto
                            );

                            throw new Error(
                                'O servidor retornou uma resposta inválida.'
                            );
                        }


                        if (!json.success) {

                            throw new Error(
                                json.message
                                ||
                                'Não foi possível executar a ação.'
                            );
                        }


                        window.location.reload();


                    } catch (erro) {

                        console.error(erro);

                        alert(
                            erro.message
                        );

                        botao.disabled =
                            false;

                        botao.innerHTML =
                            textoOriginal;
                    }

                }
            );

        });

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.getElementById('formPublicacao');

    if (!form) {
        return;
    }

    const resultado =
        document.getElementById('resultadoPublicacao');

    const barra =
        document.getElementById('barraPublicacao');

    const percentualTexto =
        document.getElementById(
            'textoPercentualPublicacao'
        );

    /*
    |--------------------------------------------------------------------------
    | RECALCULAR PROGRESSO VISUAL
    |--------------------------------------------------------------------------
    */

    function recalcularProgressoPublicacao() {

        const checks =
            form.querySelectorAll(
                '.checklist-publicacao'
            );

        let concluidos = 0;

        checks.forEach(function (check) {

            if (check.checked) {
                concluidos++;
            }

        });

        const total =
            checks.length;

        const percentual =
            total > 0
                ? Math.round(
                    (concluidos / total) * 100
                )
                : 0;


        if (barra) {
            barra.style.width =
                percentual + '%';
        }


        if (percentualTexto) {
            percentualTexto.textContent =
                percentual + '%';
        }
    }


    form
        .querySelectorAll(
            '.checklist-publicacao'
        )
        .forEach(function (check) {

            check.addEventListener(
                'change',
                recalcularProgressoPublicacao
            );

        });


    /*
    |--------------------------------------------------------------------------
    | SALVAR PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();

            const botao =
                form.querySelector(
                    'button[type="submit"]'
                );

            const textoOriginal =
                botao.innerHTML;

            botao.disabled = true;

            botao.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span> Salvando...';

            resultado.innerHTML =
                '<span class="text-secondary">Salvando publicação...</span>';

            try {

                const dados =
                    new FormData(form);

                const resposta =
                    await fetch(
                        'publicacao_salvar.php',
                        {
                            method: 'POST',
                            body: dados
                        }
                    );

                const texto =
                    await resposta.text();

                let json;

                try {

                    json =
                        JSON.parse(texto);

                } catch (erroJson) {

                    console.error(
                        'Resposta recebida:',
                        texto
                    );

                    throw new Error(
                        'O servidor retornou uma resposta inválida.'
                    );
                }

                if (!json.success) {

                    throw new Error(
                        json.message
                        ||
                        'Erro ao salvar publicação.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | ATUALIZAR PROGRESSO
                |--------------------------------------------------------------------------
                */

                if (barra) {

                    barra.style.width =
                        json.percentual + '%';
                }

                if (percentualTexto) {

                    percentualTexto.textContent =
                        json.percentual + '%';
                }


                resultado.innerHTML =
                    '<span class="text-success fw-semibold">'
                    +
                    '<i class="bi bi-check-circle me-1"></i>'
                    +
                    json.message
                    +
                    '</span>';


                setTimeout(
                    function () {

                        window.location.reload();

                    },
                    700
                );


            } catch (erro) {

                console.error(erro);

                resultado.innerHTML =
                    '<span class="text-danger fw-semibold">'
                    +
                    '<i class="bi bi-exclamation-triangle me-1"></i>'
                    +
                    erro.message
                    +
                    '</span>';

                botao.disabled = false;
                botao.innerHTML = textoOriginal;
            }

        }
    );

});
</script>

<!-- =========================================================
     JAVASCRIPT SHORTS / REELS — UX 2.0
========================================================= -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | ELEMENTOS
    |--------------------------------------------------------------------------
    */

    const form =
        document.getElementById(
            'formCorteCurto'
        );

    if (!form) {
        return;
    }


    const resultado =
        document.getElementById(
            'resultadoCorteCurto'
        );


    const corteId =
        document.getElementById(
            'corteId'
        );


    const tituloForm =
        document.getElementById(
            'tituloFormCorte'
        );


    const btnSalvar =
        document.getElementById(
            'btnSalvarCorte'
        );


    const btnCancelar =
        document.getElementById(
            'btnCancelarEdicaoCorte'
        );


    const youtubeUrl =
        document.getElementById(
            'corteYoutubeUrl'
        );


    const instagramUrl =
        document.getElementById(
            'corteInstagramUrl'
        );


    const tiktokUrl =
        document.getElementById(
            'corteTiktokUrl'
        );


    /*
    |--------------------------------------------------------------------------
    | FUNÇÃO AUXILIAR PARA PREENCHER CAMPOS
    |--------------------------------------------------------------------------
    */

    function definirValor(
        seletor,
        valor
    ) {

        const campo =
            form.querySelector(seletor);

        if (campo) {
            campo.value =
                valor || '';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RESETAR FORMULÁRIO
    |--------------------------------------------------------------------------
    */

    function resetarFormularioCorte() {

        form.reset();


        if (corteId) {
            corteId.value = '';
        }


        if (youtubeUrl) {
            youtubeUrl.value = '';
        }


        if (instagramUrl) {
            instagramUrl.value = '';
        }


        if (tiktokUrl) {
            tiktokUrl.value = '';
        }


        if (tituloForm) {

            tituloForm.textContent =
                'Novo corte';
        }


        if (btnSalvar) {

            btnSalvar.innerHTML =
                '<i class="bi bi-plus-lg"></i> Salvar corte';
        }


        if (btnCancelar) {

            btnCancelar.classList.add(
                'd-none'
            );
        }


        if (resultado) {

            resultado.innerHTML = '';
        }

    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR / ATUALIZAR CORTE
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();


            const botao =
                btnSalvar
                ||
                form.querySelector(
                    'button[type="submit"]'
                );


            if (!botao) {
                return;
            }


            const htmlOriginal =
                botao.innerHTML;


            botao.disabled = true;


            botao.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>'
                +
                'Salvando...';


            if (resultado) {

                resultado.innerHTML =
                    '<span class="text-secondary">'
                    +
                    'Salvando corte...'
                    +
                    '</span>';
            }


            try {

                /*
                |--------------------------------------------------------------------------
                | ENVIAR
                |--------------------------------------------------------------------------
                */

                const resposta =
                    await fetch(
                        'corte_curto_salvar.php',
                        {
                            method: 'POST',
                            body: new FormData(form)
                        }
                    );


                /*
                |--------------------------------------------------------------------------
                | RESPOSTA
                |--------------------------------------------------------------------------
                */

                const texto =
                    await resposta.text();


                let json;


                try {

                    json =
                        JSON.parse(texto);

                } catch (erroJson) {

                    console.error(
                        'Resposta recebida:',
                        texto
                    );

                    throw new Error(
                        'O servidor retornou uma resposta inválida.'
                    );
                }


                if (!json.success) {

                    throw new Error(
                        json.message
                        ||
                        'Erro ao salvar corte.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | SUCESSO
                |--------------------------------------------------------------------------
                */

                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-success fw-semibold">'
                        +
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        json.message
                        +
                        '</span>';
                }


                setTimeout(
                    function () {

                        window.location.reload();

                    },
                    700
                );


            } catch (erro) {

                console.error(erro);


                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-danger fw-semibold">'
                        +
                        '<i class="bi bi-exclamation-triangle me-1"></i>'
                        +
                        erro.message
                        +
                        '</span>';
                }


                botao.disabled = false;

                botao.innerHTML =
                    htmlOriginal;
            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | EDITAR CORTE
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '.btn-editar-corte'
        )
        .forEach(function (botao) {

            botao.addEventListener(
                'click',
                function () {


                    /*
                    |--------------------------------------------------------------------------
                    | ID
                    |--------------------------------------------------------------------------
                    */

                    if (corteId) {

                        corteId.value =
                            botao.dataset.id
                            || '';
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | CAMPOS PRINCIPAIS
                    |--------------------------------------------------------------------------
                    */

                    definirValor(
                        '[name="titulo"]',
                        botao.dataset.titulo
                    );


                    definirValor(
                        '[name="arquivo_origem_id"]',
                        botao.dataset.arquivo
                    );


                    definirValor(
                        '[name="inicio"]',
                        botao.dataset.inicio
                    );


                    definirValor(
                        '[name="fim"]',
                        botao.dataset.fim
                    );


                    definirValor(
                        '[name="formato"]',
                        botao.dataset.formato
                        || '9:16'
                    );


                    definirValor(
                        '[name="status"]',
                        botao.dataset.status
                        || 'ideia'
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | DETALHES
                    |--------------------------------------------------------------------------
                    */

                    definirValor(
                        '[name="duracao_final"]',
                        botao.dataset.duracao
                    );


                    definirValor(
                        '[name="gancho"]',
                        botao.dataset.gancho
                    );


                    definirValor(
                        '[name="legenda"]',
                        botao.dataset.legenda
                    );


                    definirValor(
                        '[name="instrucao_edicao"]',
                        botao.dataset.instrucao
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | LINKS JÁ PUBLICADOS
                    |--------------------------------------------------------------------------
                    |
                    | Mesmo não aparecendo mais no cadastro principal,
                    | preservamos as URLs durante a edição.
                    |--------------------------------------------------------------------------
                    */

                    if (youtubeUrl) {

                        youtubeUrl.value =
                            botao.dataset.youtube
                            || '';
                    }


                    if (instagramUrl) {

                        instagramUrl.value =
                            botao.dataset.instagram
                            || '';
                    }


                    if (tiktokUrl) {

                        tiktokUrl.value =
                            botao.dataset.tiktok
                            || '';
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | ALTERAR MODO DO FORMULÁRIO
                    |--------------------------------------------------------------------------
                    */

                    if (tituloForm) {

                        tituloForm.textContent =
                            'Editar corte';
                    }


                    if (btnSalvar) {

                        btnSalvar.innerHTML =
                            '<i class="bi bi-floppy"></i> Salvar alterações';
                    }


                    if (btnCancelar) {

                        btnCancelar.classList.remove(
                            'd-none'
                        );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | LIMPAR MENSAGEM
                    |--------------------------------------------------------------------------
                    */

                    if (resultado) {

                        resultado.innerHTML = '';
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | IR PARA FORMULÁRIO
                    |--------------------------------------------------------------------------
                    */

                    form.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | CANCELAR EDIÇÃO
    |--------------------------------------------------------------------------
    */

    if (btnCancelar) {

        btnCancelar.addEventListener(
            'click',
            function () {

                resetarFormularioCorte();

            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | EXCLUIR CORTE
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '.btn-excluir-corte'
        )
        .forEach(function (botao) {

            botao.addEventListener(
                'click',
                async function () {


                    const id =
                        botao.dataset.id;


                    const capituloId =
                        botao.dataset.capitulo;


                    const titulo =
                        botao.dataset.titulo
                        || 'este corte';


                    /*
                    |--------------------------------------------------------------------------
                    | CONFIRMAÇÃO
                    |--------------------------------------------------------------------------
                    */

                    const confirmar =
                        window.confirm(
                            'Tem certeza que deseja excluir "'
                            +
                            titulo
                            +
                            '"?\n\n'
                            +
                            'Esta ação não poderá ser desfeita.'
                        );


                    if (!confirmar) {
                        return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | CARREGAMENTO
                    |--------------------------------------------------------------------------
                    */

                    const htmlOriginal =
                        botao.innerHTML;


                    botao.disabled = true;


                    botao.innerHTML =
                        '<span class="spinner-border spinner-border-sm"></span>';


                    try {

                        const dados =
                            new FormData();


                        dados.append(
                            'corte_id',
                            id
                        );


                        dados.append(
                            'capitulo_id',
                            capituloId
                        );


                        /*
                        |--------------------------------------------------------------------------
                        | EXCLUIR
                        |--------------------------------------------------------------------------
                        */

                        const resposta =
                            await fetch(
                                'corte_curto_excluir.php',
                                {
                                    method: 'POST',
                                    body: dados
                                }
                            );


                        const texto =
                            await resposta.text();


                        let json;


                        try {

                            json =
                                JSON.parse(texto);

                        } catch (erroJson) {

                            console.error(
                                'Resposta recebida:',
                                texto
                            );

                            throw new Error(
                                'O servidor retornou uma resposta inválida.'
                            );
                        }


                        if (!json.success) {

                            throw new Error(
                                json.message
                                ||
                                'Não foi possível excluir o corte.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | RECARREGAR
                        |--------------------------------------------------------------------------
                        */

                        window.location.reload();


                    } catch (erro) {

                        console.error(erro);


                        alert(
                            erro.message
                        );


                        botao.disabled = false;

                        botao.innerHTML =
                            htmlOriginal;
                    }

                }
            );

        });

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const form =
        document.getElementById('formCorteCurto');

    if (!form) {
        return;
    }

    const corteId =
        document.getElementById('corteId');

    const tituloForm =
        document.getElementById('tituloFormCorte');

    const btnSalvar =
        document.getElementById('btnSalvarCorte');

    const btnCancelar =
        document.getElementById('btnCancelarEdicaoCorte');


    /*
    |--------------------------------------------------------------------------
    | EDITAR CORTE
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('.btn-editar-corte')
        .forEach(function (botao) {

            botao.addEventListener(
                'click',
                function () {

                    corteId.value =
                        botao.dataset.id || '';

                    form.querySelector('[name="titulo"]').value =
                        botao.dataset.titulo || '';

                    form.querySelector('[name="arquivo_origem_id"]').value =
                        botao.dataset.arquivo || '';

                    form.querySelector('[name="inicio"]').value =
                        botao.dataset.inicio || '';

                    form.querySelector('[name="fim"]').value =
                        botao.dataset.fim || '';

                    form.querySelector('[name="formato"]').value =
                        botao.dataset.formato || '9:16';

                    form.querySelector('[name="status"]').value =
                        botao.dataset.status || 'ideia';

                    form.querySelector('[name="duracao_final"]').value =
                        botao.dataset.duracao || '';

                    form.querySelector('[name="gancho"]').value =
                        botao.dataset.gancho || '';

                    form.querySelector('[name="legenda"]').value =
                        botao.dataset.legenda || '';

                    form.querySelector('[name="instrucao_edicao"]').value =
                        botao.dataset.instrucao || '';
                        
                    form.querySelector('[name="youtube_url"]').value =
                        botao.dataset.youtube || '';
                    
                    form.querySelector('[name="instagram_url"]').value =
                        botao.dataset.instagram || '';
                    
                    form.querySelector('[name="tiktok_url"]').value =
                        botao.dataset.tiktok || '';


                    tituloForm.textContent =
                        'Editar corte';

                    btnSalvar.innerHTML =
                        '<i class="bi bi-floppy"></i> Salvar alterações';

                    btnCancelar.classList.remove(
                        'd-none'
                    );


                    form.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });

                }
            );

        });


    /*
    |--------------------------------------------------------------------------
    | CANCELAR EDIÇÃO
    |--------------------------------------------------------------------------
    */

    btnCancelar.addEventListener(
        'click',
        function () {

            form.reset();

            corteId.value = '';

            tituloForm.textContent =
                'Novo corte curto';

            btnSalvar.innerHTML =
                '<i class="bi bi-plus-lg"></i> Salvar corte';

            btnCancelar.classList.add(
                'd-none'
            );

        }
    );

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    document
        .querySelectorAll('.btn-excluir-corte')
        .forEach(function (botao) {

            botao.addEventListener(
                'click',
                async function () {

                    const corteId =
                        botao.dataset.id;

                    const capituloId =
                        botao.dataset.capitulo;

                    const titulo =
                        botao.dataset.titulo || 'este corte';

                    const confirmar =
                        confirm(
                            'Tem certeza que deseja excluir "' +
                            titulo +
                            '"?\n\nEsta ação não poderá ser desfeita.'
                        );

                    if (!confirmar) {
                        return;
                    }

                    const htmlOriginal =
                        botao.innerHTML;

                    botao.disabled = true;

                    botao.innerHTML =
                        '<span class="spinner-border spinner-border-sm"></span>';

                    try {

                        const dados =
                            new FormData();

                        dados.append(
                            'corte_id',
                            corteId
                        );

                        dados.append(
                            'capitulo_id',
                            capituloId
                        );

                        const resposta =
                            await fetch(
                                'corte_curto_excluir.php',
                                {
                                    method: 'POST',
                                    body: dados
                                }
                            );

                        const texto =
                            await resposta.text();

                        let json;

                        try {

                            json =
                                JSON.parse(texto);

                        } catch (erroJson) {

                            console.error(
                                'Resposta recebida:',
                                texto
                            );

                            throw new Error(
                                'O servidor retornou uma resposta inválida.'
                            );
                        }

                        if (!json.success) {

                            throw new Error(
                                json.message
                                ||
                                'Não foi possível excluir o corte.'
                            );
                        }

                        window.location.reload();

                    } catch (erro) {

                        console.error(erro);

                        alert(
                            erro.message
                        );

                        botao.disabled = false;
                        botao.innerHTML = htmlOriginal;
                    }

                }
            );

        });

});
</script>

<!-- =========================================================
     TRIAGEM IA — SOLICITAR PROCESSAMENTO
========================================================= -->

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botao =
            document.getElementById(
                'btnAnalisarBrutosIA'
            );


        const resultado =
            document.getElementById(
                'resultadoTriagemIA'
            );


        if (!botao) {
            return;
        }


        botao.addEventListener(
            'click',
            async function () {


                /*
                |--------------------------------------------------------------------------
                | CAPÍTULO
                |--------------------------------------------------------------------------
                */

                const capituloId =
                    botao.dataset.capituloId;


                if (!capituloId) {

                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-danger">'
                            +
                            'Capítulo inválido.'
                            +
                            '</span>';
                    }

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | CARREGAMENTO
                |--------------------------------------------------------------------------
                */

                const htmlOriginal =
                    botao.innerHTML;


                botao.disabled = true;


                botao.innerHTML =
                    '<span '
                    +
                    'class="spinner-border spinner-border-sm me-1"'
                    +
                    '></span>'
                    +
                    'Preparando análise...';


                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-secondary">'
                        +
                        'Criando tarefas de processamento...'
                        +
                        '</span>';
                }


                try {


                    /*
                    |--------------------------------------------------------------------------
                    | FORM DATA
                    |--------------------------------------------------------------------------
                    */

                    const dados =
                        new FormData();


                    dados.append(
                        'capitulo_id',
                        capituloId
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | REQUEST
                    |--------------------------------------------------------------------------
                    */

                    const resposta =
                        await fetch(
                            'triagem_ia_solicitar.php',
                            {

                                method:
                                    'POST',

                                body:
                                    dados,

                            }
                        );


                    const texto =
                        await resposta.text();


                    let json;


                    try {

                        json =
                            JSON.parse(texto);

                    } catch (erroJson) {

                        console.error(
                            'Resposta recebida:',
                            texto
                        );


                        throw new Error(
                            'O servidor retornou uma resposta inválida.'
                        );
                    }


                    if (!json.success) {

                        throw new Error(
                            json.message
                            ||
                            'Não foi possível iniciar a análise.'
                        );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | SUCESSO
                    |--------------------------------------------------------------------------
                    */

                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-success fw-semibold">'
                            +
                            '<i class="bi bi-check-circle me-1"></i>'
                            +
                            json.message
                            +
                            '</span>';
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | BOTÃO
                    |--------------------------------------------------------------------------
                    */

                    botao.innerHTML =
                        '<i class="bi bi-hourglass-split"></i> '
                        +
                        'Análise solicitada';


                    /*
                     * Por enquanto NÃO recarregamos
                     * automaticamente.
                     *
                     * Queremos primeiro confirmar
                     * que a fila foi gravada.
                     */


                } catch (erro) {


                    console.error(erro);


                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-danger fw-semibold">'
                            +
                            '<i '
                            +
                            'class="bi bi-exclamation-triangle me-1"'
                            +
                            '></i>'
                            +
                            erro.message
                            +
                            '</span>';
                    }


                    botao.disabled = false;

                    botao.innerHTML =
                        htmlOriginal;
                }

            }
        );

    }
);
</script>

<!-- =========================================================
     IA — MONTAR PLANO DO EPISÓDIO
========================================================= -->

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botao =
            document.getElementById(
                'btnMontarPlanoIA'
            );


        const resultado =
            document.getElementById(
                'resultadoPlanoIA'
            );


        if (!botao) {
            return;
        }


        botao.addEventListener(
            'click',
            async function () {

                const capituloId =
                    botao.dataset.capituloId;


                if (!capituloId) {

                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-danger">'
                            +
                            'Capítulo inválido.'
                            +
                            '</span>';
                    }

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | CONFIRMAÇÃO
                |--------------------------------------------------------------------------
                */

                const confirmar =
                    window.confirm(
                        'A IA vai montar uma nova proposta '
                        +
                        'para este episódio.\n\n'
                        +
                        'Se já existir um rascunho do plano, '
                        +
                        'ele será substituído.\n\n'
                        +
                        'Deseja continuar?'
                    );


                if (!confirmar) {
                    return;
                }


                const htmlOriginal =
                    botao.innerHTML;


                botao.disabled =
                    true;


                botao.innerHTML =
                    '<span '
                    +
                    'class="spinner-border '
                    +
                    'spinner-border-sm me-1">'
                    +
                    '</span>'
                    +
                    'Montando episódio...';


                if (resultado) {

                    resultado.innerHTML =
                        '<span class="text-secondary">'
                        +
                        '<i class="bi bi-stars me-1"></i>'
                        +
                        'A IA está analisando o capítulo inteiro...'
                        +
                        '</span>';
                }


                try {

                    const dados =
                        new FormData();


                    dados.append(
                        'capitulo_id',
                        capituloId
                    );


                    const resposta =
                        await fetch(
                            'capitulo_plano_ia.php',
                            {

                                method:
                                    'POST',

                                body:
                                    dados,

                            }
                        );


                    const texto =
                        await resposta.text();


                    let json;


                    try {

                        json =
                            JSON.parse(
                                texto.replace(
                                    /^\uFEFF/,
                                    ''
                                )
                            );

                    } catch (erroJson) {

                        console.error(
                            'Resposta recebida:',
                            texto
                        );


                        throw new Error(
                            'O servidor retornou uma resposta inválida.'
                        );
                    }


                    if (!json.success) {

                        throw new Error(
                            json.message
                            ||
                            'Não foi possível montar o episódio.'
                        );
                    }


                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-success fw-semibold">'
                            +
                            '<i class="bi bi-check-circle me-1"></i>'
                            +
                            json.message
                            +
                            ' '
                            +
                            json.arquivos_selecionados
                            +
                            ' arquivo(s) selecionado(s).'
                            +
                            '</span>';
                    }


                    botao.innerHTML =
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        'Plano gerado';


                    /*
                     * Dá tempo de mostrar
                     * a confirmação antes
                     * do reload.
                     */

                    setTimeout(
                        function () {

                            window.location.reload();

                        },
                        1200
                    );


                } catch (erro) {

                    console.error(
                        erro
                    );


                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-danger fw-semibold">'
                            +
                            '<i '
                            +
                            'class="bi bi-exclamation-triangle me-1">'
                            +
                            '</i>'
                            +
                            erro.message
                            +
                            '</span>';
                    }


                    botao.disabled =
                        false;


                    botao.innerHTML =
                        htmlOriginal;
                }

            }
        );

    }
);
</script>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botao =
            document.getElementById(
                'btnGerarPrecisaoCapCut'
            );

        const resultado =
            document.getElementById(
                'resultadoPrecisaoCapCut'
            );

        const progresso =
            document.getElementById(
                'progressoPrecisaoCapCut'
            );

        const barra =
            document.getElementById(
                'barraPrecisaoCapCut'
            );

        const textoProgresso =
            document.getElementById(
                'textoProgressoPrecisaoCapCut'
            );


        if (!botao) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ESPERAR
        |--------------------------------------------------------------------------
        */

        function esperar(ms) {

            return new Promise(
                function (resolve) {

                    setTimeout(
                        resolve,
                        ms
                    );

                }
            );
        }


        /*
        |--------------------------------------------------------------------------
        | FETCH JSON
        |--------------------------------------------------------------------------
        */

        async function postJson(
            url,
            dados
        ) {

            const resposta =
                await fetch(
                    url,
                    {
                        method:
                            'POST',

                        body:
                            dados,
                    }
                );


            const texto =
                await resposta.text();


            let json;


            try {

                json =
                    JSON.parse(
                        texto.replace(
                            /^\uFEFF/,
                            ''
                        )
                    );

            } catch (erroJson) {

                console.error(
                    'Resposta inválida:',
                    texto
                );


                throw new Error(
                    'O servidor retornou uma resposta inválida.'
                );
            }


            if (
                !resposta.ok
                ||
                !json.success
            ) {

                throw new Error(
                    json.message
                    ||
                    'Erro durante o processamento.'
                );
            }


            return json;
        }


        /*
        |--------------------------------------------------------------------------
        | ATUALIZAR PROGRESSO
        |--------------------------------------------------------------------------
        */

        function atualizarProgresso(
            percentual,
            texto
        ) {

            percentual =
                Math.max(
                    0,
                    Math.min(
                        100,
                        percentual
                    )
                );


            if (barra) {

                barra.style.width =
                    percentual
                    +
                    '%';
            }


            if (textoProgresso) {

                textoProgresso.textContent =
                    texto;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | CONSULTAR SITUAÇÃO
        |--------------------------------------------------------------------------
        */

        async function consultarLote(
            capituloId
        ) {

            const dados =
                new FormData();


            dados.append(
                'capitulo_id',
                capituloId
            );


            return await postJson(
                'capitulo_edicao_clips_lote_ia.php',
                dados
            );
        }


        /*
        |--------------------------------------------------------------------------
        | GERAR CLIPS DE UM ARQUIVO
        |--------------------------------------------------------------------------
        */

        async function gerarClipsArquivo(
            capituloId,
            arquivoId
        ) {

            const dados =
                new FormData();


            dados.append(
                'capitulo_id',
                capituloId
            );


            dados.append(
                'arquivo_id',
                arquivoId
            );


            return await postJson(
                'capitulo_edicao_clips_ia.php',
                dados
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CLICK
        |--------------------------------------------------------------------------
        */

        botao.addEventListener(
            'click',
            async function () {

                const capituloId =
                    botao.dataset.capituloId;


                if (!capituloId) {

                    resultado.innerHTML =
                        '<span class="text-danger">'
                        +
                        'Capítulo inválido.'
                        +
                        '</span>';

                    return;
                }


                const confirmar =
                    window.confirm(
                        'O sistema vai preparar a precisão '
                        +
                        'dos vídeos escolhidos para este episódio.\n\n'
                        +
                        'O worker Bora pra Obra precisa estar aberto '
                        +
                        'no seu computador.\n\n'
                        +
                        'Deseja continuar?'
                    );


                if (!confirmar) {
                    return;
                }


                const htmlOriginal =
                    botao.innerHTML;


                botao.disabled =
                    true;


                botao.innerHTML =
                    '<span '
                    +
                    'class="spinner-border spinner-border-sm me-1">'
                    +
                    '</span>'
                    +
                    'Preparando CapCut...';


                if (progresso) {

                    progresso.classList.remove(
                        'd-none'
                    );
                }


                atualizarProgresso(
                    2,
                    'Verificando arquivos do episódio...'
                );


                resultado.innerHTML =
                    '<span class="text-secondary">'
                    +
                    '<i class="bi bi-bullseye me-1"></i>'
                    +
                    'Preparando precisão da linha de edição...'
                    +
                    '</span>';


                try {

                    /*
                    |--------------------------------------------------------------------------
                    | 1. MANDAR TODOS OS TIMESTAMPS FALTANTES PARA FILA
                    |--------------------------------------------------------------------------
                    */

                    const dadosSolicitar =
                        new FormData();


                    dadosSolicitar.append(
                        'capitulo_id',
                        capituloId
                    );


                    /*
                     * AGORA É TODOS.
                     */

                    dadosSolicitar.append(
                        'somente_um',
                        '0'
                    );


                    const fila =
                        await postJson(
                            'timestamps_ia_solicitar.php',
                            dadosSolicitar
                        );


                    if (
                        fila.enfileirados
                        >
                        0
                    ) {

                        resultado.innerHTML =
                            '<span class="text-primary fw-semibold">'
                            +
                            '<i class="bi bi-pc-display me-1"></i>'
                            +
                            fila.enfileirados
                            +
                            ' arquivo(s) enviados para o worker.'
                            +
                            '</span>';

                    } else {

                        resultado.innerHTML =
                            '<span class="text-secondary">'
                            +
                            'Os timestamps necessários já estão '
                            +
                            'prontos ou em processamento.'
                            +
                            '</span>';
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | 2. ESPERAR WORKER
                    |--------------------------------------------------------------------------
                    */

                    let situacao =
                        null;


                    let tentativas =
                        0;


                    /*
                     * Até aproximadamente 1 hora.
                     */

                    const maxTentativas =
                        720;


                    while (
                        tentativas
                        <
                        maxTentativas
                    ) {

                        tentativas++;


                        situacao =
                            await consultarLote(
                                capituloId
                            );


                        const totalPlano =
                            Number(
                                situacao.total_plano
                                ||
                                0
                            );


                        const errosTriagem =
                            Number(
                                situacao.total_erro_triagem
                                ||
                                0
                            );


                        const totalElegivel =
                            Math.max(
                                0,
                                totalPlano
                                -
                                errosTriagem
                            );


                        const prontos =
                            Number(
                                situacao.total_prontos
                                ||
                                0
                            );


                        const semTimestamps =
                            Array.isArray(
                                situacao.sem_timestamps
                            )
                                ?
                                situacao.sem_timestamps
                                :
                                [];


                        /*
                         * Erro de timestamp é diferente
                         * de arquivo ainda aguardando worker.
                         */

                        const errosTimestamp =
                            semTimestamps.filter(
                                function (arquivo) {

                                    return (
                                        arquivo.ia_timestamps_status
                                        ===
                                        'erro'
                                    );
                                }
                            );


                        const pendentes =
                            semTimestamps.filter(
                                function (arquivo) {

                                    return (
                                        arquivo.ia_timestamps_status
                                        !==
                                        'erro'
                                    );
                                }
                            );


                        let percentual =
                            10;


                        if (
                            totalElegivel
                            >
                            0
                        ) {

                            percentual =
                                10
                                +
                                Math.round(
                                    (
                                        prontos
                                        /
                                        totalElegivel
                                    )
                                    *
                                    55
                                );
                        }


                        atualizarProgresso(
                            percentual,
                            'Precisão temporal: '
                            +
                            prontos
                            +
                            '/'
                            +
                            totalElegivel
                            +
                            ' arquivo(s) prontos.'
                        );


                        /*
                         * Não há mais nada sendo
                         * aguardado pelo worker.
                         */

                        if (
                            pendentes.length
                            ===
                            0
                        ) {

                            break;
                        }


                        resultado.innerHTML =
                            '<span class="text-primary">'
                            +
                            '<i class="bi bi-hourglass-split me-1"></i>'
                            +
                            'Worker processando '
                            +
                            pendentes.length
                            +
                            ' arquivo(s)...'
                            +
                            '</span>';


                        await esperar(
                            5000
                        );
                    }


                    if (!situacao) {

                        throw new Error(
                            'Não foi possível consultar a linha de edição.'
                        );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | 3. PEGAR ARQUIVOS PRONTOS QUE AINDA NÃO POSSUEM CLIPS
                    |--------------------------------------------------------------------------
                    */

                    const arquivosProntos =
                        Array.isArray(
                            situacao.arquivos
                        )
                            ?
                            situacao.arquivos
                            :
                            [];


                    const arquivosParaGerar =
                        arquivosProntos.filter(
                            function (arquivo) {

                                return (
                                    Number(
                                        arquivo.total_clips_ia
                                        ||
                                        0
                                    )
                                    ===
                                    0
                                );
                            }
                        );


                    const jaGerados =
                        arquivosProntos.filter(
                            function (arquivo) {

                                return (
                                    Number(
                                        arquivo.total_clips_ia
                                        ||
                                        0
                                    )
                                    >
                                    0
                                );
                            }
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | 4. GERAR CORTES EXATOS
                    |--------------------------------------------------------------------------
                    */

                    let geradosAgora =
                        0;


                    const totalParaGerar =
                        arquivosParaGerar.length;


                    for (
                        let indice = 0;
                        indice < totalParaGerar;
                        indice++
                    ) {

                        const arquivo =
                            arquivosParaGerar[
                                indice
                            ];


                        const percentual =
                            65
                            +
                            Math.round(
                                (
                                    indice
                                    /
                                    Math.max(
                                        1,
                                        totalParaGerar
                                    )
                                )
                                *
                                30
                            );


                        atualizarProgresso(
                            percentual,
                            'IA escolhendo cortes: '
                            +
                            arquivo.nome_arquivo
                            +
                            ' ('
                            +
                            (
                                indice
                                +
                                1
                            )
                            +
                            '/'
                            +
                            totalParaGerar
                            +
                            ')'
                        );


                        resultado.innerHTML =
                            '<span class="text-primary">'
                            +
                            '<i class="bi bi-stars me-1"></i>'
                            +
                            'Escolhendo cortes de '
                            +
                            arquivo.nome_arquivo
                            +
                            '...'
                            +
                            '</span>';


                        await gerarClipsArquivo(
                            capituloId,
                            arquivo.arquivo_id
                        );


                        geradosAgora++;


                        /*
                         * Pequeno intervalo entre
                         * chamadas à IA.
                         */

                        await esperar(
                            1000
                        );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | 5. FINAL
                    |--------------------------------------------------------------------------
                    */

                    atualizarProgresso(
                        100,
                        'Precisão para CapCut concluída.'
                    );


                    if (barra) {

                        barra.classList.remove(
                            'progress-bar-animated'
                        );
                    }


                    const errosTriagem =
                        Array.isArray(
                            situacao.erro_triagem
                        )
                            ?
                            situacao.erro_triagem.length
                            :
                            0;


                    const errosTimestamp =
                        Array.isArray(
                            situacao.sem_timestamps
                        )
                            ?
                            situacao.sem_timestamps.filter(
                                function (arquivo) {

                                    return (
                                        arquivo.ia_timestamps_status
                                        ===
                                        'erro'
                                    );
                                }
                            ).length
                            :
                            0;


                    let mensagemFinal =
                        '<span class="text-success fw-semibold">'
                        +
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        'Precisão para CapCut concluída. '
                        +
                        (
                            geradosAgora
                            +
                            jaGerados.length
                        )
                        +
                        ' arquivo(s) com cortes preparados.'
                        +
                        '</span>';


                    if (
                        errosTriagem
                        >
                        0
                        ||
                        errosTimestamp
                        >
                        0
                    ) {

                        mensagemFinal +=
                            '<div class="text-warning mt-1">'
                            +
                            '<i class="bi bi-exclamation-triangle me-1"></i>'
                            +
                            errosTriagem
                            +
                            ' arquivo(s) ignorado(s) por problema na triagem; '
                            +
                            errosTimestamp
                            +
                            ' com problema nos timestamps.'
                            +
                            '</div>';
                    }


                    resultado.innerHTML =
                        mensagemFinal;


                    botao.innerHTML =
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        'Precisão pronta';


                    /*
                     * Atualizar a Etapa 3.
                     */

                    setTimeout(
                        function () {

                            window.location.reload();

                        },
                        2500
                    );


                } catch (erro) {

                    console.error(
                        erro
                    );


                    resultado.innerHTML =
                        '<span class="text-danger fw-semibold">'
                        +
                        '<i class="bi bi-exclamation-triangle me-1"></i>'
                        +
                        erro.message
                        +
                        '</span>';


                    atualizarProgresso(
                        0,
                        'Processamento interrompido.'
                    );


                    botao.disabled =
                        false;


                    botao.innerHTML =
                        htmlOriginal;
                }

            }
        );

    }
);
</script>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botao =
            document.getElementById(
                'btnAbrirGuiaCapCut'
            );


        const modalElemento =
            document.getElementById(
                'modalGuiaCapCut'
            );


        const resumo =
            document.getElementById(
                'resumoGuiaCapCut'
            );


        const loading =
            document.getElementById(
                'loadingGuiaCapCut'
            );


        const conteudo =
            document.getElementById(
                'conteudoGuiaCapCut'
            );


        const btnCopiar =
            document.getElementById(
                'btnCopiarGuiaCapCut'
            );


        if (
            !botao
            ||
            !modalElemento
        ) {

            return;
        }


        let textoGuiaAtual = '';


        /*
        |--------------------------------------------------------------------------
        | ESCAPAR HTML
        |--------------------------------------------------------------------------
        */

        function escaparHtml(
            valor
        ) {

            const div =
                document.createElement(
                    'div'
                );


            div.textContent =
                valor == null
                    ?
                    ''
                    :
                    String(valor);


            return div.innerHTML;
        }


        /*
        |--------------------------------------------------------------------------
        | FUNÇÃO VISUAL
        |--------------------------------------------------------------------------
        */

        function nomeFuncao(
            funcao
        ) {

            const nomes = {

                gancho:
                    'Gancho',

                principal:
                    'Principal',

                broll:
                    'B-roll',

                encerramento:
                    'Encerramento',

            };


            return (
                nomes[funcao]
                ||
                funcao
                ||
                'Principal'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | BADGE
        |--------------------------------------------------------------------------
        */

        function badgeFuncao(
            funcao
        ) {

            let classe =
                'text-bg-secondary';


            if (
                funcao ===
                'gancho'
            ) {

                classe =
                    'text-bg-danger';
            }


            if (
                funcao ===
                'principal'
            ) {

                classe =
                    'text-bg-primary';
            }


            if (
                funcao ===
                'broll'
            ) {

                classe =
                    'text-bg-warning';
            }


            if (
                funcao ===
                'encerramento'
            ) {

                classe =
                    'text-bg-success';
            }


            return (
                '<span class="badge '
                +
                classe
                +
                '">'
                +
                escaparHtml(
                    nomeFuncao(
                        funcao
                    )
                )
                +
                '</span>'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CARREGAR GUIA
        |--------------------------------------------------------------------------
        */

        async function carregarGuia(
            capituloId
        ) {

            loading.classList.remove(
                'd-none'
            );


            conteudo.classList.add(
                'd-none'
            );


            conteudo.innerHTML =
                '';


            resumo.textContent =
                'Carregando...';


            const dados =
                new FormData();


            dados.append(
                'capitulo_id',
                capituloId
            );


            const resposta =
                await fetch(
                    'capitulo_guia_capcut.php',
                    {

                        method:
                            'POST',

                        body:
                            dados,

                    }
                );


            const texto =
                await resposta.text();


            let json;


            try {

                json =
                    JSON.parse(
                        texto.replace(
                            /^\uFEFF/,
                            ''
                        )
                    );

            } catch (erroJson) {

                console.error(
                    texto
                );


                throw new Error(
                    'O servidor retornou uma resposta inválida.'
                );
            }


            if (
                !resposta.ok
                ||
                !json.success
            ) {

                throw new Error(
                    json.message
                    ||
                    'Não foi possível carregar o Guia CapCut.'
                );
            }


            const clips =
                Array.isArray(
                    json.clips
                )
                    ?
                    json.clips
                    :
                    [];


            resumo.textContent =
                'Capítulo '
                +
                json.capitulo.numero
                +
                ' — '
                +
                json.total_clips
                +
                ' corte(s) — '
                +
                'Duração selecionada: '
                +
                json.duracao_total;


            if (
                clips.length
                ===
                0
            ) {

                conteudo.innerHTML =
                    '<div class="alert alert-light border mb-0">'
                    +
                    '<i class="bi bi-info-circle me-1"></i>'
                    +
                    'Ainda não existem cortes preparados para este capítulo.'
                    +
                    '</div>';


                textoGuiaAtual =
                    'Ainda não existem cortes preparados.';


                loading.classList.add(
                    'd-none'
                );


                conteudo.classList.remove(
                    'd-none'
                );


                return;
            }


            /*
            |--------------------------------------------------------------------------
            | HTML
            |--------------------------------------------------------------------------
            */

            let html =
                '<div class="vstack gap-3">';


            let textoGuia =
                'GUIA CAPCUT — CAPÍTULO '
                +
                json.capitulo.numero
                +
                '\n'
                +
                json.capitulo.titulo
                +
                '\n'
                +
                'Duração selecionada: '
                +
                json.duracao_total
                +
                '\n'
                +
                'Total de cortes: '
                +
                json.total_clips
                +
                '\n\n';


            clips.forEach(
                function (
                    clip,
                    indice
                ) {

                    const numero =
                        String(
                            indice + 1
                        )
                        .padStart(
                            2,
                            '0'
                        );


                    const audioTexto =
                        clip.usar_audio
                            ?
                            'Áudio original'
                            :
                            'Sem áudio';


                    html +=
                        '<div class="border rounded p-3">'
                        +

                        '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">'
                        +

                        '<div>'
                        +

                        '<div class="small text-secondary">'
                        +
                        'CORTE '
                        +
                        numero
                        +
                        '</div>'
                        +

                        '<div class="fw-bold">'
                        +
                        escaparHtml(
                            clip.nome_arquivo
                        )
                        +
                        '</div>'
                        +

                        '</div>'
                        +

                        badgeFuncao(
                            clip.funcao
                        )
                        +

                        '</div>'
                        +

                        '<div class="row g-2">'
                        +

                        '<div class="col-md-3">'
                        +
                        '<div class="small text-secondary">Início</div>'
                        +
                        '<div class="fw-semibold font-monospace">'
                        +
                        escaparHtml(
                            clip.inicio
                        )
                        +
                        '</div>'
                        +
                        '</div>'
                        +

                        '<div class="col-md-3">'
                        +
                        '<div class="small text-secondary">Fim</div>'
                        +
                        '<div class="fw-semibold font-monospace">'
                        +
                        escaparHtml(
                            clip.fim
                        )
                        +
                        '</div>'
                        +
                        '</div>'
                        +

                        '<div class="col-md-3">'
                        +
                        '<div class="small text-secondary">Duração</div>'
                        +
                        '<div class="fw-semibold">'
                        +
                        escaparHtml(
                            clip.duracao
                        )
                        +
                        '</div>'
                        +
                        '</div>'
                        +

                        '<div class="col-md-3">'
                        +
                        '<div class="small text-secondary">Áudio</div>'
                        +
                        '<div class="fw-semibold">'
                        +
                        escaparHtml(
                            audioTexto
                        )
                        +
                        '</div>'
                        +
                        '</div>'
                        +

                        '</div>';


                    if (
                        clip.titulo_clip
                    ) {

                        html +=
                            '<div class="mt-3">'
                            +
                            '<div class="small text-secondary">O que é este corte</div>'
                            +
                            '<div>'
                            +
                            escaparHtml(
                                clip.titulo_clip
                            )
                            +
                            '</div>'
                            +
                            '</div>';
                    }


                    if (
                        clip.instrucao
                    ) {

                        html +=
                            '<div class="mt-2">'
                            +
                            '<div class="small text-secondary">Instrução</div>'
                            +
                            '<div>'
                            +
                            escaparHtml(
                                clip.instrucao
                            )
                            +
                            '</div>'
                            +
                            '</div>';
                    }


                    if (
                        clip.texto_tela
                    ) {

                        html +=
                            '<div class="mt-2">'
                            +
                            '<div class="small text-secondary">Texto na tela</div>'
                            +
                            '<div>'
                            +
                            escaparHtml(
                                clip.texto_tela
                            )
                            +
                            '</div>'
                            +
                            '</div>';
                    }


                    if (
                        clip.drive_url
                    ) {

                        html +=
                            '<div class="mt-3">'
                            +
                            '<a '
                            +
                            'href="'
                            +
                            escaparHtml(
                                clip.drive_url
                            )
                            +
                            '" '
                            +
                            'target="_blank" '
                            +
                            'class="btn btn-outline-secondary btn-sm">'
                            +
                            '<i class="bi bi-play-circle me-1"></i>'
                            +
                            'Abrir bruto'
                            +
                            '</a>'
                            +
                            '</div>';
                    }


                    html +=
                        '</div>';


                    /*
                    |--------------------------------------------------------------------------
                    | TEXTO PARA COPIAR
                    |--------------------------------------------------------------------------
                    */

                    textoGuia +=
                        numero
                        +
                        ' — '
                        +
                        clip.nome_arquivo
                        +
                        '\n'
                        +
                        clip.inicio
                        +
                        ' → '
                        +
                        clip.fim
                        +
                        ' | '
                        +
                        clip.duracao
                        +
                        '\n'
                        +
                        'Função: '
                        +
                        nomeFuncao(
                            clip.funcao
                        )
                        +
                        '\n'
                        +
                        'Áudio: '
                        +
                        audioTexto
                        +
                        '\n';


                    if (
                        clip.titulo_clip
                    ) {

                        textoGuia +=
                            clip.titulo_clip
                            +
                            '\n';
                    }


                    if (
                        clip.instrucao
                    ) {

                        textoGuia +=
                            'Instrução: '
                            +
                            clip.instrucao
                            +
                            '\n';
                    }


                    if (
                        clip.texto_tela
                    ) {

                        textoGuia +=
                            'Texto na tela: '
                            +
                            clip.texto_tela
                            +
                            '\n';
                    }


                    textoGuia +=
                        '\n';

                }
            );


            html +=
                '</div>';


            textoGuiaAtual =
                textoGuia;


            conteudo.innerHTML =
                html;


            loading.classList.add(
                'd-none'
            );


            conteudo.classList.remove(
                'd-none'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ABRIR
        |--------------------------------------------------------------------------
        */

        botao.addEventListener(
            'click',
            async function () {

                const capituloId =
                    botao.dataset.capituloId;


                const modal =
                    bootstrap.Modal.getOrCreateInstance(
                        modalElemento
                    );


                modal.show();


                try {

                    await carregarGuia(
                        capituloId
                    );

                } catch (erro) {

                    console.error(
                        erro
                    );


                    loading.classList.add(
                        'd-none'
                    );


                    conteudo.classList.remove(
                        'd-none'
                    );


                    conteudo.innerHTML =
                        '<div class="alert alert-danger mb-0">'
                        +
                        '<i class="bi bi-exclamation-triangle me-1"></i>'
                        +
                        escaparHtml(
                            erro.message
                        )
                        +
                        '</div>';


                    resumo.textContent =
                        'Erro ao carregar guia.';
                }

            }
        );


        /*
        |--------------------------------------------------------------------------
        | COPIAR
        |--------------------------------------------------------------------------
        */

        if (btnCopiar) {

            btnCopiar.addEventListener(
                'click',
                async function () {

                    if (
                        !textoGuiaAtual
                    ) {

                        return;
                    }


                    try {

                        await navigator.clipboard.writeText(
                            textoGuiaAtual
                        );


                        const original =
                            btnCopiar.innerHTML;


                        btnCopiar.innerHTML =
                            '<i class="bi bi-check-circle me-1"></i>'
                            +
                            'Copiado';


                        setTimeout(
                            function () {

                                btnCopiar.innerHTML =
                                    original;

                            },
                            1500
                        );


                    } catch (erro) {

                        alert(
                            'Não foi possível copiar o guia.'
                        );
                    }

                }
            );

        }

    }
);
</script>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botao =
            document.getElementById(
                'btnPrepararArquivosCapCut'
            );

        const status =
            document.getElementById(
                'statusPreparacaoCapCut'
            );


        if (!botao) {
            return;
        }


        botao.addEventListener(
            'click',
            async function () {

                const capituloId =
                    botao.dataset.capituloId;


                if (!capituloId) {

                    if (status) {

                        status.innerHTML =
                            '<span class="text-danger">'
                            +
                            'Capítulo inválido.'
                            +
                            '</span>';
                    }

                    return;
                }


                const confirmar =
                    window.confirm(
                        'O worker Bora pra Obra deve estar aberto no seu computador.\n\n'
                        +
                        'O sistema vai baixar os brutos necessários e gerar '
                        +
                        'os clips prontos para importar no CapCut.\n\n'
                        +
                        'Deseja continuar?'
                    );


                if (!confirmar) {
                    return;
                }


                const htmlOriginal =
                    botao.innerHTML;


                botao.disabled =
                    true;


                botao.innerHTML =
                    '<span '
                    +
                    'class="spinner-border spinner-border-sm me-1">'
                    +
                    '</span>'
                    +
                    'Enviando ao worker...';


                if (status) {

                    status.innerHTML =
                        '<span class="text-secondary">'
                        +
                        '<i class="bi bi-pc-display me-1"></i>'
                        +
                        'Criando tarefa de preparação...'
                        +
                        '</span>';
                }


                try {

                    const dados =
                        new FormData();


                    dados.append(
                        'capitulo_id',
                        capituloId
                    );


                    const resposta =
                        await fetch(
                            'capitulo_exportacao_capcut_solicitar.php',
                            {

                                method:
                                    'POST',

                                body:
                                    dados,

                            }
                        );


                    const texto =
                        await resposta.text();


                    let json;


                    try {

                        json =
                            JSON.parse(
                                texto.replace(
                                    /^\uFEFF/,
                                    ''
                                )
                            );

                    } catch (erroJson) {

                        console.error(
                            'Resposta recebida:',
                            texto
                        );


                        throw new Error(
                            'O servidor retornou uma resposta inválida.'
                        );
                    }


                    if (
                        !resposta.ok
                        ||
                        !json.success
                    ) {

                        throw new Error(
                            json.message
                            ||
                            'Não foi possível preparar os arquivos.'
                        );
                    }


                    if (status) {

                        status.innerHTML =
                            '<span class="text-success fw-semibold">'
                            +
                            '<i class="bi bi-check-circle me-1"></i>'
                            +
                            json.message
                            +
                            '<br>'
                            +
                            '<span class="text-secondary">'
                            +
                            json.total_clips
                            +
                            ' clip(s) serão preparados pelo worker.'
                            +
                            '</span>'
                            +
                            '</span>';
                    }


                    botao.innerHTML =
                        '<i class="bi bi-hourglass-split me-1"></i>'
                        +
                        'Aguardando worker';


                } catch (erro) {

                    console.error(
                        erro
                    );


                    if (status) {

                        status.innerHTML =
                            '<span class="text-danger fw-semibold">'
                            +
                            '<i class="bi bi-exclamation-triangle me-1"></i>'
                            +
                            erro.message
                            +
                            '</span>';
                    }


                    botao.disabled =
                        false;


                    botao.innerHTML =
                        htmlOriginal;
                }

            }
        );

    }
);
</script>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botao =
            document.getElementById(
                'btnRefinarCortesFala'
            );

        const resultado =
            document.getElementById(
                'resultadoRefinoCortes'
            );


        if (!botao) {
            return;
        }


        function esperar(ms) {

            return new Promise(
                resolve =>
                    setTimeout(
                        resolve,
                        ms
                    )
            );
        }


        botao.addEventListener(
            'click',
            async function () {

                const capituloId =
                    botao.dataset.capituloId;


                const confirmar =
                    window.confirm(
                        'A IA vai revisar os cortes um por um '
                        +
                        'para evitar falas cortadas no meio.\n\n'
                        +
                        'Deseja continuar?'
                    );


                if (!confirmar) {
                    return;
                }


                const htmlOriginal =
                    botao.innerHTML;


                botao.disabled =
                    true;


                let continuar =
                    true;


                try {

                    while (continuar) {

                        botao.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-1"></span>'
                            +
                            'Refinando...';


                        const dados =
                            new FormData();


                        dados.append(
                            'capitulo_id',
                            capituloId
                        );


                        const resposta =
                            await fetch(
                                'capitulo_edicao_clips_refinar_ia.php',
                                {
                                    method:
                                        'POST',

                                    body:
                                        dados,
                                }
                            );


                        const texto =
                            await resposta.text();


                        let json;


                        try {

                            json =
                                JSON.parse(
                                    texto.replace(
                                        /^\uFEFF/,
                                        ''
                                    )
                                );

                        } catch (erroJson) {

                            console.error(
                                texto
                            );


                            throw new Error(
                                'O servidor retornou uma resposta inválida.'
                            );
                        }


                        if (
                            !resposta.ok
                            ||
                            !json.success
                        ) {

                            throw new Error(
                                json.message
                                ||
                                'Erro durante o refinamento.'
                            );
                        }


                        if (resultado) {

                            resultado.innerHTML =
                                '<span class="text-primary">'
                                +
                                '<i class="bi bi-magic me-1"></i>'
                                +
                                'Cortes refinados: '
                                +
                                json.refinados
                                +
                                '/'
                                +
                                json.total_clips
                                +
                                '</span>';
                        }


                        if (json.done) {

                            continuar =
                                false;

                            break;
                        }


                        await esperar(
                            500
                        );
                    }


                    botao.innerHTML =
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        'Cortes refinados';


                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-success fw-semibold">'
                            +
                            '<i class="bi bi-check-circle me-1"></i>'
                            +
                            'Refinamento concluído.'
                            +
                            '</span>';
                    }


                } catch (erro) {

                    console.error(
                        erro
                    );


                    botao.disabled =
                        false;


                    botao.innerHTML =
                        htmlOriginal;


                    if (resultado) {

                        resultado.innerHTML =
                            '<span class="text-danger fw-semibold">'
                            +
                            '<i class="bi bi-exclamation-triangle me-1"></i>'
                            +
                            erro.message
                            +
                            '</span>';
                    }
                }

            }
        );

    }
);
</script>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botao =
            document.getElementById(
                'btnGerarRoteirosShortsIA'
            );

        const resultado =
            document.getElementById(
                'resultadoRoteirosShortsIA'
            );

        const lista =
            document.getElementById(
                'listaRoteirosShorts'
            );

        const badge =
            document.getElementById(
                'badgeShorts'
            );


        if (!botao) {
            return;
        }


        botao.addEventListener(
            'click',
            async function () {

                const capituloId =
                    botao.dataset.capituloId;


                if (!capituloId) {

                    resultado.innerHTML =
                        '<span class="text-danger">'
                        +
                        'Capítulo inválido.'
                        +
                        '</span>';

                    return;
                }


                const confirmar =
                    window.confirm(
                        'A IA vai analisar as transcrições dos vídeos deste capítulo e gerar os roteiros dos Shorts.\n\n'
                        +
                        'Esse processo pode levar alguns segundos.\n\n'
                        +
                        'Deseja continuar?'
                    );


                if (!confirmar) {
                    return;
                }


                const htmlOriginal =
                    botao.innerHTML;


                botao.disabled =
                    true;


                botao.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span>'
                    +
                    'Analisando brutos...';


                resultado.innerHTML =
                    '<span class="text-secondary">'
                    +
                    '<i class="bi bi-hourglass-split me-1"></i>'
                    +
                    'A IA está lendo as transcrições e procurando oportunidades...'
                    +
                    '</span>';


                try {

                    const dados =
                        new FormData();


                    dados.append(
                        'capitulo_id',
                        capituloId
                    );


                    const resposta =
                        await fetch(
                            'capitulo_roteiros_shorts_ia.php',
                            {
                                method:
                                    'POST',

                                body:
                                    dados
                            }
                        );


                    const texto =
                        await resposta.text();


                    let json;


                    try {

                        json =
                            JSON.parse(
                                texto.replace(
                                    /^\uFEFF/,
                                    ''
                                )
                            );

                    } catch (erroJson) {

                        console.error(
                            'Resposta recebida:',
                            texto
                        );

                        throw new Error(
                            'O servidor retornou uma resposta inválida.'
                        );
                    }


                    if (
                        !resposta.ok
                        ||
                        !json.success
                    ) {

                        throw new Error(
                            json.message
                            ||
                            'Não foi possível gerar os roteiros.'
                        );
                    }


                    resultado.innerHTML =
                        '<span class="text-success fw-semibold">'
                        +
                        '<i class="bi bi-check-circle me-1"></i>'
                        +
                        json.message
                        +
                        '</span>';


                    if (badge) {

                        badge.className =
                            'badge text-bg-success';

                        badge.textContent =
                            json.total
                            +
                            ' roteiro(s)';

                    }


                    /*
                    |--------------------------------------------------------------------------
                    | RECARREGAR
                    |--------------------------------------------------------------------------
                    |
                    | A próxima etapa será buscar os roteiros
                    | salvos no banco e renderizar os cards.
                    |
                    |--------------------------------------------------------------------------
                    */

                    setTimeout(
                        function () {

                            window.location.reload();

                        },
                        700
                    );


                } catch (erro) {

                    console.error(
                        erro
                    );


                    resultado.innerHTML =
                        '<span class="text-danger fw-semibold">'
                        +
                        '<i class="bi bi-exclamation-triangle me-1"></i>'
                        +
                        erro.message
                        +
                        '</span>';


                    botao.disabled =
                        false;


                    botao.innerHTML =
                        htmlOriginal;

                }

            }
        );

    }
);

</script>

<?php
require __DIR__ . '/includes/footer.php';

?>