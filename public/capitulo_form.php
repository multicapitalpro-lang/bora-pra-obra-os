<?php
require __DIR__.'/includes/auth.php';require __DIR__.'/config/database.php';
$pdo=db();$id=(int)($_GET['id']??0);
$row=['id'=>0,'numero'=>'','titulo'=>'','titulo_publico'=>'','descricao'=>'','tags'=>'','data_gravacao'=>'','qtd_arquivos'=>0,'temporada_id'=>'','etapa'=>'','status'=>'Catalogado','drive_url'=>'','youtube_url'=>'','data_publicacao'=>'','observacoes'=>'','notas_edicao'=>'','producao_checklist'=>''];
if($id){$st=$pdo->prepare('SELECT * FROM capitulos WHERE id=?');$st->execute([$id]);$row=$st->fetch()?:$row;}
$temps=$pdo->query('SELECT * FROM temporadas ORDER BY ordem')->fetchAll();
$pageTitle=$id?'Editar capítulo':'Novo capítulo';require __DIR__.'/includes/header.php';
$dtPub = !empty($row['data_publicacao']) ? str_replace(' ','T',substr($row['data_publicacao'],0,16)) : '';
// Etapas do checklist de produção (chave => rótulo)
$etapasProd = [
  'triado'    => 'Material triado',
  'editado'   => 'Editado',
  'revisado'  => 'Revisado',
  'thumb'     => 'Thumbnail pronta',
  'seo'       => 'SEO preenchido',
  'derivados' => 'Shorts/Reels feitos',
];
$check = [];
if(!empty($row['producao_checklist'])){ $check = json_decode($row['producao_checklist'], true) ?: []; }
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
  <h2 class="h6 text-secondary mb-3"><i class="bi bi-list-check"></i> Produção</h2>
  <label class="form-label">Checklist</label>
  <div class="row g-2 mb-3">
    <?php foreach($etapasProd as $chave=>$rotulo): ?>
    <div class="col-md-4 col-6">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="check[]" value="<?= $chave ?>" id="chk_<?= $chave ?>" <?= !empty($check[$chave])?'checked':'' ?>>
        <label class="form-check-label" for="chk_<?= $chave ?>"><?= $rotulo ?></label>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <label class="form-label">Notas de edição <span class="text-secondary small">(decisões ao assistir o bruto)</span></label>
  <textarea class="form-control" rows="4" name="notas_edicao" placeholder="Ex.: cortar do min 3, aquele erro do muro vira o gancho, faltou aéreo — pegar do drone..."><?= htmlspecialchars($row['notas_edicao']??'') ?></textarea>
</div>

<div class="panel-card mb-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 text-secondary mb-0"><i class="bi bi-youtube"></i> Publicação &amp; SEO</h2>
    <button type="button" class="btn btn-sm btn-warning" id="btnIA"><i class="bi bi-stars"></i> Gerar com IA</button>
  </div>
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
<script>
document.getElementById('btnIA').addEventListener('click', async function(){
  const btn = this;
  const f = document.querySelector('form');
  const temporadaSel = f.temporada_id;
  const payload = {
    titulo: f.titulo.value,
    etapa: f.etapa.value,
    temporada: temporadaSel.options[temporadaSel.selectedIndex] ? temporadaSel.options[temporadaSel.selectedIndex].text : '',
    notas: f.notas_edicao.value
  };
  if(!payload.titulo.trim()){
    Swal.fire({icon:'warning',title:'Falta o título',text:'Preencha o título interno do capítulo antes de gerar.'});
    return;
  }
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando...';
  try {
    const r = await fetch('ia.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await r.json();
    if(!r.ok){ throw new Error(data.erro || 'Erro ao gerar'); }
    if(data.titulo_publico) f.titulo_publico.value = data.titulo_publico;
    if(data.descricao) f.descricao.value = data.descricao;
    if(data.tags) f.tags.value = data.tags;
    Swal.fire({icon:'success',title:'Gerado!',text:'Revise os campos e salve se estiver bom.',timer:2000,showConfirmButton:false});
  } catch(e){
    Swal.fire({icon:'error',title:'Erro',text:e.message});
  } finally {
    btn.disabled = false;
    btn.innerHTML = original;
  }
});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>