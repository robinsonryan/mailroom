<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Dead = 'dead';
}
