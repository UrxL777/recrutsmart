<?php
// config/db.php — Connexion PDO + toutes les fonctions du projet

// ── Masquer toutes les erreurs PHP à l'écran ─────────────────────
// Les erreurs sont loguées côté serveur, jamais affichées à l'utilisateur
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
if (session_status() === PHP_SESSION_NONE) {
    // Configuration sécurisée de la session
    ini_set('session.cookie_httponly', '1');   // Cookie inaccessible au JS
    ini_set('session.cookie_samesite', 'Strict'); // Protection CSRF supplémentaire
    ini_set('session.use_strict_mode', '1');   // Rejeter les IDs de session non initialisés
    ini_set('session.cookie_secure', '0');     // Mettre à 1 en production HTTPS
    session_start();
}

// ── Headers de sécurité HTTP envoyés sur toutes les pages ────────
header('X-Frame-Options: DENY');                        // Anti-clickjacking
header('X-Content-Type-Options: nosniff');              // Anti-MIME sniffing
header('X-XSS-Protection: 1; mode=block');              // Protection XSS navigateur
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';");

// ── Connexion PDO ────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=recrutsmart;charset=utf8mb4',
        'root', '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Ne jamais afficher le message d'erreur en production (expose la structure BDD)
    error_log('[RecrutSmart] Erreur PDO : ' . $e->getMessage()); // Log serveur uniquement
    die('<p style="font-family:sans-serif;color:#f87171;background:#0f1117;padding:2rem;margin:0">
        Connexion à la base de données impossible.<br>
        Vérifiez que XAMPP est lancé et que la base <b>recrutsmart</b> existe.</p>');
}

// ================================================================
//  SESSION & AUTH
// ================================================================
function requireLogin(string $back = ''): void {
    // Empêcher le navigateur de mettre la page en cache
    // → le bouton "retour" ne pourra pas afficher la page après déconnexion
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        $url = '/recrutsmart/auth/login.php';
        if ($back) $url .= '?back='.urlencode($back);
        header('Location: '.$url); exit;
    }
}

function createSession(int $id, string $role, string $nom, string $prenom): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    $_SESSION['role']    = $role;
    $_SESSION['nom']     = $nom;
    $_SESSION['prenom']  = $prenom;
}

function redirectToDashboard(): void {
    header('Location: /recrutsmart/actions/set-session.php'); exit;
}

function destroySession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ================================================================
//  CSRF
// ================================================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token']))
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ================================================================
//  VALIDATION
// ================================================================
function clean(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function isValidEmail(string $e): bool {
    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidPassword(string $p): bool {
    return strlen($p) >= 8 && preg_match('/[A-Z]/', $p) && preg_match('/[0-9]/', $p);
}

function isValidPhone(string $t): bool {
    return preg_match('/^[0-9+\-\s()]{7,20}$/', $t);
}

// ================================================================
//  HASHAGE
// ================================================================
function hashPassword(string $v): string { return password_hash($v, PASSWORD_BCRYPT); }
function verifyPassword(string $v, string $h): bool { return password_verify($v, $h); }

// ================================================================
//  QUESTIONS DE SÉCURITÉ
// ================================================================
function questionsSecurite(): array {
    return [
        'Dans quelle ville votre famille résidait‑elle avant votre ville actuelle ?',
        'Quel est le nom de votre première école primaire ?',
        'Quel est le prénom de votre grand‑mère paternelle ?',
    ];
}

// ================================================================
//  FLASH MESSAGES
// ================================================================
function setFlash(string $type, string $msg): void {
    // Valider le type pour éviter toute injection CSS
    $typesAutorises = ['success', 'danger', 'warning', 'info'];
    if (!in_array($type, $typesAutorises)) $type = 'info';
    $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
}
function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
    }
    return null;
}