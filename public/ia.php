<?php
// Backend de geração de SEO por IA (OpenAI). Protegido por login.
require __DIR__.'/includes/auth.php';
require __DIR__.'/config/database.php'; // carrega $cfg (inclui openai_key)

header('Content-Type: application/json; charset=utf-8');

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405); echo json_encode(['erro'=>'Método inválido']); exit;
}

global $cfg;
$apiKey = $cfg['openai_key'] ?? '';
if($apiKey===''){
    http_response_code(500);
    echo json_encode(['erro'=>'Chave da OpenAI não configurada no servidor.']); exit;
}

// Dados do capítulo enviados pelo formulário
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$numero    = trim((string)($in['numero'] ?? ''));
$titulo    = trim($in['titulo'] ?? '');
$etapa     = trim($in['etapa'] ?? '');
$temporada = trim($in['temporada'] ?? '');
$notas     = trim($in['notas'] ?? '');

if($titulo===''){
    http_response_code(422);
    echo json_encode(['erro'=>'Informe ao menos o título interno do capítulo antes de gerar.']); exit;
}

$numFmt = $numero!=='' ? str_pad($numero, 2, '0', STR_PAD_LEFT) : '';
$contexto = "Título interno: {$titulo}\n";
if($numFmt!=='') $contexto .= "Número do episódio: {$numFmt}\n";
if($temporada!=='') $contexto .= "Temporada/etapa da obra: {$temporada}\n";
if($etapa!=='')     $contexto .= "Etapa específica: {$etapa}\n";
if($notas!=='')     $contexto .= "Notas de edição: {$notas}\n";

$system = "Você é editor de conteúdo do canal 'Bora pra Obra', que documenta uma obra residencial real (construção), mostrando execução, erros, acertos e aprendizados de forma honesta. "
        . "Gere metadados de publicação para YouTube em português do Brasil. "
        . "Tom equilibrado: prático e de obra real, mas chamativo o suficiente para gerar cliques honestos (sem clickbait enganoso). "
        . "Responda ESTRITAMENTE em JSON válido, sem markdown, com as chaves: "
        . "titulo_publico (string, até ~70 caracteres; comece com o gancho e as palavras-chave de busca e termine com o marcador de série no formato ' #NN' usando o número do episódio com dois dígitos, ex.: 'Muro de arrimo: o erro que quase custou caro #06'), descricao (string, 2 a 4 parágrafos curtos, pode terminar com uma linha de hashtags), tags (string, palavras-chave separadas por vírgula, sem #).";

$user = "Gere os metadados para este capítulo da obra:\n\n{$contexto}";

$payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role'=>'system','content'=>$system],
        ['role'=>'user','content'=>$user],
    ],
    'temperature' => 0.7,
    'response_format' => ['type'=>'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer '.$apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 45,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if($resp===false){
    http_response_code(502);
    echo json_encode(['erro'=>'Falha ao contatar a OpenAI: '.$err]); exit;
}
if($http<200 || $http>=300){
    http_response_code(502);
    echo json_encode(['erro'=>'OpenAI retornou erro HTTP '.$http]); exit;
}

$data = json_decode($resp, true);
$conteudo = $data['choices'][0]['message']['content'] ?? '';
$gerado = json_decode($conteudo, true);

if(!is_array($gerado)){
    http_response_code(502);
    echo json_encode(['erro'=>'Resposta da IA em formato inesperado.']); exit;
}

echo json_encode([
    'titulo_publico' => $gerado['titulo_publico'] ?? '',
    'descricao'      => $gerado['descricao'] ?? '',
    'tags'           => $gerado['tags'] ?? '',
]);