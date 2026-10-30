<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Typing;

use Illuminate\Support\Facades\Log;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Models\Typing;
use Throwable;

/**
 * Fires MessageTyped for a saved typing. A queued listener only enqueues here,
 * but on the sync queue, or when the push itself fails, its exception lands in
 * the middle of a tick. The typing stands either way, and replaying it would
 * find it already typed and fire nothing, so the failure is logged with the
 * typing's id for mailroom:announce to fire it again.
 */
final class TypingAnnouncer
{
    /** @return bool whether every listener took it */
    public function announce(Typing $typing): bool
    {
        $message = $typing->message;

        try {
            MessageTyped::dispatch($message->ref(), $typing->type_set, $typing->type, $typing->confidence, $typing->decided_by, $typing->confident, $typing->id);
        } catch (Throwable $e) {
            Log::error('A MessageTyped listener failed. Once it is fixed, run mailroom:announce with this typing_id.', [
                'typing_id' => $typing->id,
                'message_id' => $message->id,
                'type_set' => $typing->type_set,
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }
}
