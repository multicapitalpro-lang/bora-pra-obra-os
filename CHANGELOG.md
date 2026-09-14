# Changelog

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
