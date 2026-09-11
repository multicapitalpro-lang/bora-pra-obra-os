<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| THUMBNAIL PROXY - GOOGLE DRIVE
|--------------------------------------------------------------------------
|
| O navegador chama:
|
| thumbnail_drive.php?id=ID_DO_ARQUIVO
|
| Nosso servidor:
|
| 1. autentica na conta de serviço;
| 2. busca a thumbnail atual;
| 3. faz cache local;
| 4. entrega a imagem ao navegador.
|
|--------------------------------------------------------------------------
*/

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/Drive/GoogleDriveService.php';


$fileId = trim(
    $_GET['id'] ?? ''
);


/*
|--------------------------------------------------------------------------
| VALIDAR ID
|--------------------------------------------------------------------------
*/

if (
    $fileId === ''
    ||
    !preg_match(
        '/^[A-Za-z0-9_-]+$/',
        $fileId
    )
) {

    http_response_code(400);
    exit;
}


/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cacheDir =
    dirname(__DIR__, 2)
    . '/storage/cache/drive_thumbnails';


if (!is_dir($cacheDir)) {

    @mkdir(
        $cacheDir,
        0755,
        true
    );
}


$cacheFile =
    $cacheDir
    . '/'
    . sha1($fileId)
    . '.jpg';


/*
 * 6 horas
 */
$cacheDuracao = 21600;


/*
|--------------------------------------------------------------------------
| USAR CACHE SE EXISTIR
|--------------------------------------------------------------------------
*/

if (
    is_file($cacheFile)
    &&
    filemtime($cacheFile)
        >= (time() - $cacheDuracao)
) {

    header('Content-Type: image/jpeg');

    header(
        'Cache-Control: public, max-age=21600'
    );

    header(
        'Content-Length: '
        . filesize($cacheFile)
    );

    readfile($cacheFile);

    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR NO GOOGLE DRIVE
|--------------------------------------------------------------------------
*/

try {

    $drive =
        new GoogleDriveService();


    $thumbnail =
        $drive->obterThumbnail(
            $fileId
        );


    $conteudo =
        $thumbnail['content'];


    $contentType =
        $thumbnail['content_type']
        ?? 'image/jpeg';


    /*
    |--------------------------------------------------------------------------
    | SALVAR CACHE
    |--------------------------------------------------------------------------
    */

    if (
        is_dir($cacheDir)
        &&
        is_writable($cacheDir)
    ) {

        @file_put_contents(
            $cacheFile,
            $conteudo
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ENTREGAR IMAGEM
    |--------------------------------------------------------------------------
    */

    header(
        'Content-Type: '
        . $contentType
    );

    header(
        'Cache-Control: public, max-age=21600'
    );

    header(
        'Content-Length: '
        . strlen($conteudo)
    );


    echo $conteudo;


} catch (Throwable $e) {

    /*
     * Não exibimos mensagem de erro dentro de <img>,
     * porque isso causaria imagem quebrada + HTML.
     */

    http_response_code(404);
    exit;
}