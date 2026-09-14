# Changelog

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
