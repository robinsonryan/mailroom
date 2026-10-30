<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use Illuminate\Contracts\Cache\Repository;
use RobinsonRyan\Mailroom\Models\Mailbox;

/**
 * Each mailbox's label id <-> name map, cached and re-read on a miss. Gmail
 * history and modify speak ids; everything above the adapter speaks names.
 */
final readonly class GmailLabels
{
    private const CACHE = 'mailroom:gmail:labels:';

    /** Bounds how long a renamed label keeps its old name. */
    private const CACHE_SECONDS = 3600;

    public function __construct(
        private GmailClient $client,
        private Repository $cache,
    ) {}

    /**
     * Names for these ids. A miss re-reads the labels once; an id still unknown
     * after that is a label deleted since, and is left out.
     *
     * @param  list<string>  $ids
     * @return array<string, string> id => name
     */
    public function names(Mailbox $mailbox, array $ids): array
    {
        $map = $this->cached($mailbox);

        if (array_diff($ids, array_keys($map)) !== []) {
            $map = $this->refresh($mailbox);
        }

        return array_intersect_key($map, array_flip($ids));
    }

    /** The label's id, or null when the mailbox has no such label. */
    public function find(Mailbox $mailbox, string $name): ?string
    {
        $id = array_search($name, $this->cached($mailbox), true);

        if ($id === false) {
            $id = array_search($name, $this->refresh($mailbox), true);
        }

        return $id === false ? null : (string) $id;
    }

    /** The label's id, creating the label under this literal name if need be. */
    public function ensure(Mailbox $mailbox, string $name): string
    {
        return $this->find($mailbox, $name) ?? $this->create($mailbox, $name);
    }

    private function create(Mailbox $mailbox, string $name): string
    {
        $response = $this->client->request($mailbox, 'POST', 'labels', body: [
            'name' => $name,
            'labelListVisibility' => 'labelShow',
            'messageListVisibility' => 'show',
        ]);

        // Another worker got there first; the label now exists.
        if ($response->status() === 409) {
            $id = array_search($name, $this->refresh($mailbox), true);

            if ($id !== false) {
                return (string) $id;
            }
        }

        $id = (string) $this->client->json($response)['id'];
        $this->remember($mailbox, [...$this->cached($mailbox), $id => $name]);

        return $id;
    }

    /** @return array<string, string> id => name */
    private function cached(Mailbox $mailbox): array
    {
        $cached = $this->cache->get(self::CACHE.$mailbox->id);

        return is_array($cached) ? $cached : [];
    }

    /** @return array<string, string> id => name */
    private function refresh(Mailbox $mailbox): array
    {
        $labels = $this->client->json($this->client->request($mailbox, 'GET', 'labels'))['labels'] ?? [];
        $map = array_column((array) $labels, 'name', 'id');
        $this->remember($mailbox, $map);

        return $map;
    }

    /** @param  array<string, string>  $map */
    private function remember(Mailbox $mailbox, array $map): void
    {
        $this->cache->put(self::CACHE.$mailbox->id, $map, self::CACHE_SECONDS);
    }
}
