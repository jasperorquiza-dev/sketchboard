<?php
declare(strict_types=1);

function sketch_raw_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [];
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        return $config;
    }

    $loaded = require $path;
    if (is_array($loaded)) {
        $config = $loaded;
    }

    return $config;
}

function sketch_config(string $path, mixed $default = null): mixed
{
    $value = sketch_raw_config();
    if ($path === '') {
        return $value;
    }

    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}
