<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';

$pageTitle = 'Diagnóstico FFmpeg';


/*
|--------------------------------------------------------------------------
| VARIÁVEIS
|--------------------------------------------------------------------------
*/

$funcoes = [
    'exec',
    'shell_exec',
    'system',
    'passthru',
    'proc_open'
];

$resultadoFuncoes = [];

$disableFunctions =
    (string) ini_get(
        'disable_functions'
    );


/*
|--------------------------------------------------------------------------
| VERIFICAR FUNÇÕES PHP
|--------------------------------------------------------------------------
*/

foreach ($funcoes as $funcao) {

    $resultadoFuncoes[$funcao] = [
        'existe' =>
            function_exists($funcao),

        'chamavel' =>
            is_callable($funcao),
    ];
}


/*
|--------------------------------------------------------------------------
| TENTAR LOCALIZAR FFMPEG
|--------------------------------------------------------------------------
*/

$ffmpegEncontrado = false;

$ffmpegPath = null;

$ffmpegVersao = null;

$metodoUtilizado = null;

$erroTeste = null;


try {

    /*
    |--------------------------------------------------------------------------
    | MÉTODO 1 — SHELL_EXEC
    |--------------------------------------------------------------------------
    */

    if (
        function_exists('shell_exec')
        &&
        is_callable('shell_exec')
    ) {

        $saida =
            @shell_exec(
                'ffmpeg -version 2>&1'
            );

        if (
            is_string($saida)
            &&
            stripos(
                $saida,
                'ffmpeg version'
            ) !== false
        ) {

            $linhas =
                preg_split(
                    '/\r\n|\r|\n/',
                    trim($saida)
                );

            $ffmpegEncontrado = true;

            $ffmpegPath =
                'ffmpeg';

            $ffmpegVersao =
                $linhas[0]
                ?? 'FFmpeg encontrado';

            $metodoUtilizado =
                'shell_exec';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | MÉTODO 2 — SYSTEM
    |--------------------------------------------------------------------------
    */

    if (
        !$ffmpegEncontrado
        &&
        function_exists('system')
        &&
        is_callable('system')
    ) {

        ob_start();

        $codigo = 1;

        @system(
            'ffmpeg -version 2>&1',
            $codigo
        );

        $saida =
            (string) ob_get_clean();

        if (
            $codigo === 0
            &&
            stripos(
                $saida,
                'ffmpeg version'
            ) !== false
        ) {

            $linhas =
                preg_split(
                    '/\r\n|\r|\n/',
                    trim($saida)
                );

            $ffmpegEncontrado = true;

            $ffmpegPath =
                'ffmpeg';

            $ffmpegVersao =
                $linhas[0]
                ?? 'FFmpeg encontrado';

            $metodoUtilizado =
                'system';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | MÉTODO 3 — PASSTHRU
    |--------------------------------------------------------------------------
    */

    if (
        !$ffmpegEncontrado
        &&
        function_exists('passthru')
        &&
        is_callable('passthru')
    ) {

        ob_start();

        $codigo = 1;

        @passthru(
            'ffmpeg -version 2>&1',
            $codigo
        );

        $saida =
            (string) ob_get_clean();

        if (
            $codigo === 0
            &&
            stripos(
                $saida,
                'ffmpeg version'
            ) !== false
        ) {

            $linhas =
                preg_split(
                    '/\r\n|\r|\n/',
                    trim($saida)
                );

            $ffmpegEncontrado = true;

            $ffmpegPath =
                'ffmpeg';

            $ffmpegVersao =
                $linhas[0]
                ?? 'FFmpeg encontrado';

            $metodoUtilizado =
                'passthru';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | MÉTODO 4 — EXEC
    |--------------------------------------------------------------------------
    */

    if (
        !$ffmpegEncontrado
        &&
        function_exists('exec')
        &&
        is_callable('exec')
    ) {

        $saida = [];

        $codigo = 1;

        @exec(
            'ffmpeg -version 2>&1',
            $saida,
            $codigo
        );

        $texto =
            implode(
                "\n",
                $saida
            );

        if (
            $codigo === 0
            &&
            stripos(
                $texto,
                'ffmpeg version'
            ) !== false
        ) {

            $ffmpegEncontrado = true;

            $ffmpegPath =
                'ffmpeg';

            $ffmpegVersao =
                $saida[0]
                ?? 'FFmpeg encontrado';

            $metodoUtilizado =
                'exec';
        }
    }


} catch (Throwable $e) {

    $erroTeste =
        $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| TELA
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/header.php';

?>


<div class="panel-card">


    <div class="mb-4">

        <h2 class="h5 mb-1">

            <i class="bi bi-terminal"></i>

            Diagnóstico FFmpeg

        </h2>

        <div class="small text-secondary">

            Verificação das funções disponíveis
            no servidor para processamento de mídia.

        </div>

    </div>


    <!-- ================================================================
         FUNÇÕES PHP
    ================================================================= -->

    <div class="border rounded p-3 mb-3">

        <h3 class="h6 mb-3">
            Funções de execução do PHP
        </h3>


        <div class="table-responsive">

            <table class="table table-sm align-middle mb-0">

                <thead>

                    <tr>

                        <th>
                            Função
                        </th>

                        <th>
                            Disponível
                        </th>

                    </tr>

                </thead>

                <tbody>


                    <?php foreach (
                        $resultadoFuncoes
                        as $nome => $dados
                    ): ?>

                        <tr>

                            <td>

                                <code>
                                    <?= htmlspecialchars(
                                        $nome
                                    ) ?>()
                                </code>

                            </td>

                            <td>

                                <?php if (
                                    $dados['existe']
                                    &&
                                    $dados['chamavel']
                                ): ?>

                                    <span class="badge bg-success">

                                        Disponível

                                    </span>

                                <?php else: ?>

                                    <span class="badge bg-danger">

                                        Bloqueada

                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                </tbody>

            </table>

        </div>

    </div>


    <!-- ================================================================
         DISABLE FUNCTIONS
    ================================================================= -->

    <div class="border rounded p-3 mb-3">

        <div class="small text-secondary mb-1">

            disable_functions

        </div>


        <?php if (
            trim($disableFunctions) !== ''
        ): ?>

            <code style="word-break: break-word;">

                <?= htmlspecialchars(
                    $disableFunctions
                ) ?>

            </code>

        <?php else: ?>

            <div class="text-success fw-semibold">

                Nenhuma função informada
                em disable_functions.

            </div>

        <?php endif; ?>

    </div>


    <!-- ================================================================
         RESULTADO FFMPEG
    ================================================================= -->


    <?php if ($ffmpegEncontrado): ?>


        <div class="alert alert-success">

            <strong>

                <i class="bi bi-check-circle me-1"></i>

                FFmpeg encontrado!

            </strong>

            <div class="mt-2">

                Mesmo com algumas funções PHP
                bloqueadas, encontramos uma forma
                de executar o FFmpeg.

            </div>

        </div>


        <div class="row g-3">


            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">

                        Método disponível

                    </div>

                    <div class="fw-bold mt-1">

                        <?= htmlspecialchars(
                            $metodoUtilizado
                        ) ?>

                    </div>

                </div>

            </div>


            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">

                        Comando

                    </div>

                    <div class="fw-bold mt-1">

                        <?= htmlspecialchars(
                            $ffmpegPath
                        ) ?>

                    </div>

                </div>

            </div>


            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">

                        Versão

                    </div>

                    <div class="fw-bold mt-1">

                        <?= htmlspecialchars(
                            $ffmpegVersao
                        ) ?>

                    </div>

                </div>

            </div>


        </div>


    <?php else: ?>


        <div class="alert alert-warning">

            <strong>

                FFmpeg ainda não confirmado.

            </strong>

            <div class="mt-2">

                O diagnóstico abaixo vai nos mostrar
                se o problema é somente o
                <code>exec()</code>
                ou se a hospedagem bloqueou todas
                as formas de executar programas externos.

            </div>

        </div>


    <?php endif; ?>


    <?php if ($erroTeste): ?>

        <div class="alert alert-danger mt-3">

            <strong>
                Erro durante o teste:
            </strong>

            <div class="mt-2">

                <?= htmlspecialchars(
                    $erroTeste
                ) ?>

            </div>

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