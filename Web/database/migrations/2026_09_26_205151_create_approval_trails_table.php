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
        Schema::create('approval_trails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('proposal_id')->constrained('events');
            $table->foreignUuid('workflow_run_id')->constrained('proposal_workflow_runs');
            $table->foreignUuid('workflow_task_id')->constrained('proposal_workflow_tasks');
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->text('remarks')->nullable();
            $table->string('reference_id')->unique();
            $table->string('previous_status');
            $table->string('resulting_status');
            $table->string('assigned_role')->nullable();
            $table->string('assigned_scope')->nullable();
            $table->timestamp('decided_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['proposal_id', 'decided_at']);
            $table->index(['workflow_run_id', 'decided_at']);
            $table->index(['workflow_task_id', 'decided_at']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_approval_trails_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'approval_trails are immutable';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER approval_trails_no_update
                BEFORE UPDATE ON approval_trails
                FOR EACH ROW EXECUTE FUNCTION prevent_approval_trails_mutation();

                CREATE TRIGGER approval_trails_no_delete
                BEFORE DELETE ON approval_trails
                FOR EACH ROW EXECUTE FUNCTION prevent_approval_trails_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER approval_trails_no_update
                BEFORE UPDATE ON approval_trails
                BEGIN
                    SELECT RAISE(ABORT, 'approval_trails are immutable');
                END;

                CREATE TRIGGER approval_trails_no_delete
                BEFORE DELETE ON approval_trails
                BEGIN
                    SELECT RAISE(ABORT, 'approval_trails are immutable');
                END;
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS approval_trails_no_update ON approval_trails;
                DROP TRIGGER IF EXISTS approval_trails_no_delete ON approval_trails;
                DROP FUNCTION IF EXISTS prevent_approval_trails_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS approval_trails_no_update;
                DROP TRIGGER IF EXISTS approval_trails_no_delete;
            SQL);
        }

        Schema::dropIfExists('approval_trails');
    }
};
