<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run over a PanelConnection, driven by RunPanelTransferJob on this panel.
 *
 * direction: pull (other panel → this one) | push (this one → other panel)
 * kind: website (files + database, creates the site if needed)
 *     | files (only the files that differ; nothing else is touched)
 *     | panel (the whole panel's backup, restored afterwards like an uploaded one)
 */
class PanelTransfer extends Model
{
    use HasUuids;

    public const ACTIVE = ['queued', 'running'];

    protected $fillable = ['connection_id', 'user_id', 'direction', 'kind', 'domain', 'source_ref', 'status', 'percent', 'message', 'options', 'state', 'finished_at'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'state' => 'array',
            'percent' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PanelConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'connection_id' => $this->connection_id,
            'direction' => $this->direction,
            'kind' => $this->kind,
            'domain' => $this->domain,
            'source_ref' => $this->source_ref,
            'status' => $this->status,
            'percent' => $this->percent,
            'message' => $this->message,
            'options' => (array) $this->options,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
