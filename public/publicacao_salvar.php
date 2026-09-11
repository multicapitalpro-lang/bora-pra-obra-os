<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

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
    | STATUS
    |--------------------------------------------------------------------------
    */

    $statusPermitidos = [
        'nao_iniciada',
        'preparando',
        'agendada',
        'publicada'
    ];

    $statusPublicacao = trim(
        (string) ($_POST['status_publicacao'] ?? 'nao_iniciada')
    );

    if (!in_array($statusPublicacao, $statusPermitidos, true)) {
        $statusPublicacao = 'nao_iniciada';
    }

    /*
    |--------------------------------------------------------------------------
    | CHECKLIST
    |--------------------------------------------------------------------------
    */

    $tituloRevisado =
        isset($_POST['titulo_revisado']) ? 1 : 0;

    $descricaoRevisada =
        isset($_POST['descricao_revisada']) ? 1 : 0;

    $thumbnailAplicada =
        isset($_POST['thumbnail_aplicada']) ? 1 : 0;

    $tagsRevisadas =
        isset($_POST['tags_revisadas']) ? 1 : 0;

    $cardsConferidos =
        isset($_POST['cards_conferidos']) ? 1 : 0;

    $telaFinalConferida =
        isset($_POST['tela_final_conferida']) ? 1 : 0;

    $publicacaoConferida =
        isset($_POST['publicacao_conferida']) ? 1 : 0;

    /*
    |--------------------------------------------------------------------------
    | METADADOS DO EPISÓDIO
    |--------------------------------------------------------------------------
    */

    $tituloPublico = trim(
        (string) ($_POST['titulo_publico'] ?? '')
    );

    $descricao = trim(
        (string) ($_POST['descricao'] ?? '')
    );

    $tags = trim(
        (string) ($_POST['tags'] ?? '')
    );

    $youtubeUrl = trim(
        (string) ($_POST['youtube_url'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | YOUTUBE
    |--------------------------------------------------------------------------
    */

    $youtubeVideoId = trim(
        (string) ($_POST['youtube_video_id'] ?? '')
    );

    $observacoes = trim(
        (string) ($_POST['observacoes'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | DATA AGENDADA
    |--------------------------------------------------------------------------
    */

    $dataAgendada = trim(
        (string) ($_POST['data_agendada'] ?? '')
    );

    if ($dataAgendada !== '') {

        $timestamp = strtotime($dataAgendada);

        if ($timestamp === false) {
            throw new RuntimeException(
                'Data de agendamento inválida.'
            );
        }

        $dataAgendada = date(
            'Y-m-d H:i:s',
            $timestamp
        );

    } else {

        $dataAgendada = null;
    }

    /*
    |--------------------------------------------------------------------------
    | DATA DE PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */

    $publicadoEm = null;

    if ($statusPublicacao === 'publicada') {
        $publicadoEm = date('Y-m-d H:i:s');
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES DA PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */

    $checklistCompleto =
        $tituloRevisado === 1
        &&
        $descricaoRevisada === 1
        &&
        $thumbnailAplicada === 1
        &&
        $tagsRevisadas === 1
        &&
        $cardsConferidos === 1
        &&
        $telaFinalConferida === 1
        &&
        $publicacaoConferida === 1;

    /*
    |--------------------------------------------------------------------------
    | AGENDADA
    |--------------------------------------------------------------------------
    */

    if ($statusPublicacao === 'agendada') {

        if ($dataAgendada === null) {
            throw new RuntimeException(
                'Informe a data e hora antes de marcar como Agendada.'
            );
        }

        if ($tituloPublico === '') {
            throw new RuntimeException(
                'Preencha o título público antes de agendar.'
            );
        }

        if ($descricao === '') {
            throw new RuntimeException(
                'Preencha a descrição antes de agendar.'
            );
        }

        if ($tags === '') {
            throw new RuntimeException(
                'Preencha as tags antes de agendar.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PUBLICADA
    |--------------------------------------------------------------------------
    */

    if ($statusPublicacao === 'publicada') {

        if (!$checklistCompleto) {
            throw new RuntimeException(
                'Complete todos os itens do checklist antes de marcar o episódio como Publicado.'
            );
        }

        if ($tituloPublico === '') {
            throw new RuntimeException(
                'O título público é obrigatório.'
            );
        }

        if ($descricao === '') {
            throw new RuntimeException(
                'A descrição é obrigatória.'
            );
        }

        if ($youtubeUrl === '') {
            throw new RuntimeException(
                'Informe o link do vídeo publicado no YouTube.'
            );
        }

        if ($youtubeVideoId === '') {
            throw new RuntimeException(
                'Informe o YouTube Video ID.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SALVAR CONTROLE DA PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */

    $sql = "
        INSERT INTO capitulo_publicacao
        (
            capitulo_id,
            status_publicacao,
            titulo_revisado,
            descricao_revisada,
            thumbnail_aplicada,
            tags_revisadas,
            cards_conferidos,
            tela_final_conferida,
            publicacao_conferida,
            youtube_video_id,
            data_agendada,
            publicado_em,
            observacoes
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )

        ON DUPLICATE KEY UPDATE

            status_publicacao = VALUES(status_publicacao),
            titulo_revisado = VALUES(titulo_revisado),
            descricao_revisada = VALUES(descricao_revisada),
            thumbnail_aplicada = VALUES(thumbnail_aplicada),
            tags_revisadas = VALUES(tags_revisadas),
            cards_conferidos = VALUES(cards_conferidos),
            tela_final_conferida = VALUES(tela_final_conferida),
            publicacao_conferida = VALUES(publicacao_conferida),
            youtube_video_id = VALUES(youtube_video_id),
            data_agendada = VALUES(data_agendada),

            publicado_em = CASE
                WHEN VALUES(status_publicacao) = 'publicada'
                THEN COALESCE(
                    capitulo_publicacao.publicado_em,
                    VALUES(publicado_em)
                )
                ELSE capitulo_publicacao.publicado_em
            END,

            observacoes = VALUES(observacoes)
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        $capituloId,
        $statusPublicacao,
        $tituloRevisado,
        $descricaoRevisada,
        $thumbnailAplicada,
        $tagsRevisadas,
        $cardsConferidos,
        $telaFinalConferida,
        $publicacaoConferida,
        $youtubeVideoId !== ''
            ? $youtubeVideoId
            : null,
        $dataAgendada,
        $publicadoEm,
        $observacoes !== ''
            ? $observacoes
            : null
    ]);

    /*
    |--------------------------------------------------------------------------
    | DATA PÚBLICA DO CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $dataPublicacaoCapitulo = null;

    if ($dataAgendada !== null) {

        $dataPublicacaoCapitulo = date(
            'Y-m-d',
            strtotime($dataAgendada)
        );

    } elseif ($statusPublicacao === 'publicada') {

        $dataPublicacaoCapitulo =
            date('Y-m-d');
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS GERAL DO CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $statusCapitulo = 'Catalogado';

    if ($statusPublicacao === 'preparando') {
        $statusCapitulo = 'Pronto';
    }

    if ($statusPublicacao === 'agendada') {
        $statusCapitulo = 'Agendado';
    }

    if ($statusPublicacao === 'publicada') {
        $statusCapitulo = 'Publicado';
    }

    /*
    |--------------------------------------------------------------------------
    | ATUALIZAR DADOS PÚBLICOS DO CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo = $pdo->prepare(
        '
        UPDATE capitulos

        SET
            titulo_publico = ?,
            descricao = ?,
            tags = ?,
            youtube_url = ?,
            data_publicacao = ?,
            status = ?

        WHERE id = ?
        '
    );

    $stmtCapitulo->execute([
        $tituloPublico !== ''
            ? $tituloPublico
            : null,

        $descricao !== ''
            ? $descricao
            : null,

        $tags !== ''
            ? $tags
            : null,

        $youtubeUrl !== ''
            ? $youtubeUrl
            : null,

        $dataPublicacaoCapitulo,

        $statusCapitulo,

        $capituloId
    ]);

    /*
    |--------------------------------------------------------------------------
    | AVANÇAR FASE APÓS PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */

    if ($statusPublicacao === 'publicada') {

        $stmtFase = $pdo->prepare(
            '
            UPDATE capitulos
            SET fase_producao = "distribuicao"
            WHERE id = ?
            '
        );

        $stmtFase->execute([
            $capituloId
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PROGRESSO
    |--------------------------------------------------------------------------
    */

    $checklist = [
        $tituloRevisado,
        $descricaoRevisada,
        $thumbnailAplicada,
        $tagsRevisadas,
        $cardsConferidos,
        $telaFinalConferida,
        $publicacaoConferida
    ];

    $concluidos =
        array_sum($checklist);

    $percentual = (int) round(
        ($concluidos / 7) * 100
    );

    echo json_encode([
        'success' => true,
        'message' => 'Publicação salva com sucesso.',
        'concluidos' => $concluidos,
        'total' => 7,
        'percentual' => $percentual,
        'status_publicacao' => $statusPublicacao,
        'status_capitulo' => $statusCapitulo
    ]);

} catch (Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}