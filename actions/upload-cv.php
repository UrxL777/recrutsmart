<?php
// actions/upload-cv.php
// Traitement de l'upload du CV depuis dashboard-candidat.php
// Formats acceptés : PDF, DOCX, DOC, JPG, JPEG, PNG — max 5 Mo

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
$maxSize = 5 * 1024 * 1024; // 5 Mo
if ($fichier['size'] > $maxSize) {
    setFlash('danger', 'Le fichier est trop volumineux (max 5 Mo).');
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// ── Vérification type MIME réel (pas seulement l'extension) ─────
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
// Format : cv_[id_candidat]_[timestamp].[ext]
$nomFichier = 'cv_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
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

// ── Succès ───────────────────────────────────────────────────────
setFlash('success', 'Votre CV a été enregistré avec succès !✅  Vous pouvez le remplacer à tout moment en téléchargeant une nouvelle version.');
header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;