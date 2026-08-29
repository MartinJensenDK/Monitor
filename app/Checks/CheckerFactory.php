<?php

declare(strict_types=1);

namespace App\Checks;

use RuntimeException;

final class CheckerFactory
{
    /** @var array<string,class-string<CheckerInterface>> */
    private const CHECKERS = [
        'http' => HttpChecker::class,
        'endpoint' => EndpointChecker::class,
        'ping' => PingChecker::class,
        'port' => PortChecker::class,
    ];

    public static function for(string $type): CheckerInterface
    {
        $class = self::CHECKERS[$type] ?? null;
        if ($class === null) {
            throw new RuntimeException('No checker is registered for monitor type "' . $type . '".');
        }

        return new $class();
    }

    public static function supports(string $type): bool
    {
        return isset(self::CHECKERS[$type]);
    }

    /** @return array<int,string> */
    public static function types(): array
    {
        return array_keys(self::CHECKERS);
    }
}
