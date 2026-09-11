<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/includes/auth.php';

require_once dirname(__DIR__)
    . '/app/AI/OpenAIService.php';


$pageTitle =
    'Teste Triagem IA';


$resultado = null;

$erro = null;


/*
|--------------------------------------------------------------------------
| TRANSCRIÇÃO DE TESTE
|--------------------------------------------------------------------------
|
| Ainda não veio de um vídeo.
| Estamos testando somente o cérebro da Triagem.
|
|--------------------------------------------------------------------------
*/

$transcricaoTeste =
    '
    Hoje nós estamos aqui no terreno porque antes de começar
    a construção tivemos que resolver essa parte do poste.

    A primeira coisa foi verificar onde ele estava instalado
    e qual seria a posição correta.

    Depois entramos em contato para entender o procedimento.

    Isso precisava ser resolvido antes de continuar a preparação
    do terreno, porque poderia interferir na próxima etapa da obra.

    Agora essa parte está encaminhada e podemos seguir para
    os próximos serviços.
    ';


/*
|--------------------------------------------------------------------------
| EXECUTAR
|--------------------------------------------------------------------------
*/

try {

    $openAI =
        new OpenAIService();


    $resultado =
        $openAI->analisarBrutoTriagem(
            $transcricaoTeste,
            [

                'titulo_capitulo' =>
                    'POSTE E SANEPAR',

                'nome_arquivo' =>
                    'GX010009.MP4',

                'duracao' =>
                    '03:16',

            ]
        );


} catch (Throwable $e) {

    $erro =
        $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

require __DIR__
    . '/includes/header.php';

?>


<div class="panel-card">


    <div class="mb-4">

        <h2 class="h5 mb-1">

            <i class="bi bi-stars"></i>

            Teste de Triagem com IA

        </h2>

        <div class="small text-secondary">

            Primeiro teste da inteligência
            editorial do Bora pra Obra.

        </div>

    </div>


    <?php if ($erro): ?>


        <div class="alert alert-danger">

            <strong>
                A análise falhou.
            </strong>

            <div class="mt-2">

                <?= htmlspecialchars(
                    $erro
                ) ?>

            </div>

        </div>


    <?php elseif ($resultado): ?>


        <?php

        $dados =
            $resultado['dados']
            ?? [];

        ?>


        <div class="alert alert-success">

            <strong>

                <i class="bi bi-check-circle me-1"></i>

                Triagem gerada pela IA.

            </strong>

            <div class="small mt-1">

                A resposta chegou em formato
                estruturado e validado.

            </div>

        </div>


        <!-- RESUMO -->

        <div class="border rounded p-3 mb-3">

            <div class="small text-secondary">
                Resumo do bruto
            </div>

            <div class="fw-semibold mt-1">

                <?= htmlspecialchars(
                    $dados['resumo']
                    ?? ''
                ) ?>

            </div>

        </div>


        <div class="row g-3 mb-3">


            <!-- DECISÃO -->

            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Decisão
                    </div>

                    <div class="fs-5 fw-bold mt-1">

                        <?= htmlspecialchars(
                            strtoupper(
                                $dados['decisao']
                                ?? ''
                            )
                        ) ?>

                    </div>

                </div>

            </div>


            <!-- TIPO -->

            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Tipo
                    </div>

                    <div class="fs-5 fw-bold mt-1">

                        <?= htmlspecialchars(
                            ucfirst(
                                $dados['tipo']
                                ?? ''
                            )
                        ) ?>

                    </div>

                </div>

            </div>


            <!-- IMPORTÂNCIA -->

            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Importância
                    </div>

                    <div class="fs-5 fw-bold mt-1">

                        <?= htmlspecialchars(
                            ucfirst(
                                $dados['importancia']
                                ?? ''
                            )
                        ) ?>

                    </div>

                </div>

            </div>


        </div>


        <div class="row g-3">


            <!-- POSSÍVEL USO -->

            <div class="col-md-6">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Possível uso
                    </div>

                    <div class="fw-semibold mt-1">

                        <?= htmlspecialchars(
                            $dados['possivel_uso']
                            ?? ''
                        ) ?>

                    </div>

                </div>

            </div>


            <!-- MOTIVO -->

            <div class="col-md-6">

                <div class="border rounded p-3 h-100">

                    <div class="small text-secondary">
                        Por que a IA tomou essa decisão?
                    </div>

                    <div class="mt-1">

                        <?= htmlspecialchars(
                            $dados['motivo_decisao']
                            ?? ''
                        ) ?>

                    </div>

                </div>

            </div>


            <!-- OBSERVAÇÕES -->

            <div class="col-md-12">

                <div class="border rounded p-3">

                    <div class="small text-secondary">
                        Observação para edição
                    </div>

                    <div class="mt-1">

                        <?= htmlspecialchars(
                            $dados['observacoes']
                            ?? ''
                        ) ?>

                    </div>

                </div>

            </div>


        </div>


        <div class="row g-3 mt-1">


            <div class="col-md-6">

                <div class="border rounded p-3">

                    <div class="small text-secondary">
                        Modelo
                    </div>

                    <div class="small fw-semibold mt-1">

                        <?= htmlspecialchars(
                            $resultado['model']
                            ?? ''
                        ) ?>

                    </div>

                </div>

            </div>


            <div class="col-md-6">

                <div class="border rounded p-3">

                    <div class="small text-secondary">
                        Tempo
                    </div>

                    <div class="small fw-semibold mt-1">

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

                        segundos

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