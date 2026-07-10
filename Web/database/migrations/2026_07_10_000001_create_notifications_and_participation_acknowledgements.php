<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            $table->timestamp('invitation_acknowledged_at')->nullable()->after('status');
            $table->uuid('invitation_acknowledged_by')->nullable()->after('invitation_acknowledged_at');
            $table->timestamp('invitation_last_reminded_at')->nullable()->after('invitation_acknowledged_by');

            $table->foreign('invitation_acknowledged_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['invitation_acknowledged_at', 'invitation_last_reminded_at'], 'eps_invitation_ack_reminder_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            $table->dropForeign(['invitation_acknowledged_by']);
            $table->dropIndex('eps_invitation_ack_reminder_index');
            $table->dropColumn([
                'invitation_acknowledged_at',
                'invitation_acknowledged_by',
                'invitation_last_reminded_at',
            ]);
        });

        Schema::dropIfExists('notifications');
    }
};
