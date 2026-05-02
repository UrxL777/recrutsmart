<?php
// tests/seed.php
// Script d'insertion des données de test
// Crée un candidat par CV trouvé dans tests/cvs/
// Accès : http://localhost/recrutsmart/tests/seed.php

require_once __DIR__ . '/../config/db.php';

// Sécurité : accessible uniquement en local
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

// Liste des CV disponibles dans tests/cvs/
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

// Liste de villes pour diversifier
$villes = ['Abidjan', 'Cocody', 'Yopougon', 'Abobo', 'Plateau', 'Marcory', 'Adjame', 'Treichville', 'Koumassi', 'Bouake', 'Yamoussoukro', 'Daloa', 'San-Pedro', 'Korhogo', 'Man', 'Gagnoa'];

// Mot de passe par défaut pour tous les candidats de test : Test1234
$mdpHash = password_hash('Test1234', PASSWORD_BCRYPT);
$questionSecu = 'Quel est le nom de votre première école primaire ?';
$reponseSecu  = password_hash('ecole', PASSWORD_BCRYPT);

$inseres  = 0;
$ignores  = 0;
$erreurs  = [];

foreach ($cvDisponibles as $cvSource) {
    // Générer un nom, prénom et email à partir du nom du fichier
    $baseName = pathinfo($cvSource, PATHINFO_FILENAME);
    // Nettoyer le nom : supprimer les caractères non alphabétiques, remplacer les underscores par des espaces
    $cleanName = preg_replace('/[^a-zA-ZÀ-ÖØ-öø-ÿ\s]/u', '', str_replace(['_', '-'], ' ', $baseName));
    $cleanName = trim($cleanName);
    $parts = explode(' ', $cleanName);
    // Si on a au moins deux mots, on prend le dernier comme nom et les autres comme prénom
    if (count($parts) >= 2) {
        $prenom = implode(' ', array_slice($parts, 0, -1));
        $nom = end($parts);
    } else {
        $prenom = $cleanName ?: 'Candidat';
        $nom = 'Test';
    }
    // Tronquer pour éviter les noms trop longs
    $prenom = substr($prenom, 0, 50);
    $nom = substr($nom, 0, 50);

    // Générer un email unique
    $email = strtolower(preg_replace('/[^a-z0-9]/i', '', $prenom . $nom)) . '@test.ci';
    // Si l'email est vide ou trop court, on utilise un fallback
    if (strlen($email) < 5) {
        $email = 'candidat_' . uniqid() . '@test.ci';
    }

    // Téléphone fictif basé sur le nom
    $telephone = '+225 ' . rand(1, 9) . ' ' . rand(10, 99) . ' ' . rand(10, 99) . ' ' . rand(10, 99);

    // Ville aléatoire
    $ville = $villes[array_rand($villes)];

    // Vérifier si l'email existe déjà (éviter les doublons)
    $stCheck = $pdo->prepare('SELECT id FROM candidats WHERE email = ?');
    $stCheck->execute([$email]);
    if ($stCheck->fetch()) {
        $ignores++;
        continue;
    }

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
            $nom, $prenom, $email, $telephone, $ville,
            $mdpHash, $questionSecu, $reponseSecu,
            $nomFichierUpload, $mime
        ]);
        $inseres++;
    } catch (PDOException $e) {
        $erreurs[] = "Erreur pour {$prenom} {$nom} (fichier {$cvSource}) : " . $e->getMessage();
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
    💡 Mot de passe de tous les candidats de test : <strong>Test1234</strong><br>
    Les noms, prénoms et emails sont générés automatiquement à partir des noms des fichiers CV.
</div>

<a href="../auth/login.php" class="btn btn-primary">→ Aller au login</a>
<a href="unseed.php" class="btn btn-danger"
   onclick="return confirm('Supprimer tous les candidats de test ?')">
   🗑️ Supprimer les données de test
</a>
</body>
</html>