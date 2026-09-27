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
        Schema::create('permit_copy_owners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_permit_version_id')->constrained('event_permit_versions')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('owner_type');
            $table->uuid('owner_id')->nullable();
            $table->string('owner_name');
            $table->string('owner_role');
            $table->string('copy_label');
            $table->timestamp('assigned_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['event_permit_version_id', 'owner_type', 'owner_name'], 'permit_copy_owner_unique');
            $table->index(['event_id', 'owner_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permit_copy_owners');
    }
};
