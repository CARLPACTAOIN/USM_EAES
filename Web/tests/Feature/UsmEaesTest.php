<?php

namespace Tests\Feature;

use App\Models\University;
use App\Models\College;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RawScan;
use App\Models\AttendanceRecord;
use App\Models\Evaluation;
use App\Models\PendingStudentLink;
use App\Jobs\AnalyzeEventSentimentsJob;
use App\Jobs\ResolveAttendanceJob;
use App\Services\GeminiService;
use App\Services\Contracts\AiServiceInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Carbon\Carbon;

class UsmEaesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & permissions
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Test the entire USM EAES backend pipeline.
     */
    public function test_eaes_backend_pipeline()
    {
        $this->withoutExceptionHandling();
        // 1. Seed University, College, Organization
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Engineering and Information Technology',
            'code' => 'CEIT',
        ]);

        $org = Organization::create([
            'college_id' => $college->id,
            'name' => 'Philippine Society of Information Technology Students',
            'acronym' => 'PSITS',
            'type' => 'society',
        ]);

        $program = Program::create([
            'college_id' => $college->id,
            'name' => 'BS Information Systems',
            'code' => 'BSIS'
        ]);
        $org->programs()->attach($program->id);

        // 2. Create Student & Scanner Admin Users
        $student = User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '23-1234',
            'qr_code_value' => 'QR_JUAN_123',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $societyAdmin = User::create([
            'name' => 'Admin Officer',
            'email' => 'admin.officer@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'password' => bcrypt('password123'),
        ]);
        $societyAdmin->assignRole('Society Admin');
        $societyAdmin->assignRole('Scanner');

        // 3. Create Event with Event Days (PPA)
        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'IT Programming Seminar',
            'status' => 'approved', // must be approved for scanning
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'society_late_threshold_min' => 15,
            'general_competition_threshold_min' => 30,
            'left_early_buffer_min' => 15,
        ]);

        $day1 = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        // 4. Authenticate Scanner via API
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.officer@usm.edu.ph',
            'password' => 'password123',
            'device_name' => 'Test Scanner Device',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // 5. Test Hydration API (Roster pre-caching)
        $hydrateResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/v1/events/{$event->id}/hydrate");

        $hydrateResponse->assertStatus(200);
        $hydrateResponse->assertJsonFragment([
            'qr_code_value' => 'QR_JUAN_123',
            'name' => 'Juan Dela Cruz',
        ]);

        // 6. Test Bulk Sync API (Ingest raw scans)
        $dedup1 = 'dedup_key_in_1';
        $dedup2 = 'dedup_key_out_2';

        $syncResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/attendance/sync', [
            'event_id' => $event->id,
            'scans' => [
                // Juan Dela Cruz times in at 07:55:00 (On-Time for Society and Competition)
                [
                    'dedup_key' => $dedup1,
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(7, 55, 0)->toDateTimeString(),
                    'device_id' => 'device_abc',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_JUAN_123',
                ],
                // Juan Dela Cruz times out at 12:05:00 (Completed and not Left Early)
                [
                    'dedup_key' => $dedup2,
                    'scan_type' => 'time_out',
                    'scanned_at' => Carbon::today()->setTime(12, 5, 0)->toDateTimeString(),
                    'device_id' => 'device_abc',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_JUAN_123',
                ]
            ]
        ]);

        $syncResponse->assertStatus(200);
        $syncResponse->assertJsonFragment([
            'ingested_count' => 2,
        ]);

        // 7. Verify duplicates are ignored
        $syncResponseDup = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/attendance/sync', [
            'event_id' => $event->id,
            'scans' => [
                [
                    'dedup_key' => $dedup1, // duplicate dedup key
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(7, 55, 0)->toDateTimeString(),
                    'device_id' => 'device_abc',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_JUAN_123',
                ]
            ]
        ]);

        $syncResponseDup->assertStatus(200);
        $syncResponseDup->assertJsonFragment([
            'message' => 'All scans in batch were duplicates, skipped.'
        ]);

        // Assert 2 raw scans exist
        $this->assertEquals(2, RawScan::count());

        // 8. Run Resolution Job & Verify Attendance Records
        $job = new ResolveAttendanceJob($event->id, [$student->id]);
        $job->handle();

        $this->assertEquals(1, AttendanceRecord::count());
        $record = AttendanceRecord::first();
        
        $this->assertEquals('present_on_time', $record->society_status);
        $this->assertEquals('present_on_time', $record->competition_status);
        $this->assertFalse($record->left_early);
        $this->assertFalse($record->valid); // Locked under the evaluation gate!

        // 9. Submit Evaluation (unlocks gate)
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $studentToken = $student->createToken('Student Phone')->plainTextToken;

        $evalResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $studentToken,
        ])->postJson('/api/v1/evaluations', [
            'event_id' => $event->id,
            'section_scores' => [
                'attainment_of_objectives' => 5,
                'speaker_mastery' => 5,
                'venue_comfort' => 4,
            ],
            'open_comment' => 'The program was highly educational!',
        ]);

        $evalResponse->assertStatus(201);

        // Assert the gate is unlocked (Attendance record status valid is updated to true)
        $record->refresh();
        $this->assertTrue($record->valid);
    }

    public function test_unapproved_events_lock_scanner_endpoints()
    {
        [$org, $scanner] = $this->createOrganizationWithScanner();

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Draft Orientation',
            'status' => 'submitted',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $token = $scanner->createToken('Scanner')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id])
            ->assertStatus(423)
            ->assertJsonFragment(['event_status' => 'submitted']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/v1/events/{$event->id}/hydrate")
            ->assertStatus(423)
            ->assertJsonFragment(['event_status' => 'submitted']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [[
                    'dedup_key' => 'locked-event-scan',
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                    'device_id' => 'device_locked',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_LOCKED',
                ]],
            ])
            ->assertStatus(423)
            ->assertJsonFragment(['event_status' => 'submitted']);
    }

    public function test_aro_admin_can_create_recognition_event_in_own_scope()
    {
        $aroOrg = Organization::create([
            'college_id' => null,
            'name' => 'ARO',
            'acronym' => 'ARO',
            'type' => 'aro',
        ]);

        $otherOrg = Organization::create([
            'college_id' => null,
            'name' => 'University Student Government',
            'acronym' => 'USG',
            'type' => 'usg',
        ]);

        $graduatingStudent = User::create([
            'name' => 'Graduating Student',
            'email' => 'graduating.student@usm.edu.ph',
            'organization_id' => $otherOrg->id,
            'student_id_number' => '24-3001',
            'qr_code_value' => 'QR_GRADUATING',
            'program_code' => 'BSIS',
        ]);
        $graduatingStudent->assignRole('Student');

        $aroAdmin = User::create([
            'name' => 'ARO Officer',
            'email' => 'aro.officer@usm.edu.ph',
            'organization_id' => $aroOrg->id,
            'password' => bcrypt('password123'),
        ]);
        $aroAdmin->assignRole('ARO Admin');

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'aro.officer@usm.edu.ph',
            'password' => 'password123',
            'device_name' => 'ARO Workstation',
        ])->assertStatus(200);

        $token = $loginResponse->json('token');

        $createResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/events', [
                'title' => 'Recognition Ceremony',
                'start_date' => Carbon::today()->toDateString(),
                'end_date' => Carbon::today()->toDateString(),
                'location_type' => 'on-campus',
                'location_details' => 'University Auditorium',
                'target_demographics' => ['graduating_students' => true],
                'budget_allocations' => ['program_materials' => 10000],
                'event_days' => [[
                    'day_number' => 1,
                    'date' => Carbon::today()->toDateString(),
                    'start_time' => '08:00',
                    'end_time' => '12:00',
                ]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('event.organization_id', $aroOrg->id);

        $eventId = $createResponse->json('event.id');
        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'organization_id' => $aroOrg->id,
            'title' => 'Recognition Ceremony',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/events/{$eventId}/submit")
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/v1/events/{$eventId}/analytics")
            ->assertStatus(200)
            ->assertJsonPath('attendance.total_demographic', 1);

        $pending = PendingStudentLink::create([
            'event_id' => $eventId,
            'organization_id' => $aroOrg->id,
            'qr_code_value' => 'QR_GRAD_UNKNOWN',
            'status' => 'pending',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/pending-student-links/{$pending->id}/resolve", [
                'student_id' => $graduatingStudent->id,
                'notes' => 'Matched against graduating student list.',
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'resolved']);

        $otherEvent = Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'USG General Assembly',
            'status' => 'draft',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/events/{$otherEvent->id}/submit")
            ->assertStatus(403);
    }

    public function test_unresolved_qr_sync_creates_pending_link_and_can_be_resolved()
    {
        [$org, $scanner] = $this->createOrganizationWithScanner();

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Approved Seminar',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $token = $scanner->createToken('Scanner')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [[
                    'dedup_key' => 'unknown-qr-1',
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                    'device_id' => 'device_unknown',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_UNKNOWN',
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'ingested_count' => 1,
                'unresolved_count' => 1,
            ]);

        $this->assertEquals(1, PendingStudentLink::count());
        $pending = PendingStudentLink::first();
        $this->assertSame('pending', $pending->status);
        $this->assertSame('QR_UNKNOWN', $pending->qr_code_value);
        $this->assertNull(RawScan::first()->student_id);

        $student = User::create([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '23-5678',
            'qr_code_value' => 'QR_MARIA_5678',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/pending-student-links/{$pending->id}/resolve", [
                'student_id' => $student->id,
                'notes' => 'Matched after profile confirmation.',
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'resolved']);

        $pending->refresh();
        $this->assertSame('resolved', $pending->status);
        $this->assertSame($student->id, $pending->resolved_student_id);
        $this->assertSame($student->id, RawScan::first()->student_id);
    }

    public function test_dashboard_generated_scanner_link_drives_hydrate_sync_and_eilo_resolution()
    {
        [$org] = $this->createOrganizationWithScanner();
        $student = User::create([
            'name' => 'Scanner Link Student',
            'email' => 'scanner.link.student@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '24-3100',
            'qr_code_value' => 'QR_SCANNER_LINK',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $societyAdmin = User::create([
            'name' => 'Link Issuer',
            'email' => 'link.issuer@usm.edu.ph',
            'organization_id' => $org->id,
            'password' => bcrypt('password123'),
        ]);
        $societyAdmin->assignRole('Society Admin');

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Dashboard Link Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'society_late_threshold_min' => 15,
            'general_competition_threshold_min' => 30,
            'left_early_buffer_min' => 15,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $linkResponse = $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.scanner-link', $event->id))
            ->assertSessionHas('scanner_link');

        $link = $linkResponse->getSession()->get('scanner_link');
        parse_str(parse_url($link, PHP_URL_QUERY), $linkQuery);
        $token = $linkQuery['token'];

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id])
            ->assertStatus(200)
            ->assertJsonPath('event.id', $event->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/v1/events/{$event->id}/hydrate")
            ->assertStatus(200)
            ->assertJsonFragment(['qr_code_value' => 'QR_SCANNER_LINK']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [
                    [
                        'dedup_key' => 'scanner-link-in',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(7, 59, 0)->toDateTimeString(),
                        'device_id' => 'device_scanner_link',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_SCANNER_LINK',
                    ],
                    [
                        'dedup_key' => 'scanner-link-in',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(8, 1, 0)->toDateTimeString(),
                        'device_id' => 'device_scanner_link',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_SCANNER_LINK',
                    ],
                    [
                        'dedup_key' => 'scanner-link-out',
                        'scan_type' => 'time_out',
                        'scanned_at' => Carbon::today()->setTime(12, 3, 0)->toDateTimeString(),
                        'device_id' => 'device_scanner_link',
                        'manual_entry' => true,
                        'qr_code_value' => 'QR_SCANNER_LINK',
                    ],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'ingested_count' => 2,
                'duplicate_count' => 1,
                'unresolved_count' => 0,
            ]);

        $this->assertSame(2, RawScan::where('event_id', $event->id)->count());

        (new ResolveAttendanceJob($event->id, [$student->id]))->handle();

        $this->assertDatabaseHas('attendance_records', [
            'event_id' => $event->id,
            'student_id' => $student->id,
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'left_early' => false,
        ]);
    }

    public function test_student_token_cannot_open_scanner_session()
    {
        [$org] = $this->createOrganizationWithScanner();

        $student = User::create([
            'name' => 'Unauthorized Scanner Student',
            'email' => 'unauthorized.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '24-3200',
            'qr_code_value' => 'QR_UNAUTHORIZED_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Student Token Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $token = $student->createToken('student-phone')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id])
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'This token is not authorized for scanner sessions.',
            ]);
    }

    public function test_scanner_token_rejects_cross_tenant_event_access()
    {
        [$ownOrg, $societyAdmin] = $this->createOrganizationWithScanner();
        [$otherOrg] = $this->createOrganizationWithScanner('College of Science', 'COS', 'Science Society', 'SCI');

        $event = Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Other Tenant Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $token = $societyAdmin->createToken('cross-tenant-scanner', ['scan-qr-codes'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Unauthorized event boundary check.']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson("/api/v1/events/{$event->id}/hydrate")
            ->assertStatus(403);

        $this->assertNotSame($ownOrg->id, $otherOrg->id);
    }

    public function test_nlp_query_is_scoped_to_the_requesting_admin_organization()
    {
        [$ownOrg, $societyAdmin] = $this->createOrganizationWithScanner();
        [$otherOrg] = $this->createOrganizationWithScanner('College of Agriculture', 'COA', 'Aggies Society', 'AGS');

        $ownStudent = User::create([
            'name' => 'Own Student',
            'email' => 'own.student@usm.edu.ph',
            'organization_id' => $ownOrg->id,
            'student_id_number' => '24-1001',
            'qr_code_value' => 'QR_OWN',
            'program_code' => 'BSIS',
        ]);
        $ownStudent->assignRole('Student');

        $otherStudent = User::create([
            'name' => 'Other Student',
            'email' => 'other.student@usm.edu.ph',
            'organization_id' => $otherOrg->id,
            'student_id_number' => '24-2002',
            'qr_code_value' => 'QR_OTHER',
            'program_code' => 'BSA',
        ]);
        $otherStudent->assignRole('Student');

        $ownEvent = Event::create([
            'organization_id' => $ownOrg->id,
            'title' => 'Own Event',
            'status' => 'completed',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);
        $otherEvent = Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Other Event',
            'status' => 'completed',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $ownRecord = AttendanceRecord::create([
            'event_id' => $ownEvent->id,
            'student_id' => $ownStudent->id,
            'time_in' => Carbon::today()->setTime(8, 0),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
        ]);

        AttendanceRecord::create([
            'event_id' => $otherEvent->id,
            'student_id' => $otherStudent->id,
            'time_in' => Carbon::today()->setTime(8, 0),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
        ]);

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService {
            public function __construct()
            {
            }

            public function parseNaturalLanguageQuery(string $query, User $user): array
            {
                return [
                    'target_table' => 'attendance_records',
                    'filters' => [
                        [
                            'field' => 'organization_id',
                            'operator' => '=',
                            'value' => 'malicious-cross-tenant-filter',
                        ],
                    ],
                ];
            }
        });

        $token = $societyAdmin->createToken('Admin')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/admin/nlp-query', [
                'query' => 'Show all attendance records.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('target_table', 'attendance_records')
            ->assertJsonCount(1, 'ignored_filters')
            ->assertJsonCount(1, 'results');

        $this->assertSame($ownRecord->id, $response->json('results.0.id'));
    }

    public function test_gemini_sentiment_primary_success_is_sanitized()
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-1.5-pro',
            'services.gemini.fallback_model' => 'gemini-2.0-flash',
        ]);

        Http::fake([
            '*gemini-1.5-pro*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'results' => [
                                    ['id' => 'eval-1', 'sentiment' => 'excited', 'score' => 1.4],
                                    ['id' => 'eval-2', 'sentiment' => 'negative', 'score' => -0.2],
                                ],
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $results = (new GeminiService())->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Great event.'],
            ['id' => 'eval-2', 'comment' => 'Venue was hot.'],
        ]);

        $this->assertSame('eval-1', $results[0]['id']);
        $this->assertSame('neutral', $results[0]['sentiment']);
        $this->assertSame(1.0, $results[0]['score']);
        $this->assertSame('negative', $results[1]['sentiment']);
        $this->assertSame(0.0, $results[1]['score']);
    }

    public function test_gemini_sentiment_falls_back_to_flash()
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-1.5-pro',
            'services.gemini.fallback_model' => 'gemini-2.0-flash',
        ]);

        Http::fake([
            '*gemini-1.5-pro*' => Http::response('rate limit', 429),
            '*gemini-2.0-flash*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'results' => [
                                    ['id' => 'eval-1', 'sentiment' => 'positive', 'score' => 0.91],
                                ],
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $results = (new GeminiService())->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Very useful.'],
        ]);

        $this->assertSame('positive', $results[0]['sentiment']);
        $this->assertSame(0.91, $results[0]['score']);
    }

    public function test_gemini_sentiment_returns_empty_when_models_fail_or_return_malformed_json()
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-1.5-pro',
            'services.gemini.fallback_model' => 'gemini-2.0-flash',
        ]);

        Http::fake([
            '*gemini-1.5-pro*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'not-json']],
                    ],
                ]],
            ], 200),
            '*gemini-2.0-flash*' => Http::response('server error', 500),
        ]);

        $results = (new GeminiService())->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Comment.'],
        ]);

        $this->assertSame([], $results);
    }

    public function test_gemini_sentiment_missing_api_key_returns_neutral_fallback()
    {
        config(['services.gemini.api_key' => null]);

        $results = (new GeminiService())->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Comment.'],
        ]);

        $this->assertSame([
            ['id' => 'eval-1', 'sentiment' => 'neutral', 'score' => 0.5],
        ], $results);
    }

    public function test_sentiment_job_updates_only_unprocessed_non_blank_comments()
    {
        [$org] = $this->createOrganizationWithScanner();
        $student = User::create([
            'name' => 'Sentiment Student',
            'email' => 'sentiment.student@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '24-3001',
            'qr_code_value' => 'QR_SENTIMENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Sentiment Event',
            'status' => 'completed',
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->subDays(3),
        ]);

        $target = Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Helpful and clear.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $blank = Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 3],
            'open_comment' => '   ',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $existing = Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 4],
            'open_comment' => 'Already processed.',
            'sentiment' => 'positive',
            'sentiment_score' => 0.8,
            'submitted_at' => now(),
        ]);

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService {
            public function __construct()
            {
            }

            public function analyzeSentiments(array $comments): array
            {
                return array_map(fn ($comment) => [
                    'id' => $comment['id'],
                    'sentiment' => 'negative',
                    'score' => 0.22,
                ], $comments);
            }
        });

        (new AnalyzeEventSentimentsJob($event->id))->handle(app(AiServiceInterface::class));

        $this->assertSame('negative', $target->fresh()->sentiment);
        $this->assertSame(0.22, $target->fresh()->sentiment_score);
        $this->assertSame('unprocessed', $blank->fresh()->sentiment);
        $this->assertSame('positive', $existing->fresh()->sentiment);
    }

    public function test_closed_evaluation_command_dispatches_only_closed_events_with_unprocessed_comments()
    {
        Bus::fake([AnalyzeEventSentimentsJob::class]);

        [$org] = $this->createOrganizationWithScanner();
        $student = User::create([
            'name' => 'Command Student',
            'email' => 'command.student@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '24-3002',
            'qr_code_value' => 'QR_COMMAND',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $closedEvent = Event::create([
            'organization_id' => $org->id,
            'title' => 'Closed Evaluation Event',
            'status' => 'completed',
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->subDays(3),
        ]);
        EventDay::create([
            'event_id' => $closedEvent->id,
            'day_number' => 1,
            'date' => Carbon::today()->subDays(3),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        Evaluation::create([
            'event_id' => $closedEvent->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Ready to process.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $openEvent = Event::create([
            'organization_id' => $org->id,
            'title' => 'Open Evaluation Event',
            'status' => 'completed',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);
        EventDay::create([
            'event_id' => $openEvent->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00',
            'end_time' => '23:00',
        ]);
        Evaluation::create([
            'event_id' => $openEvent->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Still open.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $this->artisan('eaes:analyze-closed-evaluations')
            ->expectsOutput('Dispatched 1 sentiment analysis job(s).')
            ->assertSuccessful();

        Bus::assertDispatched(AnalyzeEventSentimentsJob::class, 1);
    }

    private function createOrganizationWithScanner(
        string $collegeName = 'College of Engineering and Information Technology',
        string $collegeCode = 'CEIT',
        string $organizationName = 'Philippine Society of Information Technology Students',
        string $organizationAcronym = 'PSITS'
    ): array {
        $university = University::firstOrCreate([
            'domain' => 'usm.edu.ph',
        ], [
            'name' => 'University of Southern Mindanao',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => $collegeName,
            'code' => $collegeCode,
        ]);

        $org = Organization::create([
            'college_id' => $college->id,
            'name' => $organizationName,
            'acronym' => $organizationAcronym,
            'type' => 'society',
        ]);

        $scanner = User::create([
            'name' => "{$organizationAcronym} Officer",
            'email' => strtolower($organizationAcronym) . '.officer@usm.edu.ph',
            'organization_id' => $org->id,
            'password' => bcrypt('password123'),
        ]);
        $scanner->assignRole('Society Admin');
        $scanner->assignRole('Scanner');

        return [$org, $scanner, $college, $university];
    }
}
