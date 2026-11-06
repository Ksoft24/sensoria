<?php
require_once __DIR__ . '/../includes/functions.php';

session_unset();
session_destroy();

redirect('/client_login.php');
