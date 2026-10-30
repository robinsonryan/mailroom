<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Enums\DecidedBy;

/**
 * Contract A's MessageTyped. $confident is false when a gateway answer fell
 * below the type set's threshold, or was repeatedly not a type in the set: no
 * type label was written and the consumer decides what to do (capture asks a
 * person).
 */
final readonly class MessageTyped
{
    use Dispatchable;

    public function __construct(
        public MessageRef $ref,
        public string $typeSet,
        public string $type,
        public ?float $confidence,
        public DecidedBy $decidedBy,
        public bool $confident,
        public string $typingId,
    ) {}

    /**
     * The webhook payload (Contract A field names).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'message_ref' => $this->ref->toArray(),
            'type_set' => $this->typeSet,
            'type' => $this->type,
            'confidence' => $this->confidence,
            'decided_by' => $this->decidedBy->value,
            'confident' => $this->confident,
            'typing_id' => $this->typingId,
        ];
    }
}
