<?php

declare(strict_types=1);

class OpenAIService
{
    private string $apiKey;

    private string $model;


    /*
    |--------------------------------------------------------------------------
    | CONSTRUTOR
    |--------------------------------------------------------------------------
    */

    public function __construct()
    {
        $configFile =
            dirname(__DIR__, 3)
            .
            '/storage/config/openai.php';


        if (!file_exists($configFile)) {

            throw new RuntimeException(
                'Arquivo de configuração da OpenAI não encontrado.'
            );
        }


        $config =
            require $configFile;


        $apiKey =
            trim(
                (string) (
                    $config['api_key']
                    ?? ''
                )
            );


        if (
            $apiKey === ''
            ||
            $apiKey === 'COLE_SUA_OPENAI_API_KEY_AQUI'
        ) {

            throw new RuntimeException(
                'A API Key da OpenAI ainda não foi configurada.'
            );
        }


        $this->apiKey =
            $apiKey;


        $this->model =
            trim(
                (string) (
                    $config['model']
                    ?? 'gpt-5-mini'
                )
            );


        if ($this->model === '') {

            $this->model =
                'gpt-5-mini';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REQUEST
    |--------------------------------------------------------------------------
    */

    private function request(
        string $endpoint,
        array $payload
    ): array {

        if (!function_exists('curl_init')) {

            throw new RuntimeException(
                'A extensão cURL do PHP não está disponível neste servidor.'
            );
        }


        $url =
            'https://api.openai.com'
            .
            $endpoint;


        $jsonPayload =
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
            );


        if ($jsonPayload === false) {

            throw new RuntimeException(
                'Não foi possível gerar o JSON da requisição.'
            );
        }


        $curl =
            curl_init($url);


        if ($curl === false) {

            throw new RuntimeException(
                'Não foi possível iniciar o cURL.'
            );
        }


        curl_setopt_array(
            $curl,
            [

                CURLOPT_POST =>
                    true,

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_CONNECTTIMEOUT =>
                    20,

                CURLOPT_TIMEOUT =>
                    180,

                CURLOPT_HTTPHEADER => [

                    'Authorization: Bearer '
                    .
                    $this->apiKey,

                    'Content-Type: application/json',

                ],

                CURLOPT_POSTFIELDS =>
                    $jsonPayload,

            ]
        );


        $resposta =
            curl_exec($curl);


        $curlErro =
            curl_error($curl);


        $httpCode =
            (int) curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );


        curl_close($curl);


        if ($resposta === false) {

            throw new RuntimeException(
                'Erro de comunicação com a OpenAI: '
                .
                $curlErro
            );
        }


        $json =
            json_decode(
                $resposta,
                true
            );


        if (!is_array($json)) {

            throw new RuntimeException(
                'A OpenAI retornou uma resposta JSON inválida.'
            );
        }


        if (
            $httpCode < 200
            ||
            $httpCode >= 300
        ) {

            $mensagem =
                $json['error']['message']
                ??
                'Erro HTTP '
                .
                $httpCode
                .
                ' na OpenAI.';


            throw new RuntimeException(
                $mensagem
            );
        }


        return $json;
    }


    /*
    |--------------------------------------------------------------------------
    | RESPOSTA EM TEXTO
    |--------------------------------------------------------------------------
    */

    public function responder(
        string $instrucao
    ): array {

        $instrucao =
            trim($instrucao);


        if ($instrucao === '') {

            throw new InvalidArgumentException(
                'A instrução para a IA está vazia.'
            );
        }


        return
            $this->request(
                '/v1/responses',
                [

                    'model' =>
                        $this->model,

                    /*
                     * Não precisamos guardar
                     * o estado desta chamada.
                     */

                    'store' =>
                        false,

                    'input' =>
                        $instrucao,

                ]
            );
    }


    /*
    |--------------------------------------------------------------------------
    | RESPOSTA ESTRUTURADA
    |--------------------------------------------------------------------------
    |
    | Faz a Responses API devolver JSON obedecendo exatamente
    | ao schema informado.
    |
    |--------------------------------------------------------------------------
    */

