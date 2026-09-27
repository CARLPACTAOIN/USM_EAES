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
        Schema::table('event_permit_versions', function (Blueprint $table) {
            $table->foreignUuid('workflow_run_id')->nullable()->after('event_id')->constrained('proposal_workflow_runs')->restrictOnDelete();
            $table->string('verification_reference')->nullable()->after('approved_at')->unique();
            $table->json('canonical_packet_snapshot')->nullable()->after('verification_reference');
            $table->json('proposal_snapshot')->nullable()->after('canonical_packet_snapshot');
            $table->json('permit_snapshot')->nullable()->after('proposal_snapshot');
            $table->json('evidence_snapshot')->nullable()->after('permit_snapshot');
            $table->json('approval_metadata_snapshot')->nullable()->after('evidence_snapshot');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_event_permit_versions_mutation()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'event_permit_versions are immutable';
                    END IF;

                    IF OLD.superseded_at IS NULL
                        AND NEW.superseded_at IS NOT NULL
                        AND (to_jsonb(NEW) - 'superseded_at' - 'updated_at') = (to_jsonb(OLD) - 'superseded_at' - 'updated_at')
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'event_permit_versions are immutable';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER event_permit_versions_no_update
                BEFORE UPDATE ON event_permit_versions
                FOR EACH ROW EXECUTE FUNCTION prevent_event_permit_versions_mutation();

                CREATE TRIGGER event_permit_versions_no_delete
                BEFORE DELETE ON event_permit_versions
                FOR EACH ROW EXECUTE FUNCTION prevent_event_permit_versions_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER event_permit_versions_no_update
                BEFORE UPDATE ON event_permit_versions
                WHEN NOT (
                    OLD.superseded_at IS NULL
                    AND NEW.superseded_at IS NOT NULL
                    AND NEW.id IS OLD.id
                    AND NEW.event_id IS OLD.event_id
                    AND NEW.workflow_run_id IS OLD.workflow_run_id
                    AND NEW.version_number IS OLD.version_number
                    AND NEW.venue_id IS OLD.venue_id
                    AND NEW.venue_name_snapshot IS OLD.venue_name_snapshot
                    AND NEW.venue_location_snapshot IS OLD.venue_location_snapshot
                    AND NEW.location_type IS OLD.location_type
                    AND NEW.location_details IS OLD.location_details
                    AND NEW.confirmation_office IS OLD.confirmation_office
                    AND NEW.confirmation_date IS OLD.confirmation_date
                    AND NEW.confirmation_reference_number IS OLD.confirmation_reference_number
                    AND NEW.confirmation_document_path IS OLD.confirmation_document_path
                    AND NEW.confirmation_document_original_name IS OLD.confirmation_document_original_name
                    AND NEW.ppa_document_path IS OLD.ppa_document_path
                    AND NEW.ppa_document_original_name IS OLD.ppa_document_original_name
                    AND NEW.amendment_reason IS OLD.amendment_reason
                    AND NEW.approved_by IS OLD.approved_by
                    AND NEW.approved_at IS OLD.approved_at
                    AND NEW.verification_reference IS OLD.verification_reference
                    AND NEW.canonical_packet_snapshot IS OLD.canonical_packet_snapshot
                    AND NEW.proposal_snapshot IS OLD.proposal_snapshot
                    AND NEW.permit_snapshot IS OLD.permit_snapshot
                    AND NEW.evidence_snapshot IS OLD.evidence_snapshot
                    AND NEW.approval_metadata_snapshot IS OLD.approval_metadata_snapshot
                    AND NEW.created_at IS OLD.created_at
                )
                BEGIN
                    SELECT RAISE(ABORT, 'event_permit_versions are immutable');
                END;

                CREATE TRIGGER event_permit_versions_no_delete
                BEFORE DELETE ON event_permit_versions
                BEGIN
                    SELECT RAISE(ABORT, 'event_permit_versions are immutable');
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
                DROP TRIGGER IF EXISTS event_permit_versions_no_update ON event_permit_versions;
                DROP TRIGGER IF EXISTS event_permit_versions_no_delete ON event_permit_versions;
                DROP FUNCTION IF EXISTS prevent_event_permit_versions_mutation();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS event_permit_versions_no_update;
                DROP TRIGGER IF EXISTS event_permit_versions_no_delete;
            SQL);
        }

        Schema::table('event_permit_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_run_id');
            $table->dropColumn([
                'verification_reference',
                'canonical_packet_snapshot',
                'proposal_snapshot',
                'permit_snapshot',
                'evidence_snapshot',
                'approval_metadata_snapshot',
            ]);
        });
    }
};
