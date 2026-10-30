<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Contracts;

use RobinsonRyan\Mailroom\Data\HistoryPage;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Data\MessageSummary;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Exceptions\MessageUnreadable;
use RobinsonRyan\Mailroom\Models\Mailbox;

/**
 * What a mail system adapter implements. Speaks label NAMES, never provider
 * ids: the adapter maps names to ids and back, creating labels on demand.
 *
 * Every method may throw MailboxDisconnected when the provider refuses the
 * mailbox's credentials; transient failures (rate limits, 5xx) are retried
 * inside the adapter and surface as ProviderUnavailable when retries run out.
 */
interface MailProvider
{
    /** The provider's current history cursor, used to start watching from "now". */
    public function currentCursor(Mailbox $mailbox): string;

    /**
     * Changes after $cursor: messages added and labels added or removed.
     *
     * @throws CursorExpired when the provider no longer holds history that old
     * @throws MailboxDisconnected
     */
    public function history(Mailbox $mailbox, string $cursor): HistoryPage;

    /**
     * Message ids matching a provider-native query, newest first.
     *
     * @return list<string>
     */
    public function search(Mailbox $mailbox, string $query, int $limit = 500): array;

    /**
     * @throws MessageNotFound
     * @throws MessageUnreadable when the provider permanently refuses this one message
     */
    public function summary(Mailbox $mailbox, string $providerMessageId, int $textLimit): MessageSummary;

    /**
     * @throws MessageNotFound
     * @throws MessageUnreadable when the provider permanently refuses this one message
     */
    public function content(Mailbox $mailbox, string $providerMessageId): MessageContent;

    /**
     * The attachment's raw bytes.
     *
     * @throws MessageNotFound
     * @throws MessageUnreadable when the provider permanently refuses this one message
     */
    public function attachment(Mailbox $mailbox, string $providerMessageId, string $partId): string;

    /**
     * Add and remove labels by name in one call. Missing labels in $add are created.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function changeLabels(Mailbox $mailbox, string $providerMessageId, array $add, array $remove): void;

    /** Take the message out of the inbox; it stays in the mailbox. */
    public function archive(Mailbox $mailbox, string $providerMessageId): void;

    /**
     * Put a raw RFC 822 message into the mailbox without sending it.
     *
     * @param  list<string>  $labels
     * @return string the new provider message id
     */
    public function insert(Mailbox $mailbox, string $rawMime, array $labels = []): string;

    /** @return string the provider's draft id */
    public function draft(Mailbox $mailbox, OutgoingMessage $message): string;

    /** @return string the sent message's provider id */
    public function send(Mailbox $mailbox, OutgoingMessage $message): string;
}
