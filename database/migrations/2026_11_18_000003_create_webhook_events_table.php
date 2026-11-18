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
        Schema::create('webhook_events', function (Blueprint $table) {
            // Pixelforge's event id (evt_...). Making it the primary key turns
            // "record every event exactly once" into a database guarantee.
            $table->string('id')->primary();
            $table->string('type');
            $table->json('payload');

            // How many times Pixelforge has delivered this event to us.
            $table->unsignedInteger('deliveries')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
