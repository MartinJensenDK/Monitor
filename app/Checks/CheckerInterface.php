<?php

declare(strict_types=1);

namespace App\Checks;

/**
 * A monitor type is one class implementing this interface plus a form fragment
 * in resources/views/partials/monitor-fields-<type>.php.
 */
interface CheckerInterface
{
    /** @param array<string,mixed> $monitor */
    public function run(array $monitor): CheckResult;

    /** Machine name matching monitors.type. */
    public static function type(): string;
}
