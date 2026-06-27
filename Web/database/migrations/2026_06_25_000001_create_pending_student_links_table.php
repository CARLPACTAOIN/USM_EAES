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
        Schema::create('pending_student_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('organization_id');
            $table->uuid('raw_scan_id')->nullable();
            $table->string('qr_code_value');
            $table->string('status')->default('pending');
            $table->uuid('resolved_student_id')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->uuid('flagged_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('raw_scan_id')->references('id')->on('raw_scans')->onDelete('set null');
            $table->foreign('resolved_student_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('flagged_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['event_id', 'qr_code_value', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_student_links');
    }
};
