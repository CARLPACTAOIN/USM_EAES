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
        Schema::create('event_permit_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignUuid('venue_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('venue_name_snapshot');
            $table->string('venue_location_snapshot')->nullable();
            $table->string('location_type');
            $table->string('location_details');
            $table->string('confirmation_office');
            $table->date('confirmation_date');
            $table->string('confirmation_reference_number')->nullable();
            $table->string('confirmation_document_path');
            $table->string('confirmation_document_original_name');
            $table->string('ppa_document_path');
            $table->string('ppa_document_original_name');
            $table->text('amendment_reason')->nullable();
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'version_number']);
            $table->index(['event_id', 'superseded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_permit_versions');
    }
};
