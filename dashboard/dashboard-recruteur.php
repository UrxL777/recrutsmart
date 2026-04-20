<?php
require_once __DIR__ . '/../config/db.php';
requireLogin();
if ($_SESSION['role'] !== 'recruteur') {
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

$id        = (int)$_SESSION['user_id'];
$recruteur = $pdo->prepare('SELECT * FROM recruteurs WHERE id=?');
$recruteur->execute([$id]); $recruteur = $recruteur->fetch();

// Historique agent IA
$stIA = $pdo->prepare('
    SELECT role, message FROM conversations_agent
    WHERE user_id=? AND user_role="recruteur"
    ORDER BY cree_le ASC LIMIT 50
');
$stIA->execute([$id]); $histoIA = $stIA->fetchAll();

// Résultats matching de la dernière recherche (si existante)
$dernResultats = [];
$dernRequete   = '';
$stDernRech = $pdo->prepare('
    SELECT id, requete FROM recherches WHERE recruteur_id=? ORDER BY cree_le DESC LIMIT 1
');
$stDernRech->execute([$id]); $dernRech = $stDernRech->fetch();
if ($dernRech) {
    $dernRequete = $dernRech['requete'];
    $stRM = $pdo->prepare('
        SELECT rm.score, rm.resume_ia, c.id AS cand_id,
               c.nom, c.prenom, c.ville, c.cv_fichier,
               COALESCE(ca.competences,"") AS competences,
               COALESCE(ca.experience,"") AS experience
        FROM resultats_matching rm
        JOIN candidats c ON c.id = rm.candidat_id
        LEFT JOIN cv_analyses ca ON ca.candidat_id = c.id
        WHERE rm.recherche_id = ?
        ORDER BY rm.score DESC
    ');
    $stRM->execute([$dernRech['id']]); $dernResultats = $stRM->fetchAll();
}

// Stats
$nbCandidats = $pdo->query('SELECT COUNT(*) FROM candidats WHERE actif=1')->fetchColumn();
$nbAvecCV    = $pdo->query('SELECT COUNT(*) FROM candidats WHERE cv_fichier IS NOT NULL')->fetchColumn();
$nbMessages  = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recruteur_id=?');
$nbMessages->execute([$id]); $nbMessages = $nbMessages->fetchColumn();

$csrf  = csrfToken();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Recruteur — RecrutSmart</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;
    min-height:100vh;display:flex;flex-direction:column}

  /* ── HEADER ── */
  .header{background:#161929;border-bottom:1px solid #252a40;
    padding:.9rem 1.8rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
  .brand-name{font-size:1.15rem;font-weight:800;color:#fff;letter-spacing:-.2px}
  .brand-name span{color:#00c9a7}
  .header-left{display:flex;flex-direction:column}
  .welcome-title{font-size:1.1rem;font-weight:700;color:#fff}
  .welcome-title span{color:#00c9a7}
  .welcome-sub{font-size:.8rem;color:#7a859a;margin-top:.1rem}
  .header-right{display:flex;align-items:center;gap:1rem}
  .btn-deconnexion{padding:.45rem 1.1rem;background:transparent;border:1.5px solid #ef4444;
    border-radius:8px;color:#ef4444;font-size:.85rem;font-weight:600;cursor:pointer;
    text-decoration:none;transition:background .2s,color .2s}
  .btn-deconnexion:hover{background:#ef4444;color:#fff}

  /* ── LAYOUT ── */
  .main{display:grid;grid-template-columns:280px 1fr;gap:1.2rem;
    padding:1.4rem 1.8rem;flex:1;min-height:0}

  /* ── SIDEBAR AGENT IA ── */
  .sidebar-ia{background:#161929;border:1px solid #252a40;border-radius:14px;
    display:flex;flex-direction:column;overflow:hidden;height:calc(100vh - 100px);position:sticky;top:1rem}
  .ia-header{padding:.9rem 1rem;border-bottom:1px solid #252a40;
    display:flex;align-items:center;gap:.6rem}
  .ia-plus{width:22px;height:22px;background:#00c9a7;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:.9rem;font-weight:700;color:#0d0f18;cursor:pointer;flex-shrink:0}
  .ia-title{font-weight:700;font-size:.95rem;color:#e2e8f0}
  .ia-messages{flex:1;overflow-y:auto;padding:.8rem;display:flex;flex-direction:column;gap:.7rem;
    scrollbar-width:thin;scrollbar-color:#252a40 transparent}
  .ia-bubble{display:flex;gap:.6rem;align-items:flex-start}
  .ia-bot-icon{width:36px;height:36px;border-radius:50%;
    background:linear-gradient(135deg,#00c9a7,#0ea5e9);
    display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
  .ia-text{background:#1e2235;border-radius:10px 10px 10px 2px;padding:.7rem .9rem;
    font-size:.83rem;color:#cbd5e1;line-height:1.5}
  .ia-text.user{background:#0a1f2d;color:#bae6fd;border-radius:10px 10px 2px 10px;margin-left:auto;max-width:85%}
  .ia-input-wrap{padding:.8rem;border-top:1px solid #252a40;display:flex;gap:.5rem}
  .ia-input{flex:1;background:#0d0f18;border:1.5px solid #252a40;border-radius:8px;
    padding:.55rem .8rem;color:#e2e8f0;font-size:.85rem;outline:none;font-family:inherit}
  .ia-input:focus{border-color:#00c9a7}
  .ia-send{background:linear-gradient(135deg,#00c9a7,#0ea5e9);border:none;border-radius:8px;
    width:34px;height:34px;display:flex;align-items:center;justify-content:center;
    cursor:pointer;flex-shrink:0;transition:opacity .2s}
  .ia-send:hover{opacity:.85}

  /* ── COLONNE DROITE ── */
  .right-col{display:flex;flex-direction:column;gap:1.2rem;overflow-y:auto}

  /* Barre de recherche */
  .search-bar-wrap{display:flex;gap:.8rem;align-items:center}
  .search-bar{flex:1;background:#161929;border:1.5px solid #252a40;border-radius:10px;
    padding:.82rem 1.1rem;color:#e2e8f0;font-size:.95rem;outline:none;font-family:inherit;
    transition:border-color .2s}
  .search-bar:focus{border-color:#00c9a7}
  .search-bar::placeholder{color:#4a5568}
  .btn-search{background:linear-gradient(135deg,#00c9a7,#0ea5e9);border:none;
    border-radius:10px;width:46px;height:46px;display:flex;align-items:center;
    justify-content:center;cursor:pointer;flex-shrink:0;transition:opacity .2s}
  .btn-search:hover{opacity:.88}
  .btn-search svg{width:20px;height:20px}

  /* Stats */
  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.8rem}
  .stat-card{background:#161929;border:1px solid #252a40;border-radius:12px;
    padding:1rem;text-align:center}
  .stat-num{font-size:1.6rem;font-weight:800;color:#00c9a7}
  .stat-label{font-size:.78rem;color:#7a859a;margin-top:.2rem}

  /* Résultats candidats */
  .results-header{font-size:.85rem;color:#7a859a;margin-bottom:.8rem}
  .cand-card{background:#161929;border:1px solid #252a40;border-radius:12px;
    padding:1.1rem 1.2rem;display:flex;align-items:center;gap:1rem;
    transition:border-color .2s}
  .cand-card:hover{border-color:#334155}
  .cand-avatar{width:46px;height:46px;border-radius:50%;
    background:linear-gradient(135deg,#6366f1,#0ea5e9);
    display:flex;align-items:center;justify-content:center;
    font-weight:700;font-size:1rem;color:#fff;flex-shrink:0}
  .cand-info{flex:1;min-width:0}
  .cand-name{font-weight:700;font-size:.95rem;margin-bottom:.15rem}
  .cand-meta{font-size:.8rem;color:#7a859a;margin-bottom:.3rem}
  .cand-meta span{color:#334155;margin:0 .3rem}
  .cand-skills{font-size:.78rem;color:#94a3b8}
  .cand-right{display:flex;flex-direction:column;align-items:flex-end;gap:.6rem;flex-shrink:0}
  .score-badge{background:rgba(0,201,167,.12);color:#00c9a7;
    border:1px solid rgba(0,201,167,.3);border-radius:20px;
    padding:.35rem .9rem;font-size:.82rem;font-weight:700;white-space:nowrap}
  .btn-contacter{background:transparent;border:1.5px solid #00c9a7;color:#00c9a7;
    border-radius:8px;padding:.45rem 1rem;font-size:.82rem;font-weight:700;
    cursor:pointer;white-space:nowrap;transition:background .2s,color .2s}
  .btn-contacter:hover{background:#00c9a7;color:#0d0f18}

  .empty-state{background:#161929;border:1px solid #252a40;border-radius:12px;
    padding:2.5rem;text-align:center;color:#7a859a;font-size:.9rem}
  .loading{text-align:center;padding:2rem;color:#7a859a;font-size:.9rem}

  /* ── MODAL CONTACT ── */
  .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:100;
    display:flex;align-items:center;justify-content:center;padding:1.5rem}
  .modal{background:#161929;border:1px solid #252a40;border-radius:16px;
    width:100%;max-width:560px}
  .modal-header{padding:1.2rem 1.5rem;border-bottom:1px solid #252a40;
    display:flex;justify-content:space-between;align-items:center}
  .modal-title{font-weight:700;font-size:1rem;color:#fff}
  .modal-close{background:none;border:none;color:#7a859a;font-size:1.3rem;
    cursor:pointer;padding:0;transition:color .2s}
  .modal-close:hover{color:#fff}
  .modal-body{padding:1.5rem;display:flex;flex-direction:column;gap:1rem}
  .modal-label{font-size:.83rem;font-weight:600;color:#a0aec0;
    text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem}
  .modal-input{width:100%;background:#0d0f18;border:1.5px solid #252a40;
    border-radius:9px;padding:.7rem 1rem;color:#e2e8f0;font-size:.9rem;
    outline:none;transition:border-color .2s;font-family:inherit}
  .modal-input:focus{border-color:#00c9a7}
  textarea.modal-input{resize:vertical;min-height:120px;line-height:1.6}
  .rdv-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
  .modal-footer{padding:1rem 1.5rem;border-top:1px solid #252a40;
    display:flex;justify-content:flex-end;gap:.75rem}
  .btn-annuler{background:transparent;border:1.5px solid #252a40;color:#7a859a;
    border-radius:8px;padding:.6rem 1.2rem;cursor:pointer;font-size:.9rem;font-weight:600;
    transition:border-color .2s}
  .btn-annuler:hover{border-color:#7a859a}
  .btn-envoyer{background:linear-gradient(135deg,#00c9a7,#0ea5e9);border:none;
    border-radius:8px;padding:.6rem 1.4rem;color:#fff;font-size:.9rem;
    font-weight:700;cursor:pointer;transition:opacity .2s}
  .btn-envoyer:hover{opacity:.88}
  .msg-ok{background:rgba(0,201,167,.1);border:1px solid #00c9a7;border-radius:10px;
    padding:.9rem 1.1rem;color:#00c9a7;font-size:.88rem;text-align:center;margin:.5rem 0}
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="header-left">
    <div class="welcome-title">
      Bonjour <span><?= clean($_SESSION['prenom'].' '.$_SESSION['nom']) ?></span>
    </div>
    <div class="welcome-sub"><?= clean($recruteur['entreprise']) ?></div>
  </div>
  <div class="header-right">
    <span class="brand-name">Recrut<span>Smart</span> ★</span>
    <a href="../auth/logout.php" class="btn-deconnexion">Déconnexion</a>
  </div>
</div>

<!-- MAIN -->
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
      <div class="ia-plus" onclick="nouvelleConv()" title="Nouvelle conversation">+</div>
      <span class="ia-title">Agent IA</span>
    </div>
    <div class="ia-messages" id="ia-messages">
      <div class="ia-bubble">
        <div class="ia-bot-icon">🤖</div>
        <div class="ia-text">Bonjour, comment puis-je vous aider ?</div>
      </div>
      <?php foreach ($histoIA as $h): ?>
        <?php if ($h['role'] === 'user'): ?>
          <div class="ia-bubble" style="justify-content:flex-end">
            <div class="ia-text user"><?= clean($h['message']) ?></div>
          </div>
        <?php else: ?>
          <div class="ia-bubble">
            <div class="ia-bot-icon">🤖</div>
            <div class="ia-text"><?= clean($h['message']) ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="ia-input-wrap">
      <input type="text" class="ia-input" id="ia-input"
        placeholder="Écrire un message…" autocomplete="off">
      <button class="ia-send" id="ia-send">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"
          stroke-linecap="round" stroke-linejoin="round">
          <line x1="22" y1="2" x2="11" y2="13"/>
          <polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- ── COLONNE DROITE ── -->
  <div class="right-col">

    <!-- Barre de recherche -->
    <div class="search-bar-wrap">
      <input type="text" class="search-bar" id="search-input"
        placeholder="Rechercher des candidats…"
        value="<?= clean($dernRequete) ?>">
      <button class="btn-search" id="btn-search" title="Lancer la recherche IA">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"
          stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
      </button>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-num"><?= $nbCandidats ?></div>
        <div class="stat-label">Candidats inscrits</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $nbAvecCV ?></div>
        <div class="stat-label">CV disponibles</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $nbMessages ?></div>
        <div class="stat-label">Messages envoyés</div>
      </div>
    </div>

    <!-- Résultats -->
    <div id="resultats-wrap">
      <?php if ($dernResultats): ?>
        <div class="results-header">
          <?= count($dernResultats) ?> profil<?= count($dernResultats)>1?'s':'' ?>
          correspondant à : <em>"<?= clean($dernRequete) ?>"</em>
        </div>
        <?php foreach ($dernResultats as $r): ?>
          <div class="cand-card" style="margin-bottom:.8rem">
            <div class="cand-avatar">
              <?= strtoupper(substr($r['prenom'],0,1).substr($r['nom'],0,1)) ?>
            </div>
            <div class="cand-info">
              <div class="cand-name"><?= clean($r['prenom'].' '.$r['nom']) ?></div>
              <div class="cand-meta">
                <?= clean($r['experience'] ?: 'Expérience non précisée') ?>
                <span>|</span><?= clean($r['ville']) ?>
              </div>
              <div class="cand-skills">
                <?= clean($r['competences'] ?: 'Compétences en cours d\'analyse…') ?>
              </div>
              <?php if ($r['resume_ia']): ?>
                <div style="font-size:.76rem;color:#4a5568;margin-top:.3rem;font-style:italic">
                  <?= clean(mb_substr($r['resume_ia'],0,120)).'…' ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="cand-right">
              <span class="score-badge">Score de correspondance : <?= $r['score'] ?>%</span>
              <button class="btn-contacter"
                onclick="ouvrirModal(<?= $r['cand_id'] ?>, '<?= clean($r['prenom'].' '.$r['nom']) ?>')">
                Contacter Candidat
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          🔍 Tapez une recherche pour trouver les candidats les plus adaptés.<br>
          <small style="font-size:.82rem;margin-top:.4rem;display:block">
            Ex : "Développeur PHP avec 2 ans d'expérience, disponible à Abidjan"
          </small>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /right-col -->
</div><!-- /main -->

<!-- ── MODAL CONTACTER CANDIDAT ── -->
<div class="modal-overlay" id="modal-contact" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="modal-titre">Contacter le candidat</span>
      <button class="modal-close" onclick="fermerModal()">✕</button>
    </div>
    <form method="POST" action="/recrutsmart/actions/send-message.php" id="form-contact">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="candidat_id" id="modal-cand-id">
      <div class="modal-body">
        <div>
          <div class="modal-label">Objet</div>
          <input type="text" name="sujet" id="modal-sujet" class="modal-input"
            placeholder="Invitation à un entretien — [Poste]">
        </div>
        <div>
          <div class="modal-label">Message</div>
          <textarea name="corps" id="modal-corps" class="modal-input"
            placeholder="Bonjour,&#10;&#10;Nous avons bien étudié votre profil et nous serions ravis de vous rencontrer pour discuter d'une opportunité au sein de notre entreprise.&#10;&#10;Cordialement,&#10;<?= clean($recruteur['entreprise']) ?>"></textarea>
        </div>
        <div>
          <div class="modal-label">Date et heure du rendez-vous</div>
          <div class="rdv-row">
            <input type="date" name="heure_rdv_date" id="rdv-date" class="modal-input">
            <input type="time" name="heure_rdv_heure" id="rdv-heure" class="modal-input">
          </div>
        </div>
        <input type="hidden" name="poste" id="modal-poste"
          value="<?= clean($dernRequete ?: 'Poste à définir') ?>">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-annuler" onclick="fermerModal()">Annuler</button>
        <button type="submit" class="btn-envoyer">✉️ Envoyer le message</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Agent IA ──────────────────────────────────────────────────
const iaMsgs  = document.getElementById('ia-messages');
const iaInput = document.getElementById('ia-input');
const iaSend  = document.getElementById('ia-send');

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
function escTexte(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function ajouterUser(txt) {
  const d = document.createElement('div');
  d.className = 'ia-bubble'; d.style.justifyContent = 'flex-end';
  d.innerHTML = `<div class="ia-text user">${escTexte(txt)}</div>`;
  iaMsgs.appendChild(d); scrollIA();
}
function ajouterBot(txt) {
  const d = document.createElement('div');
  d.className = 'ia-bubble';
  d.innerHTML = `<div class="ia-bot-icon">🤖</div><div class="ia-text">${escTexte(txt)}</div>`;
  iaMsgs.appendChild(d); scrollIA();
}
function nouvelleConv() {
  iaMsgs.innerHTML = '';
  ajouterBot('Nouvelle conversation démarrée. Comment puis-je vous aider ?');
}

async function envoyerIA() {
  const msg = iaInput.value.trim();
  if (!msg) return;
  iaInput.value = '';
  ajouterUser(msg);

  // Récupérer le contexte des derniers résultats
  const contexte = document.getElementById('resultats-wrap')?.innerText?.slice(0, 500) || '';

  try {
    const fd = new FormData();
    fd.append('action',      'agent');
    fd.append('message',     msg);
    fd.append('contexte',    contexte);
    fd.append('csrf_token',  '<?= $csrf ?>');

    const res  = await fetch('/recrutsmart/actions/ia-proxy.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.erreur) {
      ajouterBot('⚠️ ' + escTexte(data.erreur));
    } else {
      ajouterBot(escTexte(data.reponse));
    }
  } catch {
    ajouterBot('Le service IA est momentanément indisponible.');
  }
}
iaSend.addEventListener('click', envoyerIA);
iaInput.addEventListener('keydown', e => { if (e.key === 'Enter') envoyerIA(); });

// Navigation sécurisée — approche moderne
const SESSION_KEY = 'rs_session_active';

if (!sessionStorage.getItem(SESSION_KEY)) {
  window.location.replace('/recrutsmart/auth/login.php');
} else {
  fetch('/recrutsmart/actions/check-session.php', { cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      if (!data.connecte) {
        sessionStorage.removeItem(SESSION_KEY);
        window.location.replace('/recrutsmart/auth/login.php');
      }
    });
}

history.pushState(null, '', window.location.href);
window.addEventListener('popstate', () => {
  fetch('/recrutsmart/actions/check-session.php', { cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      if (!data.connecte) {
        sessionStorage.removeItem(SESSION_KEY);
        window.location.replace('/recrutsmart/auth/login.php');
      } else {
        history.pushState(null, '', window.location.href);
      }
    });
});

// ── Recherche IA ──────────────────────────────────────────────
document.getElementById('btn-search').addEventListener('click', lancerRecherche);
document.getElementById('search-input').addEventListener('keydown', e => {
  if (e.key === 'Enter') lancerRecherche();
});

async function lancerRecherche() {
  const q    = document.getElementById('search-input').value.trim();
  if (!q) return;
  const wrap = document.getElementById('resultats-wrap');

  wrap.innerHTML = `<div class="loading">
    <div style="text-align:center;padding:2rem;color:#7a859a">
      <div style="font-size:1.5rem;margin-bottom:.5rem">🔍</div>
      Analyse des CV en cours…
    </div>
  </div>`;

  try {
    const fd = new FormData();
    fd.append('action',     'analyser');
    fd.append('requete',    q);
    fd.append('csrf_token', '<?= $csrf ?>');

    const res  = await fetch('/recrutsmart/actions/ia-proxy.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.erreur) {
      wrap.innerHTML = `<div class="empty-state">⚠️ ${escTexte(data.erreur)}</div>`;
      return;
    }

    if (!data.resultats || data.resultats.length === 0) {
      wrap.innerHTML = `<div class="empty-state">
        ${escTexte(data.message || 'Aucune correspondance trouvée pour le profil recherché.')}
      </div>`;
      return;
    }

    // Afficher les résultats
    let html = `<div class="results-header">
      Profils correspondant à votre recherche
    </div>`;

    data.resultats.forEach(r => {
      const initiales = ((r.prenom[0] || '') + (r.nom[0] || '')).toUpperCase();
      const cvUrl     = r.cv_fichier ? `/recrutsmart/uploads/${r.cv_fichier}` : '';

      html += `<div class="cand-card" style="margin-bottom:.8rem">
        <div class="cand-avatar">${initiales}</div>
        <div class="cand-info">
          <div class="cand-name">${escHtml(r.prenom + ' ' + r.nom)}</div>
          <div class="cand-meta">
            ${escHtml(r.experience || 'Expérience non précisée')}
            <span>|</span>${escHtml(r.ville)}
          </div>
          <div class="cand-skills">${escHtml(r.competences || '')}</div>
          ${r.resume_ia ? `<div style="font-size:.82rem;color:#94a3b8;margin-top:.4rem;line-height:1.5">
            ${escHtml(r.resume_ia)}
          </div>` : ''}
        </div>
        <div class="cand-right">
          <span class="score-badge">${r.score}% de correspondance</span>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;justify-content:flex-end">
            ${cvUrl ? `<a href="${cvUrl}" target="_blank"
              style="background:transparent;border:1.5px solid #7a859a;color:#94a3b8;
              border-radius:8px;padding:.45rem 1rem;font-size:.82rem;font-weight:700;
              text-decoration:none;transition:border-color .2s"
              onmouseover="this.style.borderColor='#00c9a7';this.style.color='#00c9a7'"
              onmouseout="this.style.borderColor='#7a859a';this.style.color='#94a3b8'">
              Voir CV
            </a>` : ''}
            <button class="btn-contacter"
              onclick="ouvrirModal(${r.candidat_id}, '${r.prenom} ${r.nom}')">
              Contacter
            </button>
          </div>
        </div>
      </div>`;
    });

    wrap.innerHTML = html;

  } catch {
    wrap.innerHTML = `<div class="empty-state">
      ⚠️ Le service IA est momentanément indisponible.
    </div>`;
  }
}

// ── Modal contacter candidat ──────────────────────────────────
function ouvrirModal(candidatId, nom) {
  document.getElementById('modal-cand-id').value = candidatId;
  document.getElementById('modal-titre').textContent = 'Contacter ' + nom;
  document.getElementById('modal-sujet').value =
    'Invitation à un entretien — <?= clean($recruteur['entreprise']) ?>';
  document.getElementById('modal-contact').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function fermerModal() {
  document.getElementById('modal-contact').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('modal-contact').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-contact')) fermerModal();
});
</script>
</body>
</html>