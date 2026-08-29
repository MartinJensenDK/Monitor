<?php

declare(strict_types=1);

namespace App\Checks;

use CurlHandle;

/**
 * A checker that can hand the scheduler a prepared curl handle instead of
 * running the request itself, so many monitors go out in one curl_multi round.
 */
interface BatchableChecker extends CheckerInterface
{
    /**
     * @param array<string,mixed> $monitor
     * @return CurlHandle|CheckResult a handle to add to curl_multi, or an
     *                                immediate result when the check never
     *                                got as far as a request
     */
    public function prepare(array $monitor): CurlHandle|CheckResult;

    /**
     * @param array<string,mixed> $monitor
     * @param int|null $errno curl result code; after curl_multi it comes from
     *                        the info queue rather than from the handle
     */
    public function finish(array $monitor, CurlHandle $handle, ?string $body = null, ?int $errno = null): CheckResult;
}
