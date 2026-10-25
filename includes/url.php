<?php
declare(strict_types=1);

if (!function_exists('pc_normalize_base_path')) {
    function pc_normalize_base_path(string $path): string
    {
        $normalized = '/' . trim(str_replace('\\', '/', $path), '/');
        if ($normalized === '/') {
            return '';
        }

        return rtrim($normalized, '/');
    }
}

if (!function_exists('pc_base_path')) {
    function pc_base_path(?array $config = null): string
    {
        $configured = '';
        if (is_array($config)) {
            $configured = trim((string)($config['app_base_path'] ?? ''));
        }

        if ($configured !== '') {
            return pc_normalize_base_path($configured);
        }

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptName === '') {
            return '';
        }

        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '.') {
            $dir = '';
        }

        $dir = rtrim($dir, '/');
        if (str_ends_with($dir, '/api')) {
            $dir = substr($dir, 0, -4);
        }

        return pc_normalize_base_path($dir);
    }
}

if (!function_exists('pc_url')) {
    function pc_url(string $path, ?array $config = null): string
    {
        $basePath = pc_base_path($config);
        $normalizedPath = '/' . ltrim($path, '/');

        if ($normalizedPath === '/') {
            return $basePath === '' ? '/' : ($basePath . '/');
        }

        return $basePath . $normalizedPath;
    }
}
