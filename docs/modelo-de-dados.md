# Modelo de dados proposto

## Entidades principais

- usuarios
- temporadas
- capitulos
- capitulos_arquivos
- publicacoes
- plataformas
- ideias
- seo_templates
- custos
- categorias_custo
- fornecedores
- materiais
- capitulos_materiais
- parceiros
- parceiros_aparicoes
- tarefas
- logs
- configuracoes
- integracoes

## Relações essenciais

- Uma temporada possui vários capítulos.
- Um capítulo pode possuir vários arquivos.
- Um capítulo pode gerar várias publicações.
- Um capítulo pode registrar vários materiais e custos.
- Um parceiro pode aparecer em vários capítulos/publicações.

## Regras

- O número do capítulo deve ser único.
- Excluir capítulos deve ser preferencialmente lógico.
- Valores monetários devem usar DECIMAL, nunca FLOAT.
- Datas de gravação e publicação devem ser campos separados.
- URLs e IDs externos devem ser armazenados sem tokens de autenticação.
