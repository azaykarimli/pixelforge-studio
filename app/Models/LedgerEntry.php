<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a user's credit. Entries are only ever added, so the
 * ledger is a complete history of what each user was charged and why.
 *
 * @property int $id
 * @property int|null $user_id
 * @property LedgerEntryType $type
 * @property int $amount_nanos
 * @property string|null $generation_id
 * @property CarbonImmutable $created_at
 */
#[Unguarded]
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_nanos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
