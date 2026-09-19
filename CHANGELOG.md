# Changelog

## [Não versionado] - 2026-09-19

- **Worker totalmente autônomo entre capítulos.** Usuário pediu pra
  não precisar mais abrir cada capítulo no painel e clicar "enviar
  pra fila" / "gerar Shorts" — queria só deixar o worker aberto e ele
  descobrir sozinho o que falta.
  - `triagem_ia_proxima_tarefa.php`: quando a fila "na_fila" está
    vazia, agora enfileira sozinho o próximo capítulo (o de menor
    número) que ainda tem brutos "nao_analisado" — antes só fazia
    isso quando alguém clicava em "Enviar brutos pra fila" no painel.
  - Novo endpoint `capitulo_roteiros_shorts_proxima_tarefa.php`:
    encontra o próximo capítulo com transcrição 100% terminada
    (nada "nao_analisado"/"na_fila"/"processando") que ainda não tem
    os 5 tipos fixos de Short gerados.
  - `capitulo_roteiros_shorts_ia.php` agora aceita autenticação do
    worker (mesma chave Bearer) além da sessão do painel, pra poder
    ser chamado pelo worker sem navegador.
  - `worker.py`: novo 4º estágio no loop principal
    (`pegar_tarefa_shorts` / `processar_tarefa_shorts`) — depois de
    esvaziar as filas de triagem/timestamps/exportação, o worker
    verifica se tem capítulo esperando Shorts e gera sozinho, um
    tipo por vez, até completar os 5 ou não haver mais capítulo
    pendente.
  Resultado: basta deixar o worker aberto. Ele transcreve todos os
  capítulos pendentes e gera os Shorts de cada um sozinho, sem
  precisar mais navegar capítulo por capítulo no painel.
  Testado de ponta a ponta localmente (auto-enfileiramento, descoberta
  de capítulo pendente de Shorts e geração via auth de worker).

## [Não versionado] - 2026-09-14 (12)

- **fix: bruto travado em "processando" pra sempre depois de um 504
  do worker.** Visto ao vivo hoje: o worker processou um vídeo
  (download + transcrição + análise), mas o envio do resultado de
  volta (`triagem_ia_resultado.php`) caiu num 504 do servidor. O
  arquivo ficou marcado "processando" e nunca mais entrava na fila de
  novo (nem "Analisar brutos com IA" pegava ele, só re-enfileira
  nao_analisado/erro). Corrigido em duas frentes:
  - `triagem_ia_resultado.php` agora pede uma conexão nova ao MySQL
    (`db(true)`) depois da chamada de IA, mesmo padrão já aplicado
    nos Shorts — evita "MySQL server has gone away" na hora de salvar.
  - `triagem_ia_solicitar.php`: brutos travados em "processando" há
    mais de 30 minutos agora voltam pra fila sozinhos (antes só
    nao_analisado/erro eram re-enfileirados). Autorrecuperação sem
    precisar de intervenção manual da próxima vez.
  Arquivo travado de hoje (GX010053.MP4, capítulo #4) já foi
  destravado manualmente e reenviado pra fila.

## [Não versionado] - 2026-09-14 (11)

- **"Gerar os N restantes de uma vez"**: novo botão no passo 3 do
  Guia de Produção. Usuário pediu pra não precisar clicar em "gerar
  roteiro" um por um. Por baixo dos panos continua sendo 1 chamada de
  IA por vez, em sequência (~40-50s cada) — é isso que evita o erro
  504 de antes, quando tentávamos gerar vários roteiros numa chamada
  só. Do lado do usuário vira só um clique; a tela mostra o progresso
  ("Gerando 2 de 4...") e recarrega no final. Se uma chamada falhar
  no meio, os roteiros já gerados continuam salvos.

## [Não versionado] - 2026-09-14 (10)

- **fix: contexto desconectado do gancho + fechamento repetindo a
  narração.** Usuário reportou roteiro "horrível": o "contexto" saía
  como um fato solto sem ligação com o gancho (ex.: gancho fala de um
  cano quebrado, contexto pula pra "o furo foi até 45 metros"), e o
  "fechamento" só reescrevia com outras palavras o que a narração já
  tinha contado. Prompt de `gerarRoteirosShorts()` reforçado: instrui
  a IA a escrever o roteiro inteiro como um texto único primeiro, só
  depois dividir nos 4 campos; proíbe fechamento repetir a narração
  (tem que trazer algo novo: reflexão, o que isso significa); exemplo
  completo das 4 partes conectadas incluído no prompt. Testado no
  capítulo #4: contexto agora continua a ideia do gancho, fechamento
  traz ângulo novo em vez de repetir o resultado.

## [Não versionado] - 2026-09-14 (9)

- **Excluir roteiro de Short**: com roteiros antigos (formato livre)
  e novos (5 tipos fixos) misturados no mesmo capítulo, ficou difícil
  saber qual é qual. Adicionado botão de lixeira em cada card do
  passo 3 do Guia de Produção — apaga o roteiro (e a narração/cortes
  sugeridos dele, se houver, via `ON DELETE CASCADE`) depois de
  confirmar. Novo endpoint `short_roteiro_excluir.php`.

## [Não versionado] - 2026-09-14 (8)

- **fix: roteiro de Short saía formal demais e com anotações de
  edição misturadas na narração.** Usuário reportou: texto sem
  conexão, parecia relatório escrito, e tinha trechos tipo "(usar
  trecho gravado: ...)" dentro do que deveria ser só a fala. Causa:
  o prompt não proibia esse tipo de anotação dentro dos campos de
  narração, e não dava exemplo do tom certo. Reescrito
  `gerarRoteirosShorts()`:
  - Seção nova "TOM E ESTILO" com exemplo real ERRADO (o que a IA
    tinha gerado) vs CERTO (reescrito), ensinando frases curtas,
    primeira pessoa, conectivos de fala em vez de texto escrito.
  - Regra explícita: gancho/contexto/narração/fechamento só podem
    ter o texto falado, nunca anotação de edição entre parênteses —
    isso agora vai só no campo "broll".
  - Modelo de fluxo específico pra cada um dos 5 tipos (problema,
    custo, como_fizemos, erro, resultado), baseado no documento de
    template oficial do projeto.
  Testado no capítulo #1: novo roteiro (tipo "resultado") saiu sem
  nenhuma anotação de edição misturada e com tom bem mais direto.

