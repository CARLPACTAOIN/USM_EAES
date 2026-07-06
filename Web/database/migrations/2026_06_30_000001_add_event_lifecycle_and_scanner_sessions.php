<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('lifecycle_phase')->default('scheduled')->after('status');
        });

        Schema::create('scanner_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('scanner_user_id')->nullable();
            $table->string('token_id')->nullable()->index();
            $table->uuid('issued_by');
            $table->string('label')->nullable();
            $table->string('device_id')->nullable();
            $table->string('device_fingerprint_hash')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('issued_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('scanner_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('issued_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('revoked_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('raw_scans', function (Blueprint $table): void {
            $table->uuid('scanner_session_id')->nullable()->after('event_day_id');
            $table->uuid('scanner_user_id')->nullable()->after('scanner_session_id');
            $table->timestamp('server_received_at')->nullable()->after('scanned_at');
            $table->timestamp('processed_at')->nullable()->after('server_received_at');
            $table->string('rejection_reason')->nullable()->after('dedup_key');

            $table->foreign('scanner_session_id')->references('id')->on('scanner_sessions')->onDelete('set null');
            $table->foreign('scanner_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('raw_scans', function (Blueprint $table): void {
            $table->dropForeign(['scanner_session_id']);
            $table->dropForeign(['scanner_user_id']);
            $table->dropColumn([
                'scanner_session_id',
                'scanner_user_id',
                'server_received_at',
                'processed_at',
                'rejection_reason',
            ]);
        });

        Schema::dropIfExists('scanner_sessions');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('lifecycle_phase');
        });
    }
};
