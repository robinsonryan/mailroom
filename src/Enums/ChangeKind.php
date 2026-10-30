<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Enums;

enum ChangeKind: string
{
    case MessageAdded = 'message_added';
    case LabelAdded = 'label_added';
    case LabelRemoved = 'label_removed';
}
