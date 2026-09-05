<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    public bool $requiresAuth = true;

    public ?string $permission = null;

    public bool $requiresGuest = false;

    /**
     * Skip the CSRF check. Only ever set together with public(), and only for
     * routes that authenticate with a bearer token rather than the session
     * cookie -- there is no cookie for a browser to be tricked into sending,
     * so there is nothing for a token to protect.
     */
    public bool $csrfExempt = false;

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

    /**
     * A machine-to-machine endpoint: no session, no CSRF token, and whatever
     * the handler authenticates with is the handler's own business.
     */
    public function api(): self
    {
        $this->requiresAuth = false;
        $this->csrfExempt = true;

        return $this;
    }

    public function can(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }
}
