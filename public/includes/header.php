<?php
if (!isset($pageTitle)) $pageTitle = 'Painel Bora pra Obra';
$current = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <main class="app-main">
    <header class="topbar">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" id="menuToggle"><i class="bi bi-list"></i></button>
      <div>
        <div class="small text-secondary">Gestão de conteúdo</div>
        <h1 class="h4 mb-0"><?= htmlspecialchars($pageTitle) ?></h1>
      </div>
      <div class="ms-auto d-flex align-items-center gap-2">
        <span class="badge text-bg-light border"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Administrador') ?></span>
        <a class="btn btn-sm btn-dark" href="logout.php"><i class="bi bi-box-arrow-right"></i></a>
      </div>
    </header>
    <section class="content-wrap">
