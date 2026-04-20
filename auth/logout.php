<?php
require_once __DIR__ . '/../config/db.php';
destroySession();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Déconnexion…</title>
<style>
  body{margin:0;background:#0d0f18;display:flex;align-items:center;
    justify-content:center;min-height:100vh;font-family:'Segoe UI',sans-serif}
  .loader{text-align:center;color:#7a859a;font-size:.95rem}
  .spinner{width:36px;height:36px;border:3px solid #252a40;
    border-top-color:#ef4444;border-radius:50%;
    animation:spin .7s linear infinite;margin:0 auto 1rem}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="loader">
  <div class="spinner"></div>
  Déconnexion en cours…
</div>
<script>
  sessionStorage.removeItem('rs_session_active');
  window.location.replace('/recrutsmart/auth/login.php');
</script>
</body>
</html>