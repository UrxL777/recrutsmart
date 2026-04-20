<?php
require_once __DIR__ . '/../config/db.php';
if (!empty($_SESSION['user_id'])) redirectToDashboard();

$erreur = '';

// ── Protection anti brute force ──────────────────────────────────
// Max 5 tentatives par IP par tranche de 15 minutes
$ip          = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
$cleSession  = 'login_tentatives_' . md5($ip);
$cleTempo    = 'login_tempo_' . md5($ip);

if (!empty($_SESSION[$cleTempo]) && $_SESSION[$cleTempo] > time()) {
    $attente = ceil(($_SESSION[$cleTempo] - time()) / 60);
    $erreur  = "Trop de tentatives. Réessayez dans {$attente} minute(s).";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erreur)) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $erreur = 'Requête invalide, veuillez réessayer.';
    } else {
        $email = clean($_POST['email'] ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';

        if (!$email || !$mdp) {
            $erreur = 'Veuillez remplir tous les champs.';
        } else {
            $utilisateur = null; $role = '';

            $st = $pdo->prepare('SELECT * FROM candidats WHERE email=? AND actif=1');
            $st->execute([$email]); $row = $st->fetch();
            if ($row && verifyPassword($mdp, $row['mot_de_passe'])) {
                $utilisateur = $row; $role = 'candidat';
            }
            if (!$utilisateur) {
                $st = $pdo->prepare('SELECT * FROM recruteurs WHERE email=? AND actif=1');
                $st->execute([$email]); $row = $st->fetch();
                if ($row && verifyPassword($mdp, $row['mot_de_passe'])) {
                    $utilisateur = $row; $role = 'recruteur';
                }
            }
            if ($utilisateur) {
                // Connexion réussie → réinitialiser le compteur
                unset($_SESSION[$cleSession], $_SESSION[$cleTempo]);
                createSession((int)$utilisateur['id'], $role, $utilisateur['nom'], $utilisateur['prenom']);
                redirectToDashboard();
            } else {
                // Échec → incrémenter le compteur
                $_SESSION[$cleSession] = ($_SESSION[$cleSession] ?? 0) + 1;
                if ($_SESSION[$cleSession] >= 5) {
                    $_SESSION[$cleTempo] = time() + (15 * 60); // Bloqué 15 min
                    unset($_SESSION[$cleSession]);
                    $erreur = 'Trop de tentatives. Compte bloqué pendant 15 minutes.';
                } else {
                    $restantes = 5 - $_SESSION[$cleSession];
                    $erreur = "Email ou mot de passe incorrect. ({$restantes} tentative(s) restante(s))";
                }
            }
        }
    }
}

$flash = getFlash();
$csrf  = csrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion — RecrutSmart</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
  .card{background:#161929;border:1px solid #252a40;border-radius:16px;
    padding:2.5rem 2.2rem;width:100%;max-width:430px}
  .brand{display:flex;align-items:center;gap:.65rem;margin-bottom:2.2rem}
  .brand-logo{width:40px;height:40px;background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    border-radius:11px;display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1.2rem;color:#fff;flex-shrink:0}
  .brand-name{font-size:1.25rem;font-weight:800;color:#fff;letter-spacing:-.3px}
  .brand-name span{color:#00c9a7}
  h1{font-size:1.55rem;font-weight:700;color:#fff;margin-bottom:.3rem}
  .subtitle{font-size:.9rem;color:#7a859a;margin-bottom:1.8rem}
  .alert{padding:.8rem 1rem;border-radius:9px;font-size:.88rem;margin-bottom:1.3rem;line-height:1.5}
  .alert-danger{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
  .alert-success{background:rgba(0,201,167,.1);color:#6ee7d6;border:1px solid rgba(0,201,167,.25)}
  .form-group{margin-bottom:1.15rem}
  label{display:block;font-size:.83rem;font-weight:600;color:#a0aec0;margin-bottom:.4rem;
    text-transform:uppercase;letter-spacing:.5px}
  .input-wrap{position:relative}
  input[type=email],input[type=password],input[type=text],input[type=tel],input[type=url],select,textarea{
    width:100%;padding:.72rem 1rem;background:#0d0f18;border:1.5px solid #252a40;
    border-radius:9px;font-size:.95rem;color:#e2e8f0;outline:none;
    transition:border-color .2s;font-family:inherit}
  input:focus,select:focus,textarea:focus{border-color:#00c9a7}
  .has-toggle{padding-right:2.8rem}
  .toggle-pwd{position:absolute;right:.8rem;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;color:#7a859a;font-size:1rem;
    padding:0;line-height:1;transition:color .2s}
  .toggle-pwd:hover{color:#00c9a7}
  .forgot{text-align:right;margin-bottom:1.25rem}
  .forgot a{font-size:.83rem;color:#00c9a7;text-decoration:none}
  .forgot a:hover{text-decoration:underline}
  .btn{display:block;width:100%;padding:.8rem;border:none;border-radius:9px;
    font-size:1rem;font-weight:700;cursor:pointer;text-align:center;
    text-decoration:none;transition:opacity .2s,transform .1s;letter-spacing:.2px}
  .btn:active{transform:scale(.98)}
  .btn-primary{background:linear-gradient(135deg,#00c9a7,#0ea5e9);color:#fff}
  .btn-primary:hover{opacity:.88}
  .form-footer{text-align:center;margin-top:1.5rem;font-size:.875rem;color:#7a859a}
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

  <h1>Bon retour 👋</h1>
  <p class="subtitle">Connectez-vous !!</p>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= clean($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($erreur): ?>
    <div class="alert alert-danger"><?= clean($erreur) ?></div>
  <?php endif; ?>

  <form method="POST" action="login.php" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

    <div class="form-group">
      <label for="email">Adresse email</label>
      <input type="email" id="email" name="email"
        placeholder="votre@email.com"
        value="<?= clean($_POST['email'] ?? '') ?>"
        required autocomplete="email">
    </div>

    <div class="form-group">
      <label for="mot_de_passe">Mot de passe</label>
      <div class="input-wrap">
        <input type="password" id="mot_de_passe" name="mot_de_passe"
          placeholder="••••••••" class="has-toggle"
          required autocomplete="current-password">
        <button type="button" class="toggle-pwd" data-target="mot_de_passe"
          aria-label="Afficher le mot de passe">🔒</button>
      </div>
    </div>

    <div class="forgot">
      <a href="mdp.php">Mot de passe oublié ?</a>
    </div>

    <button type="submit" class="btn btn-primary">Se connecter</button>
  </form>

  <div class="form-footer">
    Pas encore de compte ?
    <a href="../inscription/role.php">S'inscrire en tant que</a>
  </div>
</div>

<script>
// Nettoyer le token sessionStorage sur la page login
// (au cas où l'utilisateur revient sur login après déconnexion)
sessionStorage.removeItem('rs_session_active');

document.querySelectorAll('.toggle-pwd').forEach(btn => {
  btn.addEventListener('click', () => {
    const inp = document.getElementById(btn.dataset.target);
    if (!inp) return;
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.textContent = show ? '🔓' : '🔒';
  });
});
</script>
</body>
</html>