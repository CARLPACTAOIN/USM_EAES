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
        Schema::table('events', function (Blueprint $table) {
            $table->foreignUuid('venue_id')->nullable()->after('location_type')->constrained()->restrictOnDelete();
            $table->string('venue_confirmation_office')->nullable()->after('location_details');
            $table->date('venue_confirmation_date')->nullable()->after('venue_confirmation_office');
            $table->string('venue_confirmation_reference_number')->nullable()->after('venue_confirmation_date');
            $table->string('venue_confirmation_document_path')->nullable()->after('venue_confirmation_reference_number');
            $table->string('venue_confirmation_document_original_name')->nullable()->after('venue_confirmation_document_path');
            $table->unsignedInteger('permit_version')->default(0)->after('venue_confirmation_document_original_name');
            $table->text('pending_venue_amendment_reason')->nullable()->after('permit_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_id');
            $table->dropColumn([
                'venue_confirmation_office',
                'venue_confirmation_date',
                'venue_confirmation_reference_number',
                'venue_confirmation_document_path',
                'venue_confirmation_document_original_name',
                'permit_version',
                'pending_venue_amendment_reason',
            ]);
        });
    }
};
