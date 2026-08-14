<?php

namespace App\Models;

use App\ConsoleGameStatus;
use Illuminate\Database\Eloquent\Model;

class ConsoleGame extends Model
{
    protected $casts = [

      'status'=>ConsoleGameStatus::class,

    ];
}
