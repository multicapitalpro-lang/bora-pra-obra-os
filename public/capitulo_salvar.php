<?php
require __DIR__.'/includes/auth.php';require __DIR__.'/config/database.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: catalogo.php');exit;}

$id=(int)($_POST['id']??0);

$dtPub = trim($_POST['data_publicacao']??'');
$dtPub = $dtPub!=='' ? str_replace('T',' ',$dtPub).':00' : null;

// Checklist de produção: array de chaves marcadas -> JSON {chave:true}
$etapasValidas = ['triado','editado','revisado','thumb','seo','derivados'];
$marcadas = $_POST['check'] ?? [];
if(!is_array($marcadas)) $marcadas = [];
$checklist = [];
foreach($etapasValidas as $e){ if(in_array($e,$marcadas,true)) $checklist[$e]=true; }
$checklistJson = $checklist ? json_encode($checklist) : null;

$data=[
  (int)$_POST['numero'],
  trim($_POST['titulo']),
  trim($_POST['titulo_publico']??'') ?: null,
  trim($_POST['descricao']??'') ?: null,
  trim($_POST['tags']??'') ?: null,
  $_POST['data_gravacao']?:null,
  (int)$_POST['qtd_arquivos'],
  $_POST['temporada_id']!==''?(int)$_POST['temporada_id']:null,
  trim($_POST['etapa']),
  trim($_POST['status']),
  trim($_POST['drive_url']),
  trim($_POST['youtube_url']),
  $dtPub,
  trim($_POST['observacoes']),
  trim($_POST['notas_edicao']??'') ?: null,
  $checklistJson,
];

if($id){
  $data[]=$id;
  $sql='UPDATE capitulos SET numero=?,titulo=?,titulo_publico=?,descricao=?,tags=?,data_gravacao=?,qtd_arquivos=?,temporada_id=?,etapa=?,status=?,drive_url=?,youtube_url=?,data_publicacao=?,observacoes=?,notas_edicao=?,producao_checklist=? WHERE id=?';
}else{
  $sql='INSERT INTO capitulos(numero,titulo,titulo_publico,descricao,tags,data_gravacao,qtd_arquivos,temporada_id,etapa,status,drive_url,youtube_url,data_publicacao,observacoes,notas_edicao,producao_checklist) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
}

$st=db()->prepare($sql);$st->execute($data);
header('Location: catalogo.php?ok=1');