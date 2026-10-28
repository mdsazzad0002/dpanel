<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI Chat Engine (businesses, channels, conversations, Facebook apps and
 * posts, scheduled messages, website chat widget) was removed; drop its data.
 */
return new class extends Migration
{
    /** Children before parents. */
    private const TABLES = [
        'chat_broadcast_deliveries',
        'chat_scheduled_messages',
        'chat_messages',
        'chat_conversations',
        'chat_contacts',
        'facebook_page_activities',
        'facebook_page_posts',
        'chat_channels',
        'chat_facebook_apps',
        'business_data_sources',
        'business_qnas',
        'business_products',
        'businesses',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        if (Schema::hasColumn('package_plans', 'max_ai_replies')) {
            Schema::table('package_plans', function (Blueprint $table): void {
                $table->dropColumn('max_ai_replies');
            });
        }
    }

    public function down(): void
    {
        // The dropped data cannot be restored, and the feature is gone.
    }
};
