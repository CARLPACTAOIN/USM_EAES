<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Active participation-scope uniqueness requires PostgreSQL or SQLite partial indexes.');
        }

        $duplicates = [
            DB::table('event_participation_scopes')->select('event_id')->selectRaw('COUNT(*) as duplicate_count')->where('status', 'active')->where('participation_role', 'organizer')->groupBy('event_id')->havingRaw('COUNT(*) > 1')->get()->map(fn (object $duplicate): string => "type=organizer event_id={$duplicate->event_id} audience_id={$duplicate->event_id} count={$duplicate->duplicate_count}"),
            DB::table('event_participation_scopes')->select('event_id', 'organization_id')->selectRaw('COUNT(*) as duplicate_count')->where('status', 'active')->where('participation_role', 'participant')->whereNotNull('organization_id')->groupBy('event_id', 'organization_id')->havingRaw('COUNT(*) > 1')->get()->map(fn (object $duplicate): string => "type=organization_participant event_id={$duplicate->event_id} audience_id={$duplicate->organization_id} count={$duplicate->duplicate_count}"),
            DB::table('event_participation_scopes')->select('event_id', 'college_id')->selectRaw('COUNT(*) as duplicate_count')->where('status', 'active')->where('participation_role', 'participant')->where('participant_type', 'college')->whereNull('organization_id')->whereNotNull('college_id')->groupBy('event_id', 'college_id')->havingRaw('COUNT(*) > 1')->get()->map(fn (object $duplicate): string => "type=college_participant event_id={$duplicate->event_id} audience_id={$duplicate->college_id} count={$duplicate->duplicate_count}"),
            DB::table('event_participation_scopes')->select('event_id', 'linked_event_id')->selectRaw('COUNT(*) as duplicate_count')->where('status', 'active')->where('participation_role', 'participant')->whereNotNull('linked_event_id')->groupBy('event_id', 'linked_event_id')->havingRaw('COUNT(*) > 1')->get()->map(fn (object $duplicate): string => "type=linked_event_participant event_id={$duplicate->event_id} audience_id={$duplicate->linked_event_id} count={$duplicate->duplicate_count}"),
        ];

        $duplicateDetails = collect($duplicates)->flatten()->take(20)->implode('; ');

        if ($duplicateDetails !== '') {
            throw new RuntimeException("Cannot add active participation-scope unique indexes while duplicate audiences exist: {$duplicateDetails}");
        }

        DB::statement("CREATE UNIQUE INDEX eps_active_organizer_unique ON event_participation_scopes (event_id) WHERE status = 'active' AND participation_role = 'organizer'");
        DB::statement("CREATE UNIQUE INDEX eps_active_participant_org_unique ON event_participation_scopes (event_id, organization_id) WHERE status = 'active' AND participation_role = 'participant' AND organization_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX eps_active_participant_college_unique ON event_participation_scopes (event_id, college_id) WHERE status = 'active' AND participation_role = 'participant' AND participant_type = 'college' AND organization_id IS NULL AND college_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX eps_active_linked_event_unique ON event_participation_scopes (event_id, linked_event_id) WHERE status = 'active' AND participation_role = 'participant' AND linked_event_id IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS eps_active_linked_event_unique');
        DB::statement('DROP INDEX IF EXISTS eps_active_participant_college_unique');
        DB::statement('DROP INDEX IF EXISTS eps_active_participant_org_unique');
        DB::statement('DROP INDEX IF EXISTS eps_active_organizer_unique');
    }
};
