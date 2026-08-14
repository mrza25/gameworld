<?php

namespace App;

enum ConsoleStatus: string
{
    case PENDING = 'pending';
    case INSTALLING = 'installing';
    case DELIVERED = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::INSTALLING => 'Installing',
            self::DELIVERED => 'Delivered',
        };
    }
}
