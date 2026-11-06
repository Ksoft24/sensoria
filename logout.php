<?php
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    db()->prepare("UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?")
        ->execute([$_SESSION['user_id']]);
}

session_unset();
session_destroy();
setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/']);

redirect('/login.php');
