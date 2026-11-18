<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * A generation the user has just requested: saved, but not yet sent to
     * Pixelforge. The quote is the real one for this prompt length and size.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => str_repeat('a', 80),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'estimated_charge_nanos' => 72_312_500,
            'max_charge_nanos' => 86_393_750,
            'markup_bps' => 2500,
        ];
    }

    /**
     * Accepted by Pixelforge and waiting in its queue.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GenerationStatus::Queued,
            'provider_id' => 'gen_'.Str::upper(Str::random(26)),
        ]);
    }

    /**
     * Accepted by Pixelforge and being generated.
     */
    public function processing(): static
    {
        return $this->queued()->state(fn (array $attributes) => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    /**
     * Finished and paid for: 20 text, 1,100 output and 1,100 reasoning tokens,
     * with the matching charge in the user's ledger.
     */
    public function succeeded(): static
    {
        return $this->queued()->afterCreating(fn (Generation $generation) => LedgerEntry::query()->create([
            'user_id' => $generation->user_id,
            'type' => LedgerEntryType::GenerationCharge,
            'amount_nanos' => -$generation->charge_nanos,
            'generation_id' => $generation->id,
        ]))->state(fn (array $attributes) => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 20,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1100,
            'provider_cost_nanos' => 57_850_000,
            'charge_nanos' => 72_312_500,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Failed at Pixelforge after it had been accepted.
     */
    public function failed(): static
    {
        return $this->queued()->state(fn (array $attributes) => [
            'status' => GenerationStatus::Failed,
            'failure_code' => 'internal_error',
            'failure_message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
