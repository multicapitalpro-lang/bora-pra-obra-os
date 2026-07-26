<?php
require __DIR__.'/includes/auth.php'; require __DIR__.'/config/database.php';
$pageTitle='Catálogo de Conteúdo'; $pdo=db();
$q=trim($_GET['q']??''); $temporada=(int)($_GET['temporada']??0); $status=trim($_GET['status']??'');
$where=[];$params=[];
if($q!==''){ $where[]='(e.titulo LIKE ? OR e.observacoes LIKE ?)';$params[]="%$q%";$params[]="%$q%"; }
if($temporada){$where[]='e.temporada_id=?';$params[]=$temporada;}
if($status!==''){$where[]='e.status=?';$params[]=$status;}
$sql='SELECT e.*,t.nome temporada FROM episodios e LEFT JOIN temporadas t ON t.id=e.temporada_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY e.numero';
$stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();$temps=$pdo->query('SELECT * FROM temporadas ORDER BY ordem')->fetchAll();
require __DIR__.'/includes/header.php';
?>
<?php if(isset($_GET['ok'])): ?><div class="alert alert-success auto-hide">Registro salvo com sucesso.</div><?php endif; ?>
<div class="panel-card mb-4"><form class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label">Pesquisar</label><input name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Título ou observação"></div><div class="col-md-3"><label class="form-label">Temporada</label><select name="temporada" class="form-select"><option value="0">Todas</option><?php foreach($temps as $t): ?><option value="<?= $t['id'] ?>" <?= $temporada==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['nome']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Todos</option><?php foreach(['Catalogado','Em edição','Pronto','Agendado','Publicado'] as $s): ?><option <?= $status===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-dark">Filtrar</button></div></form></div>
<div class="panel-card"><div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="h5 mb-0">Álbuns / capítulos</h2><div class="small text-secondary"><?= count($rows) ?> resultado(s)</div></div><a href="episodio_form.php" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Novo</a></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>#</th><th>Álbum</th><th>Data</th><th>Arquivos</th><th>Temporada</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><strong><?= (int)$r['numero'] ?></strong></td><td><?= htmlspecialchars($r['titulo']) ?><div class="small text-secondary"><?= htmlspecialchars($r['etapa']??'') ?></div></td><td><?= $r['data_gravacao']?date('d/m/Y',strtotime($r['data_gravacao'])):'—' ?></td><td><?= (int)$r['qtd_arquivos'] ?></td><td><?= htmlspecialchars($r['temporada']??'Não definida') ?></td><td><span class="badge text-bg-light border"><?= htmlspecialchars($r['status']) ?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-dark" href="episodio_form.php?id=<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php require __DIR__.'/includes/footer.php'; ?>
