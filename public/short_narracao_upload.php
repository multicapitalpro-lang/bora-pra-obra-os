<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';
require dirname(__DIR__) . '/app/AI/OpenAIService.php';

$pdo = db();

function responderUpload(
    array $dados,
    int $status = 200
): never {

    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

    responderUpload(
        ['success' => false, 'message' => 'Método inválido.'],
        405
    );
}


try {

    $shortRoteiroId =
        (int) ($_POST['short_roteiro_id'] ?? 0);


    if ($shortRoteiroId <= 0) {

        throw new RuntimeException('Short inválido.');
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR ROTEIRO
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id FROM capitulo_short_roteiros WHERE id = ? LIMIT 1'
    );

    $stmt->execute([$shortRoteiroId]);

    if (!$stmt->fetch()) {

        throw new RuntimeException('Short não encontrado.');
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR ARQUIVO ENVIADO
    |--------------------------------------------------------------------------
    */

    if (
        empty($_FILES['audio'])
        ||
        ($_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {

        throw new RuntimeException('Envie um arquivo de áudio.');
    }


    $extensoesPermitidas = ['mp3', 'wav', 'm4a', 'ogg', 'webm'];

    $extensao = strtolower(
        (string) pathinfo(
            (string) $_FILES['audio']['name'],
            PATHINFO_EXTENSION
        )
    );

    if (!in_array($extensao, $extensoesPermitidas, true)) {

        throw new RuntimeException(
            'Formato não suportado. Use: '
            . implode(', ', $extensoesPermitidas)
        );
    }


    $tamanhoMaximoBytes = 25 * 1024 * 1024; // limite da própria OpenAI p/ transcrição

    if ((int) $_FILES['audio']['size'] > $tamanhoMaximoBytes) {

        throw new RuntimeException('Áudio muito grande (máximo 25 MB).');
    }


    /*
    |--------------------------------------------------------------------------
    | SALVAR ARQUIVO
    |--------------------------------------------------------------------------
    */

    $pastaNarracoes =
        dirname(__DIR__, 2) . '/storage/narracoes';

    if (!is_dir($pastaNarracoes)) {

        mkdir($pastaNarracoes, 0755, true);
    }


    $nomeArquivo =
        'short_' . $shortRoteiroId . '_' . time() . '.' . $extensao;

    $caminhoDestino = $pastaNarracoes . '/' . $nomeArquivo;

    if (
        !move_uploaded_file(
            $_FILES['audio']['tmp_name'],
            $caminhoDestino
        )
    ) {

        throw new RuntimeException('Não foi possível salvar o áudio.');
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSCREVER
    |--------------------------------------------------------------------------
    */

    $openAI = new OpenAIService();

    $inicio = microtime(true);

    $transcricao = $openAI->transcreverAudio($caminhoDestino);

    $tempoSegundos = round(microtime(true) - $inicio, 2);


    if (empty($transcricao['segmentos'])) {

        throw new RuntimeException(
            'A transcrição não retornou segmentos com timestamp.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | RECONECTAR AO BANCO
    |--------------------------------------------------------------------------
    |
    | A transcrição pode levar dezenas de segundos; a conexão aberta
    | antes da chamada pode ter caído nesse meio tempo.
    |
    |--------------------------------------------------------------------------
    */

    $pdo = db(true);


    /*
    |--------------------------------------------------------------------------
    | SALVAR
    |--------------------------------------------------------------------------
    */

    $stmtUpdate = $pdo->prepare(
        '
        UPDATE capitulo_short_roteiros
        SET
            narracao_audio_path = ?,
            narracao_transcricao = ?,
            narracao_status = ?,
            narracao_enviado_at = NOW()
        WHERE id = ?
        '
    );

    /*
    | narracao_transcricao guarda o JSON dos SEGMENTOS (com
    | timestamp), não o texto corrido — cada segmento já traz seu
    | próprio texto, então dá pra reconstruir o texto completo
    | concatenando-os quando precisar exibir.
    */

    $stmtUpdate->execute([
        'narracoes/' . $nomeArquivo,
        json_encode(
            $transcricao['segmentos'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        'transcrita',
        $shortRoteiroId,
    ]);


    responderUpload([

        'success' => true,

        'message' => 'Narração transcrita com sucesso.',

        'texto' => $transcricao['texto'],

        'segmentos' => $transcricao['segmentos'],

        'tempo_segundos' => $tempoSegundos,

    ]);


} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}
