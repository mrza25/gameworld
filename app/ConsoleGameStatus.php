<?php

namespace App\Enums;

enum ConsoleGameStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Installed = 'installed';
}
