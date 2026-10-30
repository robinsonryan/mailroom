<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Support;

use RobinsonRyan\Mailroom\Contracts\BodyPdf;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;

/** Placeholder until the PDF module binds the real composer. */
final class UnboundBodyPdf implements BodyPdf
{
    public function render(MessageContent $message): string
    {
        throw new RenderFailed('No BodyPdf implementation is bound.');
    }
}
