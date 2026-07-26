<?php
require __DIR__.'/includes/auth.php';require __DIR__.'/config/database.php';
$pdo=db();$id=(int)($_GET['id']??0);
$row=['id'=>0,'numero'=>'','titulo'=>'','titulo_publico'=>'','descricao'=>'','tags'=>'','data_gravacao'=>'','qtd_arquivos'=>0,'temporada_id'=>'','etapa'=>'','status'=>'Catalogado','drive_url'=>'','youtube_url'=>'','data_publicacao'=>'','observacoes'=>''];
if($id){$st=$pdo->prepare('SELECT * FROM capitulos WHERE id=?');$st->execute([$id]);$row=$st->fetch()?:$row;}
$temps=$pdo->query('SELECT * FROM temporadas ORDER BY ordem')->fetchAll();
$pageTitle=$id?'Editar capítulo':'Novo capítulo';require __DIR__.'/includes/header.php';
// data_publicacao vem como 'YYYY-MM-DD HH:MM:SS'; input datetime-local espera 'YYYY-MM-DDTHH:MM'
$dtPub = !empty($row['data_publicacao']) ? str_replace(' ','T',substr($row['data_publicacao'],0,16)) : '';
?>
<form method="post" action="capitulo_salvar.php">
<input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

<div class="panel-card mb-4">
  <h2 class="h6 text-secondary mb-3">Catalogação</h2>
  <div class="row g-3">
    <div class="col-md-2"><label class="form-label">Número</label><input class="form-control" type="number" name="numero" required value="<?= htmlspecialchars((string)$row['numero']) ?>"></div>
    <div class="col-md-7"><label class="form-label">Título do álbum (interno)</label><input class="form-control" name="titulo" required value="<?= htmlspecialchars($row['titulo']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Data de gravação</label><input class="form-control" type="date" name="data_gravacao" value="<?= htmlspecialchars($row['data_gravacao']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">Quantidade de arquivos</label><input class="form-control" type="number" name="qtd_arquivos" min="0" value="<?= (int)$row['qtd_arquivos'] ?>"></div>
    <div class="col-md-4"><label class="form-label">Temporada</label><select class="form-select" name="temporada_id"><option value="">Não definida</option><?php foreach($temps as $t): ?><option value="<?= $t['id'] ?>" <?= $row['temporada_id']==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['nome']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Etapa</label><input class="form-control" name="etapa" value="<?= htmlspecialchars($row['etapa']??'') ?>"></div>
    <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach(['Catalogado','Em edição','Pronto','Agendado','Publicado'] as $s): ?><option <?= $row['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Link do Drive / álbum</label><input class="form-control" type="url" name="drive_url" value="<?= htmlspecialchars($row['drive_url']??'') ?>"></div>
  </div>
</div>

<div class="panel-card mb-4">
  <h2 class="h6 text-secondary mb-3"><i class="bi bi-youtube"></i> Publicação &amp; SEO</h2>
  <div class="row g-3">
    <div class="col-12"><label class="form-label">Título público <span class="text-secondary small">(o que aparece no YouTube)</span></label><input class="form-control" name="titulo_publico" maxlength="255" value="<?= htmlspecialchars($row['titulo_publico']??'') ?>" placeholder="Ex.: Muro de arrimo: o erro que quase custou caro"></div>
    <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" rows="5" name="descricao" placeholder="Descrição do vídeo para YouTube/Instagram..."><?= htmlspecialchars($row['descricao']??'') ?></textarea></div>
    <div class="col-md-8"><label class="form-label">Tags / hashtags</label><input class="form-control" name="tags" maxlength="500" value="<?= htmlspecialchars($row['tags']??'') ?>" placeholder="construção, muro de arrimo, obra, #boraproobra"></div>
    <div class="col-md-4"><label class="form-label">Data de publicação</label><input class="form-control" type="datetime-local" name="data_publicacao" value="<?= htmlspecialchars($dtPub) ?>"></div>
    <div class="col-12"><label class="form-label">Link do YouTube (publicado)</label><input class="form-control" type="url" name="youtube_url" value="<?= htmlspecialchars($row['youtube_url']??'') ?>"></div>
  </div>
</div>

<div class="panel-card mb-4">
  <h2 class="h6 text-secondary mb-3">Observações</h2>
  <textarea class="form-control" rows="4" name="observacoes"><?= htmlspecialchars($row['observacoes']??'') ?></textarea>
</div>

<div class="d-flex justify-content-between mt-4 mb-5">
  <a href="catalogo.php" class="btn btn-outline-secondary">Voltar</a>
  <button class="btn btn-dark">Salvar capítulo</button>
</div>
</form>
<?php require __DIR__.'/includes/footer.php'; ?>