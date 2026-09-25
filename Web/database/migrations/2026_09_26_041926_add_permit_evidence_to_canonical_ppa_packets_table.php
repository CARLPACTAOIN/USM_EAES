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
        Schema::table('canonical_ppa_packets', function (Blueprint $table) {
            $table->string('venue_confirmation_document_path')->nullable()->after('venue_confirmation_reference_number');
            $table->string('venue_confirmation_document_original_name')->nullable()->after('venue_confirmation_document_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('canonical_ppa_packets', function (Blueprint $table) {
            $table->dropColumn([
                'venue_confirmation_document_path',
                'venue_confirmation_document_original_name',
            ]);
        });
    }
};
