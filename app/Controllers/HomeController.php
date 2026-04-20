<?php

namespace App\Controllers;

use App\Core\Controller;

use function App\Support\app_base_url;
use function App\Support\app_config;

class HomeController extends Controller
{
    public function index(): void
    {
        $this->redirect(app_base_url((string) app_config('dashboard_path', '/views/Dashboard/dashboard.php')));
    }
}
