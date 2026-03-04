<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Subscription;
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

        $resellerPro = Package::updateOrCreate(
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

        $superAdmin = User::firstOrCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ], [
            'password' => bcrypt('password'),
        ]);
        $superAdmin->syncRoles(['super_admin']);

        $reseller = User::firstOrCreate([
            'name' => 'Reseller User',
            'email' => 'reseller@example.com',
        ], [
            'password' => bcrypt('password'),
        ]);
        $reseller->syncRoles(['reseller']);

        $generalUser = User::firstOrCreate([
            'name' => 'General User',
            'email' => 'user@example.com',
        ], [
            'password' => bcrypt('password'),
        ]);
        $generalUser->syncRoles(['general_user']);

        Subscription::updateOrCreate(
            ['user_id' => $reseller->id, 'plan_name' => 'Reseller Pro'],
            [
                'package_id' => $resellerPro->id,
                'status' => 'active',
                'price' => $resellerPro->price,
                'started_at' => now(),
                'ends_at' => now()->addDays($resellerPro->duration_days),
                'used_mail_accounts' => 32,
                'used_disk_space_mb' => 12000,
                'used_databases' => 18,
                'used_files' => 72100,
            ],
        );

        Subscription::updateOrCreate(
            ['user_id' => $generalUser->id, 'plan_name' => 'Starter'],
            [
                'package_id' => $starter->id,
                'status' => 'active',
                'price' => $starter->price,
                'started_at' => now(),
                'ends_at' => now()->addDays($starter->duration_days),
                'used_mail_accounts' => 3,
                'used_disk_space_mb' => 1200,
                'used_databases' => 2,
                'used_files' => 2560,
            ],
        );
    }
}
