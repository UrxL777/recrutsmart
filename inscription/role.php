<?php require_once __DIR__ . '/../config/db.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inscription — RecrutSmart</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
  .card{background:#161929;border:1px solid #252a40;border-radius:16px;
    padding:2.5rem 2.2rem;width:100%;max-width:480px;text-align:center}
  .brand{display:flex;align-items:center;justify-content:center;gap:.65rem;margin-bottom:2rem}
  .brand-logo{width:40px;height:40px;background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    border-radius:11px;display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1.2rem;color:#fff}
  .brand-name{font-size:1.25rem;font-weight:800;color:#fff}
  .brand-name span{color:#00c9a7}
  h1{font-size:1.5rem;font-weight:700;color:#fff;margin-bottom:.4rem}
  .subtitle{font-size:.9rem;color:#7a859a;margin-bottom:2rem}
  .role-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.8rem}
  .role-card{display:flex;flex-direction:column;align-items:center;gap:.7rem;
    padding:2rem 1rem;border:2px solid #252a40;border-radius:13px;
    text-decoration:none;color:#e2e8f0;font-weight:700;font-size:1rem;
    transition:border-color .2s,background .2s}
  .role-card:hover{border-color:#00c9a7;background:rgba(0,201,167,.06)}
  .role-card.recruteur:hover{border-color:#0ea5e9;background:rgba(14,165,233,.06)}
  .role-icon{font-size:2.5rem}
  .role-desc{font-size:.78rem;font-weight:400;color:#7a859a;text-align:center;line-height:1.5}
  .form-footer{font-size:.875rem;color:#7a859a}
  .form-footer a{color:#00c9a7;text-decoration:none;font-weight:600}
  .form-footer a:hover{text-decoration:underline}
  @media(max-width:400px){.role-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-logo">R</div>
    <span class="brand-name">Recrut<span>Smart</span></span>
  </div>
  <h1>Créer un compte</h1>
  <p class="subtitle">Vous êtes…</p>

  <div class="role-grid">
    <a href="inscription-candidat.php" class="role-card">
      <span class="role-icon">👨🏽‍💼</span>
      <span>Candidat</span>
      <span class="role-desc">Je cherche un emploi et souhaite déposer mon CV</span>
    </a>
    <a href="inscription-recruteur.php" class="role-card recruteur">
      <span class="role-icon">🏢</span>
      <span>Recruteur</span>
      <span class="role-desc">Je recrute et veux trouver les meilleurs profils</span>
    </a>
  </div>

  <div class="form-footer">
    Déjà un compte ? <a href="../auth/login.php">Se connecter</a>
  </div>
</div>
</body>
</html>