    public function responderEstruturado(
        string $instrucao,
        string $nomeSchema,
        array $schema
    ): array {

        $instrucao =
            trim($instrucao);


        $nomeSchema =
            trim($nomeSchema);


        if ($instrucao === '') {

            throw new InvalidArgumentException(
                'A instrução para a IA está vazia.'
            );
        }


        if ($nomeSchema === '') {

            throw new InvalidArgumentException(
                'Nome do schema não informado.'
            );
        }


        $response =
            $this->request(
                '/v1/responses',
                [

                    'model' =>
                        $this->model,

                    'store' =>
                        false,

                    'input' =>
                        $instrucao,

                    'text' => [

                        'format' => [

                            'type' =>
                                'json_schema',

                            'name' =>
                                $nomeSchema,

                            'strict' =>
                                true,

                            'schema' =>
                                $schema,

                        ],

                    ],

                ]
            );


        /*
        |--------------------------------------------------------------------------
        | VERIFICAR STATUS
        |--------------------------------------------------------------------------
        */

        $status =
            (string) (
                $response['status']
                ?? ''
            );


        if (
            $status !== ''
            &&
            $status !== 'completed'
        ) {

            throw new RuntimeException(
                'A resposta da IA não foi concluída. Status: '
                .
                $status
            );
        }


        /*
        |--------------------------------------------------------------------------
        | EXTRAIR TEXTO/JSON
        |--------------------------------------------------------------------------
        */

        $texto =
            $this->extrairTexto(
                $response
            );


        if ($texto === '') {

            throw new RuntimeException(
                'A IA não retornou o conteúdo estruturado.'
            );
        }


        $dados =
            json_decode(
                $texto,
                true
            );


        if (!is_array($dados)) {

            throw new RuntimeException(
                'Não foi possível interpretar o JSON estruturado retornado pela IA.'
            );
        }


        return [

            'dados' =>
                $dados,

            'response_id' =>
                $response['id']
                ?? null,

            'model' =>
                $response['model']
                ?? $this->model,

            'response' =>
                $response,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | EXTRAIR TEXTO DA RESPONSES API
    |--------------------------------------------------------------------------
    */

    public function extrairTexto(
        array $response
    ): string {

        $textos = [];


        foreach (
            $response['output']
            ?? []
            as $output
        ) {

            foreach (
                $output['content']
                ?? []
                as $content
            ) {

                if (
                    ($content['type'] ?? '')
                    === 'output_text'
                ) {

                    $texto =
                        trim(
                            (string) (
                                $content['text']
                                ?? ''
                            )
                        );


                    if ($texto !== '') {

                        $textos[] =
                            $texto;
                    }
                }
            }
        }


        return trim(
            implode(
                "\n\n",
                $textos
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ANALISAR BRUTO PARA TRIAGEM
    |--------------------------------------------------------------------------
    |
    | Esta será a função reutilizada posteriormente pela Etapa 2.
    |
    |--------------------------------------------------------------------------
    */

    public function analisarBrutoTriagem(
        string $transcricao,
        array $contexto = []
    ): array {

        $transcricao =
            trim($transcricao);


        if ($transcricao === '') {

            throw new InvalidArgumentException(
                'A transcrição do vídeo está vazia.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CONTEXTO
        |--------------------------------------------------------------------------
        */

        $tituloCapitulo =
            trim(
                (string) (
                    $contexto['titulo_capitulo']
                    ?? ''
                )
            );


        $nomeArquivo =
            trim(
                (string) (
                    $contexto['nome_arquivo']
                    ?? ''
                )
            );


        $duracao =
            trim(
                (string) (
                    $contexto['duracao']
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | PROMPT
        |--------------------------------------------------------------------------
        */

        $instrucao =
            <<<PROMPT
Você trabalha como assistente editorial do projeto "Bora pra Obra",
um canal que documenta uma obra residencial real.

Sua função é analisar a transcrição de UM vídeo bruto e fazer uma
triagem objetiva para ajudar a produzir o episódio principal.

CONTEXTO DO EPISÓDIO:
Título interno: {$tituloCapitulo}
Arquivo: {$nomeArquivo}
Duração aproximada: {$duracao}

CLASSIFICAÇÃO "decisao":
- usar: o vídeo tem conteúdo útil de forma geral para o episódio.
- parcial: somente parte do vídeo parece realmente aproveitável.
- descartar: não há conteúdo editorial relevante suficiente.

CLASSIFICAÇÃO "tipo":
- nao_definido: não é possível identificar.
- fala: predominância de explicação/fala.
- execucao: mostra execução de trabalho ou processo.
- broll: imagens de apoio.
- drone: imagens aéreas.
- detalhe: foco em algum detalhe específico.
- ambiente: mostra local/ambiente.
- timelapse: sequência acelerada ou conteúdo descrito como timelapse.
- foto: material estático/fotográfico.

CLASSIFICAÇÃO "importancia":
- normal: conteúdo aproveitável comum.
- boa: ajuda bastante o episódio.
- forte: momento particularmente interessante/importante.
- essencial: necessário para compreender a história.

"possivel_uso":
Explique em poucas palavras onde este material poderia entrar.
Exemplos: abertura, explicação principal, contexto, execução,
B-roll, transição, encerramento.

"observacoes":
Faça uma nota curta e prática para quem vai editar.
Não invente acontecimentos que não estejam na transcrição.

"resumo":
Resuma em até 2 frases o que acontece neste bruto.

"motivo_decisao":
Explique brevemente por que escolheu usar, parcial ou descartar.

IMPORTANTE:
Você está analisando SOMENTE a transcrição.
Se algo depender de imagem e não estiver descrito no áudio,
não afirme que viu.

TRANSCRIÇÃO:

{$transcricao}
PROMPT;


        /*
        |--------------------------------------------------------------------------
        | SCHEMA
        |--------------------------------------------------------------------------
        */

        $schema = [

            'type' =>
                'object',

            'additionalProperties' =>
                false,

            'properties' => [

                'decisao' => [

                    'type' =>
                        'string',

                    'enum' => [
                        'usar',
                        'parcial',
                        'descartar',
                    ],

                ],

                'tipo' => [

                    'type' =>
                        'string',

                    'enum' => [
                        'nao_definido',
                        'fala',
                        'execucao',
                        'broll',
                        'drone',
                        'detalhe',
                        'ambiente',
                        'timelapse',
                        'foto',
                    ],

                ],

                'importancia' => [

                    'type' =>
                        'string',

                    'enum' => [
                        'normal',
                        'boa',
                        'forte',
                        'essencial',
                    ],

                ],

                'possivel_uso' => [

                    'type' =>
                        'string',

                ],

                'observacoes' => [

                    'type' =>
                        'string',

                ],

                'resumo' => [

                    'type' =>
                        'string',

                ],

                'motivo_decisao' => [

                    'type' =>
                        'string',

                ],

            ],

            'required' => [

                'decisao',
                'tipo',
                'importancia',
                'possivel_uso',
                'observacoes',
                'resumo',
                'motivo_decisao',

            ],

        ];


        /*
        |--------------------------------------------------------------------------
        | CHAMAR IA
        |--------------------------------------------------------------------------
        */

        $inicio =
            microtime(true);


        $resultado =
            $this->responderEstruturado(
                $instrucao,
                'triagem_bruto',
                $schema
            );


        $resultado['tempo_segundos'] =
            round(
                microtime(true)
                -
                $inicio,
                2
            );


        return $resultado;
    }
    
    
    /*
|--------------------------------------------------------------------------
| GERAR ROTEIROS DE SHORTS
|--------------------------------------------------------------------------
*/

public function gerarRoteirosShorts(
    string $tituloCapitulo,
    string $objetivoCapitulo,
    string $ganchoCapitulo,
    string $contextoCapitulo,
    string $desenvolvimentoCapitulo,
    string $encerramentoCapitulo,
    array $arquivos
): array {

    $tituloCapitulo =
        trim($tituloCapitulo);

    $objetivoCapitulo =
        trim($objetivoCapitulo);

    $ganchoCapitulo =
        trim($ganchoCapitulo);

    $contextoCapitulo =
        trim($contextoCapitulo);

    $desenvolvimentoCapitulo =
        trim($desenvolvimentoCapitulo);

    $encerramentoCapitulo =
        trim($encerramentoCapitulo);


    /*
    |--------------------------------------------------------------------------
    | VALIDAR
    |--------------------------------------------------------------------------
    */

    if (
        $tituloCapitulo === ''
    ) {

        throw new InvalidArgumentException(
            'Título do capítulo não informado.'
        );
    }


    if (
        empty($arquivos)
    ) {

        throw new InvalidArgumentException(
            'Nenhum arquivo foi enviado para análise.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MONTAR MATERIAL DOS BRUTOS
    |--------------------------------------------------------------------------
    */

    $materialBrutos = '';


    foreach (
        $arquivos
        as $arquivo
    ) {

        $id =
            (int) (
                $arquivo['id']
                ?? 0
            );


        $nome =
            trim(
                (string) (
                    $arquivo['nome_arquivo']
                    ?? ''
                )
            );


        $duracao =
            trim(
                (string) (
                    $arquivo['duracao']
                    ?? ''
                )
            );


        $tipo =
            trim(
                (string) (
                    $arquivo['tipo']
                    ?? ''
                )
            );


        $importancia =
            trim(
                (string) (
                    $arquivo['importancia']
                    ?? ''
                )
            );


        $possivelUso =
            trim(
                (string) (
                    $arquivo['possivel_uso']
                    ?? ''
                )
            );


        $observacoes =
            trim(
                (string) (
                    $arquivo['observacoes']
                    ?? ''
                )
            );


        $transcricao =
            trim(
                (string) (
                    $arquivo['ia_transcricao']
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | SEM TRANSCRIÇÃO
        |--------------------------------------------------------------------------
        */

        if (
            $transcricao === ''
        ) {

            continue;
        }


        $materialBrutos .=
            "\n\n"
            .
            "==============================\n"
            .
            "BRUTO #"
            .
            $id
            .
            "\n"
            .
            "ARQUIVO: "
            .
            $nome
            .
            "\n"
            .
            "DURAÇÃO: "
            .
            $duracao
            .
            "\n"
            .
            "TIPO: "
            .
            $tipo
            .
            "\n"
            .
            "IMPORTÂNCIA: "
            .
            $importancia
            .
            "\n"
            .
            "POSSÍVEL USO: "
            .
            $possivelUso
            .
            "\n"
            .
            "OBSERVAÇÕES: "
            .
            $observacoes
            .
            "\n"
            .
            "TRANSCRIÇÃO:\n"
            .
            $transcricao
            .
            "\n"
            .
            "==============================\n";
    }


    if (
        trim($materialBrutos) === ''
    ) {

        throw new RuntimeException(
            'Nenhum dos arquivos possui transcrição disponível.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PROMPT
    |--------------------------------------------------------------------------
    */

    $instrucao =
        <<<PROMPT
Você é o roteirista oficial dos Shorts do projeto "Bora pra Obra".

O projeto documenta uma construção residencial real,
mostrando o processo da obra, decisões, problemas,
soluções, materiais e principalmente os custos reais.

Sua função é analisar os materiais deste capítulo e encontrar
as melhores oportunidades para criar Shorts.

NÃO faça apenas um resumo do vídeo longo.

Cada Short deve funcionar como uma história independente.

============================================================
IDENTIDADE DO CONTEÚDO
============================================================

O conteúdo deve transmitir:

- obra real;
- transparência;
- processo;
- experiência prática;
- custos reais;
- problemas e soluções;
- organização;
- controle da obra.

Não transforme o conteúdo em propaganda do aplicativo.

O aplicativo aparece como ferramenta real de controle da obra.

============================================================
ESTRUTURA PADRÃO DOS SHORTS
============================================================

A estrutura editorial deve seguir esta lógica:

1. VINHETA

A vinheta inicial já existe e será adicionada
posteriormente.

NÃO escreva a narração da vinheta.

2. ASSUNTO DO VÍDEO

Apresente rapidamente o que está acontecendo
naquele capítulo.

3. CUSTO DA ETAPA

Sempre que houver informação disponível,
o vídeo deve prever um momento para mostrar
no aplicativo o valor gasto naquela etapa.

O objetivo é mostrar transparência.

Exemplo:

"Essa etapa custou R$ X."

Mas NÃO invente o valor.

Se o valor não estiver disponível, escreva:

"INSERIR CUSTO DA ETAPA NO APLICATIVO."

4. EXECUÇÃO

Mostrar a execução da obra.

Priorize:

- demonstração;
- processo;
- detalhes;
- problemas;
- decisões;
- resultado visual.

5. FECHAMENTO

Mostrar o que foi concluído,
o que mudou ou o que foi aprendido.

6. CUSTO ACUMULADO

Quando fizer sentido, indicar um momento
para mostrar o custo acumulado da obra
até aquele capítulo.

Se não houver informação disponível:

"INSERIR CUSTO ACUMULADO NO APLICATIVO."

7. CTA

O CTA padrão do projeto será colocado posteriormente.

Não invente outro CTA.

Apenas indique:

"INSERIR CTA PADRÃO DO APLICATIVO."

============================================================
REGRAS DE NARRAÇÃO
============================================================

A narração deve ser natural.

Não escreva como texto publicitário.

Não use frases exageradas.

Não invente acontecimentos.

Não invente valores.

Não invente informações que não estejam
presentes nos materiais.

Não corte uma fala no meio de uma frase.

Sempre que possível, utilize falas completas.

Quando for necessária uma narração adicional,
escreva uma sugestão clara para ser gravada.

============================================================
CORTES
============================================================

Não recomende cortes no meio de uma frase.

Prefira:

- pausas naturais;
- mudança de assunto;
- mudança de plano;
- conclusão de uma ideia.

Quando uma fala puder continuar enquanto
entra B-roll, indique isso.

============================================================
B-ROLL
============================================================

Use B-roll quando ele ajudar a mostrar
visualmente aquilo que está sendo narrado.

Indique:

"ENTRAR B-ROLL DO BRUTO #X"

quando houver material adequado.

============================================================
TEXTOS NA TELA
============================================================

Os textos devem ser:

- curtos;
- diretos;
- fáceis de ler;
- úteis.

Evite transformar a tela em um parágrafo.

============================================================
QUANTIDADE
============================================================

Analise todo o material.

Gere de 1 a 5 Shorts.

Só gere vários Shorts quando existirem
ângulos realmente diferentes.

NÃO gere cinco Shorts praticamente iguais.

============================================================
DURAÇÃO
============================================================

Priorize aproximadamente:

25 a 60 segundos.

Não force uma duração se o assunto
funcionar melhor mais curto.

============================================================
CAPÍTULO
============================================================

Título:
{$tituloCapitulo}

Objetivo:
{$objetivoCapitulo}

Gancho:
{$ganchoCapitulo}

Contexto:
{$contextoCapitulo}

Desenvolvimento:
{$desenvolvimentoCapitulo}

Encerramento:
{$encerramentoCapitulo}

============================================================
MATERIAIS DOS VÍDEOS
============================================================

{$materialBrutos}

============================================================
RETORNO
============================================================

Para cada oportunidade de Short,
retorne:

- titulo
- angulo
- gancho
- contexto
- custo_momento
- narracao
- execucao
- fechamento
- custo_acumulado
- cta
- texto_tela
- broll
- duracao_alvo_segundos
- roteiro_completo

O campo "roteiro_completo" deve representar
a sequência final do Short em ordem.

Não inclua a vinheta dentro da narração.

A vinheta será adicionada posteriormente.

PROMPT;


    /*
    |--------------------------------------------------------------------------
    | SCHEMA
    |--------------------------------------------------------------------------
    */

    $schema = [

        'type' =>
            'object',

        'additionalProperties' =>
            false,

        'properties' => [

            'roteiros' => [

                'type' =>
                    'array',

                'minItems' =>
                    1,

                'maxItems' =>
                    5,

                'items' => [

                    'type' =>
                        'object',

                    'additionalProperties' =>
                        false,

                    'properties' => [

                        'titulo' => [
                            'type' => 'string'
                        ],

                        'angulo' => [
                            'type' => 'string'
                        ],

                        'gancho' => [
                            'type' => 'string'
                        ],

                        'contexto' => [
                            'type' => 'string'
                        ],

                        'custo_momento' => [
                            'type' => 'string'
                        ],

                        'narracao' => [
                            'type' => 'string'
                        ],

                        'execucao' => [
                            'type' => 'string'
                        ],

                        'fechamento' => [
                            'type' => 'string'
                        ],

                        'custo_acumulado' => [
                            'type' => 'string'
                        ],

                        'cta' => [
                            'type' => 'string'
                        ],

                        'texto_tela' => [
                            'type' => 'string'
                        ],

                        'broll' => [
                            'type' => 'string'
                        ],

                        'duracao_alvo_segundos' => [
                            'type' => 'integer'
                        ],

                        'roteiro_completo' => [
                            'type' => 'string'
                        ],

                    ],

                    'required' => [

                        'titulo',
                        'angulo',
                        'gancho',
                        'contexto',
                        'custo_momento',
                        'narracao',
                        'execucao',
                        'fechamento',
                        'custo_acumulado',
                        'cta',
                        'texto_tela',
                        'broll',
                        'duracao_alvo_segundos',
                        'roteiro_completo',

                    ],

                ],

            ],

        ],

        'required' => [

            'roteiros'

        ],

    ];


    /*
    |--------------------------------------------------------------------------
    | CHAMAR IA
    |--------------------------------------------------------------------------
    */

    $inicio =
        microtime(true);


    $resultado =
        $this->responderEstruturado(
            $instrucao,
            'roteiros_shorts',
            $schema
        );


    $resultado['tempo_segundos'] =
        round(
            microtime(true)
            -
            $inicio,
            2
        );


    return $resultado;
}

    /*
    |--------------------------------------------------------------------------
    | TESTE SIMPLES
    |--------------------------------------------------------------------------
    */

    public function testar(): array
    {
        $inicio =
            microtime(true);


        $response =
            $this->responder(
                'Você está sendo conectado ao sistema Bora pra Obra. '
                .
                'Responda somente com a frase: '
                .
                '"Integração com IA funcionando."'
            );


        $tempo =
            microtime(true)
            -
            $inicio;


        return [

            'texto' =>
                $this->extrairTexto(
                    $response
                ),

            'response_id' =>
                $response['id']
                ?? null,

            'model' =>
                $response['model']
                ?? $this->model,

            'tempo_segundos' =>
                round(
                    $tempo,
                    2
                ),

        ];
    }
    
    
}