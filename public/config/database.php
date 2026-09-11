<?php

$candidatos = [
    "/home/u719183319/domains/firebrick-rook-290123.hostingersite.com/config_secreto/database.local.php",
    __DIR__ . "/database.local.php",
];

$cfg = null;

foreach ($candidatos as $arquivo) {

    if (is_file($arquivo)) {

        $cfg = require $arquivo;

        break;
    }
}

if (!$cfg) {

    http_response_code(500);

    exit(
        "Configuracao ausente: database.local.php nao encontrado."
    );
}


/*
|--------------------------------------------------------------------------
| CONEXÃO COM O BANCO
|--------------------------------------------------------------------------
|
| $forcarNovaConexao = true
|
| força a criação de um novo PDO.
| Isso é importante em processos demorados,
| como chamadas para IA, porque o MySQL pode
| encerrar a conexão enquanto o PHP espera.
|
|--------------------------------------------------------------------------
*/

function db(
    bool $forcarNovaConexao = false
): PDO {

    static $pdo = null;


    /*
    |--------------------------------------------------------------------------
    | REUTILIZAR CONEXÃO EXISTENTE
    |--------------------------------------------------------------------------
    */

    if (
        !$forcarNovaConexao
        &&
        $pdo instanceof PDO
    ) {

        return $pdo;
    }


    /*
    |--------------------------------------------------------------------------
    | DESCARTAR CONEXÃO ANTIGA
    |--------------------------------------------------------------------------
    */

    if ($forcarNovaConexao) {

        $pdo = null;
    }


    global $cfg;


    $dsn =
        "mysql:host="
        .
        $cfg["host"]
        .
        ";dbname="
        .
        $cfg["name"]
        .
        ";charset=utf8mb4";


    try {

        $pdo =
            new PDO(
                $dsn,
                $cfg["user"],
                $cfg["pass"],
                [

                    PDO::ATTR_ERRMODE =>
                        PDO::ERRMODE_EXCEPTION,

                    PDO::ATTR_DEFAULT_FETCH_MODE =>
                        PDO::FETCH_ASSOC,

                    PDO::ATTR_EMULATE_PREPARES =>
                        false,

                    /*
                     * Não usamos conexão persistente.
                     *
                     * Em chamadas longas de IA,
                     * uma conexão persistente pode
                     * reaproveitar uma sessão MySQL
                     * que já foi encerrada.
                     */

                    PDO::ATTR_PERSISTENT =>
                        false,

                ]
            );


        return $pdo;


    } catch (PDOException $e) {

        http_response_code(500);

        exit(
            "Falha ao conectar ao banco."
        );
    }
}