<?php
require_once __DIR__ . '/../config/db.php';
requireLogin();
if ($_SESSION['role'] !== 'recruteur') {
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

$id        = (int)$_SESSION['user_id'];
$recruteur = $pdo->prepare('SELECT * FROM recruteurs WHERE id=?');
$recruteur->execute([$id]); $recruteur = $recruteur->fetch();

$stIA = $pdo->prepare('
    SELECT role, message FROM conversations_agent
    WHERE user_id=? AND user_role="recruteur"
    ORDER BY cree_le ASC LIMIT 50
');
$stIA->execute([$id]); $histoIA = $stIA->fetchAll();

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
               COALESCE(ca.experience,"") AS experience,
               COALESCE(ca.formation,"") AS formation
        FROM resultats_matching rm
        JOIN candidats c ON c.id = rm.candidat_id
        LEFT JOIN cv_analyses ca ON ca.candidat_id = c.id
        WHERE rm.recherche_id = ?
        ORDER BY rm.score DESC
    ');
    $stRM->execute([$dernRech['id']]); $dernResultats = $stRM->fetchAll();
}

$nbCandidats = $pdo->query('SELECT COUNT(*) FROM candidats WHERE actif=1')->fetchColumn();
$nbAvecCV    = $pdo->query('SELECT COUNT(*) FROM candidats WHERE cv_fichier IS NOT NULL')->fetchColumn();
$nbMessages  = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recruteur_id=?');
$nbMessages->execute([$id]); $nbMessages = $nbMessages->fetchColumn();

// URL de base pour les CV — fonctionne en local ET en hébergement
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST'] . '/recrutsmart';

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
body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d0f18;color:#e2e8f0;min-height:100vh;display:flex;flex-direction:column}

/* ── HEADER ── */
.header{background:#161929;border-bottom:1px solid #252a40;padding:.8rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;gap:.8rem;flex-wrap:wrap}
.brand-name{font-size:1.1rem;font-weight:800;color:#fff}
.brand-name span{color:#00c9a7}
.header-left{display:flex;flex-direction:column;min-width:0}
.welcome-title{font-size:1rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.welcome-title span{color:#00c9a7}
.welcome-sub{font-size:.78rem;color:#7a859a;margin-top:.1rem}
.header-right{display:flex;align-items:center;gap:.8rem;flex-shrink:0}
.btn-deconnexion{padding:.4rem .9rem;background:transparent;border:1.5px solid #ef4444;border-radius:8px;color:#ef4444;font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s,color .2s;white-space:nowrap}
.btn-deconnexion:hover{background:#ef4444;color:#fff}

/* ── LAYOUT DESKTOP ── */
.main{display:grid;grid-template-columns:260px 1fr;gap:1rem;padding:1rem 1.2rem;flex:1;min-height:0}

/* ── SIDEBAR IA ── */
.sidebar-ia{background:#161929;border:1px solid #252a40;border-radius:12px;display:flex;flex-direction:column;overflow:hidden;height:calc(100vh - 100px);position:sticky;top:1rem}
.ia-header{padding:.8rem 1rem;border-bottom:1px solid #252a40;display:flex;align-items:center;gap:.6rem}
.ia-plus{width:22px;height:22px;background:#00c9a7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:700;color:#0d0f18;cursor:pointer;flex-shrink:0}
.ia-title{font-weight:700;font-size:.9rem;color:#e2e8f0}
.ia-messages{flex:1;overflow-y:auto;padding:.7rem;display:flex;flex-direction:column;gap:.6rem;scrollbar-width:thin;scrollbar-color:#252a40 transparent}
.ia-bubble{display:flex;gap:.5rem;align-items:flex-start}
.ia-bot-icon{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#00c9a7,#0ea5e9);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.ia-text{background:#1e2235;border-radius:10px 10px 10px 2px;padding:.6rem .8rem;font-size:.82rem;color:#cbd5e1;line-height:1.5;max-width:85%}
.ia-text.user{background:#0a1f2d;color:#bae6fd;border-radius:10px 10px 2px 10px;margin-left:auto}
.ia-input-wrap{padding:.7rem;border-top:1px solid #252a40;display:flex;gap:.5rem}
.ia-input{flex:1;background:#0d0f18;border:1.5px solid #252a40;border-radius:8px;padding:.5rem .7rem;color:#e2e8f0;font-size:.82rem;outline:none;font-family:inherit}
.ia-input:focus{border-color:#00c9a7}
.ia-send{background:linear-gradient(135deg,#00c9a7,#0ea5e9);border:none;border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:opacity .2s}
.ia-send:hover{opacity:.85}

/* ── COLONNE DROITE ── */
.right-col{display:flex;flex-direction:column;gap:1rem;overflow-y:auto;min-width:0}

/* Barre de recherche */
.search-wrap{display:flex;flex-direction:column;gap:.4rem}
.search-hint{font-size:.78rem;color:#7a859a}

/* ── INDICATEUR MICROSERVICE ── */
.ia-status{display:flex;align-items:center;gap:.45rem;font-size:.75rem;color:#7a859a;margin-left:auto}
.ia-dot{width:9px;height:9px;border-radius:50%;background:#ef4444;flex-shrink:0;transition:background .4s}
.ia-dot.actif{background:#00c9a7;animation:pulse-dot 2s infinite}
.ia-dot.recherche{background:#eab308;animation:pulse-dot .6s infinite}
@keyframes pulse-dot{
  0%,100%{box-shadow:0 0 0 0 rgba(0,201,167,.5)}
  50%{box-shadow:0 0 0 5px rgba(0,201,167,0)}
}
.search-hint strong{color:#00c9a7}
.search-bar-wrap{display:flex;gap:.6rem;align-items:center}
.search-bar{flex:1;background:#161929;border:1.5px solid #252a40;border-radius:10px;padding:.75rem 1rem;color:#e2e8f0;font-size:.92rem;outline:none;font-family:inherit;transition:border-color .2s;min-width:0}
.search-bar:focus{border-color:#00c9a7}
.search-bar::placeholder{color:#4a5568}
.btn-search{background:linear-gradient(135deg,#00c9a7,#0ea5e9);border:none;border-radius:10px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;transition:opacity .2s}
.btn-search:hover{opacity:.88}
.btn-search svg{width:18px;height:18px}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:.7rem}
.stat-card{background:#161929;border:1px solid #252a40;border-radius:10px;padding:.8rem;text-align:center}
.stat-num{font-size:1.5rem;font-weight:800;color:#00c9a7}
.stat-label{font-size:.75rem;color:#7a859a;margin-top:.2rem}

/* Résultats */
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.cand-card{background:#161929;border:1px solid #252a40;border-radius:12px;padding:1rem;display:flex;gap:.8rem;transition:border-color .2s;animation:fadeIn .3s ease}
.cand-card:hover{border-color:#334155}
.cand-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#0ea5e9);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;color:#fff;flex-shrink:0}
.cand-info{flex:1;min-width:0}
.cand-name{font-weight:700;font-size:.92rem;margin-bottom:.12rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cand-meta{font-size:.78rem;color:#7a859a;margin-bottom:.25rem}
.cand-meta span{color:#334155;margin:0 .25rem}
.cand-skills{font-size:.76rem;color:#94a3b8}
.cand-right{display:flex;flex-direction:column;align-items:flex-end;gap:.5rem;flex-shrink:0;min-width:110px}
.score-badge{border-radius:20px;padding:.3rem .75rem;font-size:.78rem;font-weight:700;white-space:nowrap;text-align:center}
.btn-contacter{background:transparent;border:1.5px solid #00c9a7;color:#00c9a7;border-radius:8px;padding:.4rem .8rem;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .2s,color .2s}
.btn-contacter:hover{background:#00c9a7;color:#0d0f18}
.empty-state{background:#161929;border:1px solid #252a40;border-radius:12px;padding:2rem;text-align:center;color:#7a859a;font-size:.88rem}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:100;display:flex;align-items:center;justify-content:center;padding:1rem}
.modal{background:#161929;border:1px solid #252a40;border-radius:14px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto}
.modal-header{padding:1rem 1.2rem;border-bottom:1px solid #252a40;display:flex;justify-content:space-between;align-items:center}
.modal-title{font-weight:700;font-size:.95rem;color:#fff}
.modal-close{background:none;border:none;color:#7a859a;font-size:1.2rem;cursor:pointer;padding:0}
.modal-body{padding:1.2rem;display:flex;flex-direction:column;gap:.9rem}
.modal-label{font-size:.8rem;font-weight:600;color:#a0aec0;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem}
.modal-input{width:100%;background:#0d0f18;border:1.5px solid #252a40;border-radius:8px;padding:.65rem .9rem;color:#e2e8f0;font-size:.88rem;outline:none;transition:border-color .2s;font-family:inherit}
.modal-input:focus{border-color:#00c9a7}
textarea.modal-input{resize:vertical;min-height:110px;line-height:1.6}
.rdv-row{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
.modal-footer{padding:.9rem 1.2rem;border-top:1px solid #252a40;display:flex;justify-content:flex-end;gap:.6rem}
.btn-annuler{background:transparent;border:1.5px solid #252a40;color:#7a859a;border-radius:8px;padding:.55rem 1rem;cursor:pointer;font-size:.85rem;font-weight:600}
.btn-envoyer{background:linear-gradient(135deg,#00c9a7,#0ea5e9);border:none;border-radius:8px;padding:.55rem 1.2rem;color:#fff;font-size:.85rem;font-weight:700;cursor:pointer}

/* ── RESPONSIVE TABLETTE ── */
@media(max-width:900px){
  .main{grid-template-columns:220px 1fr;gap:.8rem;padding:.8rem}
}

/* ── RESPONSIVE MOBILE ── */
@media(max-width:768px){
  .main{grid-template-columns:1fr;padding:.8rem;gap:.8rem}
  .sidebar-ia{height:auto;min-height:280px;max-height:340px;position:static;order:2}
  .ia-messages{max-height:180px}
  .right-col{order:1}
  .stats-row{grid-template-columns:repeat(3,1fr);gap:.5rem}
  .stat-num{font-size:1.2rem}
  .stat-label{font-size:.68rem}
  .cand-card{flex-wrap:wrap}
  .cand-right{flex-direction:row;align-items:center;flex-wrap:wrap;min-width:unset;width:100%;gap:.4rem;margin-top:.3rem}
  .score-badge{font-size:.72rem}
  .rdv-row{grid-template-columns:1fr}
  .header{padding:.7rem 1rem}
  .welcome-title{font-size:.92rem}
}

@media(max-width:480px){
  .main{padding:.6rem;gap:.6rem}
  .stats-row{grid-template-columns:1fr 1fr}
  .search-bar{font-size:.85rem;padding:.65rem .8rem}
  .cand-name{font-size:.85rem}
  .modal{border-radius:10px}
  .sidebar-ia{min-height:240px;max-height:300px}
  .ia-messages{max-height:150px}
  .ia-input{font-size:.78rem}
  .search-hint{font-size:.72rem}
}

@media(max-width:360px){
  .stats-row{grid-template-columns:1fr}
  .main{padding:.5rem}
  .header{padding:.6rem .8rem}
  .welcome-title{font-size:.85rem}
  .brand-name{font-size:.95rem}
}
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
    <span class="brand-name">Recrut<span>Smart</span></span>
    <a href="../auth/logout.php" class="btn-deconnexion">Déconnexion</a>
  </div>
</div>

<!-- MAIN -->
<div class="main">
<?php if ($flash): ?>
  <div id="flash-msg" style="grid-column:1/-1;padding:.8rem 1.1rem;border-radius:10px;font-size:.88rem;font-weight:500;margin-bottom:.5rem;display:flex;align-items:center;justify-content:space-between;
    <?= $flash['type']==='success'
      ? 'background:rgba(0,201,167,.1);color:#00c9a7;border:1px solid rgba(0,201,167,.3);'
      : 'background:rgba(239,68,68,.1);color:#fca5a5;border:1px solid rgba(239,68,68,.25);' ?>">
    <span><?= clean($flash['message']) ?></span>
    <button onclick="document.getElementById('flash-msg').remove()" style="background:none;border:none;cursor:pointer;color:inherit;font-size:1rem;padding:0">✕</button>
  </div>
  <script>setTimeout(()=>{document.getElementById('flash-msg')?.remove()},5000)</script>
<?php endif; ?>

  <!-- SIDEBAR IA -->
  <div class="sidebar-ia">
    <div class="ia-header">
      <div class="ia-plus" onclick="nouvelleConv()" title="Nouvelle conversation">+</div>
      <span class="ia-title">RecrutSmart IA</span>
      <div class="ia-status" id="ia-status">
        <div class="ia-dot" id="ia-dot"></div>
        <span id="ia-status-txt">Vérification...</span>
      </div>
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
      <input type="text" class="ia-input" id="ia-input" placeholder="Écrire un message…" autocomplete="off">
      <button class="ia-send" id="ia-send">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
          <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- COLONNE DROITE -->
  <div class="right-col">

    <!-- Barre de recherche IA -->
    <div class="search-wrap">
      <div class="search-hint">
        💡 Tapez un métier précis — Ex : <strong>Développeur fullstack</strong>, <strong>Comptable 3 ans Abidjan</strong>, <strong>Mécanicien automobile</strong>
      </div>
      <div class="search-bar-wrap">
        <input type="text" class="search-bar" id="search-input"
          placeholder="Ex : Développeur fullstack, Mécanicien, Comptable 2 ans..."
          value="<?= clean($dernRequete) ?>">
        <button class="btn-search" id="btn-search" title="Lancer la recherche">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
        </button>
      </div>
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
        <div style="color:#00c9a7;font-weight:700;font-size:.85rem;margin-bottom:.5rem;padding:.35rem 0;border-bottom:1px solid #252a40">
          ✔ Résultats (<?= count($dernResultats) ?>)
        </div>
        <?php foreach ($dernResultats as $r): ?>
          <?php $cvUrl = $r['cv_fichier'] ? $base_url.'/uploads/'.$r['cv_fichier'] : ''; ?>
          <div class="cand-card" style="margin-bottom:.7rem">
            <div class="cand-avatar"><?= strtoupper(substr($r['prenom'],0,1).substr($r['nom'],0,1)) ?></div>
            <div class="cand-info">
              <div class="cand-name"><?= clean($r['prenom'].' '.$r['nom']) ?></div>
              <div class="cand-meta"><?= clean($r['ville']) ?><?php if($r['experience']): ?><span>|</span><?= clean($r['experience']) ?><?php endif; ?></div>
              <?php if($r['competences']): ?><div class="cand-skills"><?= clean($r['competences']) ?></div><?php endif; ?>
              <?php if($r['formation']): ?><div style="font-size:.74rem;color:#7a859a;margin-top:.18rem">🎓 <?= clean($r['formation']) ?></div><?php endif; ?>
            </div>
            <div class="cand-right">
              <span class="score-badge" style="background:rgba(0,201,167,.12);color:#00c9a7;border:1px solid rgba(0,201,167,.3)"><?= $r['score'] ?>%</span>
              <?php if($cvUrl): ?>
                <a href="<?= $cvUrl ?>" target="_blank" style="background:transparent;border:1.5px solid #7a859a;color:#94a3b8;border-radius:7px;padding:.32rem .7rem;font-size:.72rem;font-weight:700;text-decoration:none;white-space:nowrap">Voir CV</a>
              <?php endif; ?>
              <button class="btn-contacter" onclick="ouvrirModal(<?= $r['cand_id'] ?>,'<?= clean($r['prenom'].' '.$r['nom']) ?>')">Contacter</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          🔍 Tapez un métier pour trouver les candidats correspondants.<br>
          <small style="font-size:.8rem;margin-top:.4rem;display:block">Ex : "Développeur PHP", "Comptable 3 ans Abidjan", "Mécanicien automobile"</small>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- MODAL CONTACT -->
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
          <input type="text" name="sujet" id="modal-sujet" class="modal-input" placeholder="Invitation à un entretien">
        </div>
        <div>
          <div class="modal-label">Message</div>
          <textarea name="corps" class="modal-input" placeholder="Bonjour,&#10;&#10;Nous avons étudié votre profil...&#10;&#10;Cordialement,&#10;<?= clean($recruteur['entreprise']) ?>"></textarea>
        </div>
        <div>
          <div class="modal-label">Date et heure du rendez-vous</div>
          <div class="rdv-row">
            <input type="date" name="heure_rdv_date" class="modal-input">
            <input type="time" name="heure_rdv_heure" class="modal-input">
          </div>
        </div>
        <input type="hidden" name="poste" value="<?= clean($dernRequete?:'Poste à définir') ?>">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-annuler" onclick="fermerModal()">Annuler</button>
        <button type="submit" class="btn-envoyer">✉️ Envoyer</button>
      </div>
    </form>
  </div>
</div>

<script>
// URL de base pour les CV — local ET hébergement
const BASE_URL = '<?= $base_url ?>';

const iaMsgs  = document.getElementById('ia-messages');
const iaInput = document.getElementById('ia-input');
const iaSend  = document.getElementById('ia-send');

function scrollIA(){ iaMsgs.scrollTop = iaMsgs.scrollHeight; }
scrollIA();

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escTexte(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Nettoie une réponse IA avant affichage — supprime code, JSON brut, erreurs techniques
function nettoyerReponseIA(txt) {
  if (!txt) return '';
  // Si ça ressemble à du JSON brut → message générique
  const t = txt.trim();
  if ((t.startsWith('{') && t.endsWith('}')) || (t.startsWith('[') && t.endsWith(']'))) {
    return 'Je rencontre une difficulté. Pouvez-vous reformuler votre question ?';
  }
  // Supprimer les blocs de code markdown
  txt = txt.replace(/```[\s\S]*?```/g, '');
  txt = txt.replace(/`[^`]*`/g, '');
  // Supprimer les balises HTML
  txt = txt.replace(/<[^>]+>/g, '');
  // Supprimer les lignes d'erreur technique
  const lignes = txt.split('\n').filter(l => {
    const ll = l.toLowerCase();
    return !['traceback', 'error:', 'exception:', 'warning:', 'undefined', 'at line', 'stack trace', 'php '].some(m => ll.includes(m));
  });
  txt = lignes.join('\n').trim();
  return txt || 'Je rencontre une difficulté. Pouvez-vous reformuler votre question ?';
}

function ajouterUser(txt){
  const d=document.createElement('div'); d.className='ia-bubble'; d.style.justifyContent='flex-end';
  d.innerHTML=`<div class="ia-text user">${escTexte(txt)}</div>`;
  iaMsgs.appendChild(d); scrollIA();
}
function ajouterBot(txt){
  const d=document.createElement('div'); d.className='ia-bubble';
  d.innerHTML=`<div class="ia-bot-icon">🤖</div><div class="ia-text">${escTexte(txt)}</div>`;
  iaMsgs.appendChild(d); scrollIA();
}
function ajouterBotLoading(){
  const d=document.createElement('div'); d.className='ia-bubble'; d.id='ia-loading';
  d.innerHTML=`<div class="ia-bot-icon">🤖</div><div class="ia-text" style="color:#7a859a">
    <span id="ia-spinner">⠋</span> En train de réfléchir...</div>`;
  iaMsgs.appendChild(d); scrollIA();
  const sp=['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
  let i=0;
  return setInterval(()=>{ const el=document.getElementById('ia-spinner'); if(el) el.textContent=sp[i=(i+1)%sp.length]; },80);
}
function nouvelleConv(){ iaMsgs.innerHTML=''; ajouterBot('Nouvelle conversation. Comment puis-je vous aider ?'); }

async function envoyerIA(){
  const msg=iaInput.value.trim(); if(!msg) return;
  iaInput.value=''; ajouterUser(msg);
  const interval=ajouterBotLoading();
  const contexte=document.getElementById('resultats-wrap')?.innerText?.slice(0,500)||'';
  try{
    const fd=new FormData();
    fd.append('action','agent'); fd.append('message',msg);
    fd.append('contexte',contexte); fd.append('csrf_token','<?= $csrf ?>');
    const res=await fetch('/recrutsmart/actions/ia-proxy.php',{method:'POST',body:fd});
    const data=await res.json();
    clearInterval(interval); document.getElementById('ia-loading')?.remove();
    if(data.erreur) ajouterBot('⚠️ '+escTexte(data.erreur));
    else ajouterBot(escTexte(nettoyerReponseIA(data.reponse)));
  }catch{
    clearInterval(interval); document.getElementById('ia-loading')?.remove();
    ajouterBot('RecrutSmart est momentanément indisponible, veuillez réessayer.');
  }
}
iaSend.addEventListener('click',envoyerIA);
iaInput.addEventListener('keydown',e=>{ if(e.key==='Enter') envoyerIA(); });

// Session sécurisée
const SESSION_KEY='rs_session_active';
if(!sessionStorage.getItem(SESSION_KEY)){ window.location.replace('/recrutsmart/auth/login.php'); }
else{
  fetch('/recrutsmart/actions/check-session.php',{cache:'no-store'}).then(r=>r.json()).then(d=>{
    if(!d.connecte){ sessionStorage.removeItem(SESSION_KEY); window.location.replace('/recrutsmart/auth/login.php'); }
  });
}
history.pushState(null,'',window.location.href);
window.addEventListener('popstate',()=>{
  fetch('/recrutsmart/actions/check-session.php',{cache:'no-store'}).then(r=>r.json()).then(d=>{
    if(!d.connecte){ sessionStorage.removeItem(SESSION_KEY); window.location.replace('/recrutsmart/auth/login.php'); }
    else history.pushState(null,'',window.location.href);
  });
});

// ── Animation recherche (ton animation gardée) ────────────────
function afficherAnimation(wrap) {
  const spinners = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
  let i = 0;
  wrap.innerHTML = `
    <div style="background:#161929;border:1px solid #252a40;border-radius:12px;padding:2rem;text-align:center">
      <div style="font-size:1.2rem;margin-bottom:0.5rem;color:#00c9a7" id="spinner">⠋</div>
      <div style="color:#7a859a;font-size:0.85rem">Recherche en cours...</div>
    </div>
  `;
  const spinnerEl = document.getElementById('spinner');
  const interval = setInterval(() => {
    i = (i + 1) % spinners.length;
    if (spinnerEl) spinnerEl.textContent = spinners[i];
  }, 100);
  return interval;
}

function badgeNiveau(niveau, score){
  if(niveau==='exact')    return `background:rgba(0,201,167,.15);color:#00c9a7;border:1px solid rgba(0,201,167,.4)`;
  if(niveau==='partiel')  return `background:rgba(234,179,8,.1);color:#eab308;border:1px solid rgba(234,179,8,.3)`;
  return `background:rgba(148,163,184,.1);color:#94a3b8;border:1px solid rgba(148,163,184,.2)`;
}

function construireCarte(r, niveau){
  const ini = ((r.prenom[0]||'') + (r.nom[0]||'')).toUpperCase();
  // Correction chemin CV — BASE_URL fonctionne local ET hébergement
  const cvUrl = r.cv_fichier ? BASE_URL + '/uploads/' + r.cv_fichier : '';
  const scoreLabel = niveau === 'exact' ? `${r.score}% — Exact` : (niveau === 'partiel' ? `${r.score}% — Suggestion` : `${r.score}% — Similaire`);
  const resume = r.resume_ia ? escTexte(nettoyerReponseIA(r.resume_ia)) : '';

  return `<div class="cand-card" style="margin-bottom:.7rem">
    <div class="cand-avatar">${ini}</div>
    <div class="cand-info">
      <div class="cand-name">${escHtml(r.prenom+' '+r.nom)}</div>
      <div class="cand-meta">${escHtml(r.ville||'')}${r.experience ? `<span>|</span>${escHtml(r.experience)}` : ''}</div>
      ${r.competences ? `<div class="cand-skills">${escHtml(r.competences)}</div>` : ''}
      ${r.formation ? `<div style="font-size:.74rem;color:#7a859a;margin-top:.2rem">🎓 ${escHtml(r.formation)}</div>` : ''}
      ${resume ? `<div style="font-size:.78rem;color:#94a3b8;margin-top:.45rem;background:#0d0f18;border-radius:6px;padding:.45rem .65rem;line-height:1.7;border-left:2px solid #252a40">
        💡 ${resume}
      </div>` : ''}
    </div>
    <div class="cand-right">
      <span class="score-badge" style="${badgeNiveau(niveau, r.score)}">${scoreLabel}</span>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;justify-content:flex-end;margin-top:.3rem">
        ${cvUrl ? `<a href="${cvUrl}" target="_blank" style="background:transparent;border:1.5px solid #7a859a;color:#94a3b8;border-radius:7px;padding:.38rem .8rem;font-size:.76rem;font-weight:700;text-decoration:none;transition:border-color .2s" onmouseover="this.style.borderColor='#00c9a7';this.style.color='#00c9a7'" onmouseout="this.style.borderColor='#7a859a';this.style.color='#94a3b8'">Voir CV</a>` : ''}
        <button class="btn-contacter" onclick="ouvrirModal(${r.candidat_id},'${r.prenom} ${r.nom}')">Contacter</button>
      </div>
    </div>
  </div>`;
}

async function lancerRecherche() {
  const q = document.getElementById('search-input').value.trim();
  if (!q) return;
  const wrap = document.getElementById('resultats-wrap');

  majStatutIA('recherche');
  const spinnerInterval = afficherAnimation(wrap);

  const fd = new FormData();
  fd.append('action', 'analyser');
  fd.append('requete', q);
  fd.append('csrf_token', '<?= $csrf ?>');

  try {
    const res = await fetch('/recrutsmart/actions/ia-proxy.php', { method: 'POST', body: fd });
    const data = await res.json();
    clearInterval(spinnerInterval);

    if (data.erreur) {
      majStatutIA('actif');
      wrap.innerHTML = `<div class="empty-state">⚠️ ${escTexte(data.erreur)}</div>`;
      return;
    }
    const vide = !data.exacts?.length && !data.partiels?.length && !data.similaires?.length;
    if (vide || data.message) {
      majStatutIA('actif');
      wrap.innerHTML = `<div class="empty-state">🔍 ${escTexte(data.message || 'Aucune correspondance trouvée pour le profil recherché.')}</div>`;
      return;
    }
    let html = '';
    if (data.exacts?.length) {
      majStatutIA('actif');
      html += `<div style="color:#00c9a7;font-weight:700;font-size:.85rem;margin-bottom:.5rem;padding:.35rem 0;border-bottom:1px solid #252a40">✔ Résultats exacts (${data.exacts.length})</div>`;
      data.exacts.forEach(r => { html += construireCarte(r, 'exact'); });
    }
    if (data.partiels?.length) {
      majStatutIA('actif');
      html += `<div style="color:#eab308;font-weight:700;font-size:.85rem;margin:.7rem 0 .5rem;padding:.35rem 0;border-bottom:1px solid #252a40">⚡ Suggestions (${data.partiels.length})</div>`;
      data.partiels.forEach(r => { html += construireCarte(r, 'partiel'); });
    }
    if (data.similaires?.length) {
      majStatutIA('actif');
      html += `<div style="color:#94a3b8;font-weight:700;font-size:.85rem;margin:.7rem 0 .5rem;padding:.35rem 0;border-bottom:1px solid #252a40">~ Profils similaires (${data.similaires.length})</div>`;
      data.similaires.forEach(r => { html += construireCarte(r, 'similaire'); });
    }
    majStatutIA('actif');
    wrap.innerHTML = html;
  } catch (error) {
    clearInterval(spinnerInterval);
    majStatutIA('hors-ligne');
    wrap.innerHTML = `<div class="empty-state">⚠️ RecrutSmart est momentanément indisponible, veuillez réessayer.</div>`;
  }
}

document.getElementById('btn-search').addEventListener('click',lancerRecherche);
document.getElementById('search-input').addEventListener('keydown',e=>{if(e.key==='Enter') lancerRecherche();});

// ── Indicateur statut microservice ───────────────────────────────
function majStatutIA(etat) {
  const dot = document.getElementById('ia-dot');
  const txt = document.getElementById('ia-status-txt');
  if (!dot || !txt) return;
  dot.className = 'ia-dot';
  if (etat === 'actif') {
    dot.classList.add('actif');
    txt.textContent = 'IA en ligne';
    txt.style.color = '#00c9a7';
  } else if (etat === 'recherche') {
    dot.classList.add('recherche');
    txt.textContent = 'Recherche en cours...';
    txt.style.color = '#eab308';
  } else {
    txt.textContent = 'IA hors ligne';
    txt.style.color = '#ef4444';
  }
}

async function verifierStatutIA() {
  try {
    const res = await fetch('/recrutsmart/actions/ia-proxy.php?action=sante', { cache: 'no-store' });
    const data = await res.json();
    majStatutIA(data.statut === 'ok' ? 'actif' : 'hors-ligne');
  } catch {
    majStatutIA('hors-ligne');
  }
}

// Vérifier au chargement puis toutes les 30 secondes
verifierStatutIA();
setInterval(verifierStatutIA, 30000);

function ouvrirModal(id,nom){
  document.getElementById('modal-cand-id').value=id;
  document.getElementById('modal-titre').textContent='Contacter '+nom;
  document.getElementById('modal-sujet').value='Invitation à un entretien — <?= clean($recruteur['entreprise']) ?>';
  document.getElementById('modal-contact').style.display='flex';
  document.body.style.overflow='hidden';
}
function fermerModal(){
  document.getElementById('modal-contact').style.display='none';
  document.body.style.overflow='';
}
document.getElementById('modal-contact').addEventListener('click',e=>{
  if(e.target===document.getElementById('modal-contact')) fermerModal();
});
</script>
</body>
</html>