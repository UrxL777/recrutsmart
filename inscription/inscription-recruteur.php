<?php
require_once __DIR__ . '/../config/db.php';

$erreurs = []; $old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Requête invalide, veuillez réessayer.';
    } else {
        $nom        = clean($_POST['nom']        ?? '');
        $prenom     = clean($_POST['prenom']     ?? '');
        $email      = clean($_POST['email']      ?? '');
        $tel        = clean($_POST['telephone']  ?? '');
        $entreprise = clean($_POST['entreprise'] ?? '');
        $siteWeb    = clean($_POST['site_web']   ?? '');
        $mdp        = $_POST['mot_de_passe']           ?? '';
        $mdpConf    = $_POST['confirmer_mot_de_passe'] ?? '';
        $question   = clean($_POST['question_securite']?? '');
        $reponse    = clean($_POST['reponse_securite'] ?? '');
        $old = compact('nom','prenom','email','tel','entreprise','siteWeb','question');

        if (!$nom)                                     $erreurs[] = 'Le nom est requis.';
        if (!$prenom)                                  $erreurs[] = 'Le prénom est requis.';
        if (!isValidEmail($email))                     $erreurs[] = 'Adresse email invalide.';
        if (!isValidPhone($tel))                       $erreurs[] = 'Numéro de téléphone invalide.';
        if (!$entreprise)                              $erreurs[] = "Le nom de l'entreprise est requis.";
        if ($siteWeb && !filter_var($siteWeb, FILTER_VALIDATE_URL))
                                                       $erreurs[] = 'URL du site web invalide (ex: https://monsite.com).';
        if (!isValidPassword($mdp))                    $erreurs[] = 'Mot de passe : 8 car. min., 1 majuscule, 1 chiffre.';
        if ($mdp !== $mdpConf)                         $erreurs[] = 'Les mots de passe ne correspondent pas.';
        if (!in_array($question, questionsSecurite())) $erreurs[] = 'Question de sécurité invalide.';
        if (strlen(trim($reponse)) < 2)                $erreurs[] = 'Veuillez répondre à la question de sécurité.';

        if (!$erreurs) {
            $st = $pdo->prepare('SELECT id FROM recruteurs WHERE email=?');
            $st->execute([$email]);
            if ($st->fetch()) {
                header('Location: ../auth/recup_compte.php'); exit;
            }
        }
        if (!$erreurs) {
            $st = $pdo->prepare('INSERT INTO recruteurs
                (nom,prenom,email,telephone,entreprise,site_web,mot_de_passe,question_securite,reponse_securite)
                VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $nom, $prenom, $email, $tel, $entreprise,
                $siteWeb ?: null,
                hashPassword($mdp), $question,
                hashPassword(mb_strtolower(trim($reponse)))
            ]);
            createSession((int)$pdo->lastInsertId(), 'recruteur', $nom, $prenom);
            redirectToDashboard();
        }
    }
}

