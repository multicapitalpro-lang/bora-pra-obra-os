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
    array $arquivos,
    array $angulosJaUsados = []
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
    | OS 5 TIPOS FIXOS DE SHORT (template oficial do Bora pra Obra)
    |--------------------------------------------------------------------------
    |
    | Não é um ângulo livre: a IA escolhe UM entre estes 5, o que
    | ainda não foi coberto neste capítulo. $angulosJaUsados recebe as
    | chaves (problema/custo/como_fizemos/erro/resultado) já geradas.
    |
    |--------------------------------------------------------------------------
    */

    $tiposCanonicos = [
        'problema' =>
            'O problema — expõe um problema real que apareceu durante a etapa.',
        'custo' =>
            'Quanto custou — foco em revelar o valor gasto nessa etapa.',
        'como_fizemos' =>
            'Como fizemos — mostra o passo a passo de como a etapa foi executada.',
        'erro' =>
            'O erro que aconteceu — mostra um erro cometido e como foi corrigido.',
        'resultado' =>
            'O resultado — mostra o resultado final ou a transformação da etapa.',
    ];

    $tiposRestantes =
        array_diff(
            array_keys($tiposCanonicos),
            array_map('strval', $angulosJaUsados)
        );

    if (empty($tiposRestantes)) {

        throw new RuntimeException(
            'Os 5 tipos de Short do template (problema, custo, como fizemos, '
            . 'erro, resultado) já foram gerados para este capítulo.'
        );
    }

    $tiposCanonicosTexto = '';

    foreach ($tiposCanonicos as $chave => $descricao) {

        $status =
            in_array($chave, $tiposRestantes, true)
                ? 'DISPONÍVEL'
                : 'já usado neste capítulo, NÃO escolher de novo';

        $tiposCanonicosTexto .= "- {$chave}: {$descricao} ({$status})\n";
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

2. ASSUNTO DO VÍDEO (gancho + contexto)

Isto é o GANCHO. É a parte mais importante do Short.
Tem que prender em 2-3 segundos.

NÃO comece explicando a etapa ou dando contexto histórico
("Durante a finalização de X havia previsão de Y..."). Isso é
o jeito ERRADO, formal, de abrir — ninguém para de dar scroll
pra ouvir isso.

Comece pelo momento mais forte: o problema, o resultado, o
susto, a surpresa. Só depois, em UMA frase curta, dá o contexto
mínimo pra pessoa entender onde está.

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

4. EXECUÇÃO (o "meio" — campo narracao)

Mostrar a execução da obra, o problema, a decisão e a solução —
o que for relevante pro tipo de Short escolhido (ver modelos por
tipo abaixo). Isso continua DIRETO da frase do gancho, como se
fosse a mesma pessoa falando sem pausa — não comece um parágrafo
novo do zero.

5. FECHAMENTO

Mostrar o que foi concluído, o que mudou ou o que foi
aprendido — em UMA ou duas frases curtas, como conclusão
natural da fala, não como um resumo por escrito.

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
MODELO POR TIPO (siga o fluxo do tipo que você escolher)
============================================================

problema:
GANCHO ("Aconteceu um problema aqui...", direto no susto/impacto)
→ mostrar o problema → por que aconteceu → decisão tomada →
solução → resultado.

custo:
GANCHO (valor ou choque do custo logo de cara, ex: "Isso aqui
custou mais do que eu esperava...") → explicação rápida da etapa
→ execução → valor real → resultado.

como_fizemos:
GANCHO (o "uau" do processo, ex: "Olha como a gente resolveu
isso...") → apresentar a etapa → execução, passo a passo →
detalhe importante → resultado.

erro:
GANCHO ("Cometemos um erro aqui e teve que resolver na hora...")
→ mostrar o erro/imprevisto → explicar rapidinho por que
aconteceu → mostrar a correção acontecendo → resultado depois
do erro corrigido.

resultado:
GANCHO (mostrar o resultado final ou o "antes" chocante primeiro,
ex: "Olha só como ficou..." ou "Isso aqui era um buraco, agora
olha...") → situação anterior → o que foi feito → resultado
final.

============================================================
TOM E ESTILO — ISSO É PRA VIRALIZAR, NÃO PRA UM RELATÓRIO
============================================================

O texto de "gancho", "contexto"/assunto, "narracao" e "fechamento"
juntos formam UMA fala contínua, como se a pessoa estivesse
falando direto pra câmera sem cortar. NUNCA escreva como se fosse
um relatório resumindo o que aconteceu.

ERRADO (jeito de escrever, não de falar):
"Durante a finalização do poço artesiano havia previsão de dois
dias de trabalho. No meio do serviço um dos canos quebrou — isso
mudou o cronograma e a forma de finalizar a etapa."
"O que mudou: apesar do cano quebrado, a equipe antecipou as
etapas e concluiu a limpeza..."

Por que está errado: começa dando contexto formal antes do gancho,
usa frases de relatório ("isso mudou o cronograma", "o que
mudou:"), e cada bloco parece um parágrafo desconectado do
anterior.

CERTO (jeito de falar):
"Um cano estourou bem no meio da limpeza do poço — e a gente
precisou resolver na hora. Era pra ser dois dias de serviço, só
que com o cano quebrando, tive que antecipar tudo e fechar em um
dia só. Mesmo assim deu certo: puxamos a água do fundo, instalamos
a bomba, e o poço começou a jorrar."

Por que está certo: entra direto no fato mais forte (o cano
estourou), frases curtas, primeira pessoa, soa como alguém
contando o que aconteceu, não como um texto escrito.

REGRAS DE NARRAÇÃO:

- Frases curtas. Prefira 2-3 frases curtas a 1 frase longa.
- Primeira pessoa, tom de conversa, como quem está contando um
  causo pra um amigo.
- Não use conectivos de texto escrito ("Durante...", "Portanto",
  "O que mudou:", "Isso resultou em"). Use conectivos de fala
  ("Só que...", "Daí...", "Mesmo assim...", "E olha só...").
- Não invente acontecimentos, valores nem informações que não
  estejam nos materiais.
- Não corte uma fala no meio de uma frase.
- IMPORTANTE: os campos "gancho", "contexto", "narracao" e
  "fechamento" contêm SOMENTE o texto que a pessoa vai falar em
  voz alta. NUNCA inclua anotações entre parênteses tipo "(usar
  trecho gravado: ...)" ou instruções de edição dentro desses
  campos — isso quebra a leitura em voz alta. Se você quiser
  indicar que um trecho real gravado é bom pra usar como áudio
  original (em vez do usuário narrar), coloque essa indicação
  SOMENTE no campo "broll", nunca dentro da narração.

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
TIPO DO SHORT (escolha OBRIGATORIAMENTE um destes 5)
============================================================

O Bora pra Obra segue um template fixo de 5 tipos de Short. Não
invente um 6º tipo. O campo "angulo" da resposta deve ser exatamente
uma destas chaves:

{$tiposCanonicosTexto}

Escolha o tipo DISPONÍVEL que tiver o melhor material real neste
capítulo. Nunca escolha um tipo já usado.

============================================================
QUANTIDADE
============================================================

Gere APENAS 1 (UM) Short, do tipo escolhido acima.

Não gere mais de um. Esta função é chamada várias vezes, uma por
Short, até completar os 5 tipos do template.

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
                    1,

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
                            'type' => 'string',
                            'enum' => array_values($tiposRestantes),
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

    /*
    |--------------------------------------------------------------------------
    | TRANSCREVER ÁUDIO (narração própria dos Shorts)
    |--------------------------------------------------------------------------
    |
    | Diferente do request() principal (JSON), a API de transcrição
    | espera multipart/form-data com o arquivo de áudio. Usamos
    | whisper-1 com response_format=verbose_json para obter os
    | segmentos com timestamp — a narração é curta (25-60s), então
    | vem rápido e o whisper-1 já entrega isso pronto.
    |
    |--------------------------------------------------------------------------
    */

    public function transcreverAudio(
        string $caminhoArquivo
    ): array {

        if (!is_file($caminhoArquivo)) {

            throw new RuntimeException(
                'Arquivo de áudio não encontrado.'
            );
        }


        if (!function_exists('curl_init')) {

            throw new RuntimeException(
                'A extensão cURL do PHP não está disponível neste servidor.'
            );
        }


        $curl =
            curl_init(
                'https://api.openai.com/v1/audio/transcriptions'
            );


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
                    120,

                CURLOPT_HTTPHEADER => [

                    'Authorization: Bearer '
                    .
                    $this->apiKey,

                ],

                CURLOPT_POSTFIELDS => [

                    'file' =>
                        new CURLFile(
                            $caminhoArquivo
                        ),

                    'model' =>
                        'whisper-1',

                    'language' =>
                        'pt',

                    'response_format' =>
                        'verbose_json',

                ],

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
                'Erro de comunicação com a OpenAI (transcrição): '
                .
                $curlErro
            );
        }


        $dados =
            json_decode(
                $resposta,
                true
            );


        if ($httpCode >= 400) {

            throw new RuntimeException(
                'OpenAI recusou a transcrição: '
                .
                (
                    $dados['error']['message']
                    ?? $resposta
                )
            );
        }


        $segmentos = [];


        foreach (
            $dados['segments']
            ?? []
            as $segmento
        ) {

            $segmentos[] = [

                'inicio_ms' =>
                    (int) round(
                        (
                            (float) (
                                $segmento['start']
                                ?? 0
                            )
                        )
                        * 1000
                    ),

                'fim_ms' =>
                    (int) round(
                        (
                            (float) (
                                $segmento['end']
                                ?? 0
                            )
                        )
                        * 1000
                    ),

                'texto' =>
                    trim(
                        (string) (
                            $segmento['text']
                            ?? ''
                        )
                    ),

            ];
        }


        return [

            'texto' =>
                trim(
                    (string) (
                        $dados['text']
                        ?? ''
                    )
                ),

            'segmentos' =>
                $segmentos,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | SUGERIR CORTES A PARTIR DA NARRAÇÃO PRÓPRIA
    |--------------------------------------------------------------------------
    |
    | Recebe os segmentos (com timestamp) da narração que o usuário
    | gravou por conta própria, e a lista de brutos do capítulo com
    | suas transcrições completas. Para cada segmento da narração,
    | a IA aponta qual bruto melhor combina e cita o trecho da
    | transcrição que justifica a escolha.
    |
    | IMPORTANTE: os brutos não têm timestamp por trecho (a
    | transcrição deles é só o texto corrido), então a sugestão é
    | por ARQUIVO, não por segundo exato dentro dele. O usuário ainda
    | precisa achar o ponto certo ao editar.
    |
    |--------------------------------------------------------------------------
    */

    public function sugerirCortesNarracao(
        array $segmentosNarracao,
        array $brutos
    ): array {

        if (empty($segmentosNarracao)) {

            throw new InvalidArgumentException(
                'Nenhum segmento de narração informado.'
            );
        }


        if (empty($brutos)) {

            throw new InvalidArgumentException(
                'Nenhum bruto com transcrição disponível neste capítulo.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | MONTAR NARRAÇÃO
        |--------------------------------------------------------------------------
        */

        $textoNarracao = '';


        foreach (
            $segmentosNarracao
            as $indice => $segmento
        ) {

            $textoNarracao .=
                "[{$indice}] ("
                .
                $segmento['inicio_ms']
                .
                'ms - '
                .
                $segmento['fim_ms']
                .
                "ms)\n"
                .
                trim(
                    (string) (
                        $segmento['texto']
                        ?? ''
                    )
                )
                .
                "\n\n";
        }


        /*
        |--------------------------------------------------------------------------
        | MONTAR BRUTOS
        |--------------------------------------------------------------------------
        */

        $textoBrutos = '';


        foreach (
            $brutos
            as $bruto
        ) {

            $textoBrutos .=
                'ARQUIVO #'
                .
                $bruto['id']
                .
                ' ('
                .
                (
                    $bruto['nome_arquivo']
                    ?? ''
                )
                .
                ') — tipo: '
                .
                (
                    $bruto['tipo']
                    ?: 'não definido'
                )
                .
                "\nTranscrição: "
                .
                trim(
                    (string) (
                        $bruto['transcricao']
                        ?? ''
                    )
                )
                .
                "\n\n";
        }


        /*
        |--------------------------------------------------------------------------
        | INSTRUÇÃO
        |--------------------------------------------------------------------------
        */

        $instrucao = <<<PROMPT
Você é o editor de vídeo do canal Bora pra Obra.

O usuário gravou, com a própria voz, a narração final de um Short.
Ela já está transcrita e dividida em segmentos com timestamp.

Sua tarefa: para cada segmento da narração, indicar qual ARQUIVO
bruto (da lista abaixo) melhor combina visualmente com o que está
sendo dito naquele momento, e citar o trecho da transcrição do bruto
que justifica a escolha.

REGRAS:

- Baseie-se SOMENTE nas transcrições fornecidas. Nunca invente o que
  aparece na imagem de um bruto além do que a transcrição sugere.
- Se nenhum bruto combinar bem com um segmento, retorne arquivo_id
  como null e explique isso em vez de forçar uma escolha ruim.
- Você está sugerindo o ARQUIVO certo, não o timestamp exato dentro
  dele — isso o usuário ainda ajusta na edição.

============================================================
SEGMENTOS DA NARRAÇÃO
============================================================

{$textoNarracao}
============================================================
BRUTOS DISPONÍVEIS
============================================================

{$textoBrutos}
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

                'cortes' => [

                    'type' =>
                        'array',

                    'items' => [

                        'type' =>
                            'object',

                        'additionalProperties' =>
                            false,

                        'properties' => [

                            'segmento_index' => [
                                'type' => 'integer'
                            ],

                            'arquivo_id' => [
                                'type' => ['integer', 'null']
                            ],

                            'trecho_transcricao' => [
                                'type' => 'string'
                            ],

                            'justificativa' => [
                                'type' => 'string'
                            ],

                        ],

                        'required' => [

                            'segmento_index',
                            'arquivo_id',
                            'trecho_transcricao',
                            'justificativa',

                        ],

                    ],

                ],

            ],

            'required' => [

                'cortes'

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
                'cortes_narracao',
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