<?php
// tests/unseed.php
// Supprime tous les candidats de test et leurs CV
// Accès : http://localhost/recrutsmart/tests/unseed.php

require_once __DIR__ . '/../config/db.php';

// Accessible uniquement en local
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
    http_response_code(403);
    die('Accès refusé.');
}

$dossierUploads = __DIR__ . '/../uploads/';
$supprimes = 0;
$cvSupprimes = 0;

// Récupérer tous les candidats de test (emails se terminant par @test.ci)
$st = $pdo->query("SELECT id, cv_fichier FROM candidats WHERE email LIKE '%@test.ci'");
$candidatsTest = $st->fetchAll();

foreach ($candidatsTest as $c) {
    // Supprimer le fichier CV
    if ($c['cv_fichier']) {
        $chemin = $dossierUploads . $c['cv_fichier'];
        if (file_exists($chemin)) {
            unlink($chemin);
            $cvSupprimes++;
        }
    }
    // Supprimer le candidat (CASCADE supprime aussi cv_analyses, candidatures, messages)
    $pdo->prepare('DELETE FROM candidats WHERE id = ?')->execute([$c['id']]);
    $supprimes++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Unseed — RecrutSmart Tests</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#0d0f18;color:#e2e8f0;
    padding:2rem;max-width:700px;margin:0 auto}
  h1{color:#ef4444;margin-bottom:1.5rem}
  .stat{background:#161929;border:1px solid #252a40;border-radius:10px;
    padding:1rem 1.5rem;margin-bottom:1rem}
  .stat span{font-size:1.8rem;font-weight:700;color:#ef4444}
  .btn{display:inline-block;padding:.7rem 1.4rem;border-radius:8px;
    text-decoration:none;font-weight:700;margin-top:1rem}
  .btn-primary{background:linear-gradient(135deg,#00c9a7,#0ea5e9);color:#fff}
</style>
</head>
<body>
<h1>🗑️ Données de test supprimées</h1>
<div class="stat">Candidats supprimés : <span><?= $supprimes ?></span></div>
<div class="stat">CV supprimés : <span><?= $cvSupprimes ?></span></div>
<a href="../auth/login.php" class="btn btn-primary">→ Retour au login</a>
</body>
</html>