$questions = questionsSecurite();
$csrf      = csrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inscription Recruteur — RecrutSmart</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
  .card{background:#161929;border:1px solid #252a40;border-radius:16px;
    padding:2.5rem 2.2rem;width:100%;max-width:520px}
  .brand{display:flex;align-items:center;gap:.65rem;margin-bottom:2rem}
  .brand-logo{width:40px;height:40px;background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    border-radius:11px;display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1.2rem;color:#fff;flex-shrink:0}
  .brand-name{font-size:1.25rem;font-weight:800;color:#fff}
  .brand-name span{color:#00c9a7}
  h1{font-size:1.45rem;font-weight:700;color:#fff;margin-bottom:.3rem}
  .subtitle{font-size:.9rem;color:#7a859a;margin-bottom:1.8rem}
  .alert{padding:.8rem 1rem;border-radius:9px;font-size:.88rem;margin-bottom:1.2rem;line-height:1.6}
  .alert-danger{background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.25)}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
  .form-group{margin-bottom:1.1rem}
  label{display:block;font-size:.83rem;font-weight:600;color:#a0aec0;margin-bottom:.4rem;
    text-transform:uppercase;letter-spacing:.5px}
  .optional{font-weight:400;color:#7a859a;text-transform:none;letter-spacing:0}
  .input-wrap{position:relative}
  input,select{width:100%;padding:.72rem 1rem;background:#0d0f18;border:1.5px solid #252a40;
    border-radius:9px;font-size:.95rem;color:#e2e8f0;outline:none;
    transition:border-color .2s;font-family:inherit}
  input:focus,select:focus{border-color:#00c9a7}
  select option{background:#161929}
  .has-toggle{padding-right:2.8rem}
  .toggle-pwd{position:absolute;right:.8rem;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;color:#7a859a;font-size:1rem;
    padding:0;line-height:1;transition:color .2s}
  .toggle-pwd:hover{color:#00c9a7}
  .divider{display:flex;align-items:center;gap:.75rem;margin:1.4rem 0;
    font-size:.8rem;color:#7a859a}
  .divider::before,.divider::after{content:'';flex:1;height:1px;background:#252a40}
  small{display:block;margin-top:.3rem;font-size:.78rem;color:#7a859a}
  #match-msg{display:block;margin-top:.3rem;font-size:.78rem}
  .btn{display:block;width:100%;padding:.8rem;border:none;border-radius:9px;
    font-size:1rem;font-weight:700;cursor:pointer;text-align:center;
    transition:opacity .2s,transform .1s;margin-top:.3rem}
  .btn:active{transform:scale(.98)}
  .btn-secondary{background:linear-gradient(135deg,#6366f1,#0ea5e9);color:#fff}
  .btn-secondary:hover{opacity:.88}
  .form-footer{text-align:center;margin-top:1.4rem;font-size:.875rem;color:#7a859a}
  .form-footer a{color:#00c9a7;text-decoration:none;font-weight:600}
  .form-footer a:hover{text-decoration:underline}
  @media(max-width:480px){.grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-logo">R</div>
    <span class="brand-name">Recrut<span>Smart</span></span>
  </div>

  <h1>Créer votre espace recruteur</h1>
  <p class="subtitle">Accédez à la recherche IA dès la création de votre compte</p>

  <?php if ($erreurs): ?>
    <div class="alert alert-danger">
      <?php foreach ($erreurs as $e): ?><div>• <?= clean($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="inscription-recruteur.php" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

    <div class="grid-2">
      <div class="form-group">
        <label for="nom">Nom *</label>
        <input type="text" id="nom" name="nom"
          value="<?= clean($old['nom'] ?? '') ?>" placeholder="Diallo" required>
      </div>
      <div class="form-group">
        <label for="prenom">Prénom *</label>
        <input type="text" id="prenom" name="prenom"
          value="<?= clean($old['prenom'] ?? '') ?>" placeholder="Moussa" required>
      </div>
    </div>

    <div class="form-group">
      <label for="email">Adresse email *</label>
      <input type="email" id="email" name="email"
        value="<?= clean($old['email'] ?? '') ?>"
        placeholder="moussa@entreprise.com" required autocomplete="email">
    </div>

    <div class="form-group">
      <label for="telephone">Téléphone *</label>
      <input type="tel" id="telephone" name="telephone"
        value="<?= clean($old['tel'] ?? '') ?>"
        placeholder="+225 05 00 00 00 00" required>
    </div>

    <div class="divider">Informations entreprise</div>

    <div class="form-group">
      <label for="entreprise">Nom de l'entreprise *</label>
      <input type="text" id="entreprise" name="entreprise"
        value="<?= clean($old['entreprise'] ?? '') ?>"
        placeholder="Société ABC" required>
    </div>

    <div class="form-group">
      <label for="site_web">Site web <span class="optional">(optionnel)</span></label>
      <input type="url" id="site_web" name="site_web"
        value="<?= clean($old['siteWeb'] ?? '') ?>"
        placeholder="https://www.societe-abc.com">
    </div>

    <div class="divider">Sécurité du compte</div>

    <div class="form-group">
      <label for="mot_de_passe">Mot de passe *</label>
      <div class="input-wrap">
        <input type="password" id="mot_de_passe" name="mot_de_passe"
          placeholder="8 car. min., 1 majuscule, 1 chiffre"
          class="has-toggle" required autocomplete="new-password">
        <button type="button" class="toggle-pwd" data-target="mot_de_passe">🔒</button>
      </div>
    </div>

    <div class="form-group">
      <label for="confirmer_mot_de_passe">Confirmer le mot de passe *</label>
      <div class="input-wrap">
        <input type="password" id="confirmer_mot_de_passe" name="confirmer_mot_de_passe"
          placeholder="Répétez votre mot de passe"
          class="has-toggle" required autocomplete="new-password">
        <button type="button" class="toggle-pwd" data-target="confirmer_mot_de_passe">🔒</button>
      </div>
      <span id="match-msg"></span>
    </div>

    <div class="form-group">
      <label for="question_securite">Question de sécurité *</label>
      <select id="question_securite" name="question_securite" required>
        <option value="">— Choisissez une question —</option>
        <?php foreach ($questions as $q): ?>
          <option value="<?= clean($q) ?>"
            <?= (($old['question'] ?? '') === $q) ? 'selected' : '' ?>>
            <?= clean($q) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="reponse_securite">Votre réponse *</label>
      <input type="text" id="reponse_securite" name="reponse_securite"
        placeholder="Votre réponse" required autocomplete="off">
      <small>Utilisée uniquement pour récupérer votre compte.</small>
    </div>

    <button type="submit" class="btn btn-secondary">
      Créer mon compte recruteur →
    </button>
  </form>

  <div class="form-footer">
    Déjà un compte ? <a href="../auth/login.php">Se connecter</a>
    &nbsp;·&nbsp;
    <a href="role.php">Changer de rôle</a>
  </div>
</div>

<script>
document.querySelectorAll('.toggle-pwd').forEach(btn => {
  btn.addEventListener('click', () => {
    const inp = document.getElementById(btn.dataset.target);
    if (!inp) return;
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.textContent = show ? '🔓' : '🔒';
  });
});
const mdp  = document.getElementById('mot_de_passe');
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
  document.querySelector('form').addEventListener('submit', e => {
    if (mdp.value !== conf.value) { e.preventDefault(); conf.focus(); }
  });
}
</script>
</body>
</html>