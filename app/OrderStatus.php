<?php

namespace App;

enum OrderStatus:string
{
    case Pending = 'pending';

    case Received = 'received';

    case Installing = 'installing';

    case Ready = 'ready';

    case Delivered = 'delivered';

    case Cancelled = 'cancelled';
}
