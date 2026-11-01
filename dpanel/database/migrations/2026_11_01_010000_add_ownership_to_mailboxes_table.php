<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A mailbox belongs to the panel user who created it; an admin may
        // also assign it to a reseller. Admins see every mailbox.
        Schema::table('mailboxes', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('plan_id')->index();
            $table->foreignId('assigned_reseller_id')->nullable()->after('created_by')->index();
        });

        // Existing mailboxes had no owner: hand them to whoever owns the
        // website of their domain, so nobody loses the mailboxes they use now.
        DB::table('websites')
            ->whereNotNull('domain')
            ->where(fn ($query) => $query->whereNotNull('assigned_user_id')->orWhereNotNull('assigned_reseller_id'))
            ->orderBy('domain')
            ->get(['domain', 'assigned_user_id', 'assigned_reseller_id'])
            ->unique(fn ($website) => strtolower(trim((string) $website->domain)))
            ->each(function ($website): void {
                DB::table('mailboxes')
                    ->whereRaw('LOWER(domain) = ?', [strtolower(trim((string) $website->domain))])
                    ->whereNull('created_by')
                    ->update([
                        'created_by' => $website->assigned_user_id ?: $website->assigned_reseller_id,
                        'assigned_reseller_id' => $website->assigned_reseller_id,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['assigned_reseller_id']);
            $table->dropColumn(['created_by', 'assigned_reseller_id']);
        });
    }
};
