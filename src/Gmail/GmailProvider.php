<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use Illuminate\Http\Client\Response;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Data\Attachment;
use RobinsonRyan\Mailroom\Data\HistoryChange;
use RobinsonRyan\Mailroom\Data\HistoryPage;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Data\MessageSummary;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Models\Mailbox;

/**
 * The Gmail REST API behind MailProvider. A full messages.get costs 60 quota
 * units and history.list 2, so full fetches wait until a message needs reading.
 */
final readonly class GmailProvider implements MailProvider
{
    /** The most a Gmail list call returns per page. */
    private const PAGE_SIZE = 500;

    private const IGNORED_LABELS = ['DRAFT', 'SPAM', 'TRASH'];

    /** Leaving one of these is the only arrival Gmail reports for a message skipped in it. */
    private const RESCUE_LABELS = ['SPAM', 'TRASH'];

    public function __construct(
        private GmailClient $client,
        private GmailLabels $labels,
    ) {}

    public function currentCursor(Mailbox $mailbox): string
    {
        return (string) $this->client->json($this->client->request($mailbox, 'GET', 'profile'))['historyId'];
    }

    public function history(Mailbox $mailbox, string $cursor): HistoryPage
    {
        $query = [
            'startHistoryId' => $cursor,
            'historyTypes' => ['messageAdded', 'labelAdded', 'labelRemoved'],
            'maxResults' => self::PAGE_SIZE,
        ];
        $records = [];

        do {
            $response = $this->client->request($mailbox, 'GET', 'history', $query);

            if ($response->status() === 404) {
                throw new CursorExpired("History before {$cursor} is gone.");
            }

            $page = $this->client->json($response);
            $records = [...$records, ...(array) ($page['history'] ?? [])];
            $query['pageToken'] = $page['nextPageToken'] ?? null;
        } while ($query['pageToken'] !== null);

        return new HistoryPage($this->changes($mailbox, $records), (string) $page['historyId']);
    }

    public function search(Mailbox $mailbox, string $query, int $limit = 500): array
    {
        $params = ['q' => $query];
        $ids = [];

        do {
            $params['maxResults'] = min(self::PAGE_SIZE, $limit - count($ids));
            $page = $this->client->json($this->client->request($mailbox, 'GET', 'messages', $params));
            $ids = [...$ids, ...array_column((array) ($page['messages'] ?? []), 'id')];
            $params['pageToken'] = $page['nextPageToken'] ?? null;
        } while ($params['pageToken'] !== null && count($ids) < $limit);

        return array_slice($ids, 0, $limit);
    }

    public function summary(Mailbox $mailbox, string $providerMessageId, int $textLimit): MessageSummary
    {
        $message = $this->message($mailbox, $providerMessageId, 'full');

        return new MessageSummary(
            $providerMessageId,
            $message->threadId(),
            $message->receivedAt(),
            $message->header('From'),
            $message->header('Subject'),
            array_map(fn (Attachment $attachment): string => $attachment->filename, $message->attachments()),
            $message->summaryText($textLimit),
            array_values($this->labels->names($mailbox, $message->labelIds())),
        );
    }

    public function content(Mailbox $mailbox, string $providerMessageId): MessageContent
    {
        $message = $this->message($mailbox, $providerMessageId, 'full');

        return new MessageContent(
            new MessageRef($mailbox->id, $providerMessageId, $message->threadId(), $message->receivedAt()),
            $message->headers(),
            $message->header('From'),
            $message->header('To'),
            $message->header('Subject'),
            $message->text(),
            $message->html(),
            $message->attachments(),
        );
    }

    public function attachment(Mailbox $mailbox, string $providerMessageId, string $partId): string
    {
        $body = (new GmailMessage($this->resource($mailbox, $providerMessageId, 'full')))->partBody($partId);

        if (! isset($body['attachmentId'])) {
            throw new MessageNotFound("No part {$partId} in message {$providerMessageId}.");
        }

        $path = "{$this->messagePath($providerMessageId)}/attachments/".rawurlencode((string) $body['attachmentId']);
        $response = $this->client->request($mailbox, 'GET', $path);

        return GmailMessage::decode((string) $this->messageJson($response, $mailbox, $providerMessageId)['data']);
    }

    public function changeLabels(Mailbox $mailbox, string $providerMessageId, array $add, array $remove): void
    {
        $addIds = array_map(fn (string $name): string => $this->labels->ensure($mailbox, $name), $add);
        $removeIds = array_values(array_filter(array_map(fn (string $name): ?string => $this->labels->find($mailbox, $name), $remove)));

        if ($addIds === [] && $removeIds === []) {
            return;
        }

        $this->modify($mailbox, $providerMessageId, ['addLabelIds' => $addIds, 'removeLabelIds' => $removeIds]);
    }

    public function archive(Mailbox $mailbox, string $providerMessageId): void
    {
        $this->modify($mailbox, $providerMessageId, ['removeLabelIds' => ['INBOX']]);
    }

    public function insert(Mailbox $mailbox, string $rawMime, array $labels = []): string
    {
        $labelIds = array_map(fn (string $name): string => $this->labels->ensure($mailbox, $name), $labels);
        $response = $this->client->request($mailbox, 'POST', 'messages', ['internalDateSource' => 'dateHeader'], [
            'raw' => $this->encodeRaw($rawMime),
            'labelIds' => $labelIds,
        ], repeatable: false);

        return (string) $this->client->json($response)['id'];
    }

    public function draft(Mailbox $mailbox, OutgoingMessage $message): string
    {
        $response = $this->client->request($mailbox, 'POST', 'drafts', body: ['message' => $this->outgoing($mailbox, $message)], repeatable: false);

        return (string) $this->client->json($response)['id'];
    }

    public function send(Mailbox $mailbox, OutgoingMessage $message): string
    {
        $response = $this->client->request($mailbox, 'POST', 'messages/send', body: $this->outgoing($mailbox, $message), repeatable: false);

        return (string) $this->client->json($response)['id'];
    }

    /**
     * Gmail lists a record's own label ids per entry, and the message's current
     * labels alongside; DRAFT, SPAM and TRASH are read from the latter. A message
     * rescued from Spam or Trash arrives here: it was skipped when it landed, and
     * the watcher passes over one it has already typed.
     *
     * @param  array<int|string, mixed>  $records
     * @return list<HistoryChange>
     */
    private function changes(Mailbox $mailbox, array $records): array
    {
        $names = $this->labels->names($mailbox, $this->changedLabelIds($records));
        $changes = [];

        foreach ($records as $record) {
            foreach ($record['messagesAdded'] ?? [] as $entry) {
                if (! $this->ignored($entry)) {
                    $changes[] = $this->arrival($entry);
                }
            }

            foreach ($record['labelsRemoved'] ?? [] as $entry) {
                if (array_intersect($entry['labelIds'], self::RESCUE_LABELS) !== [] && ! $this->ignored($entry)) {
                    $changes[] = $this->arrival($entry);
                }
            }

            $changes = [
                ...$changes,
                ...$this->labelChanges(ChangeKind::LabelAdded, $record['labelsAdded'] ?? [], $names),
                ...$this->labelChanges(ChangeKind::LabelRemoved, $record['labelsRemoved'] ?? [], $names),
            ];
        }

        return $changes;
    }

    /** @param  array<string, mixed>  $entry  a history entry holding a message */
    private function arrival(array $entry): HistoryChange
    {
        return new HistoryChange(ChangeKind::MessageAdded, (string) $entry['message']['id'], (string) $entry['message']['threadId']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, string>  $names  id => name
     * @return list<HistoryChange>
     */
    private function labelChanges(ChangeKind $kind, array $entries, array $names): array
    {
        $changes = [];

        foreach ($entries as $entry) {
            $labels = array_values(array_intersect_key($names, array_flip($entry['labelIds'])));

            if ($labels !== [] && ! $this->ignored($entry)) {
                $changes[] = new HistoryChange($kind, (string) $entry['message']['id'], (string) $entry['message']['threadId'], $labels);
            }
        }

        return $changes;
    }

    /**
     * @param  array<int|string, mixed>  $records
     * @return list<string>
     */
    private function changedLabelIds(array $records): array
    {
        $ids = [];

        foreach ($records as $record) {
            foreach ([...$record['labelsAdded'] ?? [], ...$record['labelsRemoved'] ?? []] as $entry) {
                $ids = [...$ids, ...$entry['labelIds']];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param  array<string, mixed>  $entry  a history entry holding a message */
    private function ignored(array $entry): bool
    {
        return array_intersect($entry['message']['labelIds'] ?? [], self::IGNORED_LABELS) !== [];
    }

    private function message(Mailbox $mailbox, string $id, string $format): GmailMessage
    {
        return new GmailMessage($this->resource($mailbox, $id, $format));
    }

    /** @return array<string, mixed> */
    private function resource(Mailbox $mailbox, string $id, string $format): array
    {
        $response = $this->client->request($mailbox, 'GET', $this->messagePath($id), ['format' => $format]);

        return $this->messageJson($response, $mailbox, $id);
    }

    /** @param  array<string, mixed>  $body */
    private function modify(Mailbox $mailbox, string $id, array $body): void
    {
        $response = $this->client->request($mailbox, 'POST', "{$this->messagePath($id)}/modify", body: $body);
        $this->messageJson($response, $mailbox, $id);
    }

    /** @return array<string, mixed> */
    private function messageJson(Response $response, Mailbox $mailbox, string $id): array
    {
        if ($response->status() === 404) {
            throw new MessageNotFound("No message {$id} in {$mailbox->address}.");
        }

        return $this->client->json($response);
    }

    private function messagePath(string $id): string
    {
        return 'messages/'.rawurlencode($id);
    }

    /**
     * The API body for a new message; a reply reads the original's headers
     * (metadata costs a third of a full fetch) and joins its thread.
     *
     * @return array{raw: string, threadId?: string}
     */
    private function outgoing(Mailbox $mailbox, OutgoingMessage $message): array
    {
        if ($message->inReplyTo === null) {
            return ['raw' => $this->encodeRaw(RawMessage::render($mailbox->address, $message))];
        }

        $original = $this->message($mailbox, $message->inReplyTo, 'metadata');
        $messageId = $original->header('Message-ID');
        $references = trim($original->header('References').' '.$messageId);

        return [
            'raw' => $this->encodeRaw(RawMessage::render($mailbox->address, $message, $messageId, $references)),
            'threadId' => $original->threadId(),
        ];
    }

    private function encodeRaw(string $mime): string
    {
        return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    }
}
