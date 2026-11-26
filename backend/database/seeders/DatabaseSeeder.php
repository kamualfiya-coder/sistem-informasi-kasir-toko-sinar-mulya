<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'nama' => 'Owner',
                'username' => 'owner',
                'password' => 'owner123',
                'role' => 'owner',
            ],
            [
                'nama' => 'Kasir',
                'username' => 'kasir',
                'password' => 'kasir123',
                'role' => 'cashier',
            ],
        ]);
    }
}
