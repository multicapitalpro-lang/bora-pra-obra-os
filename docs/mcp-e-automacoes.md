# MCP e automações

## Integrações desejadas

- GitHub: branches, commits, pull requests e issues.
- Arquivos locais: leitura e edição segura do repositório.
- MySQL: inspeção e migrações com acesso limitado.
- Hostinger/SFTP: deploy controlado.
- Google Drive: localização e backup de ativos.
- YouTube: metadados e métricas, respeitando permissões da API.
- n8n: rotinas e integrações.
- FFmpeg: análise e processamento local de mídia.

## Regras de segurança para MCP

- Usar menor privilégio possível.
- Preferir acesso somente leitura inicialmente.
- Nunca disponibilizar banco de produção para comandos irrestritos.
- Criar usuário MySQL específico para automações.
- Restringir diretórios acessíveis pelo MCP de arquivos.
- Revisar diffs antes de commits e deploys.
- Não permitir publicação automática em redes sociais na primeira fase.
