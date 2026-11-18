<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Nobody is an admin unless an operator says so. Stating the default
     * here means a newly created user reports false straight away, without
     * first being reloaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

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
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The user's credit in nanodollars: everything they bought, minus
     * everything they were charged.
     *
     * It is always added up from the ledger. There is no stored balance
     * that could drift out of step with the entries behind it.
     */
    public function creditBalanceNanos(): int
    {
        return (int) $this->ledgerEntries()->sum('amount_nanos');
    }

    /**
     * Credit set aside for generations that are still on their way: the most
     * each of them could cost. It is released the moment a generation
     * finishes, when the real charge (or none, if it failed) takes its place.
     */
    public function reservedCreditNanos(): int
    {
        return (int) $this->generations()->inFlight()->sum('max_charge_nanos');
    }

    /**
     * What the user can still spend: their balance, less what is set aside.
     */
    public function availableCreditNanos(): int
    {
        // What is set aside is read first, on purpose. A generation can finish
        // between the two reads; it is then counted twice (still set aside,
        // and already charged), which only makes this number too cautious.
        // Read the other way round, it could briefly be counted in neither.
        $reserved = $this->reservedCreditNanos();

        return $this->creditBalanceNanos() - $reserved;
    }
}
