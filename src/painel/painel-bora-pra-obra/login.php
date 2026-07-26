<?php
session_start();
require __DIR__ . '/config/database.php';
if (!empty($_SESSION['admin_id'])) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($senha, $user['senha_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_name'] = $user['nome'];
        header('Location: dashboard.php'); exit;
    }
    $error = 'E-mail ou senha inválidos.';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar • Bora pra Obra</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{min-height:100vh;background:#111315;display:grid;place-items:center}.box{width:min(430px,92vw);background:#fff;border-radius:20px;padding:34px}.brand{width:52px;height:52px;border-radius:15px;background:#e0a100;display:grid;place-items:center;font-size:25px}</style></head><body><div class="box"><div class="brand mb-3">🔨</div><h1 class="h3">Painel Bora pra Obra</h1><p class="text-secondary">Entre para gerenciar o acervo e as publicações.</p><?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?><form method="post"><div class="mb-3"><label class="form-label">E-mail</label><input class="form-control form-control-lg" type="email" name="email" required></div><div class="mb-3"><label class="form-label">Senha</label><input class="form-control form-control-lg" type="password" name="senha" required></div><button class="btn btn-dark btn-lg w-100">Entrar</button></form><div class="small text-secondary mt-3">Acesso inicial: admin@borapraobra.com.br</div></div></body></html>
