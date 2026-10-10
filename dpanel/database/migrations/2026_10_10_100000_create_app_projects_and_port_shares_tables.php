<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Node/Python apps that run from any folder of a site owner's home,
        // independent of a domain. drust names the systemd unit after
        // unit_key (dpanel-node-{unit_key} / dpanel-python-{unit_key}).
        Schema::create('app_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('runtime', 16);
            $table->string('site_owner', 64)->index();
            $table->string('working_directory', 512);
            $table->string('entry_file')->nullable();
            $table->string('start_command', 1000)->nullable();
            $table->string('version', 16)->nullable();
            $table->unsignedInteger('port')->unique();
            $table->unsignedSmallInteger('python_workers')->nullable();
            $table->string('python_mode', 16)->nullable();
            $table->unsignedSmallInteger('python_timeout')->nullable();
            $table->string('status', 16)->default('stopped');
            $table->string('unit_key', 64)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Read by the edge gateway (drust/src/edge_gateway/source.rs) on every
        // reload; keep column names in step with PORT_SHARES_SQL there.
        Schema::create('port_shares', function (Blueprint $table): void {
            $table->id();
            $table->string('website_id', 64)->index();
            // "/" or "/segment[/segment…]" without a trailing slash.
            $table->string('path_prefix')->default('/');
            $table->unsignedInteger('target_port');
            $table->unsignedBigInteger('app_project_id')->nullable()->index();
            $table->boolean('strip_prefix')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'path_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_shares');
        Schema::dropIfExists('app_projects');
    }
};