## [Não versionado] - 2026-09-14 (7)

- `guia_producao.php` passo 1: agora tem o botão **"Enviar N bruto(s)
  pra fila de transcrição"** direto na tela (chama
  `triagem_ia_solicitar.php`), em vez de precisar abrir
  `capitulo_detalhes.php` pra achar esse botão. Também mostra quantos
  brutos estão na fila esperando o worker e quantos deram erro no
  processamento anterior. Só #1 e #4 tinham brutos enfileirados até
  agora -- os outros 118 capítulos nunca tinham sido enviados.

## [Não versionado] - 2026-09-14 (6)

- **Shorts travados nos 5 tipos fixos do template oficial**: seguindo
  o documento de padrão de roteiro/edição do Bora pra Obra, a IA não
  escolhe mais um "ângulo" livre para cada Short — ela escolhe
  obrigatoriamente entre os 5 tipos do template (`problema`, `custo`,
  `como_fizemos`, `erro`, `resultado`), um por chamada, nunca
  repetindo um tipo já gerado no capítulo. O campo `tema` agora guarda
  a chave do tipo. Roteiros de Shorts gerados antes dessa mudança
  mantêm o texto livre antigo em `tema` e não são afetados/bloqueados
  por essa checagem. `guia_producao.php` mostra quais dos 5 tipos já
  foram gerados e quantos faltam.
  (Alinhamento do episódio longo — "o que vamos fazer", "problema /
  decisão / solução", "resultado", "custo da etapa/acumulado" e "CTA"
  como campos próprios — fica pra depois, por decisão do dono do
  projeto: começar só pelos Shorts.)

## [Não versionado] - 2026-09-14 (5)

- **fix: "roteiro pra ler" mostrava só um pedaço do Short.**
  `guia_producao.php` e `short_narracao.php` exibiam apenas o campo
  `roteiro_narracao` (só o bloco de execução) como texto pra narrar —
  por isso um Short com alvo de 50s aparecia com um texto de ~10s.
  Corrigido: agora mostram abertura (`bloco_2_assunto`) + meio
  (`roteiro_narracao`) + fechamento (`bloco_5_fechamento`)
  concatenados, que é o que realmente é narrado (vinheta e CTA entram
  depois, os blocos de custo são placeholder).
- `guia_producao.php`: passos 3 e 5, quando bloqueados, agora mostram
  o motivo por escrito (ex.: "nenhum bruto transcrito ainda") em vez
  de só ficarem esmaecidos — reduz a confusão de "por que esse
  capítulo deixa e o outro não".

## [Não versionado] - 2026-09-14 (4)

- **fix: 504 (timeout) ao gerar roteiros de Shorts em capítulos grandes.**
  Em produção, gerar o lote de 1-5 roteiros de uma vez em capítulos com
  muitos brutos (ex.: 46 arquivos) estourava o timeout do servidor —
  a IA levava 70s+ escrevendo todos os campos de vários roteiros
  numa chamada só. Mudança: `gerarRoteirosShorts()` agora gera **1
  roteiro por chamada** (a melhor oportunidade ainda não coberta,
  recebendo a lista dos ângulos já usados no capítulo pra não
  repetir); `capitulo_roteiros_shorts_ia.php` não apaga mais os
  roteiros existentes a cada clique — soma um novo, até o limite de 5
  por capítulo. Também reduzido o material enviado à IA (antes: todos
  os brutos do capítulo; agora: os 10 melhores por decisão de
  triagem/importância) pra manter a chamada mais leve. Testado com o
  capítulo #4 (46 arquivos, o que gerou o 504 original): agora
  responde em ~50s por roteiro, sem apagar os já gerados.
  Textos dos botões/confirmação ajustados pra refletir "um roteiro
  por vez".

