<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->index(['assigned_user_id', 'status'], 'websites_user_status_index');
            $table->index(['assigned_reseller_id', 'status'], 'websites_reseller_status_index');
            $table->index(['parent_id', 'type', 'domain'], 'websites_parent_type_domain_index');
            $table->index(['site_owner', 'php_version'], 'websites_owner_php_index');
        });
        Schema::table('website_metrics', function (Blueprint $table): void {
            $table->index(['website_id', 'captured_at'], 'website_metrics_site_captured_index');
        });
        Schema::table('migration_imports', function (Blueprint $table): void {
            $table->index(['created_by', 'created_at'], 'migration_imports_creator_created_index');
            $table->index(['assigned_reseller_id', 'created_at'], 'migration_imports_reseller_created_index');
            $table->index(['status', 'updated_at'], 'migration_imports_status_updated_index');
        });
        Schema::table('redis_config_revisions', function (Blueprint $table): void {
            $table->index(['website_id', 'created_at'], 'redis_revisions_site_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex('websites_user_status_index');
            $table->dropIndex('websites_reseller_status_index');
            $table->dropIndex('websites_parent_type_domain_index');
            $table->dropIndex('websites_owner_php_index');
        });
        Schema::table('website_metrics', fn (Blueprint $table) => $table->dropIndex('website_metrics_site_captured_index'));
        Schema::table('migration_imports', function (Blueprint $table): void {
            $table->dropIndex('migration_imports_creator_created_index');
            $table->dropIndex('migration_imports_reseller_created_index');
            $table->dropIndex('migration_imports_status_updated_index');
        });
        Schema::table('redis_config_revisions', fn (Blueprint $table) => $table->dropIndex('redis_revisions_site_created_index'));
    }
};
