<?php
$items = [
 ['dashboard.php','bi-speedometer2','Dashboard'],
 ['catalogo.php','bi-collection-play','Catálogo de Conteúdo'],
 ['temporadas.php','bi-layers','Temporadas'],
 ['publicacoes.php','bi-calendar-check','Publicações'],
 ['ideias.php','bi-lightbulb','Ideias'],
 ['seo.php','bi-search','SEO'],
 ['configuracoes.php','bi-gear','Configurações'],
];
?>
<aside class="sidebar" id="sidebar">
  <div class="brand-box">
    <div class="brand-mark"><i class="bi bi-hammer"></i></div>
    <div><strong>Bora pra Obra</strong><small>Painel interno</small></div>
  </div>
  <nav class="nav flex-column gap-1 mt-4">
    <?php foreach ($items as [$url,$icon,$label]): ?>
      <a class="nav-link <?= $current === $url ? 'active' : '' ?>" href="<?= $url ?>">
        <i class="bi <?= $icon ?>"></i><span><?= $label ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <small>ALICERCE • Controle central</small>
  </div>
</aside>
