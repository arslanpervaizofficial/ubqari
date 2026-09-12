<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@ubqari.pos'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'password' => Hash::make('password'), // CHANGE THIS after first login
                'role' => 'admin',
            ]
        );

        $this->call(ProductSeeder::class);
    }
}
