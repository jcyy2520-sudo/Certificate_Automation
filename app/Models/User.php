<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\AccountService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    public const ADMINISTRATOR_ROLE = 'administrator';

    public const USER_ROLE = 'user';

    protected $attributes = ['auth_version' => 1, 'approval_status' => 'pending_verification'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'last_login_at',
        'approval_status',
        'password_setup_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'email_normalized',
        'verification_token_hash',
    ];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('email')) {
                $email = Str::lower(trim((string) $user->email));
                $user->email = $email;
                $user->email_normalized = $email;
            }
        });
    }

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
            'two_factor_last_used_step' => 'integer',
            'last_login_at' => 'datetime',
            'password_setup_at' => 'datetime',
            'approved_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'auth_version' => 'integer',
        ];
    }

    public function webinars()
    {
        return $this->hasMany(Webinar::class, 'created_by');
    }

    /** The authorization middleware re-checks this on every protected request. */
    public function isAdministrator(): bool
    {
        return $this->is_active && $this->approval_status === 'approved'
            && hash_equals(self::ADMINISTRATOR_ROLE, (string) $this->role);
    }

    public function canAccessPlatform(): bool
    {
        return $this->is_active && $this->approval_status === 'approved'
            && $this->password_setup_at !== null
            && ($this->role === self::ADMINISTRATOR_ROLE || ($this->role === self::USER_ROLE && $this->email_verified_at !== null));
    }

    public function sendPasswordResetNotification($token): void
    {
        app(AccountService::class)->passwordInvitation($this, $token);
    }
}
