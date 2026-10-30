<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Contracts;

use RobinsonRyan\Mailroom\Data\Credentials;
use RobinsonRyan\Mailroom\Exceptions\MissingCredentials;

/**
 * Maps a mailbox's identity (a work-registry identity slug) to the OAuth client
 * and refresh token that act as it. Implementations read a secrets mount or a
 * vault; credentials never come from .env or the database.
 */
interface CredentialResolver
{
    /**
     * @throws MissingCredentials
     */
    public function resolve(string $identity): Credentials;
}
