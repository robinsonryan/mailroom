<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Contract;

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Gmail\GmailMessage;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;

/**
 * The real Gmail adapter against an in-memory Gmail: Http::fake answering with
 * the REST shapes the recorded fixtures show, keeping state between calls. It
 * covers what the adapter asks of Gmail, not Gmail.
 */
final class GmailWorld implements MailWorld
{
    /** @var array<string, string> id => name; a system label's id is its name */
    private array $labels = ['INBOX' => 'INBOX', 'UNREAD' => 'UNREAD', 'DRAFT' => 'DRAFT', 'SPAM' => 'SPAM', 'TRASH' => 'TRASH'];

    /** @var array<string, array<string, mixed>> id => users.messages resource */
    private array $messages = [];

    /** @var array<string, string> attachmentId => base64url */
    private array $stored = [];

    /** @var array<int, array<string, mixed>> historyId => record */
    private array $history = [];

    private int $historyId = 100;

    private int $expiredBefore = 0;

    private bool $disconnected = false;

    private readonly MailProvider $provider;

    private readonly Mailbox $mailbox;

    public function __construct()
    {
        $this->provider = GmailApi::provider();
        $this->mailbox = GmailApi::mailbox();

        Http::preventStrayRequests();
        Http::fake(fn (Request $request): PromiseInterface => $this->answer($request));
    }

    public function provider(): MailProvider
    {
        return $this->provider;
    }

    public function mailbox(): Mailbox
    {
        return $this->mailbox;
    }

    public function deliver(
        string $from = 'Sender <sender@example.com>',
        string $subject = 'Hello',
        ?string $text = 'Body',
        ?string $html = null,
        array $attachments = [],
        ?CarbonImmutable $receivedAt = null,
        array $labels = [],
    ): string {
        $received = $receivedAt ?? CarbonImmutable::now();
        $headers = [
            'From' => $from,
            'To' => $this->mailbox->address,
            'Subject' => $subject,
            'Date' => $received->toRfc2822String(),
        ];

        $labelIds = array_map($this->ensureLabel(...), ['INBOX', ...$labels]);

        return $this->store($headers, $text, $html, $attachments, $received, $labelIds);
    }

    public function personLabels(string $id, array $add = [], array $remove = []): void
    {
        $this->modify($id, array_map($this->ensureLabel(...), $add), array_map($this->ensureLabel(...), $remove));
    }

