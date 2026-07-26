# Ambiente e deploy (estado real)

Documento operacional do ambiente em produção. Atualizar quando a
infraestrutura mudar.

## Repositório

- GitHub: `multicapitalpro-lang/bora-pra-obra-os`
- Branch estável: `main`

## Estrutura servida

O deploy da Hostinger clona o repositório inteiro em `public_html`, mas
apenas a pasta `public/` é servida ao navegador. O `.htaccess` da raiz
faz o roteamento e bloqueia as pastas internas.

```
public_html/            <- clone do repositório
├── .htaccess           <- roteia tudo para public/ e protege pastas internas
├── public/             <- raiz efetiva do site
│   ├── config/
│   │   ├── database.php <- carrega a config externa
│   │   └── .htaccess    <- bloqueia acesso web à pasta
│   └── ...
├── docs/  prompts/  database/   <- internos, nunca servidos
```

## Credenciais do banco (fora do repositório)

A config com a senha NÃO fica no Git nem no `public_html`. Ela vive em:

```
domains/firebrick-rook-290123.hostingersite.com/config_secreto/database.local.php
```

Esse arquivo sobrevive aos deploys. O `public/config/database.php`
procura a config nesta ordem: caminho externo (produção) e, se não
achar, `config/database.local.php` ao lado dele (desenvolvimento local).

Formato do arquivo:

```php
<?php
return [
    'host' => 'localhost',
    'name' => 'u719183319_borapraobra',
    'user' => 'u719183319_borapraobra',
    'pass' => 'SENHA_DO_BANCO',
];
```

## Deploy

1. Trabalhar localmente no VS Code.
2. `git add <arquivos>` (nomear os arquivos; evitar `git add .`).
3. `git commit -m "..."` e `git push`.
4. hPanel → Avançado → GIT → Reimplantar (ou configurar webhook para
   deploy automático a cada push).

O deploy apaga do `public_html` o que não vier do Git. Arquivos que
precisam persistir (como a config do banco) ficam fora do `public_html`.

## Banco de produção

- Nome: `u719183319_borapraobra`
- Acesso administrativo via phpMyAdmin no hPanel.
- Fazer backup (exportar) antes de qualquer migração.

## Pendências de segurança conhecidas

- `install.sql` no repositório ainda contém e-mail e hash de exemplo do
  admin; limpar em faxima futura (a senha real já foi trocada no banco).
- Configurar HTTPS forçado quando houver domínio definitivo.
