<?php
require __DIR__.'/includes/auth.php';require __DIR__.'/config/database.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: catalogo.php');exit;}

$id=(int)($_POST['id']??0);

// data_publicacao do input datetime-local: 'YYYY-MM-DDTHH:MM' -> 'YYYY-MM-DD HH:MM:00' ou null
$dtPub = trim($_POST['data_publicacao']??'');
$dtPub = $dtPub!=='' ? str_replace('T',' ',$dtPub).':00' : null;

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
];

if($id){
  $data[]=$id;
  $sql='UPDATE capitulos SET numero=?,titulo=?,titulo_publico=?,descricao=?,tags=?,data_gravacao=?,qtd_arquivos=?,temporada_id=?,etapa=?,status=?,drive_url=?,youtube_url=?,data_publicacao=?,observacoes=? WHERE id=?';
}else{
  $sql='INSERT INTO capitulos(numero,titulo,titulo_publico,descricao,tags,data_gravacao,qtd_arquivos,temporada_id,etapa,status,drive_url,youtube_url,data_publicacao,observacoes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
}

$st=db()->prepare($sql);$st->execute($data);
header('Location: catalogo.php?ok=1');