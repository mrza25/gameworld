<?php

namespace App;

enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Pending = 'pending';

    case Processing = 'processing';

    case Completed = 'completed';

    case Cancelled = 'cancelled';
}
