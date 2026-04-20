<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\SystemHealthModel;

class SystemController extends Controller
{
    public function health(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $status = (new SystemHealthModel())->status();
        http_response_code($status['ok'] ? 200 : 500);
        echo json_encode($status, JSON_UNESCAPED_UNICODE);
    }
}
