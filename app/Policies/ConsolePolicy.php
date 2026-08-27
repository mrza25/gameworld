<?php

namespace App\Policies;

use App\Models\Console;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConsolePolicy
{
    public function view(User $user, Console $console): bool
    {
        return $user->id === $console->user_id || $user->is_admin;
    }

    public function update(User $user, Console $console): bool
    {
        return $user->id === $console->user_id || $user->is_admin;
    }

    public function delete(User $user, Console $console): bool
    {
        return $user->id === $console->user_id || $user->is_admin;
    }

    public function updateStatus(User $user, Console $console): bool
    {
        return $user->is_admin;
    }

    public function manageGames(User $user, Console $console): bool
    {
        return $user->id === $console->user_id || $user->is_admin;
    }
}
