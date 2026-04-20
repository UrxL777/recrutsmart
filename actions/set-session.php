<?php
// actions/set-session.php
// Page de transition appelée après une connexion ou inscription réussie
// Elle pose le token sessionStorage côté JS puis redirige vers le bon dashboard

require_once __DIR__ . '/../config/db.php';
requireLogin();

$dashboard = ($_SESSION['role'] === 'candidat')
    ? '/recrutsmart/dashboard/dashboard-candidat.php'
    : '/recrutsmart/dashboard/dashboard-recruteur.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Chargement…</title>
<style>
  body{margin:0;background:#0d0f18;display:flex;align-items:center;
    justify-content:center;min-height:100vh;font-family:'Segoe UI',sans-serif}
  .loader{text-align:center;color:#00c9a7;font-size:.95rem}
  .spinner{width:36px;height:36px;border:3px solid #252a40;
    border-top-color:#00c9a7;border-radius:50%;
    animation:spin .7s linear infinite;margin:0 auto 1rem}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="loader">
  <div class="spinner"></div>
  Chargement de votre espace…
</div>
<script>
  // Poser le token de session actif — indique au dashboard que la connexion est valide
  sessionStorage.setItem('rs_session_active', '1');
  // Rediriger vers le bon dashboard
  window.location.replace('<?= $dashboard ?>');
</script>
</body>
</html>