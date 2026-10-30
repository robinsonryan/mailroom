<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Enums;

enum DecidedBy: string
{
    case Rule = 'rule';
    case Gateway = 'gateway';
    case Person = 'person';
}
