<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        $admin = User::where('email', 'admin@komiko.id')->first();
        if (!$admin) {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Admin KomikoID',
                'email' => 'admin@komiko.id', 
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'status' => 'active',
            ]);
        }

        // Create regular user
        $user = User::where('email', 'user@komiko.id')->first();
        if (!$user) {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'User Demo',
                'email' => 'user@komiko.id',
                'password' => Hash::make('password123'),
                'role' => 'user',
                'status' => 'active',
            ]);
        }
    }
}
