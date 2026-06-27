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
            $table->string('proposal_category')->nullable()->after('title');
            $table->string('proposal_document_path')->nullable()->after('budget_allocations');
            $table->string('proposal_document_original_name')->nullable()->after('proposal_document_path');
            $table->string('resolution_number')->nullable()->after('proposal_document_original_name');
            $table->boolean('hardcopy_submitted')->default(false)->after('resolution_number');
            $table->timestamp('hardcopy_submitted_at')->nullable()->after('hardcopy_submitted');
            $table->boolean('head_organization_signed')->default(false)->after('hardcopy_submitted_at');
            $table->boolean('adviser_signed')->default(false)->after('head_organization_signed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'proposal_category',
                'proposal_document_path',
                'proposal_document_original_name',
                'resolution_number',
                'hardcopy_submitted',
                'hardcopy_submitted_at',
                'head_organization_signed',
                'adviser_signed',
            ]);
        });
    }
};
