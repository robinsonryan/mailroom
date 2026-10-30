<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Enums;

enum LabelAction: string
{
    case Add = 'add';
    case Remove = 'remove';
}
