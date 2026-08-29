<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    public bool $requiresAuth = true;

    public ?string $permission = null;

    public bool $requiresGuest = false;

    /** @param array<int,string> $segments */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $pattern,
        /** @var callable|array{0:class-string,1:string} */
        public readonly mixed $handler,
        public readonly array $segments
    ) {
    }

    /** Route is reachable without a session (login page, install wizard). */
    public function public(): self
    {
        $this->requiresAuth = false;

        return $this;
    }

    /** Only for signed-out visitors; signed-in users get redirected home. */
    public function guest(): self
    {
        $this->requiresAuth = false;
        $this->requiresGuest = true;

        return $this;
    }

    public function can(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }
}
