<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureSupportedDriver();

        $duplicates = [
            ...$this->attendanceIdentityDuplicates(),
            ...$this->pendingLinkDuplicates(),
        ];

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Scanner sync concurrency constraints cannot be added while duplicate active identities exist: '
                .implode('; ', $duplicates)
            );
        }

        DB::statement('CREATE UNIQUE INDEX eaes_attendance_scoped_day_identity_unique ON attendance_records (event_id, event_day_id, student_id, event_participation_scope_id) WHERE event_day_id IS NOT NULL AND event_participation_scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_legacy_day_identity_unique ON attendance_records (event_id, event_day_id, student_id) WHERE event_day_id IS NOT NULL AND event_participation_scope_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_scoped_legacy_day_identity_unique ON attendance_records (event_id, student_id, event_participation_scope_id) WHERE event_day_id IS NULL AND event_participation_scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_legacy_identity_unique ON attendance_records (event_id, student_id) WHERE event_day_id IS NULL AND event_participation_scope_id IS NULL');
        DB::statement("CREATE UNIQUE INDEX eaes_pending_link_scope_pending_unique ON pending_student_links (event_id, event_participation_scope_id, qr_code_value) WHERE status = 'pending' AND event_participation_scope_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX eaes_pending_link_legacy_pending_unique ON pending_student_links (event_id, qr_code_value) WHERE status = 'pending' AND event_participation_scope_id IS NULL");
    }

    public function down(): void
    {
        $this->ensureSupportedDriver();

        DB::statement('DROP INDEX IF EXISTS eaes_pending_link_legacy_pending_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_pending_link_scope_pending_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_legacy_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_scoped_legacy_day_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_legacy_day_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_scoped_day_identity_unique');
    }

    /**
     * @return array<int, string>
     */
    private function attendanceIdentityDuplicates(): array
    {
        return DB::table('attendance_records')
            ->selectRaw('event_id, event_day_id, student_id, event_participation_scope_id, COUNT(*) AS duplicate_count')
            ->groupBy('event_id', 'event_day_id', 'student_id', 'event_participation_scope_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn (object $duplicate): string => sprintf(
                'type=attendance event_id=%s event_day_id=%s student_id=%s audience_id=%s count=%d',
                $duplicate->event_id,
                $duplicate->event_day_id ?? 'null',
                $duplicate->student_id,
                $duplicate->event_participation_scope_id ?? 'null',
                $duplicate->duplicate_count,
            ))
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function pendingLinkDuplicates(): array
    {
        return DB::table('pending_student_links')
            ->selectRaw('event_id, event_participation_scope_id, COUNT(*) AS duplicate_count')
            ->where('status', 'pending')
            ->groupBy('event_id', 'event_participation_scope_id', 'qr_code_value')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn (object $duplicate): string => sprintf(
                'type=pending_link event_id=%s audience_id=%s count=%d',
                $duplicate->event_id,
                $duplicate->event_participation_scope_id ?? 'null',
                $duplicate->duplicate_count,
            ))
            ->all();
    }

    private function ensureSupportedDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Scanner sync concurrency constraints require PostgreSQL or SQLite.');
        }
    }
};
