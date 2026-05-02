<?php
require_once __DIR__ . '/../config/db.php';
requireLogin();
if ($_SESSION['role'] !== 'candidat') {
    header('Location: /dashboard/dashboard-recruteur.php'); exit;
}

$id       = (int)$_SESSION['user_id'];
$candidat = $pdo->prepare('SELECT * FROM candidats WHERE id=?');
$candidat->execute([$id]); $candidat = $candidat->fetch();

// Candidatures
$stCand = $pdo->prepare('
    SELECT c.poste, c.statut, c.cree_le, r.entreprise
    FROM candidatures c
    JOIN recruteurs r ON r.id = c.recruteur_id
    WHERE c.candidat_id = ?
    ORDER BY c.cree_le DESC LIMIT 10
');
$stCand->execute([$id]); $candidatures = $stCand->fetchAll();

// Messages
$stMsg = $pdo->prepare('
    SELECT m.id, m.sujet, m.corps, m.heure_rdv, m.lu, m.cree_le, r.entreprise, r.nom, r.prenom
    FROM messages m
    JOIN recruteurs r ON r.id = m.recruteur_id
    WHERE m.candidat_id = ?
    ORDER BY m.cree_le DESC LIMIT 20
');
$stMsg->execute([$id]); $messages = $stMsg->fetchAll();

// Historique agent IA
$stIA = $pdo->prepare('
    SELECT role, message, cree_le FROM conversations_agent
    WHERE user_id=? AND user_role="candidat"
    ORDER BY cree_le ASC LIMIT 50
');
$stIA->execute([$id]); $histoIA = $stIA->fetchAll();

// Marquer message comme lu si ouvert
$msgOuvert = null;
if (isset($_GET['msg'])) {
    $mid = (int)$_GET['msg'];
    $stLu = $pdo->prepare('UPDATE messages SET lu=1 WHERE id=? AND candidat_id=?');
    $stLu->execute([$mid, $id]);
    $stMo = $pdo->prepare('
        SELECT m.*, r.entreprise, r.nom AS rnom, r.prenom AS rprenom
        FROM messages m JOIN recruteurs r ON r.id=m.recruteur_id
        WHERE m.id=? AND m.candidat_id=?
    ');
    $stMo->execute([$mid, $id]); $msgOuvert = $stMo->fetch();
}

$nonLus = array_filter($messages, fn($m) => !$m['lu']);
$csrf     = csrfToken();
$flash    = getFlash();
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST'];

function statutCouleur(string $s): string {
    return match($s) {
        'Entretien planifié' => 'background:#0a2d28;color:#00c9a7;border:1px solid #00c9a7',
        'Accepté'            => 'background:#0a2d1a;color:#4ade80;border:1px solid #4ade80',
        'Refusé'             => 'background:#2d0a0a;color:#f87171;border:1px solid #f87171',
        default              => 'background:#1e2235;color:#94a3b8;border:1px solid #334155',
    };
}

function tempsRelatif(string $date): string {
    $diff = time() - strtotime($date);
    if ($diff < 3600)  return 'Il y a '.round($diff/60).' min';
    if ($diff < 86400) return 'Il y a '.round($diff/3600).'h';
    return 'Il y a '.round($diff/86400).' jour'.(round($diff/86400)>1?'s':'');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon Espace — RecrutSmart</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;
    min-height:100vh;display:flex;flex-direction:column}

  /* ── HEADER ── */
  .header{background:#161929;border-bottom:1px solid #252a40;
    padding:.9rem 1.8rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
  .brand{display:flex;align-items:center;gap:.6rem}
  .brand-logo{width:36px;height:36px;background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    border-radius:9px;display:flex;align-items:center;justify-content:center;
    font-weight:900;font-size:1rem;color:#fff}
  .brand-name{font-size:1.1rem;font-weight:800;color:#fff}
  .brand-name span{color:#00c9a7}
  .header-right{display:flex;align-items:center;gap:1.2rem}
  .welcome-text{font-size:.95rem;color:#94a3b8}
  .welcome-text strong{color:#e2e8f0}
  .btn-deconnexion{padding:.45rem 1.1rem;background:transparent;border:1.5px solid #ef4444;
    border-radius:8px;color:#ef4444;font-size:.85rem;font-weight:600;cursor:pointer;
    text-decoration:none;transition:background .2s,color .2s}
  .btn-deconnexion:hover{background:#ef4444;color:#fff}

  /* ── LAYOUT PRINCIPAL ── */
  .main{display:grid;grid-template-columns:260px 1fr 300px;gap:1.2rem;
    padding:1.4rem 1.8rem;flex:1;min-height:0}

  /* ── SIDEBAR AGENT IA ── */
  .sidebar-ia{background:#161929;border:1px solid #252a40;border-radius:14px;
    display:flex;flex-direction:column;overflow:hidden;
    height:calc(100vh - 120px);position:sticky;top:1rem}
  .ia-header{padding:.9rem 1rem;border-bottom:1px solid #252a40;
    display:flex;align-items:center;gap:.6rem}
  .ia-dot{width:8px;height:8px;background:#00c9a7;border-radius:50%;
    box-shadow:0 0 6px #00c9a7;animation:pulse 2s infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
  .ia-title{font-weight:700;font-size:.95rem;color:#00c9a7}
  .ia-messages{flex:1;overflow-y:auto;padding:.8rem;display:flex;flex-direction:column;gap:.7rem;
    scrollbar-width:thin;scrollbar-color:#252a40 transparent}
  .ia-bubble{display:flex;gap:.6rem;align-items:flex-start}
  .ia-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
  .ia-text{background:#1e2235;border-radius:10px 10px 10px 2px;padding:.6rem .8rem;
    font-size:.83rem;color:#cbd5e1;line-height:1.5;max-width:calc(100% - 40px)}
  .ia-text.user{background:#0a2d28;color:#a7f3d0;border-radius:10px 10px 2px 10px;margin-left:auto}
  .ia-input-wrap{padding:.8rem;border-top:1px solid #252a40;display:flex;gap:.5rem}
  .ia-input{flex:1;background:#0d0f18;border:1.5px solid #252a40;border-radius:8px;
    padding:.55rem .8rem;color:#e2e8f0;font-size:.85rem;outline:none;font-family:inherit}
  .ia-input:focus{border-color:#00c9a7}
  .ia-send{background:linear-gradient(135deg,#00c9a7,#0ea5e9);border:none;border-radius:8px;
    width:34px;height:34px;display:flex;align-items:center;justify-content:center;
    cursor:pointer;flex-shrink:0;transition:opacity .2s}
  .ia-send:hover{opacity:.85}

  /* ── CENTRE : CV ── */
  .center-col{display:flex;flex-direction:column;gap:1.2rem}
  .section-card{background:#161929;border:1px solid #252a40;border-radius:14px;padding:1.4rem}
  .section-title{font-size:1.1rem;font-weight:700;color:#00c9a7;margin-bottom:1.1rem}

  /* Zone upload */
  .upload-zone{border:2px dashed #334155;border-radius:12px;padding:2.5rem 1rem;
    text-align:center;cursor:pointer;transition:border-color .2s,background .2s;margin-bottom:1rem}
  .upload-zone:hover,.upload-zone.dragover{border-color:#00c9a7;background:rgba(0,201,167,.04)}
  .upload-icon{font-size:2.8rem;margin-bottom:.6rem}
  .upload-text{color:#7a859a;font-size:.92rem}
  .upload-text strong{color:#e2e8f0;display:block;margin-bottom:.3rem}
  .cv-actuel{background:#0d0f18;border:1px solid #252a40;border-radius:9px;
    padding:.75rem 1rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem}
  .cv-actuel-icon{font-size:1.4rem}
  .cv-actuel-info{flex:1}
  .cv-actuel-nom{font-weight:600;font-size:.9rem}
  .cv-actuel-date{font-size:.78rem;color:#7a859a}
  .btn-cv{display:block;width:100%;padding:.78rem;border:none;border-radius:9px;
    font-size:1rem;font-weight:700;cursor:pointer;text-align:center;
    background:linear-gradient(135deg,#00c9a7,#0ea5e9);color:#fff;
    transition:opacity .2s,transform .1s}
  .btn-cv:hover{opacity:.88}
  .btn-cv:active{transform:scale(.98)}

  /* ── DROITE : candidatures + messages ── */
  .right-col{display:flex;flex-direction:column;gap:1.2rem;overflow-y:auto}

  /* Candidatures */
  .cand-item{display:flex;align-items:center;justify-content:space-between;
    padding:.75rem 0;border-bottom:1px solid #1e2235}
  .cand-item:last-child{border-bottom:none}
  .cand-poste{font-weight:600;font-size:.9rem}
  .cand-entreprise{font-size:.78rem;color:#7a859a;margin-top:.15rem}
  .statut-badge{padding:.3rem .7rem;border-radius:20px;font-size:.75rem;font-weight:600;white-space:nowrap}
  .empty-state{color:#7a859a;font-size:.88rem;text-align:center;padding:1rem 0}

  /* Messages */
  .msg-item{display:flex;align-items:flex-start;gap:.75rem;padding:.8rem 0;
    border-bottom:1px solid #1e2235;cursor:pointer;transition:background .2s;
    border-radius:8px;padding:.8rem .5rem}
  .msg-item:hover{background:#1e2235}
  .msg-item:last-child{border-bottom:none}
  .msg-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#0ea5e9);
    display:flex;align-items:center;justify-content:center;font-weight:700;
    font-size:.85rem;color:#fff;flex-shrink:0}
  .msg-content{flex:1;min-width:0}
  .msg-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.2rem}
  .msg-from{font-weight:600;font-size:.88rem}
  .msg-time{font-size:.75rem;color:#7a859a;white-space:nowrap}
  .msg-preview{font-size:.8rem;color:#7a859a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .msg-unread .msg-from{color:#00c9a7}
  .msg-unread .msg-preview{color:#94a3b8}
  .unread-dot{width:8px;height:8px;background:#00c9a7;border-radius:50%;margin-top:.25rem;flex-shrink:0}

  /* Modal message ouvert */
  .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;
    display:flex;align-items:center;justify-content:center;padding:1.5rem}
  .modal{background:#161929;border:1px solid #252a40;border-radius:16px;
    width:100%;max-width:540px;max-height:80vh;overflow-y:auto}
  .modal-header{padding:1.2rem 1.5rem;border-bottom:1px solid #252a40;
    display:flex;justify-content:space-between;align-items:center}
  .modal-title{font-weight:700;font-size:1rem;color:#fff}
  .modal-close{background:none;border:none;color:#7a859a;font-size:1.3rem;cursor:pointer;
    line-height:1;padding:0;transition:color .2s}
  .modal-close:hover{color:#fff}
  .modal-body{padding:1.5rem}
  .modal-meta{font-size:.83rem;color:#7a859a;margin-bottom:1rem;line-height:1.8}
  .modal-meta strong{color:#e2e8f0}
  .modal-corps{background:#0d0f18;border:1px solid #252a40;border-radius:10px;
    padding:1rem 1.2rem;font-size:.9rem;line-height:1.7;color:#cbd5e1;white-space:pre-wrap}
  .rdv-badge{display:inline-flex;align-items:center;gap:.5rem;
    background:rgba(0,201,167,.1);border:1px solid #00c9a7;
    border-radius:8px;padding:.5rem 1rem;font-size:.88rem;color:#00c9a7;
    font-weight:600;margin-top:1rem}

  /* ── RESPONSIVE TABLETTE ── */
  @media(max-width:900px){
    .main{grid-template-columns:1fr;grid-template-rows:auto}
    .sidebar-ia{height:auto;min-height:300px;position:static}
    .ia-messages{max-height:200px}
  }

  /* ── RESPONSIVE MOBILE ── */
  @media(max-width:680px){
    .main{grid-template-columns:1fr;padding:.7rem;gap:.8rem}
    .sidebar-ia{height:auto;min-height:280px;position:static;order:3}
    .ia-messages{max-height:170px}
    .center-col{order:1}
    .right-col{order:2}
    .header{padding:.65rem .9rem;flex-wrap:wrap;gap:.5rem}
    .welcome-text{font-size:.85rem}
    .section-card{padding:1rem}
    .upload-zone{padding:1.5rem .8rem}
    .upload-icon{font-size:2rem}
    .cand-item{flex-direction:column;gap:.3rem;align-items:flex-start}
    .msg-item{padding:.6rem .4rem}
  }

  @media(max-width:400px){
    .main{padding:.5rem}
    .btn-cv{font-size:.88rem;padding:.65rem}
    .ia-input{font-size:.78rem}
  }
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="brand">
    <div class="brand-logo">R</div>
    <span class="brand-name">Recrut<span>Smart</span></span>
  </div>
  <div class="header-right">
    <span class="welcome-text">
      Bonjour <strong><?= clean($_SESSION['prenom'].' '.$_SESSION['nom']) ?></strong>
    </span>
    <a href="../auth/logout.php" class="btn-deconnexion">Déconnexion</a>
  </div>
</div>

<!-- MAIN GRID -->
<div class="main">
<?php if ($flash): ?>
  <div id="flash-msg" style="grid-column:1/-1;padding:.85rem 1.2rem;border-radius:10px;
    font-size:.9rem;font-weight:500;margin-bottom:.5rem;
    display:flex;align-items:center;justify-content:space-between;
    <?= $flash['type']==='success'
      ? 'background:rgba(0,201,167,.1);color:#00c9a7;border:1px solid rgba(0,201,167,.3);'
      : 'background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.25);' ?>">
    <span><?= clean($flash['message']) ?></span>
    <button onclick="document.getElementById('flash-msg').remove()"
      style="background:none;border:none;cursor:pointer;color:inherit;font-size:1.1rem;padding:0;line-height:1;">✕</button>
  </div>
  <script>setTimeout(() => { document.getElementById('flash-msg')?.remove(); }, 5000);</script>
<?php endif; ?>

  <!-- ── SIDEBAR AGENT IA ── -->
  <div class="sidebar-ia">
    <div class="ia-header">
      <div class="ia-dot"></div>
      <span class="ia-title">RecrutSmart IA</span>
    </div>
    <div class="ia-messages" id="ia-messages">
      <!-- Message d'accueil -->
      <div class="ia-bubble">
        <div class="ia-avatar">🤖</div>
        <div class="ia-text">Bonjour ! Comment puis-je vous aider ?</div>
      </div>
      <?php foreach ($histoIA as $h): ?>
        <?php if ($h['role'] === 'user'): ?>
          <div class="ia-bubble" style="justify-content:flex-end">
            <div class="ia-text user"><?= clean($h['message']) ?></div>
          </div>
        <?php else: ?>
          <div class="ia-bubble">
            <div class="ia-avatar">🤖</div>
            <div class="ia-text"><?= clean($h['message']) ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="ia-input-wrap">
      <input type="text" class="ia-input" id="ia-input"
        placeholder="Écrire un message…" autocomplete="off">
      <button class="ia-send" id="ia-send" title="Envoyer">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"
          stroke-linecap="round" stroke-linejoin="round">
          <line x1="22" y1="2" x2="11" y2="13"/>
          <polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- ── CENTRE : MON CV ── -->
  <div class="center-col">
    <div class="section-card">
      <div class="section-title">Mon CV</div>

      <!-- Format CV recommandé -->
      <div style="background:rgba(0,201,167,.06);border:1px solid rgba(0,201,167,.25);border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.8rem;line-height:1.7;color:#94a3b8">
        <div style="color:#00c9a7;font-weight:700;margin-bottom:.4rem">📋 Format de CV recommandé</div>
        Remplissez votre CV avec ces rubriques obligatoires : <strong style="color:#e2e8f0">Compétences/Qualités</strong> (ex: rigoureux, PHP, anglais), <strong style="color:#e2e8f0">Formation/Diplôme</strong> (ex: Master en Logistique), <strong style="color:#e2e8f0">Langue</strong> (arabe, mandarin…), <strong style="color:#e2e8f0">Expérience</strong> (nombre d'années), et <strong style="color:#e2e8f0">Localisation</strong> (ville ou quartier).
      </div>

      <?php if ($candidat['cv_fichier']): ?>
        <div class="cv-actuel">
          <div class="cv-actuel-icon">📄</div>
          <div class="cv-actuel-info">
            <div class="cv-actuel-nom"><?= clean($candidat['cv_fichier']) ?></div>
            <div class="cv-actuel-date">
              Mis à jour le <?= date('d/m/Y à H:i', strtotime($candidat['cv_date'])) ?>
            </div>
          </div>
          <a href="<?= $base_url ?>/uploads/<?= clean($candidat['cv_fichier']) ?>"
            target="_blank" style="color:#00c9a7;font-size:.82rem;text-decoration:none;font-weight:600;">
            Voir
          </a>
        </div>
      <?php endif; ?>

      <form method="POST" action="/actions/upload-cv.php"
        enctype="multipart/form-data" id="form-cv">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="upload-zone" id="upload-zone" onclick="document.getElementById('cv-file').click()">
          <div class="upload-icon">☁️</div>
          <div class="upload-text">
            <strong id="upload-label">
              <?= $candidat['cv_fichier'] ? 'Cliquez pour mettre à jour votre CV' : 'Téléverser votre CV' ?>
            </strong>
            PDF, DOCX ou image — max 5 Mo
          </div>
        </div>
        <input type="file" id="cv-file" name="cv" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
          style="display:none" onchange="majLabel(this)">
        <button type="submit" class="btn-cv">
          <?= $candidat['cv_fichier'] ? 'Mettre à jour mon CV' : 'Envoyer CV' ?>
        </button>
      </form>
    </div>
  </div>

  <!-- ── DROITE : CANDIDATURES + MESSAGES ── -->
  <div class="right-col">

    <!-- Candidatures -->
    <div class="section-card">
      <div class="section-title">
        Mes Candidatures
        <?php if (count($candidatures)): ?>
          <span style="font-size:.8rem;color:#7a859a;font-weight:400;">
            (<?= count($candidatures) ?>)
          </span>
        <?php endif; ?>
      </div>
      <?php if ($candidatures): ?>
        <?php foreach ($candidatures as $c): ?>
          <div class="cand-item">
            <div>
              <div class="cand-poste"><?= clean($c['poste']) ?></div>
              <div class="cand-entreprise"><?= clean($c['entreprise']) ?></div>
            </div>
            <span class="statut-badge" style="<?= statutCouleur($c['statut']) ?>">
              <?= clean($c['statut']) ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">Aucune candidature pour le moment.</div>
      <?php endif; ?>
    </div>

    <!-- Messages -->
    <div class="section-card">
      <div class="section-title">
        Messages
        <?php if (count($nonLus)): ?>
          <span style="background:#00c9a7;color:#0d0f18;font-size:.72rem;font-weight:700;
            padding:.15rem .5rem;border-radius:20px;margin-left:.4rem">
            <?= count($nonLus) ?> nouveau<?= count($nonLus) > 1 ? 'x' : '' ?>
          </span>
        <?php endif; ?>
      </div>
      <?php if ($messages): ?>
        <?php foreach ($messages as $m): ?>
          <a href="?msg=<?= $m['id'] ?>" class="msg-item <?= !$m['lu'] ? 'msg-unread' : '' ?>"
            style="display:flex;text-decoration:none;color:inherit">
            <div class="msg-avatar">
              <?= strtoupper(substr($m['prenom'],0,1).substr($m['nom'],0,1)) ?>
            </div>
            <div class="msg-content">
              <div class="msg-header">
                <span class="msg-from"><?= clean($m['entreprise']) ?></span>
                <span class="msg-time"><?= tempsRelatif($m['cree_le']) ?></span>
              </div>
              <div class="msg-preview"><?= clean($m['sujet']) ?></div>
            </div>
            <?php if (!$m['lu']): ?>
              <div class="unread-dot"></div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">Aucun message pour le moment.</div>
      <?php endif; ?>
    </div>

  </div><!-- /right-col -->
</div><!-- /main -->

<!-- ── MODAL MESSAGE OUVERT ── -->
<?php if ($msgOuvert): ?>
<div class="modal-overlay" id="modal" onclick="fermerModal(event)">
  <div class="modal" onclick="event.stopPropagation()">
    <div class="modal-header">
      <span class="modal-title"><?= clean($msgOuvert['sujet']) ?></span>
      <button class="modal-close" onclick="window.location='dashboard-candidat.php'">✕</button>
    </div>
    <div class="modal-body">
      <div class="modal-meta">
        <strong>De :</strong> <?= clean($msgOuvert['rprenom'].' '.$msgOuvert['rnom']) ?>
        — <?= clean($msgOuvert['entreprise']) ?><br>
        <strong>Reçu le :</strong> <?= date('d/m/Y à H:i', strtotime($msgOuvert['cree_le'])) ?>
      </div>
      <div class="modal-corps"><?= clean($msgOuvert['corps']) ?></div>
      <?php if ($msgOuvert['heure_rdv']): ?>
        <div class="rdv-badge">
          📅 Rendez-vous prévu le
          <?= date('d/m/Y à H:i', strtotime($msgOuvert['heure_rdv'])) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// Fermer modal en cliquant l'overlay
function fermerModal(e) {
  if (e.target.id === 'modal') window.location = 'dashboard-candidat.php';
}

// Drag & drop zone
const zone = document.getElementById('upload-zone');
if (zone) {
  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (file) {
      document.getElementById('cv-file').files = e.dataTransfer.files;
      majLabel({files:[file]});
    }
  });
}

function majLabel(input) {
  const f = input.files[0];
  if (f) {
    document.getElementById('upload-label').textContent = '📎 ' + f.name;
  }
}

// RecrutSmart IA — chatbox (interface prête, branchement Python à la semaine 4)
const iaInput = document.getElementById('ia-input');
const iaSend  = document.getElementById('ia-send');
const iaMsgs  = document.getElementById('ia-messages');

function scrollIA() { iaMsgs.scrollTop = iaMsgs.scrollHeight; }
scrollIA();

// Échappement HTML robuste anti-XSS
function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
// Pour le texte affiché dans les bulles — sans échapper les guillemets
function escTexte(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

// Nettoie une réponse IA avant affichage — supprime code, JSON brut, erreurs techniques
function nettoyerReponseIA(txt) {
  if (!txt) return '';
  const t = txt.trim();
  if ((t.startsWith('{') && t.endsWith('}')) || (t.startsWith('[') && t.endsWith(']'))) {
    return 'Je rencontre une difficulté. Pouvez-vous reformuler votre question ?';
  }
  txt = txt.replace(/```[\s\S]*?```/g, '');
  txt = txt.replace(/`[^`]*`/g, '');
  txt = txt.replace(/<[^>]+>/g, '');
  const lignes = txt.split('\n').filter(l => {
    const ll = l.toLowerCase();
    return !['traceback', 'error:', 'exception:', 'warning:', 'undefined', 'at line', 'stack trace', 'php '].some(m => ll.includes(m));
  });
  txt = lignes.join('\n').trim();
  return txt || 'Je rencontre une difficulté. Pouvez-vous reformuler votre question ?';
}

function ajouterBulleUser(txt) {
  const d = document.createElement('div');
  d.className = 'ia-bubble'; d.style.justifyContent = 'flex-end';
  d.innerHTML = `<div class="ia-text user">${escTexte(txt)}</div>`;
  iaMsgs.appendChild(d); scrollIA();
}

function ajouterBulleIA(txt) {
  const d = document.createElement('div');
  d.className = 'ia-bubble';
  d.innerHTML = `<div class="ia-avatar">🤖</div><div class="ia-text">${escTexte(txt)}</div>`;
  iaMsgs.appendChild(d); scrollIA();
}

async function envoyerIA() {
  const msg = iaInput.value.trim();
  if (!msg) return;
  iaInput.value = '';
  ajouterBulleUser(msg);

  try {
    const fd = new FormData();
    fd.append('action',     'agent');
    fd.append('message',    msg);
    fd.append('contexte',   '');
    fd.append('csrf_token', '<?= $csrf ?>');

    const res  = await fetch('/actions/ia-proxy.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.erreur) {
      ajouterBulleIA('⚠️ ' + escTexte(data.erreur));
    } else {
      ajouterBulleIA(escTexte(nettoyerReponseIA(data.reponse)));
    }
  } catch {
    ajouterBulleIA('RecrutSmart est momentanément indisponible, veuillez réessayer.');
  }
}

iaSend.addEventListener('click', envoyerIA);
iaInput.addEventListener('keydown', e => { if (e.key === 'Enter') envoyerIA(); });

// Navigation sécurisée — approche moderne
// On stocke un token actif dans sessionStorage
// Si on revient sur la page sans token valide → login
const SESSION_KEY = 'rs_session_active';

// À la connexion, le token est posé (voir login.php)
// Si sessionStorage ne contient pas le token → session expirée ou bouton retour après déco
if (!sessionStorage.getItem(SESSION_KEY)) {
  window.location.replace('/auth/login.php');
} else {
  // Vérifier côté serveur que la session PHP est toujours active
  fetch('/actions/check-session.php', { cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      if (!data.connecte) {
        sessionStorage.removeItem(SESSION_KEY);
        window.location.replace('/auth/login.php');
      }
    });
}

// Empêcher le retour arrière après déconnexion
history.pushState(null, '', window.location.href);
window.addEventListener('popstate', () => {
  fetch('/actions/check-session.php', { cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      if (!data.connecte) {
        sessionStorage.removeItem(SESSION_KEY);
        window.location.replace('/auth/login.php');
      } else {
        history.pushState(null, '', window.location.href);
      }
    });
});
</script>
</body>
</html>