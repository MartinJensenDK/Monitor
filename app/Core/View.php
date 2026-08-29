<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    private static string $path = '';

    /** @var array<string,mixed> */
    private static array $shared = [];

    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @return array<string,mixed> */
    public static function shared(): array
    {
        return self::$shared;
    }

    /**
     * Render a template into a layout. Templates are plain PHP; every value
     * printed through e() is escaped, so there is no template compiler to trust.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);

        if ($layout === '') {
            return $content;
        }

        return self::partial($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $template, array $data = []): string
    {
        $file = self::$path . '/' . trim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        extract(self::$shared, EXTR_SKIP);
        extract($data, EXTR_OVERWRITE);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}
