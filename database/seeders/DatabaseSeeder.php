<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PlatformSeeder::class);
        $this->call(GameSeeder::class);

        $password = config('app.seed_password')
            ?: (app()->environment(['local', 'testing']) ? 'password123' : null);

        // The default password is no secret, so it must not open accounts on a public server.
        if (blank($password)) {
            $this->command?->warn('Test accounts were skipped: set SEED_PASSWORD to create them.');

            return;
        }

        // Test Admin
        User::firstOrCreate(
            ['phonenumber' => '09120000000'],
            [
                'name' => 'مدیر سیستم',
                'password' => Hash::make($password),
                'is_admin' => true,
            ]
        );

        // Test Customer
        $customer = User::firstOrCreate(
            ['phonenumber' => '09121111111'],
            [
                'name' => 'کاربر تستی',
                'password' => Hash::make($password),
                'is_admin' => false,
            ]
        );
        $customer->wallet()->firstOrCreate(['user_id' => $customer->id], ['balance' => 500000]);
    }
}
