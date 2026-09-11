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

$erro = null;

$resultado = null;

$arquivo = null;


/*
|--------------------------------------------------------------------------
| FORMATAR BYTES
|--------------------------------------------------------------------------
*/

function formatarBytesTeste(int $bytes): string
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
    )
    .
    ' '
    .
    $unidades[$indice];
}


/*
|--------------------------------------------------------------------------
| TESTE
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | PEGAR SOMENTE UM ARQUIVO ATIVO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query(
        '
        SELECT
            id,
            capitulo_id,
            drive_file_id,
            nome_arquivo,
            mime_type,
            tamanho_bytes,
            duracao_ms
        FROM capitulo_arquivos
        WHERE ativo = 1
          AND drive_file_id IS NOT NULL
          AND drive_file_id <> ""
        ORDER BY
            id ASC
        LIMIT 1
        '
    );

    $arquivo =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$arquivo) {

        throw new RuntimeException(
            'Nenhum arquivo ativo foi encontrado para o teste.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DRIVE
    |--------------------------------------------------------------------------
    */

    $drive =
        new GoogleDriveService();


    /*
    |--------------------------------------------------------------------------
    | DOWNLOAD TEMPORÁRIO
    |--------------------------------------------------------------------------
    */

    $download =
        $drive->baixarArquivoTemporario(
            (string) $arquivo['drive_file_id'],
            (string) $arquivo['nome_arquivo']
        );


    $caminho =
        (string) (
            $download['caminho']
            ?? ''
        );


    if (
        $caminho === ''
        ||
        !file_exists($caminho)
    ) {

        throw new RuntimeException(
            'O arquivo temporário não foi encontrado após o download.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DADOS DO DOWNLOAD
    |--------------------------------------------------------------------------
    */

    $tamanhoLocal =
        (int) filesize($caminho);


    $resultado = [

        'nome' =>
            $arquivo['nome_arquivo'],

        'drive_file_id' =>
            $arquivo['drive_file_id'],

        'mime_type' =>
            $arquivo['mime_type'],

        'tamanho_drive' =>
            (int) (
                $arquivo['tamanho_bytes']
                ?? 0
            ),

        'tamanho_local' =>
            $tamanhoLocal,

        'caminho_temporario' =>
            $caminho,

        'arquivo_existia_antes_remover' =>
            file_exists($caminho),

    ];


    /*
    |--------------------------------------------------------------------------
    | REMOVER LOGO APÓS O TESTE
    |--------------------------------------------------------------------------
    */

    $drive->removerArquivoTemporario(
        $caminho
    );


    /*
    |--------------------------------------------------------------------------
    | CONFIRMAR REMOÇÃO
    |--------------------------------------------------------------------------
    */

    $resultado[
        'arquivo_existe_depois_remover'
    ] =
        file_exists($caminho);


} catch (Throwable $e) {

    $erro =
        $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| TELA
|--------------------------------------------------------------------------
*/

$pageTitle =
    'Teste de Download Google Drive';

require __DIR__ . '/includes/header.php';

?>


<div class="panel-card">


    <div class="mb-4">

        <h2 class="h5 mb-1">

            <i class="bi bi-cloud-arrow-down"></i>

            Teste de download para IA

        </h2>

        <div class="small text-secondary">

            Baixa apenas um arquivo,
            confirma o resultado
            e remove o temporário.

        </div>

    </div>


    <?php if ($erro): ?>


        <div class="alert alert-danger">

            <strong>
                Teste falhou.
            </strong>

            <div class="mt-2">

                <?= htmlspecialchars(
                    $erro
                ) ?>

            </div>

        </div>


    <?php elseif ($resultado): ?>


        <div class="alert alert-success">

            <strong>
                Download concluído com sucesso.
            </strong>

            <div class="small mt-1">

                O arquivo temporário também
                foi removido após o teste.

            </div>

        </div>


        <div class="row g-3">


            <div class="col-md-6">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Arquivo
                    </div>

                    <div class="fw-bold mt-1">

                        <?= htmlspecialchars(
                            $resultado['nome']
                        ) ?>

                    </div>

                </div>

            </div>


            <div class="col-md-6">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        MIME Type
                    </div>

                    <div class="fw-bold mt-1">

                        <?= htmlspecialchars(
                            $resultado[
                                'mime_type'
                            ]
                            ?? ''
                        ) ?>

                    </div>

                </div>

            </div>


            <div class="col-md-6">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Tamanho no Drive
                    </div>

                    <div class="fs-5 fw-bold mt-1">

                        <?= formatarBytesTeste(
                            (int)
                            $resultado[
                                'tamanho_drive'
                            ]
                        ) ?>

                    </div>

                </div>

            </div>


            <div class="col-md-6">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Tamanho baixado
                    </div>

                    <div class="fs-5 fw-bold mt-1">

                        <?= formatarBytesTeste(
                            (int)
                            $resultado[
                                'tamanho_local'
                            ]
                        ) ?>

                    </div>

                </div>

            </div>


        </div>


        <div class="border rounded p-3 mt-3">

            <div class="small text-secondary mb-2">
                Limpeza do temporário
            </div>


            <?php if (
                empty(
                    $resultado[
                        'arquivo_existe_depois_remover'
                    ]
                )
            ): ?>

                <div class="fw-semibold text-success">

                    <i class="bi bi-check-circle me-1"></i>

                    Arquivo temporário removido corretamente.

                </div>

            <?php else: ?>

                <div class="fw-semibold text-danger">

                    <i class="bi bi-exclamation-triangle me-1"></i>

                    O arquivo temporário ainda existe.

                </div>

            <?php endif; ?>

        </div>


    <?php endif; ?>


    <div class="mt-4">

        <a
            href="catalogo.php"
            class="btn btn-outline-dark"
        >

            Voltar ao catálogo

        </a>

    </div>


</div>


<?php

require __DIR__ . '/includes/footer.php';

?>