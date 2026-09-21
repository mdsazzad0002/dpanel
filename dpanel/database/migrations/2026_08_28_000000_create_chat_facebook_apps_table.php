<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A registered Facebook App used to auto-connect one or more Pages
        // via OAuth ("Login with Facebook") instead of pasting a page access
        // token by hand. One app can own many chat_channels (one per page).
        Schema::create('chat_facebook_apps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('app_id');
            $table->text('app_secret'); // encrypted
            $table->string('verify_token'); // pasted into Meta's webhook dashboard for this app
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('chat_channels', function (Blueprint $table): void {
            $table->foreignUuid('chat_facebook_app_id')->nullable()->after('type')
                ->constrained('chat_facebook_apps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('chat_facebook_app_id');
        });

        Schema::dropIfExists('chat_facebook_apps');
    }
};
