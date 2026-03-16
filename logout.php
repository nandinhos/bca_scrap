<?php
require_once __DIR__ . '/scripts/login_auth.php';
fazerLogout();
header('Location: login.php');
exit;
?>