<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PlatformSeeder::class);
        $this->call(GameSeeder::class);

        // Test Admin
        User::firstOrCreate(
            ['phonenumber' => '09120000000'],
            [
                'name' => 'مدیر سیستم',
                'password' => \Illuminate\Support\Facades\Hash::make('password123'),
                'is_admin' => true,
            ]
        );

        // Test Customer
        $customer = User::firstOrCreate(
            ['phonenumber' => '09121111111'],
            [
                'name' => 'کاربر تستی',
                'password' => \Illuminate\Support\Facades\Hash::make('password123'),
                'is_admin' => false,
            ]
        );
        $customer->wallet()->firstOrCreate(['user_id' => $customer->id], ['balance' => 500000]);
    }
}
