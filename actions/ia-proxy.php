<?php
// actions/ia-proxy.php
// Proxy sécurisé entre le frontend PHP et le microservice FastAPI Python
// Appelé en AJAX depuis les dashboards

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Configuration microservice ────────────────────────────────────
define('IA_BASE_URL', 'http://127.0.0.1:5000');
define('IA_SECRET',   'CDG_music_calmez_vous_orrh'); // Doit correspondre au .env

// ── Récupérer l'action demandée ───────────────────────────────────
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

if (!in_array($action, ['analyser', 'agent', 'sante'])) {
    http_response_code(400);
    echo json_encode(['erreur' => 'Action invalide.']);
    exit;
}

// ── Health check — pas besoin de session ni CSRF ──────────────────
if ($action === 'sante') {
    $ch = curl_init(IA_BASE_URL . '/sante');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . IA_SECRET],
    ]);
    $rep  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo ($code === 200 && $rep) ? $rep : json_encode(['statut' => 'hors-ligne']);
    exit;
}

// ── Pour toutes les autres actions : session obligatoire ──────────
requireLogin();

// ── Vérification CSRF ─────────────────────────────────────────────
if (!verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['erreur' => 'Requête invalide.']);
    exit;
}

// ── Construire le payload selon l'action ─────────────────────────
if ($action === 'analyser') {
    // Seul le recruteur peut analyser
    if ($_SESSION['role'] !== 'recruteur') {
        http_response_code(403);
        echo json_encode(['erreur' => 'Non autorisé.']);
        exit;
    }
    $requete = trim($_POST['requete'] ?? '');
    if (strlen($requete) < 3) {
        http_response_code(422);
        echo json_encode(['erreur' => 'Requête trop courte.']);
        exit;
    }
    $payload = json_encode([
        'recruteur_id' => (int)$_SESSION['user_id'],
        'requete'      => $requete
    ]);
    $endpoint = '/analyser';

} elseif ($action === 'agent') {
    $message = trim($_POST['message'] ?? '');
    if (!$message) {
        http_response_code(422);
        echo json_encode(['erreur' => 'Message vide.']);
        exit;
    }
    $payload = json_encode([
        'user_id'   => (int)$_SESSION['user_id'],
        'user_role' => $_SESSION['role'],
        'message'   => $message,
        'contexte'  => trim($_POST['contexte'] ?? '')
    ]);
    $endpoint = '/agent';
}

// ── Appel HTTP vers le microservice Python ────────────────────────
$ch = curl_init(IA_BASE_URL . $endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-API-Key: ' . IA_SECRET,
    ],
]);

$reponse    = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErreur = curl_error($ch);
curl_close($ch);

// ── Gestion des erreurs ───────────────────────────────────────────
if ($curlErreur) {
    http_response_code(503);
    echo json_encode(['erreur' => 'Le service IA est momentanément indisponible.']);
    exit;
}

if ($httpCode === 401) {
    http_response_code(503);
    echo json_encode(['erreur' => 'Erreur de configuration du service IA.']);
    exit;
}

if ($httpCode !== 200) {
    http_response_code(503);
    echo json_encode(['erreur' => 'Le service IA a rencontré une erreur.']);
    exit;
}

// ── Retourner la réponse au frontend ─────────────────────────────
echo $reponse;