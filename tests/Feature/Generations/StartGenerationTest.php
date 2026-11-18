<?php

namespace Tests\Feature\Generations;

use App\Actions\StartGeneration;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class StartGenerationTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    public function test_it_saves_the_request_with_its_quote_and_queues_it_for_pixelforge(): void
    {
        Queue::fake();
        $this->fakePixelforge();

        $user = User::factory()->withCredit()->create();

        $generation = app(StartGeneration::class)(
            $user,
            prompt: str_repeat('a', 80),
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
            referenceImages: [],
        );

        $generation->refresh();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertSame(72_312_500, $generation->estimated_charge_nanos);
        $this->assertSame(86_393_750, $generation->max_charge_nanos);
        $this->assertSame(2500, $generation->markup_bps);
        $this->assertNull($generation->charge_nanos, 'Nothing is charged until the generation succeeds.');

        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
    }

    public function test_reference_images_are_part_of_the_quote(): void
    {
        Queue::fake();
        $this->fakePixelforge();

        $generation = app(StartGeneration::class)(
            User::factory()->withCredit()->create(),
            prompt: str_repeat('a', 80),
            size: ImageSize::Square,
            quality: ImageQuality::Medium,
            referenceImages: ['https://example.com/a.png', 'https://example.com/b.png'],
        );

        // Two reference images are 1,530 input image tokens at $10 per 1M, plus 25%.
        $this->assertSame(72_312_500 + 19_125_000, $generation->estimated_charge_nanos);
        $this->assertSame(['https://example.com/a.png', 'https://example.com/b.png'], Generation::query()->sole()->reference_images);
    }
}
