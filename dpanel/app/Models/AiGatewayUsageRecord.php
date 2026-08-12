<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AiGatewayUsageRecord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'provider_id',
        'model_id',
        'usage_date',
        'requests',
        'input_tokens',
        'output_tokens',
        'cost',
        'failures',
    ];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'requests' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'float',
            'failures' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiGatewayProvider::class, 'provider_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiGatewayModel::class, 'model_id');
    }

    /**
     * Record aggregated usage for a completed request, upserting a daily row.
     */
    public static function record(
        ?int $providerId,
        ?int $modelId,
        float $inputTokens,
        float $outputTokens,
        float $cost,
        int $failures = 0
    ): void {
        DB::transaction(function () use ($providerId, $modelId, $inputTokens, $outputTokens, $cost, $failures): void {
            $row = self::query()
                ->where('provider_id', $providerId)
                ->where('model_id', $modelId)
                ->where('usage_date', now()->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = new self([
                    'provider_id' => $providerId,
                    'model_id' => $modelId,
                    'usage_date' => now()->toDateString(),
                    'requests' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cost' => 0,
                    'failures' => 0,
                ]);
            }

            $row->requests += 1;
            $row->input_tokens += (int) $inputTokens;
            $row->output_tokens += (int) $outputTokens;
            $row->cost += (float) $cost;
            $row->failures += $failures;
            $row->save();
        });
    }

    /**
     * Summarise usage for a given period (and optionally model/provider).
     *
     * @return array{requests:int,input_tokens:int,output_tokens:int,cost:float,failures:int}
     */
    public static function summarise(?string $from, ?string $to, ?int $modelId = null, ?int $providerId = null): array
    {
        $query = self::query();

        if ($from) {
            $query->where('usage_date', '>=', $from);
        }

        if ($to) {
            $query->where('usage_date', '<=', $to);
        }

        if ($modelId) {
            $query->where('model_id', $modelId);
        }

        if ($providerId) {
            $query->where('provider_id', $providerId);
        }

        $row = (clone $query)->select([
            DB::raw('COALESCE(SUM(requests), 0) as requests'),
            DB::raw('COALESCE(SUM(input_tokens), 0) as input_tokens'),
            DB::raw('COALESCE(SUM(output_tokens), 0) as output_tokens'),
            DB::raw('COALESCE(SUM(cost), 0) as cost'),
            DB::raw('COALESCE(SUM(failures), 0) as failures'),
        ])->first();

        return [
            'requests' => (int) $row->requests,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'cost' => round((float) $row->cost, 6),
            'failures' => (int) $row->failures,
        ];
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('usage_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('usage_date', '<=', $to));
    }
}
