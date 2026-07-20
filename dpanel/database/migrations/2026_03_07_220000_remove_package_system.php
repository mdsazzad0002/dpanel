<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'package_id')) {
            $this->dropForeignKeysForColumn('users', 'package_id');
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('package_id');
            });
        }

        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'package_id')) {
            $this->dropForeignKeysForColumn('subscriptions', 'package_id');
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('package_id');
            });
        }

        Schema::dropIfExists('packages');
    }

    public function down(): void
    {
        if (! Schema::hasTable('packages')) {
            Schema::create('packages', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->unsignedInteger('duration_days')->default(30);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('mail_accounts_limit')->nullable();
                $table->unsignedBigInteger('disk_space_mb_limit')->nullable();
                $table->unsignedInteger('databases_limit')->nullable();
                $table->unsignedBigInteger('files_limit')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'package_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('package_id')->nullable()->after('reseller_id')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'package_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreignId('package_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            });
        }
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $database = DB::getDatabaseName();
        if (! is_string($database) || $database === '') {
            return;
        }

        $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->values()
            ->all();

        foreach ($constraints as $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                str_replace('`', '``', $table),
                str_replace('`', '``', (string) $constraint),
            ));
        }
    }
};
