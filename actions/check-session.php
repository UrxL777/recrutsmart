<?php
// actions/check-session.php
// Appelé en AJAX par les dashboards pour vérifier si la session est active
// Retourne JSON : {connecte: true} ou {connecte: false}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
// Pas de cache sur cette réponse
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    echo json_encode(['connecte' => true]);
} else {
    echo json_encode(['connecte' => false]);
}