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

    /*
    |--------------------------------------------------------------------------
    | IDENTIFICAÇÃO
    |--------------------------------------------------------------------------
    */

    $capituloId = (int) ($_POST['capitulo_id'] ?? 0);
    $corteId = (int) ($_POST['corte_id'] ?? 0);

    if ($capituloId <= 0) {
        throw new RuntimeException(
            'Capítulo inválido.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DADOS DO CORTE
    |--------------------------------------------------------------------------
    */

    $titulo = trim(
        (string) ($_POST['titulo'] ?? '')
    );

    $arquivoOrigemId = (int) (
        $_POST['arquivo_origem_id'] ?? 0
    );

    $inicio = trim(
        (string) ($_POST['inicio'] ?? '')
    );

    $fim = trim(
        (string) ($_POST['fim'] ?? '')
    );

    $formato = trim(
        (string) ($_POST['formato'] ?? '9:16')
    );

    $status = trim(
        (string) ($_POST['status'] ?? 'ideia')
    );

    $duracaoFinal = trim(
        (string) ($_POST['duracao_final'] ?? '')
    );

    $gancho = trim(
        (string) ($_POST['gancho'] ?? '')
    );

    $legenda = trim(
        (string) ($_POST['legenda'] ?? '')
    );

    $instrucaoEdicao = trim(
        (string) ($_POST['instrucao_edicao'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | LINKS DE PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */

    $youtubeUrl = trim(
        (string) ($_POST['youtube_url'] ?? '')
    );

    $instagramUrl = trim(
        (string) ($_POST['instagram_url'] ?? '')
    );

    $tiktokUrl = trim(
        (string) ($_POST['tiktok_url'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES BÁSICAS
    |--------------------------------------------------------------------------
    */

    if ($titulo === '') {
        throw new RuntimeException(
            'Informe o título interno do corte.'
        );
    }

    if ($arquivoOrigemId <= 0) {
        throw new RuntimeException(
            'Selecione o vídeo bruto de origem.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR SE O ARQUIVO PERTENCE AO CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmtArquivo = $pdo->prepare(
        '
        SELECT id
        FROM capitulo_arquivos
        WHERE id = ?
          AND capitulo_id = ?
          AND ativo = 1
        LIMIT 1
        '
    );

    $stmtArquivo->execute([
        $arquivoOrigemId,
        $capituloId
    ]);

    if (!$stmtArquivo->fetchColumn()) {
        throw new RuntimeException(
            'O arquivo selecionado não pertence a este capítulo.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    $statusPermitidos = [
        'ideia',
        'selecionado',
        'editando',
        'pronto',
        'publicado'
    ];

    if (!in_array(
        $status,
        $statusPermitidos,
        true
    )) {
        $status = 'ideia';
    }

    /*
    |--------------------------------------------------------------------------
    | FORMATO
    |--------------------------------------------------------------------------
    */

    $formatosPermitidos = [
        '9:16',
        '1:1',
        '16:9'
    ];

    if (!in_array(
        $formato,
        $formatosPermitidos,
        true
    )) {
        $formato = '9:16';
    }

    /*
    |--------------------------------------------------------------------------
    | CONVERTER TEMPO PARA MILISSEGUNDOS
    |--------------------------------------------------------------------------
    */

    $tempoParaMs = function (string $valor): ?int {

        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        if (ctype_digit($valor)) {
            return ((int) $valor) * 1000;
        }

        $partes = explode(':', $valor);

        if (
            count($partes) < 2
            ||
            count($partes) > 3
        ) {
            throw new RuntimeException(
                'Formato de tempo inválido. Use mm:ss.'
            );
        }

        foreach ($partes as $parte) {

            if (
                $parte === ''
                ||
                !ctype_digit($parte)
            ) {
                throw new RuntimeException(
                    'Formato de tempo inválido. Use mm:ss.'
                );
            }
        }

        if (count($partes) === 2) {

            $minutos = (int) $partes[0];
            $segundos = (int) $partes[1];

            if ($segundos > 59) {
                throw new RuntimeException(
                    'Os segundos devem estar entre 00 e 59.'
                );
            }

            $totalSegundos =
                ($minutos * 60)
                +
                $segundos;

        } else {

            $horas = (int) $partes[0];
            $minutos = (int) $partes[1];
            $segundos = (int) $partes[2];

            if (
                $minutos > 59
                ||
                $segundos > 59
            ) {
                throw new RuntimeException(
                    'Formato de tempo inválido.'
                );
            }

            $totalSegundos =
                ($horas * 3600)
                +
                ($minutos * 60)
                +
                $segundos;
        }

        return $totalSegundos * 1000;
    };

    /*
    |--------------------------------------------------------------------------
    | CONVERTER TEMPOS
    |--------------------------------------------------------------------------
    */

    $inicioMs =
        $tempoParaMs($inicio);

    $fimMs =
        $tempoParaMs($fim);

    $duracaoFinalMs =
        $tempoParaMs($duracaoFinal);

    /*
    |--------------------------------------------------------------------------
    | VALIDAR TRECHO
    |--------------------------------------------------------------------------
    */

    if (
        $inicioMs !== null
        &&
        $fimMs !== null
        &&
        $fimMs <= $inicioMs
    ) {
        throw new RuntimeException(
            'O fim do corte precisa ser maior que o início.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DETECTAR PUBLICAÇÃO
    |--------------------------------------------------------------------------
    */

    $temPublicacao =
        $youtubeUrl !== ''
        ||
        $instagramUrl !== ''
        ||
        $tiktokUrl !== '';

    /*
    |--------------------------------------------------------------------------
    | STATUS AUTOMÁTICO
    |--------------------------------------------------------------------------
    |
    | Tem pelo menos um link:
    |     => publicado
    |
    | Marcou publicado sem links:
    |     => bloqueia
    |
    | Estava publicado e removeu todos os links:
    |     => pronto
    |--------------------------------------------------------------------------
    */

    if ($temPublicacao) {

        $status = 'publicado';

    } elseif ($status === 'publicado') {

        /*
         * Se o usuário escolheu Publicado mas
         * não informou nenhuma plataforma,
         * não permitimos.
         */

        throw new RuntimeException(
            'Para marcar o corte como Publicado, informe pelo menos um link de YouTube, Instagram ou TikTok.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EDITAR CORTE EXISTENTE
    |--------------------------------------------------------------------------
    */

    if ($corteId > 0) {

        /*
        |--------------------------------------------------------------------------
        | BUSCAR CORTE ATUAL
        |--------------------------------------------------------------------------
        */

        $stmtCorte = $pdo->prepare(
            '
            SELECT
                id,
                status
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

        $corteAtual =
            $stmtCorte->fetch();

        if (!$corteAtual) {
            throw new RuntimeException(
                'Corte não encontrado neste capítulo.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | REMOVEU TODOS OS LINKS DE UM CORTE PUBLICADO
        |--------------------------------------------------------------------------
        */

        if (
            !$temPublicacao
            &&
            ($corteAtual['status'] ?? '')
            === 'publicado'
        ) {
            $status = 'pronto';
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare(
            '
            UPDATE capitulo_cortes_curtos

            SET
                titulo = ?,
                arquivo_origem_id = ?,
                inicio_ms = ?,
                fim_ms = ?,
                gancho = ?,
                legenda = ?,
                formato = ?,
                instrucao_edicao = ?,
                duracao_final_ms = ?,
                status = ?,
                youtube_url = ?,
                instagram_url = ?,
                tiktok_url = ?

            WHERE id = ?
              AND capitulo_id = ?
            '
        );

        $stmt->execute([
            $titulo,
            $arquivoOrigemId,
            $inicioMs,
            $fimMs,

            $gancho !== ''
                ? $gancho
                : null,

            $legenda !== ''
                ? $legenda
                : null,

            $formato,

            $instrucaoEdicao !== ''
                ? $instrucaoEdicao
                : null,

            $duracaoFinalMs,

            $status,

            $youtubeUrl !== ''
                ? $youtubeUrl
                : null,

            $instagramUrl !== ''
                ? $instagramUrl
                : null,

            $tiktokUrl !== ''
                ? $tiktokUrl
                : null,

            $corteId,
            $capituloId
        ]);

        echo json_encode([
            'success' => true,
            'message' =>
                'Corte atualizado com sucesso.',
            'id' =>
                $corteId,
            'modo' =>
                'edicao',
            'status' =>
                $status
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | CRIAR NOVO CORTE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        '
        INSERT INTO capitulo_cortes_curtos
        (
            capitulo_id,
            titulo,
            arquivo_origem_id,
            inicio_ms,
            fim_ms,
            gancho,
            legenda,
            formato,
            instrucao_edicao,
            duracao_final_ms,
            status,
            youtube_url,
            instagram_url,
            tiktok_url
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
            ?,
            ?
        )
        '
    );

    $stmt->execute([
        $capituloId,
        $titulo,
        $arquivoOrigemId,
        $inicioMs,
        $fimMs,

        $gancho !== ''
            ? $gancho
            : null,

        $legenda !== ''
            ? $legenda
            : null,

        $formato,

        $instrucaoEdicao !== ''
            ? $instrucaoEdicao
            : null,

        $duracaoFinalMs,

        $status,

        $youtubeUrl !== ''
            ? $youtubeUrl
            : null,

        $instagramUrl !== ''
            ? $instagramUrl
            : null,

        $tiktokUrl !== ''
            ? $tiktokUrl
            : null
    ]);

    $novoId =
        (int) $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' =>
            'Corte salvo com sucesso.',
        'id' =>
            $novoId,
        'modo' =>
            'novo',
        'status' =>
            $status
    ]);

} catch (Throwable $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}