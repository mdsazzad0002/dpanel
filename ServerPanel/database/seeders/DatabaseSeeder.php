<?php

namespace Database\Seeders;

use App\Models\Package;
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

        $starter = Package::updateOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter',
                'description' => 'Starter package for small websites.',
                'price' => 9.99,
                'duration_days' => 30,
                'is_active' => true,
                'mail_accounts_limit' => 10,
                'disk_space_mb_limit' => 10240,
                'databases_limit' => 5,
                'files_limit' => 10000,
            ],
        );

        Package::updateOrCreate(
            ['slug' => 'reseller-pro'],
            [
                'name' => 'Reseller Pro',
                'description' => 'Reseller package for multi-client hosting.',
                'price' => 49.99,
                'duration_days' => 30,
                'is_active' => true,
                'mail_accounts_limit' => 200,
                'disk_space_mb_limit' => 102400,
                'databases_limit' => 100,
                'files_limit' => 500000,
            ],
        );

        $admin = User::firstOrCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ], [
            'password' => bcrypt('password'),
            'package_id' => $starter->id,
        ]);
        $admin->syncRoles(['admin']);
    }
}
