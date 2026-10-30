<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A key used by a monitoring source (Zabbix, Grafana, ...) to call the webhooks. Only the
 * SHA-256 hash of the key is stored; the plain key is shown once when created or rotated.
 *
 * @property list<string> $scopes
 * @property list<string>|null $ip_allowlist
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['name', 'source', 'scopes', 'ip_allowlist', 'key_prefix', 'key_hash', 'signing_secret', 'created_by'])]
#[Hidden(['key_hash', 'signing_secret'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use Auditable, HasFactory;

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['key_prefix', 'key_hash', 'signing_secret', 'last_used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'signing_secret' => 'encrypted',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<ApiKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public static function findActiveByPlainKey(string $plainKey): ?self
    {
        return self::query()->active()->where('key_hash', self::hashKey($plainKey))->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
