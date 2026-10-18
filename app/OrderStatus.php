<?php

namespace App;

enum OrderStatus: string
{
    case Pending = 'pending';

    case Processing = 'processing';

    case Completed = 'completed';
}
