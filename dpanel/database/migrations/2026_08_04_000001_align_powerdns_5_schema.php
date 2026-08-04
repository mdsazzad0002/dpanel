<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domains')) {
            Schema::create('domains', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('master', 128)->nullable();
                $table->integer('last_check')->nullable();
                $table->string('type', 16)->default('NATIVE');
                $table->unsignedInteger('notified_serial')->nullable();
                $table->string('account', 40)->nullable();
                $table->text('options')->nullable();
                $table->string('catalog')->nullable()->index();
            });
        } else {
            Schema::table('domains', function (Blueprint $table): void {
                if (! Schema::hasColumn('domains', 'options')) {
                    $table->text('options')->nullable()->after('account');
                }
                if (! Schema::hasColumn('domains', 'catalog')) {
                    $table->string('catalog')->nullable()->index()->after('options');
                }
            });
        }

        if (! Schema::hasTable('records')) {
            Schema::create('records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('domain_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('type', 16)->nullable();
                $table->text('content')->nullable();
                $table->unsignedInteger('ttl')->nullable();
                $table->unsignedInteger('prio')->nullable();
                $table->boolean('disabled')->default(false);
                $table->string('ordername')->nullable()->index();
                $table->boolean('auth')->default(true);
                $table->index(['name', 'type']);
            });
        }

        if (! Schema::hasTable('supermasters')) {
            Schema::create('supermasters', function (Blueprint $table): void {
                $table->string('ip', 64);
                $table->string('nameserver');
                $table->string('account', 40);
                $table->primary(['ip', 'nameserver']);
            });
        }

        if (! Schema::hasTable('comments')) {
            Schema::create('comments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('domain_id');
                $table->string('name');
                $table->string('type', 10);
                $table->unsignedInteger('modified_at');
                $table->string('account', 40)->nullable();
                $table->text('comment');
                $table->index(['name', 'type']);
                $table->index(['domain_id', 'modified_at']);
            });
        }

        if (! Schema::hasTable('domainmetadata')) {
            Schema::create('domainmetadata', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('domain_id');
                $table->string('kind', 32)->nullable();
                $table->text('content')->nullable();
                $table->index(['domain_id', 'kind']);
            });
        }

        if (! Schema::hasTable('cryptokeys')) {
            Schema::create('cryptokeys', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('domain_id');
                $table->unsignedInteger('flags');
                $table->boolean('active')->nullable();
                $table->boolean('published')->default(true);
                $table->text('content')->nullable();
                $table->index('domain_id');
            });
        }

        if (! Schema::hasTable('tsigkeys')) {
            Schema::create('tsigkeys', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('algorithm', 50)->nullable();
                $table->string('secret')->nullable();
                $table->unique(['name', 'algorithm']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tsigkeys');
        Schema::dropIfExists('cryptokeys');
        Schema::dropIfExists('domainmetadata');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('supermasters');

        Schema::table('domains', function (Blueprint $table): void {
            $columns = array_values(array_filter(['options', 'catalog'], fn (string $column): bool => Schema::hasColumn('domains', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
