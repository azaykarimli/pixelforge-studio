<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook event received from Pixelforge, keyed by Pixelforge's own
 * event id so that the same event can never be recorded twice.
 *
 * @property string $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property int $deliveries
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Unguarded]
class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
