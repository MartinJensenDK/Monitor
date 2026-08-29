<?php

declare(strict_types=1);

namespace App\Install;

final class RequirementsCheck
{
    public const MIN_PHP = '8.2.0';

    /** @return array<int,array{label:string,ok:bool,detail:string,fix:string,required:bool}> */
    public static function run(string $basePath): array
    {
        $results = [];

        $results[] = [
            'label' => 'PHP ' . self::MIN_PHP . ' or newer',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'detail' => 'Running ' . PHP_VERSION,
            'fix' => 'Switch this site to a newer PHP version in your hosting panel.',
            'required' => true,
        ];

        foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'sodium', 'json'] as $extension) {
            $results[] = [
                'label' => 'Extension: ' . $extension,
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'Loaded' : 'Missing',
                'fix' => 'Enable the ' . $extension . ' extension for this PHP version.',
                'required' => true,
            ];
        }

        foreach ([
            'Project folder is writable' => $basePath,
            'storage/ is writable' => $basePath . '/storage',
            'storage/logs is writable' => $basePath . '/storage/logs',
            'storage/sessions is writable' => $basePath . '/storage/sessions',
        ] as $label => $path) {
            $results[] = [
                'label' => $label,
                'ok' => is_dir($path) && is_writable($path),
                'detail' => $path,
                'fix' => 'Run: chmod u+w ' . $path,
                'required' => true,
            ];
        }

        $results[] = [
            'label' => 'Webroot points at public/',
            'ok' => self::webrootLooksCorrect($basePath),
            'detail' => self::webrootLooksCorrect($basePath)
                ? 'Serving from public/'
                : 'Serving from the project root — .env would be reachable over the web',
            'fix' => 'Set the site root to ' . $basePath . '/public in your hosting panel.',
            'required' => false,
        ];

        return $results;
    }

    public static function passes(string $basePath): bool
    {
        foreach (self::run($basePath) as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }

    private static function webrootLooksCorrect(string $basePath): bool
    {
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!is_string($root) || $root === '') {
            return true; // CLI, or a server that does not report it — do not cry wolf.
        }

        return rtrim(realpath($root) ?: $root, '/') === rtrim(realpath($basePath . '/public') ?: '', '/');
    }
}
