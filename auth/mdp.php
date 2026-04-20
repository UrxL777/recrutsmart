<?php
require_once __DIR__ . '/../config/db.php';

// Reset possible via ?reset=1
if (isset($_GET['reset'])) {
    unset($_SESSION['reset_step'], $_SESSION['reset_email'],
          $_SESSION['reset_role'],  $_SESSION['reset_question']);
    header('Location: mdp.php'); exit;
}

if (empty($_SESSION['reset_step'])) $_SESSION['reset_step'] = 1;
$etape  = (int)$_SESSION['reset_step'];
$erreur = '';
$csrf   = csrfToken();

// ── ÉTAPE 1 : saisir l'email ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'step1') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { $erreur = 'Requête invalide.'; }
    else {
        $email = clean($_POST['email'] ?? '');
        if (!isValidEmail($email)) { $erreur = 'Adresse email invalide.'; }
        else {
            $utilisateur = null; $role = '';
            $st = $pdo->prepare('SELECT id,question_securite FROM candidats WHERE email=? AND actif=1');
            $st->execute([$email]); $row = $st->fetch();
            if ($row) { $utilisateur = $row; $role = 'candidat'; }
            if (!$utilisateur) {
                $st = $pdo->prepare('SELECT id,question_securite FROM recruteurs WHERE email=? AND actif=1');
                $st->execute([$email]); $row = $st->fetch();
                if ($row) { $utilisateur = $row; $role = 'recruteur'; }
            }
            if (!$utilisateur) { $erreur = 'Aucun compte actif trouvé pour cet email.'; }
            else {
                $_SESSION['reset_email']    = $email;
                $_SESSION['reset_role']     = $role;
                $_SESSION['reset_question'] = $utilisateur['question_securite'];
                $_SESSION['reset_step']     = 2;
                $etape = 2;
            }
        }
    }
}

// ── ÉTAPE 2 : répondre à la question de sécurité ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'step2') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { $erreur = 'Requête invalide.'; }
    else {
        $reponse = mb_strtolower(trim($_POST['reponse_securite'] ?? ''));
        $email   = $_SESSION['reset_email'] ?? '';
        $role    = $_SESSION['reset_role']  ?? '';
        $table   = $role === 'candidat' ? 'candidats' : 'recruteurs';
        $st = $pdo->prepare("SELECT reponse_securite FROM $table WHERE email=?");
        $st->execute([$email]); $row = $st->fetch();
        if (!$row || !verifyPassword($reponse, $row['reponse_securite'])) {
            $erreur = 'Réponse incorrecte. Veuillez réessayer.';
        } else {
            $_SESSION['reset_step'] = 3; $etape = 3;
        }
    }
}

