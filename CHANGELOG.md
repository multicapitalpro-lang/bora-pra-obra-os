# Changelog

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
