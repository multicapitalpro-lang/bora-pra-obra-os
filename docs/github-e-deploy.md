# GitHub e deploy

## Repositório

Criar um repositório privado chamado `bora-pra-obra-os`.

## Branches

- `main`: versão estável.
- `develop`: integração das próximas funções.
- `feature/nome-da-funcao`: desenvolvimento isolado.
- `hotfix/nome-do-ajuste`: correção urgente.

## Commits

Exemplos:

- `docs: adiciona bíblia do projeto`
- `feat: cria cadastro de capítulos`
- `fix: corrige filtro por temporada`
- `security: remove credenciais do código`

## Deploy na Hostinger

Opções:

1. Git integrado da Hostinger, apontando para a branch estável.
2. GitHub Actions via FTP/SFTP usando Secrets.
3. Deploy manual inicial e automação posterior.

Nunca colocar senhas no workflow. Usar GitHub Secrets.

## Domínio provisório

`firebrick-rook-290123.hostingersite.com`

## Banco

O banco deve ser criado e atualizado por scripts versionados em `database/migrations/`. Dados reais não entram no Git.
