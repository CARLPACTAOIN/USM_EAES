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
        Schema::create('queue_outbox_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('dedup_key')->unique();
            $table->json('payload');
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();

            $table->index(['type', 'dispatched_at', 'available_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_outbox_entries');
    }
};
