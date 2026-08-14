<?php

namespace App\Models;

use App\ConsoleStatus;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

class Console extends Model
{
    protected $casts = [
        'status'=> ConsoleStatus::class,
    ];
}
