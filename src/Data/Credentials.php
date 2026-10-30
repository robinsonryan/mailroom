<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

use SensitiveParameter;

/**
 * An OAuth client and a refresh token for one identity. Never logged, never
 * persisted by the package.
 */
final readonly class Credentials
{
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        #[SensitiveParameter]
        public string $refreshToken,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'clientSecret' => '***', 'refreshToken' => '***'];
    }
}
