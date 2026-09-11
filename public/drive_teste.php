<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/Drive/GoogleDriveService.php';

try {

    $drive = new GoogleDriveService();

    /*
     * 1. Busca tudo que foi compartilhado diretamente
     * com a conta de serviço.
     */
    $compartilhados = $drive->listarCompartilhados();

    $rootFolderId = null;
    $rootFolderName = null;

    /*
     * 2. Procura automaticamente a pasta
     * "01 - VIDEOS BRUTOS".
     */
    foreach ($compartilhados as $item) {

        if (
            $item->getMimeType() === 'application/vnd.google-apps.folder'
            && trim($item->getName()) === '01 - VIDEOS BRUTOS'
        ) {

            $rootFolderId = $item->getId();
            $rootFolderName = $item->getName();

            break;
        }
    }

    if (!$rootFolderId) {
        throw new RuntimeException(
            'A pasta "01 - VIDEOS BRUTOS" não foi encontrada entre os itens compartilhados.'
        );
    }

    /*
     * 3. Entra na pasta encontrada e lista
     * tudo que existe dentro dela.
     */
    $arquivos = $drive->listarPasta($rootFolderId);

} catch (Throwable $e) {

    http_response_code(500);

    echo '<h1>Erro ao conectar ao Google Drive</h1>';

    echo '<pre>';
    echo htmlspecialchars($e->getMessage());
    echo '</pre>';

    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">

    <title>Teste Google Drive</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            padding: 30px;
            background: #f5f5f5;
        }

        .ok {
            background: #dff5e3;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #111;
            color: white;
        }

        code {
            font-size: 12px;
        }

    </style>

</head>

<body>

<div class="ok">

    <strong>✅ Google Drive conectado com sucesso!</strong>

    <br><br>

    Itens compartilhados encontrados:
    <?= count($arquivos) ?>

</div>

<table>

    <thead>

        <tr>
            <th>Nome</th>
            <th>Tipo</th>
            <th>ID Google Drive</th>
            <th>Abrir</th>
        </tr>

    </thead>

    <tbody>

    <?php foreach ($arquivos as $arquivo): ?>

        <tr>

            <td>
                <?= htmlspecialchars($arquivo->getName()) ?>
            </td>

            <td>

                <?php if (
                    $arquivo->getMimeType()
                    === 'application/vnd.google-apps.folder'
                ): ?>

                    📁 Pasta

                <?php else: ?>

                    📄 Arquivo

                <?php endif; ?>

            </td>

            <td>
                <code>
                    <?= htmlspecialchars($arquivo->getId()) ?>
                </code>
            </td>

            <td>

                <?php if ($arquivo->getWebViewLink()): ?>

                    <a
                        href="<?= htmlspecialchars(
                            $arquivo->getWebViewLink()
                        ) ?>"
                        target="_blank"
                    >
                        Abrir
                    </a>

                <?php endif; ?>

            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

</body>
</html>