    public function expireHistory(): void
    {
        $this->expiredBefore = $this->historyId + 1;
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    public function labelsOf(string $id): array
    {
        return array_map(fn (string $labelId): string => $this->labels[$labelId], $this->messages[$id]['labelIds']);
    }

    private function answer(Request $request): PromiseInterface
    {
        if ($request->url() === GmailApi::TOKEN_URL) {
            return $this->disconnected
                ? Http::response(GmailApi::fixture('token-invalid-grant'), 400)
                : Http::response(GmailApi::fixture('token'));
        }

        if ($this->disconnected) {
            return Http::response(GmailApi::fixture('error-unauthorized'), 401);
        }

        $path = (string) parse_url(substr($request->url(), strlen(GmailApi::BASE)), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $body = (array) $request->data();

        return match (true) {
            $path === 'profile' => Http::response(['historyId' => (string) $this->historyId]),
            $path === 'labels' && $request->method() === 'GET' => $this->listLabels(),
            $path === 'labels' => Http::response(['id' => $this->ensureLabel((string) $body['name'])]),
            $path === 'history' => $this->listHistory((int) $query['startHistoryId']),
            $path === 'messages' && $request->method() === 'GET' => $this->listMessages((int) $query['maxResults']),
            $path === 'messages' => Http::response(['id' => $this->insertRaw($body)]),
            $path === 'messages/send' => Http::response(['id' => 'sent-'.(++$this->historyId)]),
            $path === 'drafts' => Http::response(['id' => 'draft-'.(++$this->historyId)]),
            default => $this->onMessage(explode('/', $path), $body),
        };
    }

    /**
     * @param  list<string>  $path  messages, id, then what is asked of it
     * @param  array<array-key, mixed>  $body
     */
    private function onMessage(array $path, array $body): PromiseInterface
    {
        $id = rawurldecode($path[1]);

        if (! isset($this->messages[$id])) {
            return Http::response(GmailApi::fixture('error-not-found'), 404);
        }

        if (($path[2] ?? '') === 'attachments') {
            return Http::response(['data' => $this->stored[rawurldecode($path[3])]]);
        }

        if (($path[2] ?? '') === 'modify') {
            $this->modify($id, (array) ($body['addLabelIds'] ?? []), (array) ($body['removeLabelIds'] ?? []));
        }

        return Http::response($this->messages[$id]);
    }

    private function listLabels(): PromiseInterface
    {
        $labels = [];
        foreach ($this->labels as $id => $name) {
            $labels[] = ['id' => $id, 'name' => $name];
        }

        return Http::response(['labels' => $labels]);
    }

    private function listHistory(int $start): PromiseInterface
    {
        if ($start < $this->expiredBefore) {
            return Http::response(GmailApi::fixture('error-not-found'), 404);
        }

        $records = array_filter($this->history, fn (int $id): bool => $id > $start, ARRAY_FILTER_USE_KEY);

        return Http::response(['history' => array_values($records), 'historyId' => (string) $this->historyId]);
    }

    private function listMessages(int $limit): PromiseInterface
    {
        $messages = $this->messages;
        usort($messages, fn (array $a, array $b): int => (int) $b['internalDate'] <=> (int) $a['internalDate']);

        return Http::response(['messages' => array_map(
            fn (array $message): array => ['id' => $message['id'], 'threadId' => $message['threadId']],
            array_slice($messages, 0, $limit),
        )]);
    }

    /** @param  array<array-key, mixed>  $body */
    private function insertRaw(array $body): string
    {
        [$head, $text] = explode("\r\n\r\n", GmailMessage::decode((string) $body['raw']), 2);
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[$name] = trim($value);
        }

        return $this->store($headers, $text, null, [], CarbonImmutable::now(), array_values((array) ($body['labelIds'] ?? [])));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array{name: string, mime: string, bytes: string}>  $attachments
     * @param  list<string>  $labelIds
     */
    private function store(array $headers, ?string $text, ?string $html, array $attachments, CarbonImmutable $received, array $labelIds): string
    {
        $id = 'g'.(++$this->historyId);
        $parts = [];

        foreach (['text/plain' => $text, 'text/html' => $html] as $mime => $content) {
            if ($content !== null) {
                $parts[] = ['partId' => (string) count($parts), 'mimeType' => $mime, 'filename' => '', 'body' => ['size' => strlen($content), 'data' => GmailApi::encode($content)]];
            }
        }

        foreach ($attachments as $attachment) {
            $stored = 'stored-'.$id.'-'.count($parts);
            $this->stored[$stored] = GmailApi::encode($attachment['bytes']);
            $parts[] = ['partId' => (string) count($parts), 'mimeType' => $attachment['mime'], 'filename' => $attachment['name'], 'body' => ['size' => strlen($attachment['bytes']), 'attachmentId' => $stored]];
        }

        $this->messages[$id] = [
            'id' => $id,
            'threadId' => 't'.$id,
            'labelIds' => $labelIds,
            'internalDate' => (string) $received->getTimestampMs(),
            'payload' => [
                'partId' => '',
                'mimeType' => 'multipart/mixed',
                'filename' => '',
                'headers' => array_map(fn (string $name, string $value): array => ['name' => $name, 'value' => $value], array_keys($headers), $headers),
                'body' => ['size' => 0],
                'parts' => $parts,
            ],
        ];

        $this->record(['messagesAdded' => [['message' => $this->reference($id)]]]);

        return $id;
    }

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    private function modify(string $id, array $add, array $remove): void
    {
        $before = $this->messages[$id]['labelIds'];
        $after = array_values(array_unique(array_diff([...$before, ...$add], $remove)));
        $this->messages[$id]['labelIds'] = $after;

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== []) {
            $this->record(['labelsAdded' => [['message' => $this->reference($id), 'labelIds' => $added]]]);
        }

        if ($removed !== []) {
            $this->record(['labelsRemoved' => [['message' => $this->reference($id), 'labelIds' => $removed]]]);
        }
    }

    /** @return array{id: string, threadId: string, labelIds: list<string>} */
    private function reference(string $id): array
    {
        $message = $this->messages[$id];

        return ['id' => $id, 'threadId' => $message['threadId'], 'labelIds' => $message['labelIds']];
    }

    /** @param  array<string, mixed>  $record */
    private function record(array $record): void
    {
        $this->history[++$this->historyId] = ['id' => (string) $this->historyId, ...$record];
    }

    private function ensureLabel(string $name): string
    {
        $id = array_search($name, $this->labels, true);

        if ($id === false) {
            $id = 'Label_'.count($this->labels);
            $this->labels[$id] = $name;
        }

        return (string) $id;
    }
}
