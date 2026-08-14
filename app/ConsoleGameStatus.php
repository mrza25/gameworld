<?php

namespace App;


enum ConsoleGameStatus: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
}
