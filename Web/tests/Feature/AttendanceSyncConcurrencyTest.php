<?php

namespace Tests\Feature;

use App\Jobs\ResolveAttendanceJob;
use App\Models\AdminAssignment;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\AttendanceSyncPersistenceService;
use App\Services\EventAnalyticsCache;
use App\Services\ScannerSessionService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AttendanceSyncConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_active_attendance_and_pending_link_identities_are_database_enforced(): void
    {
        [$event, $scope, $day, $student] = $this->attendanceContext();

        $this->createAttendanceRecord($event, $scope, $day, $student);

        $this->expectException(QueryException::class);
        $this->createAttendanceRecord($event, $scope, $day, $student);
    }

    public function test_pending_link_history_does_not_block_a_new_active_link(): void
    {
        [$event, $scope] = $this->eventContext();

        $this->createPendingLink($event, $scope, 'unknown-history')->update(['status' => 'resolved']);
        $this->createPendingLink($event, $scope, 'unknown-history');

        $this->assertSame(2, PendingStudentLink::query()
            ->where('event_id', $event->id)
            ->where('qr_code_value', 'unknown-history')
            ->count());
        $this->assertSame(1, PendingStudentLink::query()
            ->where('event_id', $event->id)
            ->where('qr_code_value', 'unknown-history')
            ->where('status', 'pending')
            ->count());
    }

    public function test_active_pending_links_are_database_enforced(): void
    {
        [$event, $scope] = $this->eventContext();
        $this->createPendingLink($event, $scope, 'unknown-unique');

        $this->expectException(QueryException::class);
        $this->createPendingLink($event, $scope, 'unknown-unique');
    }

    public function test_replayed_sync_only_persists_winners_and_dispatches_once(): void
    {
        Queue::fake();
        [$event, $scope, $day, $student, $scanner] = $this->attendanceContext();
        $token = $this->scannerTokenForEvent($event, $scanner);
        $payload = [
            'event_id' => $event->id,
            'scans' => [
                $this->scanPayload('winner-known', $student->qr_code_value, 'time_in', $day),
                $this->scanPayload('winner-unknown', 'UNKNOWN-WINNER', 'time_in', $day),
            ],
        ];

        $this->withToken($token)->postJson('/api/v1/attendance/sync', $payload)
            ->assertOk()
            ->assertJsonPath('ingested_count', 2)
            ->assertJsonPath('unresolved_count', 1);

        $this->withToken($token)->postJson('/api/v1/attendance/sync', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'All scans in batch were duplicates, skipped.');

        $this->assertSame(2, RawScan::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, PendingStudentLink::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, DB::table('queue_outbox_entries')->count());
        $this->assertNotNull(DB::table('queue_outbox_entries')->value('dispatched_at'));
        Queue::assertPushed(ResolveAttendanceJob::class, 1);
    }

    public function test_persistence_service_keeps_mixed_batches_idempotent(): void
    {
        [$event, $scope] = $this->eventContext();
        $candidate = $this->rawScanCandidate($event, $scope, 'mixed-existing', 'UNKNOWN-MIXED');
        $service = app(AttendanceSyncPersistenceService::class);
        $cache = app(EventAnalyticsCache::class);
        $summaryCalls = 0;

        $cache->remember($event, function () use (&$summaryCalls): array {
            $summaryCalls++;

            return ['calls' => $summaryCalls];
        });

        $this->assertCount(1, $service->persist($event, $scope, [$candidate]));

        $summary = $cache->remember($event, function () use (&$summaryCalls): array {
            $summaryCalls++;

            return ['calls' => $summaryCalls];
        });

        $this->assertSame(['calls' => 2], $summary);
        $newCandidate = $this->rawScanCandidate($event, $scope, 'mixed-new', 'UNKNOWN-MIXED-NEW');
        $competingCandidate = [
            ...$candidate,
            'id' => (string) Str::uuid(),
        ];

        $persisted = $service->persist($event, $scope, [$competingCandidate, $newCandidate]);

        $this->assertCount(1, $persisted);
        $this->assertSame('mixed-new', $persisted->first()->dedup_key);
        $this->assertSame(2, RawScan::query()->where('event_id', $event->id)->count());
        $this->assertSame(2, PendingStudentLink::query()->where('event_id', $event->id)->count());
    }

    public function test_resolution_job_is_idempotent_and_preserves_evaluation_gate_fields(): void
    {
        [$event, $scope, $day, $student] = $this->attendanceContext();
        RawScan::create($this->rawScanCandidate($event, $scope, 'resolution-in', $student->qr_code_value, $student->id, 'time_in'));
        RawScan::create($this->rawScanCandidate($event, $scope, 'resolution-out', $student->qr_code_value, $student->id, 'time_out'));
        $existing = $this->createAttendanceRecord($event, $scope, $day, $student, [
            'valid' => true,
            'force_validated' => true,
            'validated_by' => $student->id,
        ]);
        $cache = app(EventAnalyticsCache::class);
        $summaryCalls = 0;

        $cache->remember($event, function () use (&$summaryCalls): array {
            $summaryCalls++;

            return ['calls' => $summaryCalls];
        });

        $job = new ResolveAttendanceJob($event->id, [$student->id], $scope->id);
        $job->handle($cache);

        $summary = $cache->remember($event, function () use (&$summaryCalls): array {
            $summaryCalls++;

            return ['calls' => $summaryCalls];
        });

        $this->assertSame(['calls' => 2], $summary);
        $job->handle($cache);

        $this->assertSame(1, AttendanceRecord::query()
            ->where('event_id', $event->id)
            ->where('event_participation_scope_id', $scope->id)
            ->where('event_day_id', $day->id)
            ->where('student_id', $student->id)
            ->count());
        $existing->refresh();
        $this->assertTrue($existing->valid);
        $this->assertTrue($existing->force_validated);
        $this->assertSame($student->id, $existing->validated_by);
    }

    public function test_resolution_job_has_scoped_overlap_and_retry_policy(): void
    {
        $job = new ResolveAttendanceJob('event-id', ['student-id'], 'scope-id');
        $middleware = $job->middleware();

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([5, 30, 90], $job->backoff);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('attendance-resolution:event-id:scope-id', $middleware[0]->key);
        $this->assertSame(5, $middleware[0]->releaseAfter);
        $this->assertSame(180, $middleware[0]->expiresAfter);
    }

    public function test_migration_preflight_aborts_before_creating_indexes_for_duplicate_attendance(): void
    {
        [$event, $scope, $day, $student] = $this->attendanceContext();
        $migration = require database_path('migrations/2026_08_08_163440_add_scanner_sync_concurrency_constraints.php');
        $migration->down();

        try {
            $this->createAttendanceRecord($event, $scope, $day, $student);
            $this->createAttendanceRecord($event, $scope, $day, $student);

            try {
                $migration->up();
                $this->fail('Expected duplicate attendance preflight to abort the migration.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('type=attendance', $exception->getMessage());
                $this->assertSame(2, AttendanceRecord::query()->where('event_id', $event->id)->count());
            }
        } finally {
            AttendanceRecord::query()->where('event_id', $event->id)->delete();
            $migration->up();
        }
    }

    public function test_migration_preflight_aborts_before_creating_indexes_for_duplicate_pending_links(): void
    {
        [$event, $scope] = $this->eventContext();
        $migration = require database_path('migrations/2026_08_08_163440_add_scanner_sync_concurrency_constraints.php');
        $migration->down();

        try {
            $this->createPendingLink($event, $scope, 'duplicate-pending-link');
            $this->createPendingLink($event, $scope, 'duplicate-pending-link');

            try {
                $migration->up();
                $this->fail('Expected duplicate pending-link preflight to abort the migration.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('type=pending_link', $exception->getMessage());
                $this->assertSame(2, PendingStudentLink::query()->where('event_id', $event->id)->count());
            }
        } finally {
            PendingStudentLink::query()->where('event_id', $event->id)->delete();
            $migration->up();
        }
    }

    /**
     * @return array{Event, EventParticipationScope}
     */
    private function eventContext(): array
    {
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);
        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Concurrency',
            'code' => 'CC',
        ]);
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Concurrency Society',
            'acronym' => 'CS',
            'type' => 'society',
        ]);
        $event = Event::create([
            'organization_id' => $organization->id,
            'title' => 'Concurrency Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);
        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'participant_type' => 'organization',
            'organization_id' => $organization->id,
            'participant_name_snapshot' => $organization->acronym,
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_NOT_REQUIRED,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        return [$event, $scope];
    }

    /**
     * @return array{Event, EventParticipationScope, EventDay, User, User}
     */
    private function attendanceContext(): array
    {
        [$event, $scope] = $this->eventContext();
        $day = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $student = User::create([
            'name' => 'Concurrency Student',
            'email' => 'student-'.Str::uuid().'@usm.edu.ph',
            'organization_id' => $scope->organization_id,
            'college_id' => $scope->organization->college_id,
            'student_id_number' => '2026-'.random_int(1000, 9999),
            'qr_code_value' => 'QR-'.Str::uuid(),
        ]);
        $student->assignRole('Student');
        $scanner = User::create([
            'name' => 'Concurrency Scanner',
            'email' => 'scanner-'.Str::uuid().'@usm.edu.ph',
        ]);
        $this->assignAdminScope($scanner, $scope->organization);
        $scanner->assignRole('Scanner');

        return [$event, $scope, $day, $student, $scanner];
    }

    private function createAttendanceRecord(Event $event, EventParticipationScope $scope, EventDay $day, User $student, array $attributes = []): AttendanceRecord
    {
        return AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'student_id' => $student->id,
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'left_early' => false,
            'valid' => false,
            'force_validated' => false,
            ...$attributes,
        ]);
    }

    private function createPendingLink(Event $event, EventParticipationScope $scope, string $qrCode): PendingStudentLink
    {
        return PendingStudentLink::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'organization_id' => $scope->organization_id,
            'qr_code_value' => $qrCode,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawScanCandidate(Event $event, EventParticipationScope $scope, string $dedupKey, string $qrCode, ?string $studentId = null, string $scanType = 'time_in'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => null,
            'scanner_session_id' => null,
            'scanner_user_id' => null,
            'captured_by_organization_id' => $scope->organization_id,
            'captured_by_admin_scope' => $scope->participation_role,
            'student_id' => $studentId,
            'qr_code_value' => $qrCode,
            'scan_type' => $scanType,
            'scanned_at' => Carbon::today()->setTime($scanType === 'time_in' ? 8 : 12, 0),
            'server_received_at' => now(),
            'device_id' => 'concurrency-device',
            'manual_entry' => false,
            'dedup_key' => $dedupKey,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scanPayload(string $dedupKey, string $qrCode, string $scanType, EventDay $day): array
    {
        return [
            'dedup_key' => $dedupKey,
            'scan_type' => $scanType,
            'scanned_at' => Carbon::parse($day->date)->setTime($scanType === 'time_in' ? 8 : 12, 0)->toDateTimeString(),
            'device_id' => 'concurrency-device',
            'manual_entry' => false,
            'qr_code_value' => $qrCode,
        ];
    }

    private function assignAdminScope(User $scanner, Organization $organization): void
    {
        app(AdminAssignmentService::class)->createAssignment(
            user: $scanner,
            roleName: 'Society Admin',
            organization: $organization,
            college: $organization->college,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Concurrency test scanner',
            approvedBy: $scanner,
            isPrimaryAdmin: ! AdminAssignment::query()
                ->where('organization_id', $organization->id)
                ->where('role_name', 'Society Admin')
                ->where('status', AdminAssignment::STATUS_ACTIVE)
                ->exists(),
        );
    }

    private function scannerTokenForEvent(Event $event, User $scanner): string
    {
        [, $token] = app(ScannerSessionService::class)->createSession(
            event: $event,
            scannerUser: $scanner,
            issuedBy: $scanner,
            revokeExisting: false,
        );

        return $token;
    }
}
