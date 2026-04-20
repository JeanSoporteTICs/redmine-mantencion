<?php

namespace App\Core;

use RuntimeException;

class View
{
    public static function render(string $view, array $data = []): void
    {
        $file = APP_BASE_PATH . '/app/Views/' . str_replace('\\', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $view);
        }

        extract($data, EXTR_SKIP);
        require $file;
    }
}
