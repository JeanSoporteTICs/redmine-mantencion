<?php
require_once __DIR__ . '/controllers/auth.php';
auth_logout();
header('Location: /redmine/login.php');
exit;

