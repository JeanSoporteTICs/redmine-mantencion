<?php

namespace App\Models;

class UserModel
{
    public function all(): array
    {
        $path = APP_BASE_PATH . '/data/usuarios.json';
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public function count(): int
    {
        return count($this->all());
    }
}
