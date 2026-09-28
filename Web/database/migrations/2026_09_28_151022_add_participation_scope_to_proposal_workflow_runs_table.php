<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proposal_workflow_runs', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
            $table->foreignUuid('event_participation_scope_id')
                ->nullable()
                ->after('event_id')
                ->constrained('event_participation_scopes')
                ->cascadeOnDelete();
            $table->unique('event_participation_scope_id', 'proposal_workflow_runs_scope_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX proposal_workflow_runs_event_organizer_unique ON proposal_workflow_runs (event_id) WHERE event_participation_scope_id IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS proposal_workflow_runs_event_organizer_unique');

        Schema::table('proposal_workflow_runs', function (Blueprint $table) {
            $table->dropUnique('proposal_workflow_runs_scope_unique');
            $table->dropConstrainedForeignId('event_participation_scope_id');
            $table->unique('event_id');
        });
    }
};
