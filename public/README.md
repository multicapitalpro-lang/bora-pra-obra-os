# Painel Bora pra Obra — MVP 1.0

## Instalação na hospedagem

1. Crie um banco MySQL na hospedagem.
2. Abra o phpMyAdmin, selecione o banco e importe `install.sql`.
3. Edite `config/database.php` com host, nome, usuário e senha do banco.
4. Envie todos os arquivos para a pasta do domínio ou subdomínio.
5. Acesse o domínio pelo navegador.

## Login inicial

- E-mail: `admin@borapraobra.com.br`
- Senha: `BoraPraObra@2026`

Troque a senha diretamente no banco antes de colocar o painel em uso definitivo.

## O que já vem pronto

- Login protegido por sessão
- Dashboard com indicadores
- Catálogo dos 120 capítulos
- Quantidades de arquivos visíveis nos prints já preenchidas
- Sete temporadas
- Pesquisa e filtros
- Cadastro e edição de capítulos
- Fila de publicações
- Banco de ideias
- Área de SEO e configurações

## Requisitos

- PHP 8.0 ou superior
- MySQL 5.7/8.0 ou MariaDB compatível
- Extensão PDO MySQL habilitada
