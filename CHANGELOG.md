# Changelog

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
