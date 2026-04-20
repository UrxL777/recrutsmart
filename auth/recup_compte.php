<?php
// recup_compte.php — Page d'aide à la récupération de compte
// Accessible depuis inscription si l'email est déjà utilisé
require_once __DIR__ . '/../config/db.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Récupérer mon compte — RecrutSmart</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
  .card{background:#161929;border:1px solid #252a40;border-radius:16px;
    padding:2.5rem 2.2rem;width:100%;max-width:460px}
  .brand{display:flex;align-items:center;gap:.65rem;margin-bottom:2rem}
  .brand-logo{width:40px;height:40px;background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    border-radius:11px;display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1.2rem;color:#fff}
  .brand-name{font-size:1.25rem;font-weight:800;color:#fff}
  .brand-name span{color:#00c9a7}
  h1{font-size:1.45rem;font-weight:700;color:#fff;margin-bottom:.4rem}
  .subtitle{font-size:.9rem;color:#7a859a;margin-bottom:2rem;line-height:1.6}
  .options{display:flex;flex-direction:column;gap:.85rem;margin-bottom:2rem}
  .option-card{display:flex;align-items:center;gap:1rem;padding:1.1rem 1.2rem;
    background:#0d0f18;border:1.5px solid #252a40;border-radius:10px;
    text-decoration:none;color:#e2e8f0;transition:border-color .2s}
  .option-card:hover{border-color:#00c9a7}
  .option-icon{font-size:1.6rem;flex-shrink:0}
  .option-title{font-weight:600;font-size:.95rem;margin-bottom:.15rem}
  .option-desc{font-size:.8rem;color:#7a859a}
  .divider{height:1px;background:#252a40;margin:1.2rem 0}
  .form-footer{text-align:center;font-size:.875rem;color:#7a859a}
  .form-footer a{color:#00c9a7;text-decoration:none;font-weight:600}
  .form-footer a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-logo">R</div>
    <span class="brand-name">Recrut<span>Smart</span></span>
  </div>

  <h1>Récupérer votre compte</h1>
  <p class="subtitle">
    Cette adresse email est déjà associée à un compte RecrutSmart.<br>
    Voici vos options :
  </p>

  <div class="options">
    <a href="login.php" class="option-card">
      <span class="option-icon">🔑</span>
      <div>
        <div class="option-title">Je connais mon mot de passe</div>
        <div class="option-desc">Connectez-vous directement à votre espace</div>
      </div>
    </a>
    <a href="mdp.php" class="option-card">
      <span class="option-icon">🔒</span>
      <div>
        <div class="option-title">J'ai oublié mon mot de passe</div>
        <div class="option-desc">Réinitialisez via votre question de sécurité</div>
      </div>
    </a>
    <a href="../inscription/role.php" class="option-card">
      <span class="option-icon">📝</span>
      <div>
        <div class="option-title">Créer un nouveau compte</div>
        <div class="option-desc">Utilisez une adresse email différente</div>
      </div>
    </a>
  </div>

  <div class="divider"></div>
  <div class="form-footer">
    <a href="login.php">← Retour à la connexion</a>
  </div>
</div>
</body>
</html>