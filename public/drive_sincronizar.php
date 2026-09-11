<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/Drive/GoogleDriveService.php';

$pdo = db();

/*
|--------------------------------------------------------------------------
| CONTADORES
|--------------------------------------------------------------------------
*/

$pastasEncontradas = 0;
$capitulosVinculados = 0;

$arquivosEncontrados = 0;
$arquivosNovos = 0;
$arquivosAtualizados = 0;

$duracaoTotalMs = 0;
$tamanhoTotalBytes = 0;

$naoVinculados = [];

$erro = null;


/*
|--------------------------------------------------------------------------
| FUNÇÕES AUXILIARES
|--------------------------------------------------------------------------
*/

function driveDateToMysql(?string $data): ?string
{
    if (!$data) {
        return null;
    }

    try {

        $dt = new DateTime($data);

        return $dt->format('Y-m-d H:i:s');

    } catch (Throwable $e) {

        return null;
    }
}


function formatarDuracaoSync(int $ms): string
{
    if ($ms <= 0) {
        return '00:00';
    }

    $segundos = (int) floor($ms / 1000);

    $horas = (int) floor($segundos / 3600);

    $minutos = (int) floor(
        ($segundos % 3600) / 60
    );

    $segundosRestantes = $segundos % 60;

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


function formatarBytesSync(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $unidades = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB'
    ];

    $i = 0;
    $valor = $bytes;

    while (
        $valor >= 1024
        && $i < count($unidades) - 1
    ) {

        $valor /= 1024;
        $i++;
    }

    return number_format(
        $valor,
        2,
        ',',
        '.'
    ) . ' ' . $unidades[$i];
}


/*
|--------------------------------------------------------------------------
| SINCRONIZAÇÃO
|--------------------------------------------------------------------------
*/

