<?php
require __DIR__.'/includes/auth.php';require __DIR__.'/config/database.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: catalogo.php');exit;}
$id=(int)($_POST['id']??0);$data=[(int)$_POST['numero'],trim($_POST['titulo']),$_POST['data_gravacao']?:null,(int)$_POST['qtd_arquivos'],$_POST['temporada_id']!==''?(int)$_POST['temporada_id']:null,trim($_POST['etapa']),trim($_POST['status']),trim($_POST['drive_url']),trim($_POST['youtube_url']),trim($_POST['observacoes'])];
if($id){$data[]=$id;$sql='UPDATE episodios SET numero=?,titulo=?,data_gravacao=?,qtd_arquivos=?,temporada_id=?,etapa=?,status=?,drive_url=?,youtube_url=?,observacoes=? WHERE id=?';}else{$sql='INSERT INTO episodios(numero,titulo,data_gravacao,qtd_arquivos,temporada_id,etapa,status,drive_url,youtube_url,observacoes) VALUES(?,?,?,?,?,?,?,?,?,?)';}
$st=db()->prepare($sql);$st->execute($data);header('Location: catalogo.php?ok=1');
