<?php
// actions/send-message.php
// Traitement envoi message recruteur → candidat
// Crée le message EN BASE + crée/met à jour la candidature automatiquement

require_once __DIR__ . '/../config/db.php';
requireLogin();

// Seul un recruteur peut envoyer un message
if ($_SESSION['role'] !== 'recruteur') {
    header('Location: /recrutsmart/dashboard/dashboard-candidat.php'); exit;
}

// Vérification CSRF
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    setFlash('danger', 'Requête invalide, veuillez réessayer.');
    header('Location: /recrutsmart/dashboard/dashboard-recruteur.php'); exit;
}

$recruteurId = (int)$_SESSION['user_id'];
$candidatId  = (int)($_POST['candidat_id'] ?? 0);
$sujet       = clean($_POST['sujet']       ?? '');
$corps       = trim($_POST['corps']        ?? '');
$poste       = clean($_POST['poste']       ?? 'Poste à définir');
$rdvDate     = clean($_POST['heure_rdv_date']  ?? '');
$rdvHeure    = clean($_POST['heure_rdv_heure'] ?? '');

// ── Validations ──────────────────────────────────────────────────
if (!$candidatId) {
    setFlash('danger', 'Candidat introuvable.');
    header('Location: /recrutsmart/dashboard/dashboard-recruteur.php'); exit;
}
if (!$sujet) {
    setFlash('danger', 'L\'objet du message est requis.');
    header('Location: /recrutsmart/dashboard/dashboard-recruteur.php'); exit;
}
if (strlen($corps) < 10) {
    setFlash('danger', 'Le message est trop court.');
    header('Location: /recrutsmart/dashboard/dashboard-recruteur.php'); exit;
}

// ── Vérifier que le candidat existe ─────────────────────────────
$stCheck = $pdo->prepare('SELECT id FROM candidats WHERE id=? AND actif=1');
$stCheck->execute([$candidatId]);
if (!$stCheck->fetch()) {
    setFlash('danger', 'Candidat introuvable ou inactif.');
    header('Location: /recrutsmart/dashboard/dashboard-recruteur.php'); exit;
}

// ── Construire la date/heure du RDV ─────────────────────────────
$heureRdv = null;
if ($rdvDate && $rdvHeure) {
    $heureRdv = $rdvDate . ' ' . $rdvHeure . ':00';
    // Valider le format datetime
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $heureRdv);
    if (!$dt) $heureRdv = null;
}

// ── Corps du message : ajouter les infos RDV si présentes ────────
$recruteur = $pdo->prepare('SELECT * FROM recruteurs WHERE id=?');
$recruteur->execute([$recruteurId]); $recruteur = $recruteur->fetch();

$corpsComplet = $corps;
if ($heureRdv) {
    $corpsComplet .= "\n\n──────────────────────────────\n";
    $corpsComplet .= "📅 Rendez-vous prévu le : " . date('d/m/Y à H:i', strtotime($heureRdv));
    $corpsComplet .= "\n📍 Lieu : Locaux de " . $recruteur['entreprise'];
    $corpsComplet .= "\n──────────────────────────────";
}

// ── Transaction : message + candidature en même temps ───────────
try {
    $pdo->beginTransaction();

    // 1. Insérer le message
    $stMsg = $pdo->prepare('
        INSERT INTO messages (recruteur_id, candidat_id, sujet, corps, heure_rdv)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stMsg->execute([$recruteurId, $candidatId, $sujet, $corpsComplet, $heureRdv]);
    $messageId = (int)$pdo->lastInsertId();

    // 2. Créer ou mettre à jour la candidature
    //    Si une candidature existe déjà entre ce recruteur et ce candidat → on la met à jour
    $stExiste = $pdo->prepare('
        SELECT id FROM candidatures
        WHERE candidat_id=? AND recruteur_id=?
        LIMIT 1
    ');
    $stExiste->execute([$candidatId, $recruteurId]);
    $existing = $stExiste->fetch();

    if ($existing) {
        // Mise à jour : nouveau message, statut → Entretien planifié si RDV défini
        $nouveauStatut = $heureRdv ? 'Entretien planifié' : 'En attente';
        $stUpd = $pdo->prepare('
            UPDATE candidatures
            SET message_id=?, poste=?, statut=?, maj=NOW()
            WHERE id=?
        ');
        $stUpd->execute([$messageId, $poste, $nouveauStatut, $existing['id']]);
    } else {
        // Création : nouvelle candidature
        $statut = $heureRdv ? 'Entretien planifié' : 'En attente';
        $stCand = $pdo->prepare('
            INSERT INTO candidatures (candidat_id, recruteur_id, message_id, poste, statut)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stCand->execute([$candidatId, $recruteurId, $messageId, $poste, $statut]);
    }

    $pdo->commit();

    // ── Message flash de succès ──────────────────────────────────
    $nomCand = $pdo->prepare('SELECT prenom, nom, email FROM candidats WHERE id=?');
    $nomCand->execute([$candidatId]); $nomCand = $nomCand->fetch();
    $nomAffiche  = $nomCand ? clean($nomCand['prenom'].' '.$nomCand['nom']) : 'le candidat';
    $emailCandid = $nomCand['email'] ?? '';

    // ── Envoi email réel si adresse valide ───────────────────────
    if ($emailCandid && filter_var($emailCandid, FILTER_VALIDATE_EMAIL)) {
        _envoyer_email_candidat(
            $emailCandid,
            $nomAffiche,
            $sujet,
            $corpsComplet,
            $recruteur
        );
    }

    setFlash('success', "✅ Message envoyé à $nomAffiche avec succès !");

} catch (Exception $e) {
    $pdo->rollBack();
    setFlash('danger', 'Erreur lors de l\'envoi du message. Veuillez réessayer.');
}

header('Location: /recrutsmart/dashboard/dashboard-recruteur.php'); exit;


// ================================================================
//  Fonction : envoi email via Gmail SMTP (PHPMailer)
// ================================================================
function _envoyer_email_candidat(
    string $emailDest,
    string $nomDest,
    string $sujet,
    string $corps,
    array  $recruteur
): void {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return;
    require_once $autoload;

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, $recruteur['entreprise'] . ' via RecrutSmart');
        $mail->addReplyTo($recruteur['email'], $recruteur['prenom'].' '.$recruteur['nom']);
        $mail->addAddress($emailDest, $nomDest);

        $mail->Subject = $sujet;
        $mail->Body    = $corps;

        $mail->send();
    } catch (\Exception $e) {
        // Échec silencieux — le message est déjà sauvegardé en base
        error_log('[RecrutSmart] Erreur envoi email : ' . $e->getMessage());
    }
}
