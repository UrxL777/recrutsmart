<?php
// actions/upload-cv.php
// Traitement de l'upload du CV depuis dashboard-candidat.php
// Formats acceptés : PDF, DOCX, DOC, JPG, JPEG, PNG — max 5 Mo
// Après upload : appel automatique au microservice IA pour analyse

require_once __DIR__ . '/../config/db.php';
requireLogin();

// Seul un candidat peut uploader son CV
if ($_SESSION['role'] !== 'candidat') {
    header('Location: /recrutsmart/dashboard/dashboard-recruteur.php'); exit;
}

// Vérification CSRF
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    setFlash('danger', 'Requête invalide, veuillez réessayer.');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// Vérifier qu'un fichier a bien été envoyé
if (empty($_FILES['cv']) || $_FILES['cv']['error'] === UPLOAD_ERR_NO_FILE) {
    setFlash('danger', 'Aucun fichier sélectionné.');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

$fichier = $_FILES['cv'];

// ── Vérification erreur upload PHP ──────────────────────────────
if ($fichier['error'] !== UPLOAD_ERR_OK) {
    $erreurs = [
        UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la limite du serveur.',
        UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la limite du formulaire.',
        UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement uploadé.',
        UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
        UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire le fichier.',
    ];
    setFlash('danger', $erreurs[$fichier['error']] ?? 'Erreur lors de l\'upload.');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// ── Vérification taille : max 5 Mo ──────────────────────────────
$maxSize = 5 * 1024 * 1024;
if ($fichier['size'] > $maxSize) {
    setFlash('danger', 'Le fichier est trop volumineux (max 5 Mo).');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// ── Vérification type MIME réel ──────────────────────────────────
$mimeAutorise = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/png',
];

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeReel = $finfo->file($fichier['tmp_name']);

if (!in_array($mimeReel, $mimeAutorise)) {
    setFlash('danger', 'Format non autorisé. Utilisez PDF, DOCX, JPG ou PNG.');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// ── Vérification extension ───────────────────────────────────────
$extAutorisee = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
$ext          = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $extAutorisee)) {
    setFlash('danger', 'Extension non autorisée. Utilisez PDF, DOCX, JPG ou PNG.');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// ── Dossier de destination ───────────────────────────────────────
$dossierUpload = __DIR__ . '/../uploads/';
if (!is_dir($dossierUpload)) {
    mkdir($dossierUpload, 0755, true);
}

// ── Supprimer l'ancien CV s'il existe ───────────────────────────
$stAncien = $pdo->prepare('SELECT cv_fichier FROM candidats WHERE id=?');
$stAncien->execute([$_SESSION['user_id']]);
$ancien = $stAncien->fetch();
if ($ancien && $ancien['cv_fichier']) {
    $cheminAncien = $dossierUpload . $ancien['cv_fichier'];
    if (file_exists($cheminAncien)) {
        unlink($cheminAncien);
    }
}

// ── Nom de fichier sécurisé et unique ───────────────────────────
$nomFichier  = 'cv_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
$destination = $dossierUpload . $nomFichier;

// ── Déplacement du fichier ───────────────────────────────────────
if (!move_uploaded_file($fichier['tmp_name'], $destination)) {
    setFlash('danger', 'Erreur lors de l\'enregistrement du fichier. Réessayez.');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// ── Mise à jour en base ──────────────────────────────────────────
$st = $pdo->prepare('
    UPDATE candidats
    SET cv_fichier = ?, cv_mime = ?, cv_date = NOW()
    WHERE id = ?
');
$st->execute([$nomFichier, $mimeReel, $_SESSION['user_id']]);

// ── Appel automatique au microservice IA pour analyser le CV ────
// L'analyse se fait en arrière-plan — on n'attend pas la réponse
// pour ne pas bloquer la redirection du candidat
_appeler_microservice_analyse((int)$_SESSION['user_id'], $nomFichier, $mimeReel);

// ── Succès ───────────────────────────────────────────────────────
setFlash('success', 'Votre CV a été enregistré avec succès ! ✅ L\'analyse IA est en cours, vos informations seront disponibles dans quelques instants.');
header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;


// ================================================================
//  Fonction : appel au microservice IA (non bloquant)
// ================================================================
function _appeler_microservice_analyse(int $candidat_id, string $cv_fichier, string $cv_mime): void
{
    $ia_url    = 'http://127.0.0.1:5000/analyser-cv';
    $ia_secret = 'CDG_music_calmez_vous_orrh'; // Doit correspondre au .env

    $payload = json_encode([
        'candidat_id' => $candidat_id,
        'cv_fichier'  => $cv_fichier,
        'cv_mime'     => $cv_mime,
    ]);

    $ch = curl_init($ia_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $ia_secret,
        ],
        CURLOPT_RETURNTRANSFER => true,
        // Timeout court : on n'attend pas la fin de l'analyse
        // Le microservice continue en arrière-plan
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    // On ignore la réponse — l'analyse se fait en arrière-plan
    curl_exec($ch);
    curl_close($ch);
}
