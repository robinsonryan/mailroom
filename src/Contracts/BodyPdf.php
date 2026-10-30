<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Contracts;

use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;

/**
 * Contract A's asPdf: a message body rendered to PDF with a
 * From/To/Date/Subject block on top and CreationDate = the message date.
 */
interface BodyPdf
{
    /**
     * @throws RenderFailed
     */
    public function render(MessageContent $message): string;
}
