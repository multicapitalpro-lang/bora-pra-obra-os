<?php

declare(strict_types=1);

use Google\Client;
use Google\Service\Drive;

class GoogleDriveService
{
    private Drive $drive;
    private Client $client;


    /*
    |--------------------------------------------------------------------------
    | CONSTRUTOR
    |--------------------------------------------------------------------------
    */

    public function __construct()
    {
        $credentials = glob(
            dirname(__DIR__, 3)
            . '/storage/credentials/*.json'
        );

        if (
            !$credentials
            ||
            !isset($credentials[0])
        ) {
            throw new RuntimeException(
                'Arquivo JSON de credenciais do Google Drive não encontrado.'
            );
        }

        $client = new Client();

        $client->setAuthConfig(
            $credentials[0]
        );

        $client->setScopes([
            Drive::DRIVE_READONLY
        ]);

        $this->client = $client;
        $this->drive = new Drive($client);
    }


    /*
    |--------------------------------------------------------------------------
    | COMPARTILHADOS
    |--------------------------------------------------------------------------
    */

    public function listarCompartilhados(): array
    {
        $resultado =
            $this->drive
                ->files
                ->listFiles([
                    'q' =>
                        'sharedWithMe = true and trashed = false',

                    'fields' =>
                        'files(id,name,mimeType,webViewLink,createdTime,modifiedTime)',

                    'pageSize' =>
                        100,

                    'orderBy' =>
                        'name',
                ]);

        return $resultado->getFiles();
    }


    /*
    |--------------------------------------------------------------------------
    | LISTAR PASTA
    |--------------------------------------------------------------------------
    */

    public function listarPasta(
        string $folderId
    ): array {

        $folderId = trim($folderId);

        if ($folderId === '') {
            throw new InvalidArgumentException(
                'ID da pasta do Google Drive não informado.'
            );
        }

        $resultado =
            $this->drive
                ->files
                ->listFiles([
                    'q' =>
                        sprintf(
                            "'%s' in parents and trashed = false",
                            addslashes($folderId)
                        ),

                    'fields' =>
                        'files(id,name,mimeType,size,webViewLink,thumbnailLink,videoMediaMetadata,createdTime,modifiedTime)',

                    'pageSize' =>
                        1000,

                    'orderBy' =>
                        'name',
                ]);

        return $resultado->getFiles();
    }


    /*
    |--------------------------------------------------------------------------
    | METADADOS DE UM ARQUIVO
    |--------------------------------------------------------------------------
    */

