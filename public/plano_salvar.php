<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';

$pdo = db();

function responder(
    bool $sucesso,
    string $mensagem,
    array $extra = []
): never {

    echo json_encode(
        array_merge(
            [
                'sucesso' => $sucesso,
                'mensagem' => $mensagem,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, 'Método inválido.');
}


$capituloId = (int) ($_POST['capitulo_id'] ?? 0);

if ($capituloId <= 0) {
    responder(false, 'Capítulo inválido.');
}


/*
|--------------------------------------------------------------------------
| CAMPOS DO PLANO
|--------------------------------------------------------------------------
*/

$objetivo = trim($_POST['objetivo'] ?? '');
$gancho = trim($_POST['gancho'] ?? '');
$contexto = trim($_POST['contexto'] ?? '');
$desenvolvimento = trim($_POST['desenvolvimento'] ?? '');
$encerramento = trim($_POST['encerramento'] ?? '');

$broll = trim($_POST['broll'] ?? '');
$textosTela = trim($_POST['textos_tela'] ?? '');
$audioMusica = trim($_POST['audio_musica'] ?? '');
$observacoesEdicao = trim($_POST['observacoes_edicao'] ?? '');
$duracaoAlvo = trim($_POST['duracao_alvo'] ?? '');

$statusPlano = $_POST['status_plano'] ?? 'nao_iniciado';


$statusPermitidos = [
    'nao_iniciado',
    'rascunho',
    'pronto_edicao',
];


if (!in_array($statusPlano, $statusPermitidos, true)) {
    $statusPlano = 'nao_iniciado';
}


$arquivos = $_POST['arquivos'] ?? [];

if (!is_array($arquivos)) {
    $arquivos = [];
}


try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | 1. CONFIRMAR QUE O CAPÍTULO EXISTE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM capitulos
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$capituloId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(
            'O capítulo informado não existe.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 2. SALVAR / ATUALIZAR PLANO
    |--------------------------------------------------------------------------
    |
    | capitulo_id possui UNIQUE no banco.
    | Portanto cada capítulo possui apenas um plano.
    |
    */

    $stmt = $pdo->prepare("
        INSERT INTO capitulo_planos (
            capitulo_id,
            objetivo,
            gancho,
            contexto,
            desenvolvimento,
            encerramento,
            broll,
            textos_tela,
            audio_musica,
            observacoes_edicao,
            duracao_alvo,
            status_plano
        )
        VALUES (
            :capitulo_id,
            :objetivo,
            :gancho,
            :contexto,
            :desenvolvimento,
            :encerramento,
            :broll,
            :textos_tela,
            :audio_musica,
            :observacoes_edicao,
            :duracao_alvo,
            :status_plano
        )

        ON DUPLICATE KEY UPDATE

            objetivo = VALUES(objetivo),
            gancho = VALUES(gancho),
            contexto = VALUES(contexto),
            desenvolvimento = VALUES(desenvolvimento),
            encerramento = VALUES(encerramento),
            broll = VALUES(broll),
            textos_tela = VALUES(textos_tela),
            audio_musica = VALUES(audio_musica),
            observacoes_edicao = VALUES(observacoes_edicao),
            duracao_alvo = VALUES(duracao_alvo),
            status_plano = VALUES(status_plano),
            updated_at = CURRENT_TIMESTAMP
    ");


    $stmt->execute([
        ':capitulo_id' => $capituloId,
        ':objetivo' => $objetivo,
        ':gancho' => $gancho,
        ':contexto' => $contexto,
        ':desenvolvimento' => $desenvolvimento,
        ':encerramento' => $encerramento,
        ':broll' => $broll,
        ':textos_tela' => $textosTela,
        ':audio_musica' => $audioMusica,
        ':observacoes_edicao' => $observacoesEdicao,
        ':duracao_alvo' => $duracaoAlvo,
        ':status_plano' => $statusPlano,
    ]);


    /*
    |--------------------------------------------------------------------------
    | 3. BUSCAR ARQUIVOS VÁLIDOS DESTE CAPÍTULO
    |--------------------------------------------------------------------------
    |
    | Isso impede alguém de alterar manualmente o HTML
    | e tentar relacionar arquivo de outro capítulo.
    |
    */

    $stmtArquivosValidos = $pdo->prepare("
        SELECT id
        FROM capitulo_arquivos
        WHERE capitulo_id = ?
          AND ativo = 1
    ");

    $stmtArquivosValidos->execute([$capituloId]);

    $idsValidos = array_map(
        'intval',
        $stmtArquivosValidos->fetchAll(PDO::FETCH_COLUMN)
    );

    $mapaValidos = array_fill_keys(
        $idsValidos,
        true
    );


    /*
    |--------------------------------------------------------------------------
    | 4. REMOVER LINHA DE EDIÇÃO ANTERIOR
    |--------------------------------------------------------------------------
    |
    | Depois inserimos novamente o estado atual do formulário.
    | Isso evita registros antigos de arquivos que deixaram
    | de fazer parte do plano.
    |
    */

    $stmt = $pdo->prepare("
        DELETE FROM capitulo_plano_arquivos
        WHERE capitulo_id = ?
    ");

    $stmt->execute([$capituloId]);


    /*
    |--------------------------------------------------------------------------
    | 5. INSERIR LINHA DE EDIÇÃO
    |--------------------------------------------------------------------------
    */

    $stmtInserirArquivo = $pdo->prepare("
        INSERT INTO capitulo_plano_arquivos (
            capitulo_id,
            arquivo_id,
            ordem,
            funcao,
            instrucao
        )
        VALUES (
            :capitulo_id,
            :arquivo_id,
            :ordem,
            :funcao,
            :instrucao
        )
    ");


    $funcoesPermitidas = [
        'gancho',
        'principal',
        'broll',
        'encerramento',
    ];


    $arquivosSalvos = 0;


    foreach ($arquivos as $arquivoId => $dados) {

        $arquivoId = (int) $arquivoId;


        /*
         * Arquivo precisa realmente pertencer
         * ao capítulo.
         */

        if (
            $arquivoId <= 0
            ||
            !isset($mapaValidos[$arquivoId])
        ) {
            continue;
        }


        if (!is_array($dados)) {
            continue;
        }


        $ordem = (int) ($dados['ordem'] ?? 0);

        if ($ordem <= 0) {
            $ordem = $arquivosSalvos + 1;
        }


        $funcao = $dados['funcao'] ?? 'principal';

        if (
            !in_array(
                $funcao,
                $funcoesPermitidas,
                true
            )
        ) {
            $funcao = 'principal';
        }


        $instrucao = trim(
            $dados['instrucao'] ?? ''
        );


        $stmtInserirArquivo->execute([
            ':capitulo_id' => $capituloId,
            ':arquivo_id' => $arquivoId,
            ':ordem' => $ordem,
            ':funcao' => $funcao,
            ':instrucao' => $instrucao,
        ]);


        $arquivosSalvos++;
    }


    /*
    |--------------------------------------------------------------------------
    | 6. FINALIZAR
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    responder(
        true,
        'Plano do episódio salvo com sucesso.',
        [
            'status_plano' => $statusPlano,
            'arquivos_salvos' => $arquivosSalvos,
        ]
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    responder(
        false,
        'Não foi possível salvar o plano: '
        . $e->getMessage()
    );
}