<?php

namespace App;

enum ConsoleStatus: string
{
    case PENDING = 'pending';
    case INSTALLING = 'installing';
    case DELIVERED = 'delivered';
}