    public function obterMetadadosArquivo(
        string $fileId
    ): array {

        $fileId = trim($fileId);

        if ($fileId === '') {
            throw new InvalidArgumentException(
                'Google Drive File ID não informado.'
            );
        }

        $arquivo =
            $this->drive
                ->files
                ->get(
                    $fileId,
                    [
                        'fields' =>
                            'id,name,mimeType,size,webViewLink,thumbnailLink,videoMediaMetadata,createdTime,modifiedTime,capabilities(canDownload)'
                    ]
                );

        $capabilities =
            $arquivo->getCapabilities();

        $canDownload =
            $capabilities
                ? (bool) $capabilities->getCanDownload()
                : true;

        return [
            'id' =>
                (string) $arquivo->getId(),

            'nome' =>
                (string) $arquivo->getName(),

            'mime_type' =>
                (string) $arquivo->getMimeType(),

            'tamanho_bytes' =>
                (int) (
                    $arquivo->getSize()
                    ?? 0
                ),

            'drive_url' =>
                $arquivo->getWebViewLink(),

            'thumbnail_url' =>
                $arquivo->getThumbnailLink(),

            'pode_baixar' =>
                $canDownload,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | THUMBNAIL
    |--------------------------------------------------------------------------
    */

    public function obterThumbnail(
        string $fileId
    ): array {

        $fileId = trim($fileId);

        if ($fileId === '') {
            throw new InvalidArgumentException(
                'Google Drive File ID não informado.'
            );
        }

        $arquivo =
            $this->drive
                ->files
                ->get(
                    $fileId,
                    [
                        'fields' =>
                            'id,mimeType,thumbnailLink'
                    ]
                );

        $thumbnailLink =
            $arquivo->getThumbnailLink();

        if (!$thumbnailLink) {
            throw new RuntimeException(
                'Este arquivo não possui thumbnail disponível.'
            );
        }

        $http =
            $this->client->authorize();

        $response =
            $http->request(
                'GET',
                $thumbnailLink,
                [
                    'http_errors' => true
                ]
            );

        $content =
            (string) $response->getBody();

        if ($content === '') {
            throw new RuntimeException(
                'O Google Drive retornou uma thumbnail vazia.'
            );
        }

        $contentType =
            $response->getHeaderLine(
                'Content-Type'
            );

        if ($contentType === '') {
            $contentType = 'image/jpeg';
        }

        return [
            'content' =>
                $content,

            'content_type' =>
                $contentType,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | BAIXAR ARQUIVO
    |--------------------------------------------------------------------------
    |
    | O vídeo é gravado diretamente no disco.
    | Não carregamos o arquivo inteiro em memória.
    |
    |--------------------------------------------------------------------------
    */

    public function baixarArquivo(
        string $fileId,
        string $destino
    ): array {

        $fileId = trim($fileId);
        $destino = trim($destino);

        if ($fileId === '') {
            throw new InvalidArgumentException(
                'Google Drive File ID não informado.'
            );
        }

        if ($destino === '') {
            throw new InvalidArgumentException(
                'Caminho de destino não informado.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | METADADOS
        |--------------------------------------------------------------------------
        */

        $metadados =
            $this->obterMetadadosArquivo(
                $fileId
            );

        if (
            empty(
                $metadados['pode_baixar']
            )
        ) {
            throw new RuntimeException(
                'O Google Drive não permite baixar este arquivo.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | BLOQUEAR GOOGLE DOCS / SHEETS / SLIDES
        |--------------------------------------------------------------------------
        */

        $mimeType =
            (string) (
                $metadados['mime_type']
                ?? ''
            );

        if (
            str_starts_with(
                $mimeType,
                'application/vnd.google-apps.'
            )
        ) {
            throw new RuntimeException(
                'Este método não baixa documentos nativos do Google Workspace.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CRIAR DIRETÓRIO
        |--------------------------------------------------------------------------
        */

        $diretorio =
            dirname($destino);

        if (!is_dir($diretorio)) {

            if (
                !mkdir(
                    $diretorio,
                    0775,
                    true
                )
                &&
                !is_dir($diretorio)
            ) {
                throw new RuntimeException(
                    'Não foi possível criar o diretório temporário.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | REMOVER DESTINO ANTIGO
        |--------------------------------------------------------------------------
        */

        if (file_exists($destino)) {

            if (!unlink($destino)) {
                throw new RuntimeException(
                    'Não foi possível remover o arquivo temporário anterior.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | DOWNLOAD
        |--------------------------------------------------------------------------
        |
        | O Google Drive documenta files.get + alt=media
        | para baixar arquivos blob armazenados no Drive.
        |
        |--------------------------------------------------------------------------
        */

        $http =
            $this->client->authorize();

        $url =
            'https://www.googleapis.com/drive/v3/files/'
            .
            rawurlencode($fileId)
            .
            '?alt=media';


        try {

            $response =
                $http->request(
                    'GET',
                    $url,
                    [
                        'sink' =>
                            $destino,

                        'http_errors' =>
                            true,

                        'timeout' =>
                            0,

                        'connect_timeout' =>
                            30,
                    ]
                );

        } catch (Throwable $e) {

            if (file_exists($destino)) {
                @unlink($destino);
            }

            throw new RuntimeException(
                'Erro ao baixar arquivo do Google Drive: '
                .
                $e->getMessage(),
                0,
                $e
            );
        }


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        $statusCode =
            $response->getStatusCode();

        if (
            $statusCode < 200
            ||
            $statusCode >= 300
        ) {

            if (file_exists($destino)) {
                @unlink($destino);
            }

            throw new RuntimeException(
                'O Google Drive respondeu com HTTP '
                .
                $statusCode
                .
                '.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDAR ARQUIVO
        |--------------------------------------------------------------------------
        */

        if (!file_exists($destino)) {
            throw new RuntimeException(
                'O download terminou, mas o arquivo não foi criado.'
            );
        }

        $tamanhoLocal =
            filesize($destino);

        if (
            $tamanhoLocal === false
            ||
            $tamanhoLocal <= 0
        ) {

            @unlink($destino);

            throw new RuntimeException(
                'O arquivo baixado está vazio.'
            );
        }


        return [
            'sucesso' =>
                true,

            'caminho' =>
                $destino,

            'tamanho_bytes' =>
                (int) $tamanhoLocal,

            'nome' =>
                $metadados['nome'],

            'mime_type' =>
                $metadados['mime_type'],

            'drive_file_id' =>
                $fileId,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | BAIXAR TEMPORÁRIO PARA IA
    |--------------------------------------------------------------------------
    */

    public function baixarArquivoTemporario(
        string $fileId,
        ?string $nomeOriginal = null
    ): array {

        $fileId = trim($fileId);

        if ($fileId === '') {
            throw new InvalidArgumentException(
                'Google Drive File ID não informado.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | NOME ORIGINAL
        |--------------------------------------------------------------------------
        */

        if (
            $nomeOriginal === null
            ||
            trim($nomeOriginal) === ''
        ) {

            $metadados =
                $this->obterMetadadosArquivo(
                    $fileId
                );

            $nomeOriginal =
                (string) (
                    $metadados['nome']
                    ?? 'arquivo.bin'
                );
        }


        /*
        |--------------------------------------------------------------------------
        | EXTENSÃO
        |--------------------------------------------------------------------------
        */

        $extensao =
            strtolower(
                pathinfo(
                    $nomeOriginal,
                    PATHINFO_EXTENSION
                )
            );

        $extensao =
            preg_replace(
                '/[^a-z0-9]/',
                '',
                $extensao
            );

        if ($extensao === '') {
            $extensao = 'bin';
        }


        /*
        |--------------------------------------------------------------------------
        | PASTA TEMPORÁRIA
        |--------------------------------------------------------------------------
        |
        | GoogleDriveService.php:
        | app/Drive/GoogleDriveService.php
        |
        | dirname(__DIR__, 2):
        | raiz do projeto
        |
        |--------------------------------------------------------------------------
        */

        $pastaTemporaria =
            dirname(__DIR__, 2)
            .
            '/storage/temp/ia';


        /*
        |--------------------------------------------------------------------------
        | NOME TEMPORÁRIO
        |--------------------------------------------------------------------------
        */

        $nomeTemporario =
            'drive_'
            .
            bin2hex(
                random_bytes(12)
            )
            .
            '.'
            .
            $extensao;


        $caminho =
            $pastaTemporaria
            .
            '/'
            .
            $nomeTemporario;


        return
            $this->baixarArquivo(
                $fileId,
                $caminho
            );
    }


    /*
    |--------------------------------------------------------------------------
    | REMOVER TEMPORÁRIO
    |--------------------------------------------------------------------------
    */

    public function removerArquivoTemporario(
        ?string $caminho
    ): void {

        if (
            !$caminho
            ||
            trim($caminho) === ''
        ) {
            return;
        }

        if (!file_exists($caminho)) {
            return;
        }


        $pastaPermitida =
            realpath(
                dirname(__DIR__, 2)
                .
                '/storage/temp/ia'
            );

        $arquivoReal =
            realpath($caminho);

        if (
            !$pastaPermitida
            ||
            !$arquivoReal
        ) {
            return;
        }


        if (
            !str_starts_with(
                $arquivoReal,
                $pastaPermitida
                .
                DIRECTORY_SEPARATOR
            )
        ) {
            throw new RuntimeException(
                'Tentativa de remover arquivo fora da pasta temporária permitida.'
            );
        }


        if (!unlink($arquivoReal)) {
            throw new RuntimeException(
                'Não foi possível remover o arquivo temporário.'
            );
        }
    }
}