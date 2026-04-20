<?php
// tests/seed.php
// Script d'insertion des données de test
// Accès : http://localhost/recrutsmart/tests/seed.php
// ⚠️ À supprimer ou protéger avant la mise en production

require_once __DIR__ . '/../config/db.php';

// ── Sécurité : accessible uniquement en local ─────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
    http_response_code(403);
    die('Accès refusé.');
}

$dossierCvs     = __DIR__ . '/cvs/';
$dossierUploads = __DIR__ . '/../uploads/';

// Créer le dossier uploads si inexistant
if (!is_dir($dossierUploads)) {
    mkdir($dossierUploads, 0755, true);
}

// ── Liste des CV disponibles dans tests/cvs/ ─────────────────────
$cvDisponibles = [];
if (is_dir($dossierCvs)) {
    foreach (glob($dossierCvs . '*.{pdf,PDF,docx,DOCX,jpg,JPG,jpeg,JPEG,png,PNG}', GLOB_BRACE) as $fichier) {
        $cvDisponibles[] = basename($fichier);
    }
}

if (empty($cvDisponibles)) {
    die('<p style="font-family:sans-serif;color:red;padding:2rem;">
        ⚠️ Aucun CV trouvé dans <strong>tests/cvs/</strong><br>
        Déposez vos CV PDF/DOCX/JPG dans ce dossier puis relancez ce script.
    </p>');
}

// ── Données des 40 candidats fictifs ─────────────────────────────
$candidats = [
    ['nom'=>'Koné',       'prenom'=>'Aya',        'email'=>'aya.kone@test.ci',        'telephone'=>'+225 07 11 11 11', 'ville'=>'Abidjan'],
    ['nom'=>'Diallo',     'prenom'=>'Moussa',      'email'=>'moussa.diallo@test.ci',   'telephone'=>'+225 07 22 22 22', 'ville'=>'Cocody'],
    ['nom'=>'Traoré',     'prenom'=>'Fatou',       'email'=>'fatou.traore@test.ci',    'telephone'=>'+225 07 33 33 33', 'ville'=>'Yopougon'],
    ['nom'=>'Coulibaly',  'prenom'=>'Ibrahim',     'email'=>'ibrahim.coul@test.ci',    'telephone'=>'+225 07 44 44 44', 'ville'=>'Abobo'],
    ['nom'=>'Bamba',      'prenom'=>'Mariam',      'email'=>'mariam.bamba@test.ci',    'telephone'=>'+225 07 55 55 55', 'ville'=>'Plateau'],
    ['nom'=>'Ouattara',   'prenom'=>'Seydou',      'email'=>'seydou.ouat@test.ci',     'telephone'=>'+225 07 66 66 66', 'ville'=>'Yamoussoukro'],
    ['nom'=>'Soro',       'prenom'=>'Aminata',     'email'=>'aminata.soro@test.ci',    'telephone'=>'+225 07 77 77 77', 'ville'=>'Bouaké'],
    ['nom'=>'Touré',      'prenom'=>'Karim',       'email'=>'karim.toure@test.ci',     'telephone'=>'+225 07 88 88 88', 'ville'=>'Marcory'],
    ['nom'=>'Sanogo',     'prenom'=>'Djeneba',     'email'=>'djeneba.sano@test.ci',    'telephone'=>'+225 07 99 99 99', 'ville'=>'Treichville'],
    ['nom'=>'Fofana',     'prenom'=>'Adama',       'email'=>'adama.fofana@test.ci',    'telephone'=>'+225 05 11 11 11', 'ville'=>'Cocody'],
    ['nom'=>'Doumbia',    'prenom'=>'Rokia',       'email'=>'rokia.doumb@test.ci',     'telephone'=>'+225 05 22 22 22', 'ville'=>'Abidjan'],
    ['nom'=>'Camara',     'prenom'=>'Lamine',      'email'=>'lamine.cama@test.ci',     'telephone'=>'+225 05 33 33 33', 'ville'=>'Yopougon'],
    ['nom'=>'Diabaté',    'prenom'=>'Nadia',       'email'=>'nadia.diaba@test.ci',     'telephone'=>'+225 05 44 44 44', 'ville'=>'Abobo'],
    ['nom'=>'Konaté',     'prenom'=>'Fousseni',    'email'=>'fousseni.kon@test.ci',    'telephone'=>'+225 05 55 55 55', 'ville'=>'Plateau'],
    ['nom'=>'Méité',      'prenom'=>'Sylvie',      'email'=>'sylvie.meit@test.ci',     'telephone'=>'+225 05 66 66 66', 'ville'=>'Abidjan'],
    ['nom'=>'Bah',        'prenom'=>'Mamadou',     'email'=>'mamadou.bah@test.ci',     'telephone'=>'+225 05 77 77 77', 'ville'=>'Bouaké'],
    ['nom'=>'Coulibaly',  'prenom'=>'Salimata',    'email'=>'salimata.c@test.ci',      'telephone'=>'+225 05 88 88 88', 'ville'=>'Cocody'],
    ['nom'=>'Koné',       'prenom'=>'Jean-Claude', 'email'=>'jc.kone@test.ci',         'telephone'=>'+225 05 99 99 99', 'ville'=>'Marcory'],
    ['nom'=>'Yao',        'prenom'=>'Prisca',      'email'=>'prisca.yao@test.ci',      'telephone'=>'+225 01 11 11 11', 'ville'=>'Treichville'],
    ['nom'=>'N\'Guessan', 'prenom'=>'Hervé',       'email'=>'herve.ngu@test.ci',       'telephone'=>'+225 01 22 22 22', 'ville'=>'Yamoussoukro'],
    ['nom'=>'Aka',        'prenom'=>'Christelle',  'email'=>'christelle.aka@test.ci',  'telephone'=>'+225 01 33 33 33', 'ville'=>'Abidjan'],
    ['nom'=>'Kouassi',    'prenom'=>'Parfait',     'email'=>'parfait.kou@test.ci',     'telephone'=>'+225 01 44 44 44', 'ville'=>'Cocody'],
    ['nom'=>'Bogui',      'prenom'=>'Estelle',     'email'=>'estelle.bog@test.ci',     'telephone'=>'+225 01 55 55 55', 'ville'=>'Yopougon'],
    ['nom'=>'Ahoussou',   'prenom'=>'Rodrigue',    'email'=>'rodrigue.ah@test.ci',     'telephone'=>'+225 01 66 66 66', 'ville'=>'Abobo'],
    ['nom'=>'Dossou',     'prenom'=>'Laure',       'email'=>'laure.dossou@test.ci',    'telephone'=>'+225 01 77 77 77', 'ville'=>'Abidjan'],
    ['nom'=>'Sékongo',    'prenom'=>'Brahima',     'email'=>'brahima.sek@test.ci',     'telephone'=>'+225 01 88 88 88', 'ville'=>'Bouaké'],
    ['nom'=>'Tiéhi',      'prenom'=>'Mireille',    'email'=>'mireille.tie@test.ci',    'telephone'=>'+225 01 99 99 99', 'ville'=>'Plateau'],
    ['nom'=>'Gnagne',     'prenom'=>'Alexis',      'email'=>'alexis.gna@test.ci',      'telephone'=>'+225 07 12 12 12', 'ville'=>'Cocody'],
    ['nom'=>'Lobognon',   'prenom'=>'Vanessa',     'email'=>'vanessa.lob@test.ci',     'telephone'=>'+225 07 23 23 23', 'ville'=>'Marcory'],
    ['nom'=>'Brou',       'prenom'=>'Serge',       'email'=>'serge.brou@test.ci',      'telephone'=>'+225 07 34 34 34', 'ville'=>'Treichville'],
    ['nom'=>'Kacou',      'prenom'=>'Adjoua',      'email'=>'adjoua.kac@test.ci',      'telephone'=>'+225 07 45 45 45', 'ville'=>'Abidjan'],
    ['nom'=>'Assi',       'prenom'=>'Romuald',     'email'=>'romuald.ass@test.ci',     'telephone'=>'+225 07 56 56 56', 'ville'=>'Yamoussoukro'],
    ['nom'=>'Djè',        'prenom'=>'Ornella',     'email'=>'ornella.dje@test.ci',     'telephone'=>'+225 07 67 67 67', 'ville'=>'Cocody'],
    ['nom'=>'Ehui',       'prenom'=>'Gilles',      'email'=>'gilles.ehui@test.ci',     'telephone'=>'+225 07 78 78 78', 'ville'=>'Yopougon'],
    ['nom'=>'Koffi',      'prenom'=>'Patricia',    'email'=>'patricia.kof@test.ci',    'telephone'=>'+225 07 89 89 89', 'ville'=>'Abobo'],
    ['nom'=>'Kouadio',    'prenom'=>'Thierry',     'email'=>'thierry.kou@test.ci',     'telephone'=>'+225 07 90 90 90', 'ville'=>'Abidjan'],
    ['nom'=>'Séri',       'prenom'=>'Ange',        'email'=>'ange.seri@test.ci',       'telephone'=>'+225 05 12 12 12', 'ville'=>'Bouaké'],
    ['nom'=>'Tape',       'prenom'=>'Carine',      'email'=>'carine.tape@test.ci',     'telephone'=>'+225 05 23 23 23', 'ville'=>'Plateau'],
    ['nom'=>'Wattara',    'prenom'=>'Drissa',      'email'=>'drissa.wat@test.ci',      'telephone'=>'+225 05 34 34 34', 'ville'=>'Cocody'],
    ['nom'=>'Zadi',       'prenom'=>'Bénédicte',   'email'=>'benedicte.z@test.ci',     'telephone'=>'+225 05 45 45 45', 'ville'=>'Abidjan'],
];

// Mot de passe par défaut pour tous les candidats de test : Test1234
$mdpHash = password_hash('Test1234', PASSWORD_BCRYPT);
$questionSecu = 'Quel est le nom de votre première école primaire ?';
$reponseSecu  = password_hash('ecole', PASSWORD_BCRYPT);

$inseres  = 0;
$ignores  = 0;
$erreurs  = [];
$cvIndex  = 0;

foreach ($candidats as $c) {
    // Vérifier si l'email existe déjà
    $stCheck = $pdo->prepare('SELECT id FROM candidats WHERE email = ?');
    $stCheck->execute([$c['email']]);
    if ($stCheck->fetch()) {
        $ignores++;
        continue;
    }

    // Assigner un CV du dossier tests/cvs/ (en rotation)
    $cvSource = $cvDisponibles[$cvIndex % count($cvDisponibles)];
    $cvIndex++;

    // Déterminer le MIME
    $ext = strtolower(pathinfo($cvSource, PATHINFO_EXTENSION));
    $mimeMap = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    $mime = $mimeMap[$ext] ?? 'application/pdf';

    // Nom du fichier dans uploads/
    $nomFichierUpload = 'test_cv_' . uniqid() . '.' . $ext;
    $source      = $dossierCvs . $cvSource;
    $destination = $dossierUploads . $nomFichierUpload;

    // Copier le CV dans uploads/
    if (!copy($source, $destination)) {
        $erreurs[] = "Impossible de copier {$cvSource}";
        $nomFichierUpload = null;
        $mime = null;
    }

    // Insérer le candidat
    try {
        $st = $pdo->prepare('
            INSERT INTO candidats
                (nom, prenom, email, telephone, ville, mot_de_passe,
                 question_securite, reponse_securite, cv_fichier, cv_mime, cv_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $st->execute([
            $c['nom'], $c['prenom'], $c['email'], $c['telephone'], $c['ville'],
            $mdpHash, $questionSecu, $reponseSecu,
            $nomFichierUpload, $mime
        ]);
        $inseres++;
    } catch (PDOException $e) {
        $erreurs[] = "Erreur pour {$c['prenom']} {$c['nom']} : " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Seed — RecrutSmart Tests</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#0d0f18;color:#e2e8f0;
    padding:2rem;max-width:700px;margin:0 auto}
  h1{color:#00c9a7;margin-bottom:1.5rem}
  .stat{background:#161929;border:1px solid #252a40;border-radius:10px;
    padding:1rem 1.5rem;margin-bottom:1rem}
  .stat span{font-size:1.8rem;font-weight:700;color:#00c9a7}
  .erreur{background:rgba(239,68,68,.1);color:#fca5a5;
    border:1px solid rgba(239,68,68,.25);border-radius:8px;
    padding:.75rem 1rem;margin-bottom:.5rem;font-size:.88rem}
  .btn{display:inline-block;padding:.7rem 1.4rem;border-radius:8px;
    text-decoration:none;font-weight:700;margin-top:1rem;margin-right:.5rem}
  .btn-primary{background:linear-gradient(135deg,#00c9a7,#0ea5e9);color:#fff}
  .btn-danger{background:#ef4444;color:#fff}
  .info{background:rgba(0,201,167,.1);color:#00c9a7;border:1px solid rgba(0,201,167,.3);
    border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.88rem}
</style>
</head>
<body>
<h1>🌱 Données de test insérées</h1>

<div class="stat">✅ Candidats insérés : <span><?= $inseres ?></span></div>
<div class="stat">⏭️ Ignorés (déjà existants) : <span><?= $ignores ?></span></div>
<div class="stat">📄 CV disponibles : <span><?= count($cvDisponibles) ?></span></div>

<?php if ($erreurs): ?>
    <h3 style="color:#fca5a5;margin-top:1.5rem">Erreurs :</h3>
    <?php foreach ($erreurs as $e): ?>
        <div class="erreur">⚠️ <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="info">
    💡 Mot de passe de tous les candidats de test : <strong>Test1234</strong>
</div>

<a href="../auth/login.php" class="btn btn-primary">→ Aller au login</a>
<a href="unseed.php" class="btn btn-danger"
   onclick="return confirm('Supprimer tous les candidats de test ?')">
   🗑️ Supprimer les données de test
</a>
</body>
</html>