<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header(
    'Content-Type: application/json; charset=utf-8'
);

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';

$pdo = db();


function responderExportacaoCapCut(
    array $dados,
    int $status = 200
): never {

    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
        |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


try {

    if (
        $_SERVER['REQUEST_METHOD']
        !== 'POST'
    ) {

        responderExportacaoCapCut(
            [
                'success' =>
                    false,

                'message' =>
                    'Método inválido.',
            ],
            405
        );
    }


    $capituloId =
        (int) (
            $_POST['capitulo_id']
            ?? 0
        );


    if ($capituloId <= 0) {

        throw new RuntimeException(
            'Capítulo inválido.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CONFERIR CAPÍTULO
    |--------------------------------------------------------------------------
    */

    $stmtCapitulo =
        $pdo->prepare(
            '
            SELECT
                id,
                numero,
                titulo

            FROM capitulos

            WHERE id = ?

            LIMIT 1
            '
        );


    $stmtCapitulo->execute([
        $capituloId
    ]);


    $capitulo =
        $stmtCapitulo->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$capitulo) {

        throw new RuntimeException(
            'Capítulo não encontrado.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CONTAR CLIPS
    |--------------------------------------------------------------------------
    */

    $stmtClips =
        $pdo->prepare(
            '
            SELECT
                COUNT(*)

            FROM capitulo_edicao_clips

            WHERE capitulo_id = ?
              AND status_clip IN (
                    "sugerido",
                    "aprovado",
                    "preparado",
                    "importado",
                    "concluido"
              )
            '
        );


    $stmtClips->execute([
        $capituloId
    ]);


    $totalClips =
        (int) $stmtClips
            ->fetchColumn();


    if ($totalClips <= 0) {

        throw new RuntimeException(
            'Este capítulo ainda não possui cortes preparados para o CapCut.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR EXPORTAÇÃO ATIVA
    |--------------------------------------------------------------------------
    */

    $stmtAtiva =
        $pdo->prepare(
            '
            SELECT
                id,
                status

            FROM capitulo_exportacoes

            WHERE capitulo_id = ?
              AND tipo = "capcut_clips"
              AND status IN (
                    "na_fila",
                    "processando"
              )

            ORDER BY id DESC

            LIMIT 1
            '
        );


    $stmtAtiva->execute([
        $capituloId
    ]);


    $ativa =
        $stmtAtiva->fetch(
            PDO::FETCH_ASSOC
        );


    if ($ativa) {

        responderExportacaoCapCut(
            [
                'success' =>
                    true,

                'message' =>
                    'Já existe uma preparação do CapCut em andamento.',

                'exportacao_id' =>
                    (int) $ativa[
                        'id'
                    ],

                'status' =>
                    (string) $ativa[
                        'status'
                    ],

                'total_clips' =>
                    $totalClips,

                'ja_existia' =>
                    true,
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CRIAR NOVA EXPORTAÇÃO
    |--------------------------------------------------------------------------
    */

    $pdo =
        db(true);


    $stmtCriar =
        $pdo->prepare(
            '
            INSERT INTO capitulo_exportacoes
            (
                capitulo_id,
                tipo,
                status,
                total_clips,
                clips_processados,
                token,
                caminho_saida,
                erro,
                processamento_inicio_at,
                concluido_at
            )
            VALUES
            (
                ?,
                "capcut_clips",
                "na_fila",
                ?,
                0,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL
            )
            '
        );


    $stmtCriar->execute([
        $capituloId,
        $totalClips,
    ]);


    $exportacaoId =
        (int) $pdo
            ->lastInsertId();


    responderExportacaoCapCut(
        [
            'success' =>
                true,

            'message' =>
                'Preparação dos arquivos para CapCut enviada ao worker.',

            'exportacao_id' =>
                $exportacaoId,

            'capitulo_id' =>
                $capituloId,

            'capitulo_numero' =>
                (int) $capitulo[
                    'numero'
                ],

            'total_clips' =>
                $totalClips,

            'status' =>
                'na_fila',

            'ja_existia' =>
                false,
        ]
    );


} catch (Throwable $e) {

    responderExportacaoCapCut(
        [
            'success' =>
                false,

            'message' =>
                $e->getMessage(),
        ],
        500
    );
}