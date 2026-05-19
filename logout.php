<?php
require_once __DIR__ . '/includes/auth.php';
Auth::logout();
header('Location: /rzd_ais/login.php');
exit;
