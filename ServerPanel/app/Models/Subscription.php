<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasFactory;

    public const RESOURCE_MAIL_ACCOUNTS = 'mail_accounts';
    public const RESOURCE_DISK_SPACE_MB = 'disk_space_mb';
    public const RESOURCE_DATABASES = 'databases';
    public const RESOURCE_FILES = 'files';

    private const RESOURCE_COLUMN_MAP = [
        self::RESOURCE_MAIL_ACCOUNTS => [
            'used' => 'used_mail_accounts',
            'limit' => 'mail_accounts_limit',
        ],
        self::RESOURCE_DISK_SPACE_MB => [
            'used' => 'used_disk_space_mb',
            'limit' => 'disk_space_mb_limit',
        ],
        self::RESOURCE_DATABASES => [
            'used' => 'used_databases',
            'limit' => 'databases_limit',
        ],
        self::RESOURCE_FILES => [
            'used' => 'used_files',
            'limit' => 'files_limit',
        ],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'package_id',
        'plan_name',
        'status',
        'price',
        'started_at',
        'ends_at',
        'used_mail_accounts',
        'used_disk_space_mb',
        'used_databases',
        'used_files',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Subscription owner.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Subscription package.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Check whether subscription is currently active.
     */
    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->ends_at === null) {
            return true;
        }

        return $this->ends_at->greaterThanOrEqualTo(Carbon::now());
    }

    /**
     * Return usage and limits for all tracked resources.
     *
     * @return array<string, array<string, int|null>>
     */
    public function quotas(): array
    {
        $quotas = [];

        foreach (array_keys(self::RESOURCE_COLUMN_MAP) as $resource) {
            $used = $this->usedFor($resource);
            $limit = $this->limitFor($resource);
            $remaining = $limit === null ? null : max(0, $limit - $used);

            $quotas[$resource] = [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
            ];
        }

        return $quotas;
    }

    /**
     * Check whether requested amount can be used for a resource.
     */
    public function canUse(string $resource, int $amount = 1): bool
    {
        $this->assertKnownResource($resource);

        if ($amount < 1 || ! $this->isActive()) {
            return false;
        }

        $limit = $this->limitFor($resource);
        if ($limit === null) {
            return true;
        }

        return ($this->usedFor($resource) + $amount) <= $limit;
    }

    /**
     * Consume amount for a given resource if quota allows.
     */
    public function consume(string $resource, int $amount = 1): bool
    {
        if (! $this->canUse($resource, $amount)) {
            return false;
        }

        $usedColumn = self::RESOURCE_COLUMN_MAP[$resource]['used'];
        $this->increment($usedColumn, $amount);
        $this->refresh();

        return true;
    }

    /**
     * Release amount from a given resource usage.
     */
    public function release(string $resource, int $amount = 1): void
    {
        $this->assertKnownResource($resource);

        if ($amount < 1) {
            return;
        }

        $usedColumn = self::RESOURCE_COLUMN_MAP[$resource]['used'];
        $nextValue = max(0, (int) $this->{$usedColumn} - $amount);
        $this->forceFill([$usedColumn => $nextValue])->save();
    }

    /**
     * Get consumed units for a resource.
     */
    public function usedFor(string $resource): int
    {
        $this->assertKnownResource($resource);
        $usedColumn = self::RESOURCE_COLUMN_MAP[$resource]['used'];

        return (int) $this->{$usedColumn};
    }

    /**
     * Get limit for a resource; null means unlimited.
     */
    public function limitFor(string $resource): ?int
    {
        $this->assertKnownResource($resource);
        $limitColumn = self::RESOURCE_COLUMN_MAP[$resource]['limit'];

        if ($this->package === null) {
            return null;
        }

        $value = $this->package->{$limitColumn};

        return $value === null ? null : (int) $value;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertKnownResource(string $resource): void
    {
        if (! array_key_exists($resource, self::RESOURCE_COLUMN_MAP)) {
            throw new \InvalidArgumentException("Unknown quota resource: {$resource}");
        }
    }
}