// ── ÉTAPE 3 : nouveau mot de passe ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'step3') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { $erreur = 'Requête invalide.'; }
    else {
        $mdp     = $_POST['mot_de_passe']           ?? '';
        $mdpConf = $_POST['confirmer_mot_de_passe']  ?? '';
        $email   = $_SESSION['reset_email'] ?? '';
        $role    = $_SESSION['reset_role']  ?? '';
        if (!isValidPassword($mdp)) {
            $erreur = 'Mot de passe : 8 caractères min., 1 majuscule, 1 chiffre.';
        } elseif ($mdp !== $mdpConf) {
            $erreur = 'Les mots de passe ne correspondent pas.';
        } elseif (!$email || !$role) {
            $erreur = 'Session expirée. Recommencez.';
            $_SESSION['reset_step'] = 1; $etape = 1;
        } else {
            $table = $role === 'candidat' ? 'candidats' : 'recruteurs';
            $st = $pdo->prepare("UPDATE $table SET mot_de_passe=? WHERE email=?");
            $st->execute([hashPassword($mdp), $email]);
            unset($_SESSION['reset_step'], $_SESSION['reset_email'],
                  $_SESSION['reset_role'],  $_SESSION['reset_question']);
            setFlash('success', '✅ Mot de passe modifié ! Vous pouvez vous reconnecter.');
            header('Location: login.php'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mot de passe oublié — RecrutSmart</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
  .card{background:#161929;border:1px solid #252a40;border-radius:16px;
    padding:2.5rem 2.2rem;width:100%;max-width:430px}
  .brand{display:flex;align-items:center;gap:.65rem;margin-bottom:2rem}
  .brand-logo{width:40px;height:40px;background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    border-radius:11px;display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1.2rem;color:#fff;flex-shrink:0}
  .brand-name{font-size:1.25rem;font-weight:800;color:#fff}
  .brand-name span{color:#00c9a7}
  /* Stepper */
  .stepper{display:flex;align-items:center;gap:.4rem;margin-bottom:1.8rem;font-size:.8rem}
  .step{display:flex;align-items:center;gap:.35rem}
  .step-num{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;
    justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0}
  .step-num.done{background:#00c9a7;color:#0d0f18}
  .step-num.active{background:#0ea5e9;color:#fff}
  .step-num.todo{background:#252a40;color:#7a859a}
  .step-label.active{color:#e2e8f0;font-weight:600}
  .step-label{color:#7a859a}
  .step-sep{flex:1;height:1px;background:#252a40;min-width:16px}
  h1{font-size:1.45rem;font-weight:700;color:#fff;margin-bottom:.3rem}
  .subtitle{font-size:.9rem;color:#7a859a;margin-bottom:1.8rem}
  .alert{padding:.8rem 1rem;border-radius:9px;font-size:.88rem;margin-bottom:1.2rem}
  .alert-danger{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
  .form-group{margin-bottom:1.15rem}
  label{display:block;font-size:.83rem;font-weight:600;color:#a0aec0;margin-bottom:.4rem;
    text-transform:uppercase;letter-spacing:.5px}
  .input-wrap{position:relative}
  input,select{width:100%;padding:.72rem 1rem;background:#0d0f18;border:1.5px solid #252a40;
    border-radius:9px;font-size:.95rem;color:#e2e8f0;outline:none;
    transition:border-color .2s;font-family:inherit}
  input:focus{border-color:#00c9a7}
  .has-toggle{padding-right:2.8rem}
  .toggle-pwd{position:absolute;right:.8rem;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;color:#7a859a;font-size:1rem;padding:0}
  .toggle-pwd:hover{color:#00c9a7}
  .question-box{background:#0d0f18;border:1.5px solid #252a40;border-radius:9px;
    padding:.72rem 1rem;font-size:.92rem;color:#a0aec0;line-height:1.5}
  .btn{display:block;width:100%;padding:.8rem;border:none;border-radius:9px;
    font-size:1rem;font-weight:700;cursor:pointer;text-align:center;
    text-decoration:none;transition:opacity .2s,transform .1s}
  .btn:active{transform:scale(.98)}
  .btn-primary{background:linear-gradient(135deg,#00c9a7,#0ea5e9);color:#fff}
  .btn-primary:hover{opacity:.88}
  .form-footer{text-align:center;margin-top:1.3rem;font-size:.875rem;color:#7a859a}
  .form-footer a{color:#00c9a7;text-decoration:none;font-weight:600}
  small{display:block;margin-top:.35rem;font-size:.78rem;color:#7a859a}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-logo">R</div>
    <span class="brand-name">Recrut<span>Smart</span></span>
  </div>

  <!-- Stepper -->
  <div class="stepper">
    <?php
    $steps = ['Email','Sécurité','Nouveau mot de passe'];
    foreach ($steps as $i => $label):
      $n = $i + 1;
      $cls = $n < $etape ? 'done' : ($n === $etape ? 'active' : 'todo');
      $txt = $n < $etape ? '✓' : $n;
    ?>
      <div class="step">
        <span class="step-num <?= $cls ?>"><?= $txt ?></span>
        <span class="step-label <?= $cls === 'active' ? 'active' : '' ?>"><?= $label ?></span>
      </div>
      <?php if ($i < 2): ?><div class="step-sep"></div><?php endif; ?>
    <?php endforeach; ?>
  </div>

  <?php if ($erreur): ?>
    <div class="alert alert-danger"><?= clean($erreur) ?></div>
  <?php endif; ?>

  <!-- ── ÉTAPE 1 ── -->
  <?php if ($etape === 1): ?>
    <h1>Mot de passe oublié</h1>
    <p class="subtitle">Saisissez votre email pour récupérer votre compte</p>
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="step1">
      <div class="form-group">
        <label for="email">Adresse email</label>
        <input type="email" id="email" name="email" placeholder="votre@email.com" required>
      </div>
      <button type="submit" class="btn btn-primary">Continuer →</button>
    </form>

  <!-- ── ÉTAPE 2 ── -->
  <?php elseif ($etape === 2): ?>
    <h1>Question de sécurité</h1>
    <p class="subtitle">Répondez pour confirmer votre identité</p>
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="step2">
      <div class="form-group">
        <label>Votre question</label>
        <div class="question-box"><?= clean($_SESSION['reset_question'] ?? '') ?></div>
      </div>
      <div class="form-group">
        <label for="reponse_securite">Votre réponse</label>
        <input type="text" id="reponse_securite" name="reponse_securite"
          placeholder="Répondez (insensible à la casse)" required autocomplete="off">
        <small>La casse n'est pas prise en compte.</small>
      </div>
      <button type="submit" class="btn btn-primary">Vérifier →</button>
    </form>
    <div class="form-footer"><a href="mdp.php?reset=1">↩ Recommencer</a></div>

  <!-- ── ÉTAPE 3 ── -->
  <?php elseif ($etape === 3): ?>
    <h1>Nouveau mot de passe</h1>
    <p class="subtitle">Choisissez un nouveau mot de passe sécurisé</p>
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="step3">
      <div class="form-group">
        <label for="mot_de_passe">Nouveau mot de passe</label>
        <div class="input-wrap">
          <input type="password" id="mot_de_passe" name="mot_de_passe"
            placeholder="8 car. min., 1 majuscule, 1 chiffre"
            class="has-toggle" required autocomplete="new-password">
          <button type="button" class="toggle-pwd" data-target="mot_de_passe">🔒</button>
        </div>
      </div>
      <div class="form-group">
        <label for="confirmer_mot_de_passe">Confirmer le mot de passe</label>
        <div class="input-wrap">
          <input type="password" id="confirmer_mot_de_passe" name="confirmer_mot_de_passe"
            placeholder="Répétez le nouveau mot de passe"
            class="has-toggle" required autocomplete="new-password">
          <button type="button" class="toggle-pwd" data-target="confirmer_mot_de_passe">🔒</button>
        </div>
        <small id="match-msg"></small>
      </div>
      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
  <?php endif; ?>

  <?php if ($etape === 1): ?>
    <div class="form-footer"><a href="login.php">← Retour à la connexion</a></div>
  <?php endif; ?>
</div>

<script>
// Toggle mot de passe
document.querySelectorAll('.toggle-pwd').forEach(btn => {
  btn.addEventListener('click', () => {
    const inp = document.getElementById(btn.dataset.target);
    if (!inp) return;
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.textContent = show ? '🔓' : '🔒';
  });
});
// Validation confirmation
const mdp = document.getElementById('mot_de_passe');
const conf = document.getElementById('confirmer_mot_de_passe');
const msg  = document.getElementById('match-msg');
if (conf && mdp && msg) {
  conf.addEventListener('input', () => {
    if (!conf.value) { msg.textContent = ''; return; }
    if (mdp.value !== conf.value) {
      msg.style.color = '#fca5a5'; msg.textContent = '❌ Les mots de passe ne correspondent pas.';
    } else {
      msg.style.color = '#6ee7d6'; msg.textContent = '✅ Les mots de passe correspondent.';
    }
  });
  document.querySelector('form')?.addEventListener('submit', e => {
    if (mdp.value !== conf.value) { e.preventDefault(); conf.focus(); }
  });
}
</script>
</body>
</html>