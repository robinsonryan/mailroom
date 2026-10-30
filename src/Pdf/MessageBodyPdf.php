<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Pdf;

use RobinsonRyan\Mailroom\Contracts\BodyPdf;
use RobinsonRyan\Mailroom\Contracts\PdfRenderer;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;

/**
 * Composes the document asPdf renders: a From/To/Date/Subject block, then the
 * cleaned HTML part, or the text part when there is none. Remote images are
 * what usually sink a render (slow or dead hosts), so a failure earns exactly
 * one retry without them.
 */
final readonly class MessageBodyPdf implements BodyPdf
{
    private const HEADER_CELL = 'padding:2px 0;border:0;background:#fff;color:#000;text-align:left;vertical-align:top;font:13px/1.4 Helvetica,Arial,sans-serif';

    public function __construct(private PdfRenderer $renderer) {}

    public function render(MessageContent $message): string
    {
        $html = $this->compose($message);
        $date = $message->ref->receivedAt;

        try {
            return $this->renderer->render($html, $date);
        } catch (RenderFailed) {
            return $this->renderer->render($html, $date, allowRemoteImages: false);
        }
    }

    /**
     * @throws RenderFailed
     */
    private function compose(MessageContent $message): string
    {
        $mail = $this->hasHtml($message) ? MailHtml::clean((string) $message->html) : null;
        $body = $mail instanceof MailHtml
            ? $mail->body
            : '<pre style="white-space:pre-wrap">'.e((string) $message->text).'</pre>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.e($message->subject).'</title>'
            .$mail?->head.'</head><body>'.$this->headerBlock($message).$body.'</body></html>';
    }

    private function hasHtml(MessageContent $message): bool
    {
        return $message->html !== null && trim($message->html) !== '';
    }

    /**
     * Styled inline, cell by cell, so a mail's `th {...}` or `td {...}` cannot
     * restyle it by accident.
     */
    private function headerBlock(MessageContent $message): string
    {
        $rows = '';

        foreach ([
            'From' => $message->from,
            'To' => $message->to,
            'Date' => $message->ref->receivedAt->toRfc2822String(),
            'Subject' => $message->subject,
        ] as $label => $value) {
            $rows .= '<tr><th style="'.self::HEADER_CELL.';width:1%;padding-right:12px;font-weight:bold;white-space:nowrap">'.$label.'</th>'
                .'<td style="'.self::HEADER_CELL.';font-weight:normal;word-break:break-word">'.e($value).'</td></tr>';
        }

        return '<table style="width:100%;margin:0 0 16px;border-collapse:collapse;border:0;border-bottom:1px solid #999">'.$rows.'</table>';
    }
}
