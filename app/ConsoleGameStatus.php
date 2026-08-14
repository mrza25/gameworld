<?php

namespace App;


enum ConsoleGameStatus: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::DELIVERED => 'Delivered',
        };
    }
}