try {

    $drive = new GoogleDriveService();


    /*
    |--------------------------------------------------------------------------
    | 1. LOCALIZAR "01 - VIDEOS BRUTOS"
    |--------------------------------------------------------------------------
    */

    $compartilhados = $drive->listarCompartilhados();

    $pastaRaiz = null;

    foreach ($compartilhados as $item) {

        if (
            $item->getMimeType()
            === 'application/vnd.google-apps.folder'
            &&
            trim($item->getName())
            === '01 - VIDEOS BRUTOS'
        ) {

            $pastaRaiz = $item;

            break;
        }
    }


    if (!$pastaRaiz) {

        throw new RuntimeException(
            'A pasta "01 - VIDEOS BRUTOS" não foi encontrada no Google Drive.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 2. LISTAR AS PASTAS DOS CAPÍTULOS
    |--------------------------------------------------------------------------
    */

    $pastas = $drive->listarPasta(
        $pastaRaiz->getId()
    );


    /*
    |--------------------------------------------------------------------------
    | 3. PROCESSAR CADA PASTA
    |--------------------------------------------------------------------------
    */

    foreach ($pastas as $pasta) {

        /*
         * Ignorar qualquer arquivo solto
         * que exista na raiz.
         */

        if (
            $pasta->getMimeType()
            !== 'application/vnd.google-apps.folder'
        ) {
            continue;
        }

        $pastasEncontradas++;

        $nomePasta = trim(
            $pasta->getName()
        );


        /*
        |--------------------------------------------------------------------------
        | IDENTIFICAR NÚMERO DO CAPÍTULO
        |--------------------------------------------------------------------------
        |
        | Aceita:
        |
        | 1 - POSTE E SANEPAR
        | 2- LOCAÇÃO CONTAINER
        | 31 - NEUTROL
        |
        */

        if (
            !preg_match(
                '/^\s*(\d+)\s*[-–—]\s*/u',
                $nomePasta,
                $match
            )
        ) {

            $naoVinculados[] =
                $nomePasta .
                ' — número não identificado';

            continue;
        }


        $numero = (int) $match[1];


        /*
        |--------------------------------------------------------------------------
        | LOCALIZAR CAPÍTULO NO MYSQL
        |--------------------------------------------------------------------------
        */

        $stmtCapitulo = $pdo->prepare(
            '
            SELECT
                id,
                numero,
                titulo
            FROM capitulos
            WHERE numero = ?
            LIMIT 1
            '
        );

        $stmtCapitulo->execute([
            $numero
        ]);

        $capitulo = $stmtCapitulo->fetch();


        if (!$capitulo) {

            $naoVinculados[] =
                $nomePasta .
                ' — capítulo #' .
                $numero .
                ' não existe no banco';

            continue;
        }


        $capituloId = (int) $capitulo['id'];

        $folderId = $pasta->getId();

        $folderUrl =
            'https://drive.google.com/drive/folders/' .
            rawurlencode($folderId);


        /*
        |--------------------------------------------------------------------------
        | TÍTULO REAL DA PASTA
        |--------------------------------------------------------------------------
        |
        | "1 - POSTE E SANEPAR"
        |
        | vira:
        |
        | "POSTE E SANEPAR"
        |
        */

        $tituloDrive = preg_replace(
            '/^\s*\d+\s*[-–—]\s*/u',
            '',
            $nomePasta
        );

        $tituloDrive = trim(
            $tituloDrive
        );


        /*
        |--------------------------------------------------------------------------
        | 4. LISTAR OS ARQUIVOS REAIS DA PASTA
        |--------------------------------------------------------------------------
        */

        $itensDrive = $drive->listarPasta(
            $folderId
        );


        /*
         * Primeiro marcamos arquivos antigos
         * deste capítulo como inativos.
         *
         * Os arquivos que ainda existirem no Drive
         * serão reativados logo abaixo.
         */

        $stmtInativar = $pdo->prepare(
            '
            UPDATE capitulo_arquivos
            SET ativo = 0
            WHERE capitulo_id = ?
            '
        );

        $stmtInativar->execute([
            $capituloId
        ]);


        $qtdArquivosCapitulo = 0;

        $duracaoCapituloMs = 0;

        $tamanhoCapituloBytes = 0;

        $ordem = 0;


        /*
        |--------------------------------------------------------------------------
        | 5. PROCESSAR CADA VÍDEO / ARQUIVO
        |--------------------------------------------------------------------------
        */

        foreach ($itensDrive as $arquivoDrive) {

            /*
             * Ignorar subpastas.
             */

            if (
                $arquivoDrive->getMimeType()
                === 'application/vnd.google-apps.folder'
            ) {
                continue;
            }


            $ordem++;

            $qtdArquivosCapitulo++;
            $arquivosEncontrados++;


            /*
            |--------------------------------------------------------------------------
            | METADADOS
            |--------------------------------------------------------------------------
            */

            $driveFileId = $arquivoDrive->getId();

            $nomeArquivo = $arquivoDrive->getName();

            $mimeType = $arquivoDrive->getMimeType();

            $tamanhoBytes = (int) (
                $arquivoDrive->getSize() ?? 0
            );


            /*
            |--------------------------------------------------------------------------
            | DURAÇÃO DO VÍDEO
            |--------------------------------------------------------------------------
            */

            $duracaoMs = 0;

            $videoMetadata =
                $arquivoDrive->getVideoMediaMetadata();

            if ($videoMetadata) {

                $duracaoMs = (int) (
                    $videoMetadata->getDurationMillis()
                    ?? 0
                );
            }


            $driveUrl =
                $arquivoDrive->getWebViewLink();


            if (!$driveUrl) {

                $driveUrl =
                    'https://drive.google.com/file/d/' .
                    rawurlencode($driveFileId) .
                    '/view';
            }


            $thumbnailUrl =
                $arquivoDrive->getThumbnailLink();


            $driveCreatedAt =
                driveDateToMysql(
                    $arquivoDrive->getCreatedTime()
                );


            $driveModifiedAt =
                driveDateToMysql(
                    $arquivoDrive->getModifiedTime()
                );


            /*
            |--------------------------------------------------------------------------
            | SOMATÓRIOS
            |--------------------------------------------------------------------------
            */

            $duracaoCapituloMs +=
                $duracaoMs;

            $tamanhoCapituloBytes +=
                $tamanhoBytes;

            $duracaoTotalMs +=
                $duracaoMs;

            $tamanhoTotalBytes +=
                $tamanhoBytes;


            /*
            |--------------------------------------------------------------------------
            | VERIFICAR SE O ARQUIVO JÁ EXISTE
            |--------------------------------------------------------------------------
            */

            $stmtExiste = $pdo->prepare(
                '
                SELECT id
                FROM capitulo_arquivos
                WHERE drive_file_id = ?
                LIMIT 1
                '
            );

            $stmtExiste->execute([
                $driveFileId
            ]);

            $arquivoExistente =
                $stmtExiste->fetch();


            /*
            |--------------------------------------------------------------------------
            | ARQUIVO JÁ CADASTRADO
            |--------------------------------------------------------------------------
            */

            if ($arquivoExistente) {

                $stmtUpdateArquivo = $pdo->prepare(
                    '
                    UPDATE capitulo_arquivos
                    SET
                        capitulo_id = ?,
                        nome_arquivo = ?,
                        mime_type = ?,
                        tamanho_bytes = ?,
                        duracao_ms = ?,
                        drive_url = ?,
                        thumbnail_url = ?,
                        drive_created_at = ?,
                        drive_modified_at = ?,
                        ordem = ?,
                        ativo = 1
                    WHERE id = ?
                    '
                );

                $stmtUpdateArquivo->execute([

                    $capituloId,

                    $nomeArquivo,

                    $mimeType,

                    $tamanhoBytes,

                    $duracaoMs,

                    $driveUrl,

                    $thumbnailUrl,

                    $driveCreatedAt,

                    $driveModifiedAt,

                    $ordem,

                    $arquivoExistente['id']

                ]);

                $arquivoId =
                    (int) $arquivoExistente['id'];

                $arquivosAtualizados++;

            }


            /*
            |--------------------------------------------------------------------------
            | ARQUIVO NOVO
            |--------------------------------------------------------------------------
            */

            else {

                $stmtInsertArquivo = $pdo->prepare(
                    '
                    INSERT INTO capitulo_arquivos
                    (
                        capitulo_id,
                        drive_file_id,
                        nome_arquivo,
                        mime_type,
                        tamanho_bytes,
                        duracao_ms,
                        drive_url,
                        thumbnail_url,
                        drive_created_at,
                        drive_modified_at,
                        ordem,
                        ativo
                    )
                    VALUES
                    (
                        ?,?,?,?,?,?,
                        ?,?,?,?,?,
                        1
                    )
                    '
                );


                $stmtInsertArquivo->execute([

                    $capituloId,

                    $driveFileId,

                    $nomeArquivo,

                    $mimeType,

                    $tamanhoBytes,

                    $duracaoMs,

                    $driveUrl,

                    $thumbnailUrl,

                    $driveCreatedAt,

                    $driveModifiedAt,

                    $ordem

                ]);


                $arquivoId =
                    (int) $pdo->lastInsertId();

                $arquivosNovos++;

            }


            /*
            |--------------------------------------------------------------------------
            | CRIAR REGISTRO DE TRIAGEM AUTOMATICAMENTE
            |--------------------------------------------------------------------------
            |
            | Se já existir, INSERT IGNORE não altera nada.
            |
            */

            $stmtTriagem = $pdo->prepare(
                '
                INSERT IGNORE INTO capitulo_triagem
                (
                    arquivo_id
                )
                VALUES
                (?)
                '
            );

            $stmtTriagem->execute([
                $arquivoId
            ]);

        }


        /*
        |--------------------------------------------------------------------------
        | 6. ATUALIZAR O CAPÍTULO
        |--------------------------------------------------------------------------
        */

        $stmtAtualizarCapitulo = $pdo->prepare(
            '
            UPDATE capitulos
            SET
                titulo = ?,
                qtd_arquivos = ?,
                duracao_bruta_ms = ?,
                drive_folder_id = ?,
                drive_folder_url = ?,
                drive_sync_at = NOW()
            WHERE id = ?
            '
        );


        $stmtAtualizarCapitulo->execute([

            $tituloDrive,

            $qtdArquivosCapitulo,

            $duracaoCapituloMs,

            $folderId,

            $folderUrl,

            $capituloId

        ]);


        $capitulosVinculados++;

    }


    /*
    |--------------------------------------------------------------------------
    | REGISTRAR LOG
    |--------------------------------------------------------------------------
    */

    try {

        $stmtLog = $pdo->prepare(
            '
            INSERT INTO atividades
            (
                entidade,
                entidade_id,
                usuario,
                acao,
                detalhes
            )
            VALUES
            (
                "google_drive",
                NULL,
                NULL,
                "Sincronização do Google Drive",
                ?
            )
            '
        );


        $detalhesLog = json_encode(
            [
                'pastas' =>
                    $pastasEncontradas,

                'capitulos' =>
                    $capitulosVinculados,

                'arquivos' =>
                    $arquivosEncontrados,

                'novos' =>
                    $arquivosNovos,

                'atualizados' =>
                    $arquivosAtualizados
            ],
            JSON_UNESCAPED_UNICODE
        );


        $stmtLog->execute([
            $detalhesLog
        ]);

    } catch (Throwable $e) {

        /*
         * O log não pode interromper
         * a sincronização principal.
         */

    }


} catch (Throwable $e) {

    $erro = $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| TELA
|--------------------------------------------------------------------------
*/

$pageTitle = 'Sincronização Google Drive';

require __DIR__ . '/includes/header.php';

?>


<div class="panel-card">


    <div
        class="d-flex
               justify-content-between
               align-items-center
               mb-4"
    >

        <div>

            <h2 class="h5 mb-1">

                <i class="bi bi-arrow-repeat"></i>

                Sincronização Google Drive V2

            </h2>


            <div class="small text-secondary">

                Pastas e arquivos brutos
                sincronizados com o catálogo.

            </div>

        </div>


        <a
            href="catalogo.php"
            class="btn btn-outline-dark"
        >

            <i class="bi bi-arrow-left"></i>

            Voltar

        </a>

    </div>


    <?php if ($erro): ?>


        <div class="alert alert-danger">

            <strong>
                Erro durante a sincronização.
            </strong>

            <div class="mt-2">

                <?= htmlspecialchars($erro) ?>

            </div>

        </div>


    <?php else: ?>


        <div class="alert alert-success">

            <strong>
                Google Drive sincronizado com sucesso.
            </strong>

            <div class="small mt-1">

                O índice local do Bora pra Obra
                foi atualizado.

            </div>

        </div>


        <!-- =================================================
             RESUMO
        ================================================== -->

        <div class="row g-3 mb-4">


            <div class="col-md-3">

                <div class="border rounded p-3">

                    <div class="small text-secondary">
                        Pastas
                    </div>

                    <div class="fs-2 fw-bold">

                        <?= $pastasEncontradas ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div class="border rounded p-3">

                    <div class="small text-secondary">
                        Capítulos
                    </div>

                    <div class="fs-2 fw-bold text-success">

                        <?= $capitulosVinculados ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div class="border rounded p-3">

                    <div class="small text-secondary">
                        Arquivos
                    </div>

                    <div class="fs-2 fw-bold">

                        <?= $arquivosEncontrados ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div class="border rounded p-3">

                    <div class="small text-secondary">
                        Não vinculados
                    </div>

                    <div
                        class="
                            fs-2
                            fw-bold
                            <?= $naoVinculados
                                ? 'text-danger'
                                : 'text-success'
                            ?>
                        "
                    >

                        <?= count($naoVinculados) ?>

                    </div>

                </div>

            </div>


        </div>


        <!-- =================================================
             DETALHES
        ================================================== -->

        <div class="row g-3 mb-4">


            <div class="col-md-3">

                <div class="border rounded p-3">

                    <div class="small text-secondary">

                        Arquivos novos

                    </div>

                    <div class="fs-4 fw-bold text-success">

                        <?= $arquivosNovos ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div class="border rounded p-3">

                    <div class="small text-secondary">

                        Atualizados

                    </div>

                    <div class="fs-4 fw-bold">

                        <?= $arquivosAtualizados ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div class="border rounded p-3">

                    <div class="small text-secondary">

                        Duração bruta

                    </div>

                    <div class="fs-4 fw-bold">

                        <?= formatarDuracaoSync(
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

                    <div class="fs-4 fw-bold">

                        <?= formatarBytesSync(
                            $tamanhoTotalBytes
                        ) ?>

                    </div>

                </div>

            </div>


        </div>


        <?php if ($naoVinculados): ?>


            <div class="alert alert-warning">

                <strong>

                    Pastas que precisam de atenção:

                </strong>


                <ul class="mb-0 mt-2">


                    <?php foreach (
                        $naoVinculados
                        as $item
                    ): ?>


                        <li>

                            <?= htmlspecialchars(
                                $item
                            ) ?>

                        </li>


                    <?php endforeach; ?>


                </ul>

            </div>


        <?php else: ?>


            <div class="alert alert-light border">

                <i class="bi bi-check-circle text-success"></i>

                Todas as pastas encontradas
                foram vinculadas corretamente.

            </div>


        <?php endif; ?>


    <?php endif; ?>


    <div class="d-flex gap-2">


        <a
            href="catalogo.php"
            class="btn btn-dark"
        >

            Abrir catálogo

        </a>


        <a
            href="drive_sincronizar.php"
            class="btn btn-outline-dark"
        >

            <i class="bi bi-arrow-repeat"></i>

            Sincronizar novamente

        </a>


    </div>


</div>


<?php

require __DIR__ . '/includes/footer.php';

?>