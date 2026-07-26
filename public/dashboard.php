<?php
require __DIR__ . '/includes/auth.php'; require __DIR__ . '/config/database.php';
$pageTitle='Dashboard';
$pdo=db();
$stats=[
 'albuns'=>(int)$pdo->query('SELECT COUNT(*) FROM episodios')->fetchColumn(),
 'arquivos'=>(int)$pdo->query('SELECT COALESCE(SUM(qtd_arquivos),0) FROM episodios')->fetchColumn(),
 'publicados'=>(int)$pdo->query("SELECT COUNT(*) FROM episodios WHERE status='Publicado'")->fetchColumn(),
 'temporadas'=>(int)$pdo->query('SELECT COUNT(*) FROM temporadas')->fetchColumn(),
];
$recentes=$pdo->query('SELECT e.*,t.nome temporada FROM episodios e LEFT JOIN temporadas t ON t.id=e.temporada_id ORDER BY e.numero DESC LIMIT 8')->fetchAll();
require __DIR__.'/includes/header.php';
?>
<div class="row g-3 mb-4">
<?php foreach ([['albuns','bi-collection-play','Álbuns'],['arquivos','bi-camera-video','Arquivos brutos'],['temporadas','bi-layers','Temporadas'],['publicados','bi-youtube','Publicados']] as [$key,$icon,$label]): ?>
<div class="col-6 col-xl-3"><div class="stat-card h-100"><div class="d-flex justify-content-between"><div><div class="text-secondary small"><?= $label ?></div><div class="stat-number"><?= number_format($stats[$key],0,',','.') ?></div></div><div class="icon"><i class="bi <?= $icon ?>"></i></div></div></div></div>
<?php endforeach; ?>
</div>
<div class="row g-4"><div class="col-xl-8"><div class="panel-card"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Últimos capítulos do acervo</h2><a href="catalogo.php" class="btn btn-sm btn-outline-dark">Ver catálogo</a></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>#</th><th>Título</th><th>Temporada</th><th>Status</th></tr></thead><tbody><?php foreach($recentes as $r): ?><tr><td><?= (int)$r['numero'] ?></td><td><strong><?= htmlspecialchars($r['titulo']) ?></strong><div class="small text-secondary"><?= (int)$r['qtd_arquivos'] ?> arquivo(s)</div></td><td><?= htmlspecialchars($r['temporada'] ?? 'Não definida') ?></td><td><span class="badge text-bg-light border"><?= htmlspecialchars($r['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div></div></div><div class="col-xl-4"><div class="panel-card h-100"><h2 class="h5">Próxima ação</h2><p class="text-secondary">Comece revisando os álbuns 1 a 7 e confirme os títulos, datas e quantidades de arquivos.</p><a href="catalogo.php" class="btn btn-dark w-100">Abrir catálogo</a><hr><div class="small text-secondary">O banco já contém os capítulos 1 a 120 como ponto de partida.</div></div></div></div>
<?php require __DIR__.'/includes/footer.php'; ?>
