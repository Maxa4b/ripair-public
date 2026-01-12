<?php
// config/config.php

if (!function_exists('load_env')) {
    function load_env($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "'\"");
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        return ($value !== false) ? $value : $default;
    }
}

load_env(__DIR__ . '/../.env');
