<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_scanner_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('scanner_user_id');
            $table->uuid('assigned_by_user_id');
            $table->string('label')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by_user_id')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_devices')->default(1);
            $table->text('notes')->nullable();
            $table->timestamp('last_activated_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('scanner_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('revoked_by_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['event_id', 'scanner_user_id']);
            $table->index(['scanner_user_id', 'status']);
            $table->index(['event_id', 'status']);
        });

        Schema::table('scanner_sessions', function (Blueprint $table): void {
            $table->uuid('assignment_id')->nullable()->after('event_id');
            $table->foreign('assignment_id')->references('id')->on('event_scanner_assignments')->onDelete('set null');
            $table->index(['assignment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('scanner_sessions', function (Blueprint $table): void {
            $table->dropForeign(['assignment_id']);
            $table->dropIndex(['assignment_id', 'status']);
            $table->dropColumn('assignment_id');
        });

        Schema::dropIfExists('event_scanner_assignments');
    }
};
