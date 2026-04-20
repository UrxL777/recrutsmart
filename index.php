<?php
require_once __DIR__ . '/config/db.php';
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    redirectToDashboard();
}
header('Location: /recrutsmart/auth/login.php'); exit;