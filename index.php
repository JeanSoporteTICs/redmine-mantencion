<?php

require_once __DIR__ . '/app/bootstrap.php';

$controller = new App\Controllers\HomeController();
$controller->index();
