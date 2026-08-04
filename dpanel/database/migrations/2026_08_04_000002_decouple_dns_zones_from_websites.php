<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->unsignedBigInteger('powerdns_domain_id')->nullable()->unique()->after('id');
            $table->uuid('website_id')->nullable()->index()->after('domain');
            $table->unsignedBigInteger('assigned_reseller_id')->nullable()->index()->after('assigned_user_id');
            $table->string('source', 32)->default('standalone')->index();
            $table->string('provider', 32)->default('powerdns');
            $table->string('mode', 32)->default('authoritative');
            $table->boolean('dnssec_enabled')->default(false);
            $table->boolean('proxy_enabled')->default(false);
            $table->boolean('logging_enabled')->default(false);
            $table->boolean('analytics_enabled')->default(false);
            $table->json('settings')->nullable();
        });

        Schema::table('dns_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('powerdns_record_id')->nullable()->unique()->after('id');
            $table->boolean('proxied')->default(false);
            $table->json('settings')->nullable();
        });

        $now = now();
        foreach (DB::table('domains')->orderBy('id')->get() as $domain) {
            $name = strtolower(trim((string) $domain->name));
            $website = Schema::hasTable('websites')
                ? DB::table('websites')->whereRaw('LOWER(domain) = ?', [$name])->first()
                : null;
            $zone = DB::table('dns_zones')->where('domain', $name)->first();
            $zoneId = $zone?->id ?: (string) Str::uuid();

            if ($zone) {
                DB::table('dns_zones')->where('id', $zoneId)->update([
                    'powerdns_domain_id' => $domain->id,
                    'website_id' => $website?->id,
                    'assigned_user_id' => $zone->assigned_user_id ?? $website?->assigned_user_id,
                    'assigned_reseller_id' => $website?->assigned_reseller_id,
                    'source' => $website ? 'website' : 'legacy',
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('dns_zones')->insert([
                    'id' => $zoneId,
                    'powerdns_domain_id' => $domain->id,
                    'domain' => $name,
                    'website_id' => $website?->id,
                    'server_id' => null,
                    'status' => 'active',
                    'assigned_user_id' => $website?->assigned_user_id,
                    'assigned_reseller_id' => $website?->assigned_reseller_id,
                    'source' => $website ? 'website' : 'legacy',
                    'provider' => 'powerdns',
                    'mode' => 'authoritative',
                    'dnssec_enabled' => false,
                    'proxy_enabled' => false,
                    'logging_enabled' => false,
                    'analytics_enabled' => false,
                    'settings' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (DB::table('records')->where('domain_id', $domain->id)->where('type', '!=', 'SOA')->get() as $record) {
                if (DB::table('dns_records')->where('powerdns_record_id', $record->id)->exists()) {
                    continue;
                }
                DB::table('dns_records')->insert([
                    'id' => (string) Str::uuid(),
                    'powerdns_record_id' => $record->id,
                    'dns_zone_id' => $zoneId,
                    'type' => $record->type,
                    'name' => $record->name,
                    'content' => $record->content,
                    'ttl' => $record->ttl ?: 3600,
                    'priority' => $record->prio,
                    'is_active' => ! (bool) $record->disabled,
                    'proxied' => false,
                    'settings' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('dns_records', function (Blueprint $table): void {
            $table->dropUnique(['powerdns_record_id']);
            $table->dropColumn(['powerdns_record_id', 'proxied', 'settings']);
        });
        Schema::table('dns_zones', function (Blueprint $table): void {
            $table->dropUnique(['powerdns_domain_id']);
            $table->dropColumn(['powerdns_domain_id', 'website_id', 'assigned_reseller_id', 'source', 'provider', 'mode', 'dnssec_enabled', 'proxy_enabled', 'logging_enabled', 'analytics_enabled', 'settings']);
        });
    }
};
