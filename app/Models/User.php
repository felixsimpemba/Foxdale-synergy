<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 */
#[Fillable(['name', 'email', 'password', 'phone', 'mattermost_username', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Roles and permissions are registered on the web guard and checked for Sanctum users.
     */
    protected string $guard_name = 'web';

    /**
     * Mirrors the column defaults so a just-created user serializes correctly.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['two_factor_secret', 'two_factor_recovery_codes', 'last_login_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('is_lead')->withTimestamps();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->hasAnyRole(config('incidents.auth.two_factor_required_roles'));
    }

    /**
     * Browsers and installed apps that asked for push notifications.
     *
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * @return HasOne<NotificationPreference, $this>
     */
    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }
}
