<?php

namespace App\Pixelforge\Data;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * Pixelforge's generation object: a snapshot of one generation as it was
 * at some moment. The create response, every webhook and every poll carry
 * one, so they can all be handled the same way.
 */
final readonly class ProviderGeneration
{
    /**
     * @param  string|null  $localId  Our own generation id, echoed back from the metadata we sent.
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?string $localId,
        public Rates $rates,
        public ?TokenUsage $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $generation
     */
    public static function fromApi(array $generation): self
    {
        return new self(
            id: $generation['id'],
            status: GenerationStatus::from($generation['status']),
            localId: $generation['metadata']['generation_id'] ?? null,
            rates: new Rates($generation['rates']),
            usage: isset($generation['usage']) ? TokenUsage::fromApi($generation['usage']) : null,
            imageUrl: $generation['image_url'] ?? null,
            imageUrlExpiresAt: self::time($generation['image_url_expires_at'] ?? null),
            errorCode: $generation['error']['code'] ?? null,
            errorMessage: $generation['error']['message'] ?? null,
            startedAt: self::time($generation['started_at'] ?? null),
            completedAt: self::time($generation['completed_at'] ?? null),
        );
    }

    private static function time(?string $timestamp): ?CarbonImmutable
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp);
    }
}
