<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use Symfony\Component\Mime\Email;

/**
 * Renders an OutgoingMessage as RFC 822, with Symfony Mime doing the encoding.
 */
final class RawMessage
{
    /**
     * @param  string|null  $inReplyTo  the Message-ID being answered
     * @param  string|null  $references  the thread's References, ending in that Message-ID
     */
    public static function render(string $from, OutgoingMessage $message, ?string $inReplyTo = null, ?string $references = null): string
    {
        $email = (new Email)
            ->from($from)
            ->to(...$message->to)
            ->cc(...$message->cc)
            ->subject($message->subject)
            ->text($message->text)
            ->html($message->html);

        // Text headers, not id headers: those refuse any Message-ID that is not
        // strictly RFC-shaped, and a reply must not fail over someone else's.
        foreach (['In-Reply-To' => $inReplyTo, 'References' => $references] as $name => $value) {
            if ((string) $value !== '') {
                $email->getHeaders()->addTextHeader($name, (string) preg_replace('/\s+/', ' ', (string) $value));
            }
        }

        return $email->toString();
    }
}