## [Não versionado] - 2026-09-14 (3)

- **`guia_producao.php`** (novo, no menu como "Guia de Produção"): uma
  única página em formato de assistente que junta todo o caminho —
  escolher capítulo, checar transcrição dos brutos, triagem, gerar
  roteiros de Shorts, gravar/subir a narração, pedir a sugestão de
  corte e aprovar — com 6 passos numerados, cada um mostrando se está
  pendente, em andamento ou concluído, sem precisar navegar entre
  páginas diferentes. Reaproveita os mesmos endpoints já testados
  (`capitulo_roteiros_shorts_ia.php`, `short_narracao_upload.php`,
  `short_corte_sugerir_ia.php`, `short_corte_aprovar.php`).

## [Não versionado] - 2026-09-14 (2)

- **Narração própria → sugestão de corte (Shorts)**: novo fluxo pra
  reduzir o tempo de edição. Em cada Short, o usuário sobe o áudio da
  própria narração (`short_narracao.php`); a IA transcreve com
  timestamp por trecho (`OpenAIService::transcreverAudio`, Whisper) e
  sugere qual bruto do capítulo combina com cada trecho, citando o
  trecho da transcrição que justifica a escolha
  (`OpenAIService::sugerirCortesNarracao`). A sugestão é sempre por
  ARQUIVO, não por timestamp exato — os brutos ainda não têm
  transcrição segmentada. O usuário revisa e aprova cada corte antes
  de editar. Novos endpoints: `short_narracao_upload.php`,
  `short_corte_sugerir_ia.php`, `short_corte_aprovar.php`. Testado de
  ponta a ponta (upload, transcrição, casamento e aprovação).
- **`capitulo_detalhes.php`**: a lista de roteiros de Shorts gerados
  (`listaRoteirosShorts`) estava com um placeholder fixo de "nenhum
  roteiro gerado" que nunca era substituído por dados reais — mesmo
  depois de gerar roteiros com sucesso, eles ficavam invisíveis no
  painel (só existiam no banco). Agora a lista busca os roteiros do
  capítulo e mostra um card por Short, com link para a nova página de
  narração/corte.

## [Não versionado] - 2026-09-14

- `capitulo_roteiros_shorts_ia.php` (gerar roteiros de Shorts com IA):
  corrigidos dois bugs que faziam o botão falhar sempre. 1) o caminho
  de `require` do `OpenAIService.php` estava errado (procurava dentro
  de `public/app/...`, que não existe). 2) a geração de vários
  roteiros leva 50-70s na OpenAI; nesse tempo o MySQL derrubava a
  conexão original ("server has gone away") antes de salvar o
  resultado — agora o script pede uma conexão nova (`db(true)`) depois
  da chamada de IA, mesmo padrão já usado em `timestamps_ia_resultado.php`.
  Testado de ponta a ponta no capítulo #1 (3 roteiros gerados com
  sucesso a partir das transcrições reais).

## [Não versionado] - 2026-09-11

- Recuperados ~40 arquivos que existiam apenas no servidor Hostinger e
  nunca haviam sido commitados: pipeline de IA (triagem de brutos,
  roteiros de Shorts, plano/edição de capítulo), integração com Google
  Drive, exportação para CapCut, página de detalhes do capítulo e
  scripts de diagnóstico.
- Adicionada dependência `google/apiclient` (Composer) para a
  integração com Google Drive.
- `config/database.php`: `db()` agora aceita `$forcarNovaConexao` para
  recriar a conexão PDO em chamadas longas de IA.
- `catalogo.php`: título do álbum agora linka para os detalhes do
  capítulo; adicionado botão de sincronização com o Drive.
- Corrigido `.gitignore`: `database.local.php` não estava sendo
  ignorado fora da raiz do repositório.
- Ambiente de desenvolvimento local criado (PHP 8.3 portátil +
  Composer, conectado ao MySQL de produção via acesso remoto).

## [0.1.0] - 2026-07-25

- Criada estrutura inicial do Bora pra Obra OS.
- Consolidado contexto da marca e da obra.
- Adicionada Bíblia do Projeto.
- Documentada arquitetura do ERP.
- Incluído MVP PHP/MySQL existente.
- Adicionadas instruções para Claude, GitHub, deploy e MCP.
