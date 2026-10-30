<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Credentials;

use Illuminate\Contracts\Config\Repository;
use RobinsonRyan\Mailroom\Contracts\CredentialResolver;
use RobinsonRyan\Mailroom\Data\Credentials;
use RobinsonRyan\Mailroom\Exceptions\MissingCredentials;

/**
 * Reads <mailroom.credentials.directory>/<identity>.json holding
 * {client_id, client_secret, refresh_token}. On the hub that directory is a
 * read-only tmpfs mount. Errors name the identity, never the file's contents.
 */
final readonly class FileCredentialResolver implements CredentialResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(string $identity): Credentials
    {
        if (strpbrk($identity, '/\\.') !== false) {
            throw new MissingCredentials("Identity {$identity} cannot name a credentials file.");
        }

        $path = $this->config->get('mailroom.credentials.directory').'/'.$identity.'.json';
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new MissingCredentials("No credentials file for {$identity}.");
        }

        $json = json_decode($contents, true);
        $fields = is_array($json) ? $json : [];

        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            if (! is_string($fields[$key] ?? null) || $fields[$key] === '') {
                throw new MissingCredentials("The credentials file for {$identity} is malformed.");
            }
        }

        return new Credentials($fields['client_id'], $fields['client_secret'], $fields['refresh_token']);
    }
}
