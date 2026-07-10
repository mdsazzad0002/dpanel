<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $admin = User::firstOrCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ], [
            'password' => bcrypt('password'),
        ]);
        $admin->syncRoles(['admin']);
    }
}
