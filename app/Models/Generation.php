<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image a user asked for, and what it cost them.
 *
 * Never filled from request input directly, hence unguarded: rows are
 * created by StartGeneration and changed only by ApplyProviderSnapshot
 * and FailGeneration.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property int $estimated_charge_nanos
 * @property int $max_charge_nanos
 * @property int $markup_bps
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost_nanos
 * @property int|null $charge_nanos
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}|null $rates
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int $submission_attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Unguarded]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'estimated_charge_nanos' => 'integer',
            'max_charge_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'rates' => 'array',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * Generations that are still on their way: requested, but neither
     * succeeded nor failed yet. Each holds back the most it could cost
     * from its owner's available credit.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inFlight(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    /**
     * Generations Pixelforge has accepted but not finished. Each one takes up
     * one of the few slots Pixelforge gives our account.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function atProvider(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    /**
     * Whether a status reported by Pixelforge should replace the current one.
     *
     * Statuses only move forward, so a duplicate or late update changes
     * nothing, and a finished generation stays finished.
     */
    public function shouldAdvanceTo(GenerationStatus $status): bool
    {
        // A failure we declared ourselves, before Pixelforge ever confirmed
        // the generation, isn't Pixelforge's verdict. If it turns out to exist
        // after all, Pixelforge's word wins, so our books still match theirs.
        $current = $this->status === GenerationStatus::Failed && $this->provider_id === null
            ? GenerationStatus::Pending
            : $this->status;

        return $status->rank() > $current->rank();
    }
}
