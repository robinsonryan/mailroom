<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use RobinsonRyan\Mailroom\Contracts\CredentialResolver;
use RobinsonRyan\Mailroom\Credentials\FileCredentialResolver;
use RobinsonRyan\Mailroom\Exceptions\MissingCredentials;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/mailroom-credentials-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->directory.'/nested');
    config()->set('mailroom.credentials.directory', $this->directory);
    $this->valid = json_encode(['client_id' => 'client', 'client_secret' => 'secret', 'refresh_token' => 'refresh']);
    $this->resolver = app(FileCredentialResolver::class);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('reads the identity file in the configured directory', function (): void {
    file_put_contents($this->directory.'/shop-info.json', $this->valid);

    $credentials = $this->resolver->resolve('shop-info');

    expect($credentials->clientId)->toBe('client')
        ->and($credentials->clientSecret)->toBe('secret')
        ->and($credentials->refreshToken)->toBe('refresh');
});

it('reports a missing file as missing credentials', function (): void {
    $this->resolver->resolve('shop-info');
})->throws(MissingCredentials::class);

it('reports a malformed file as missing credentials, without echoing it', function (string $contents): void {
    file_put_contents($this->directory.'/shop-info.json', $contents);

    try {
        $this->resolver->resolve('shop-info');
        $this->fail('Expected MissingCredentials.');
    } catch (MissingCredentials $e) {
        expect($e->getMessage())->toContain('shop-info')->not->toContain('hunter2');
    }
})->with([
    'not json' => 'hunter2 {',
    'a json string' => '"hunter2"',
    'a json list' => '["hunter2"]',
    'no client id' => '{"client_secret":"hunter2","refresh_token":"hunter2"}',
    'no client secret' => '{"client_id":"a","refresh_token":"hunter2"}',
    'no refresh token' => '{"client_id":"a","client_secret":"hunter2"}',
    'an empty refresh token' => '{"client_id":"a","client_secret":"hunter2","refresh_token":""}',
    'a non-string value' => '{"client_id":"a","client_secret":"hunter2","refresh_token":123}',
]);

it('rejects an identity with a path separator or a dot, even when that file exists', function (string $identity, string $file): void {
    file_put_contents($this->directory.'/'.$file, $this->valid);

    $this->resolver->resolve($identity);
})->with([
    'a slash' => ['nested/box', 'nested/box.json'],
    'a parent traversal' => ['nested/../box', 'box.json'],
    'a backslash' => ['nested\\box', 'nested\\box.json'],
    'a dot' => ['shop.info', 'shop.info.json'],
])->throws(MissingCredentials::class);

it('is the bound credential resolver', function (): void {
    expect(app(CredentialResolver::class))->toBeInstanceOf(FileCredentialResolver::class);
});
