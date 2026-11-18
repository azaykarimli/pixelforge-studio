<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our own id. It doubles as the Idempotency-Key we send Pixelforge
            // and as the metadata that ties its webhooks back to this row.
            $table->uuid('id')->primary();

            // Cost records outlive the account that created them. If deleting
            // a user deleted these, our totals would stop matching Pixelforge's.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Pixelforge's id (gen_...), once it has accepted the request.
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // What we quoted when the user pressed Generate. All money is in
            // nanodollars (see App\Billing\Money).
            $table->unsignedBigInteger('estimated_charge_nanos');
            $table->unsignedBigInteger('max_charge_nanos');
            $table->unsignedInteger('markup_bps');

            // Recorded once, when the generation succeeds.
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('provider_cost_nanos')->nullable();
            $table->unsignedBigInteger('charge_nanos')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            // The rates Pixelforge bills this generation at, as it sent them.
            $table->json('rates')->nullable();

            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            $table->unsignedTinyInteger('submission_attempts')->default(0);

            // Pixelforge's own timestamps (UTC). It bills a generation on the
            // day it completed, so reports group by completed_at.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
