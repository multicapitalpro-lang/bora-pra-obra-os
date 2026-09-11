<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


require __DIR__ . '/includes/auth.php';

require_once dirname(__DIR__)
    . '/app/AI/OpenAIService.php';


$pageTitle =
    'Teste OpenAI';


$resultado = null;

$erro = null;


/*
|--------------------------------------------------------------------------
| EXECUTAR TESTE
|--------------------------------------------------------------------------
*/

try {

    $openAI =
        new OpenAIService();


    $resultado =
        $openAI->testar();


} catch (Throwable $e) {

    $erro =
        $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| CABEÇALHO
|--------------------------------------------------------------------------
*/

require __DIR__
    . '/includes/header.php';

?>


<div class="panel-card">


    <div class="mb-4">

        <h2 class="h5 mb-1">

            <i class="bi bi-stars"></i>

            Teste OpenAI

        </h2>


        <div class="small text-secondary">

            Verificação da comunicação entre
            o Bora pra Obra e a API de IA.

        </div>

    </div>


    <?php if ($erro): ?>


        <div class="alert alert-danger">

            <strong>

                <i
                    class="
                        bi
                        bi-exclamation-triangle
                        me-1
                    "
                ></i>

                Integração não concluída.

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

                <i
                    class="
                        bi
                        bi-check-circle
                        me-1
                    "
                ></i>

                OpenAI conectada com sucesso!

            </strong>


            <div class="mt-2">

                <?= htmlspecialchars(
                    $resultado['texto']
                    ?? ''
                ) ?>

            </div>

        </div>


        <div class="row g-3">


            <!-- MODELO -->

            <div class="col-md-4">

                <div
                    class="
                        border
                        rounded
                        p-3
                        h-100
                    "
                >

                    <div class="small text-secondary">
                        Modelo
                    </div>

                    <div class="fw-bold mt-1">

                        <?= htmlspecialchars(
                            $resultado['model']
                            ?? ''
                        ) ?>

                    </div>

                </div>

            </div>


            <!-- TEMPO -->

            <div class="col-md-4">

                <div
                    class="
                        border
                        rounded
                        p-3
                        h-100
                    "
                >

                    <div class="small text-secondary">
                        Tempo da chamada
                    </div>

                    <div class="fw-bold mt-1">

                        <?= number_format(
                            (float) (
                                $resultado[
                                    'tempo_segundos'
                                ]
                                ?? 0
                            ),
                            2,
                            ',',
                            '.'
                        ) ?>

                        s

                    </div>

                </div>

            </div>


            <!-- RESPONSE ID -->

            <div class="col-md-4">

                <div
                    class="
                        border
                        rounded
                        p-3
                        h-100
                    "
                >

                    <div class="small text-secondary">
                        Response ID
                    </div>

                    <div
                        class="
                            small
                            fw-semibold
                            mt-1
                            text-break
                        "
                    >

                        <?= htmlspecialchars(
                            $resultado[
                                'response_id'
                            ]
                            ?? ''
                        ) ?>

                    </div>

                </div>

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

require __DIR__
    . '/includes/footer.php';

?>