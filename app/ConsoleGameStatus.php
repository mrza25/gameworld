<?php

namespace App;

enum ConsoleGameStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Installed = 'installed';
}
