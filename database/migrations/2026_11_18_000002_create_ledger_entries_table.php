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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');

            // Signed nanodollars: negative takes credit away, positive adds it.
            // A user's balance is the sum of their entries.
            $table->bigInteger('amount_nanos');

            // UNIQUE, so the database itself refuses to charge a generation twice.
            $table->foreignUuid('generation_id')->nullable()->unique()->constrained()->restrictOnDelete();

            // Entries are only ever added, never edited.
            $table->timestamp('created_at');

            $table->index(['user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
