# Arquitetura do sistema

## Objetivo

Criar o ERP interno do Bora pra Obra, iniciando pelo controle editorial e evoluindo para custos, parceiros, mídia e automações.

## Stack

- PHP 8.x
- MySQL/MariaDB
- Bootstrap 5
- jQuery
- SweetAlert2
- PDO
- HTML5/CSS3

## Módulos previstos

### Dashboard

Indicadores de capítulos, publicações, Shorts, pendências, próximos conteúdos e atividade recente.

### Bíblia do Projeto

Temporadas, capítulos, objetivos narrativos e sequência cronológica.

### Capítulos

Campos principais:

- número;
- título interno;
- título público;
- álbum de origem;
- data;
- quantidade de arquivos;
- temporada;
- etapa da obra;
- status de produção;
- roteiro;
- descrição;
- tags e hashtags;
- thumbnail;
- arquivos relacionados;
- custos, materiais e fornecedores;
- observações;
- links publicados.

### Publicações

Calendário, fila, agendamentos, plataformas, URLs e resultados.

### Biblioteca

Referências para GoPro, drone, celular, fotos, projetos Premiere, exports e thumbnails. Arquivos de vídeo grandes não devem ser armazenados no Git.

### Custos

Despesas por etapa, fornecedor, data, categoria, nota e capítulo relacionado.

### Parceiros

Cadastro de marcas, contatos, aparições, entregas, acordos e resultados.

### IA

Geração assistida de títulos, descrições, hashtags, roteiros de Shorts/Reels e ideias de thumbnails.

### Administração

Usuários, permissões, backups, logs, configurações e integrações.

## Princípios

- Separar regras de negócio de apresentação.
- Usar prepared statements.
- Validar dados no servidor.
- Proteger sessões e formulários.
- Manter logs de alterações importantes.
- Preparar migrações SQL versionadas.
