<?php
// actions/recherche-nom.php
// Recherche rapide d'un candidat par nom ou prénom (SQL direct, sans LLM)

require_once __DIR__ . '/../config/db.php';
requireLogin();

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Seul le recruteur peut chercher
if ($_SESSION['role'] !== 'recruteur') {
    http_response_code(403);
    echo json_encode(['erreur' => 'Non autorisé.']);
    exit;
}

// Vérification CSRF
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['erreur' => 'Requête invalide.']);
    exit;
}

$nom = trim($_POST['nom'] ?? '');

if (strlen($nom) < 2) {
    echo json_encode(['erreur' => 'Tapez au moins 2 caractères.']);
    exit;
}

if (strlen($nom) > 100) {
    echo json_encode(['erreur' => 'Recherche trop longue.']);
    exit;
}

// Recherche SQL par nom OU prénom (insensible à la casse)
$st = $pdo->prepare('
    SELECT
        c.id,
        c.nom,
        c.prenom,
        c.ville,
        c.cv_fichier,
        COALESCE(a.competences, "") AS competences,
        COALESCE(a.experience,  "") AS experience,
        COALESCE(a.formation,   "") AS formation,
        COALESCE(a.langues,     "") AS langues
    FROM candidats c
    LEFT JOIN cv_analyses a ON a.candidat_id = c.id
    WHERE c.actif = 1
    AND (
        c.nom     LIKE ?
        OR c.prenom LIKE ?
        OR CONCAT(c.prenom, " ", c.nom) LIKE ?
        OR CONCAT(c.nom, " ", c.prenom) LIKE ?
    )
    ORDER BY c.nom ASC, c.prenom ASC
    LIMIT 20
');

$motif = '%' . $nom . '%';
$st->execute([$motif, $motif, $motif, $motif]);
$candidats = $st->fetchAll();

echo json_encode(['candidats' => $candidats]);
