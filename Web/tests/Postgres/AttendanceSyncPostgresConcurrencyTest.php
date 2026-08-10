<?php

namespace Tests\Postgres;

use App\Models\College;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\University;
use App\Services\AttendanceSyncPersistenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceSyncPostgresConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || ! env('EAES_RUN_POSTGRES_CONCURRENCY_TESTS')) {
            $this->markTestSkipped('PostgreSQL concurrency tests require phpunit.pgsql.xml.');
        }

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_two_persistence_processes_produce_one_raw_scan_and_pending_link(): void
    {
        [$event, $scope] = $this->eventContext();
        $candidate = $this->candidate($event, $scope);
        $competingCandidate = [
            ...$candidate,
            'id' => (string) Str::uuid(),
        ];
        $eventId = $event->id;
        $scopeId = $scope->id;

        $results = Concurrency::driver('process')->run([
            static function () use ($candidate, $eventId, $scopeId): int {
                return app(AttendanceSyncPersistenceService::class)
                    ->persist(Event::findOrFail($eventId), EventParticipationScope::findOrFail($scopeId), [$candidate])
                    ->count();
            },
            static function () use ($competingCandidate, $eventId, $scopeId): int {
                return app(AttendanceSyncPersistenceService::class)
                    ->persist(Event::findOrFail($eventId), EventParticipationScope::findOrFail($scopeId), [$competingCandidate])
                    ->count();
            },
        ]);

        sort($results);

        $this->assertSame([0, 1], $results);
        $this->assertSame(1, RawScan::query()->where('dedup_key', $candidate['dedup_key'])->count());
        $this->assertSame(1, PendingStudentLink::query()
            ->where('event_id', $eventId)
            ->where('event_participation_scope_id', $scopeId)
            ->where('status', 'pending')
            ->count());
    }

    /**
     * @return array{Event, EventParticipationScope}
     */
    private function eventContext(): array
    {
        $university = University::create([
            'name' => 'PostgreSQL Concurrency University',
            'domain' => 'postgres-concurrency.usm.edu.ph',
        ]);
        $college = College::create([
            'university_id' => $university->id,
            'name' => 'PostgreSQL Concurrency College',
            'code' => 'PCC',
        ]);
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'PostgreSQL Concurrency Organization',
            'acronym' => 'PCO',
            'type' => 'society',
        ]);
        $event = Event::create([
            'organization_id' => $organization->id,
            'title' => 'PostgreSQL Concurrency Event',
            'status' => 'approved',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
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
     * @return array<string, mixed>
     */
    private function candidate(Event $event, EventParticipationScope $scope): array
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
            'student_id' => null,
            'qr_code_value' => 'POSTGRES-CONCURRENCY-UNKNOWN',
            'scan_type' => 'time_in',
            'scanned_at' => now(),
            'server_received_at' => now(),
            'device_id' => 'postgres-concurrency-device',
            'manual_entry' => false,
            'dedup_key' => 'postgres-concurrency-'.Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
