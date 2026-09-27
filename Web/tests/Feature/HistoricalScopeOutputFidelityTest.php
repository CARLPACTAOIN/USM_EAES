<?php

namespace Tests\Feature;

use App\Exports\EventAttendanceExport;
use App\Exports\EventEvaluationExport;
use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\Contracts\AiServiceInterface;
use App\Services\EventPermitService;
use App\Services\GeminiService;
use App\Support\EventEvaluationForm;
use App\Support\EventExportData;
use App\Support\NlpQueryExecutor;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HistoricalScopeOutputFidelityTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_pdf_and_excel_export_generators_render_department_program_year_level_and_school_year_from_locked_snapshots(): void
    {
        $setup = $this->createDomainHierarchy();
        $sy2025 = $setup['sy2025'];
        $sy2026 = $setup['sy2026'];
        $org = $setup['org1A'];
        $dept = $setup['dept1A'];
        $prog = $setup['prog1A'];
        $col = $setup['college1'];

        $progA = $setup['prog1A'];
        $progB = $setup['prog1B'];
        // 1. Create a student who was 2nd year BSCS in SY 2025-2026
        $student = $this->createStudentUser('Historical Student', $org, $progA, $dept, $col, '2nd_year');
        $this->createStudentMembership($student, $org, $progA, $dept, $col, $sy2025, '2nd_year');

        // 2. Create an admin who was Society Admin for SY 2025-2026
        $admin = $this->createAdminUser('Society Admin', $org, $col, $dept, $sy2025);

        // 3. Create an event in SY 2025-2026 with locked governance snapshots
        $snapshots = [
            'school_year_id' => $sy2025->id,
            'college_name_snapshot' => 'Historical College of Science',
            'department_name_snapshot' => 'Historical Dept of Computing',
            'program_name_snapshot' => 'Historical BS Computer Science',
            'organization_name_snapshot' => 'Historical CS Society',
            'proponent_name_snapshot' => 'Historical Proponent Officer',
            'metadata' => [
                'school_year_label' => $sy2025->label,
                'department_code' => 'HDCS',
                'program_code' => 'HBSCS',
                'organization_acronym' => 'HCSS',
            ],
        ];

        $event = Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $sy2025->id,
            'department_id' => $dept->id,
            'title' => 'Historical Symposium 2025',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_COMPLETED,
            'lifecycle_phase' => 'completed',
            'event_domain' => 'student_affairs',
            'college_name_snapshot' => $snapshots['college_name_snapshot'],
            'department_name_snapshot' => $snapshots['department_name_snapshot'],
            'program_name_snapshot' => $snapshots['program_name_snapshot'],
            'organization_name_snapshot' => $snapshots['organization_name_snapshot'],
            'proponent_name_snapshot' => $snapshots['proponent_name_snapshot'],
            'governance_snapshots' => $snapshots,
            'start_date' => '2025-09-10',
            'end_date' => '2025-09-10',
            'location_type' => 'on-campus',
        ]);

        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'school_year_id' => $sy2025->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'participant_type' => 'organization',
            'organization_id' => $org->id,
            'college_id' => $col->id,
            'department_id' => $dept->id,
            'participant_name_snapshot' => 'Historical CS Society',
            'status' => EventParticipationScope::STATUS_ACTIVE,
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
        ]);

        $day = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2025-09-10',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        $form = EventEvaluationForm::createForEvent($event, $admin, [[
            'id' => 'content_quality',
            'label' => 'Content Quality',
            'description' => 'Relevance of topics',
        ]], 'Evaluation 2025');

        $event->attendanceRecords()->create([
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'student_id' => $student->id,
            'time_in' => '2025-09-10 08:30:00',
            'time_out' => '2025-09-10 17:00:00',
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => true,
        ]);

        $event->evaluations()->create([
            'evaluation_form_version_id' => $form->id,
            'student_id' => $student->id,
            'section_scores' => ['content_quality' => 5],
            'open_comment' => 'Excellent historical symposium',
            'submitted_at' => '2025-09-10 17:30:00',
            'sentiment' => 'positive',
            'sentiment_score' => 0.95,
        ]);

        // 4. NOW: Years pass, and mutations occur in live database rows:
        // - Department is renamed
        $dept->update(['name' => 'Renamed Dept of Advanced AI', 'code' => 'RDAI']);
        // - Program is renamed
        $progA->update(['name' => 'Renamed BS Artificial Intelligence']);
        // - Organization is renamed
        $org->update(['name' => 'Renamed AI Society', 'acronym' => 'RAIS']);
        // - College is renamed
        $col->update(['name' => 'Renamed College of Modern Tech', 'code' => 'RCMT']);
        // - Student advances to 4th year and shifts to Program B in current student profile!
        $student->update([
            'year_level' => '4th_year',
            'program_id' => $progB->id,
            'program_code' => $progB->code,
        ]);
        // And student has a new membership for SY 2026-2027 in Program B
        $this->createStudentMembership($student, $org, $progB, $dept, $col, $sy2026, '4th_year');

        // 5. Build export payload
        $payload = EventExportData::build($event->fresh(), $admin);

        // A. Summary rows verification:
        $summary = collect($payload['summary_rows'])->keyBy(0)->map(fn ($row) => $row[1]);

        $this->assertSame('Historical Symposium 2025', $summary['Event']);
        $this->assertSame('2025-2026', $summary['School Year']);
        $this->assertSame('Historical CS Society', $summary['Organizer']);
        $this->assertSame('Historical College of Science', $summary['College']);
        $this->assertSame('Historical Dept of Computing', $summary['Department']);
        $this->assertSame('Historical BS Computer Science', $summary['Program']);
        $this->assertSame('Historical Proponent Officer', $summary['Proponent']);

        // Assert mutated names DO NOT appear in summary
        $this->assertNotSame('Renamed Dept of Advanced AI', $summary['Department']);
        $this->assertNotSame('Renamed BS Artificial Intelligence', $summary['Program']);
        $this->assertNotSame('Renamed AI Society', $summary['Organizer']);

        // B. Attendance row verification:
        $attHeaders = $payload['attendance_rows'][0];
        $attRow = $payload['attendance_rows'][1];

        $this->assertSame('Program', $attHeaders[4]);
        $this->assertSame('Year Level', $attHeaders[5]);
        // Preserves historical locked membership program code and year level
        $this->assertSame($progA->code, $attRow[4]);
        $this->assertSame('2nd Year', $attRow[5]);
        $this->assertNotSame('4th Year', $attRow[5]);
        $this->assertNotSame($progB->code, $attRow[4]);

        // C. Evaluation row verification:
        $evalHeaders = $payload['evaluation_rows'][0];
        $evalRow = $payload['evaluation_rows'][1];

        $this->assertSame('Program', $evalHeaders[2]);
        $this->assertSame('Year Level', $evalHeaders[3]);
        $this->assertSame($progA->code, $evalRow[2]);
        $this->assertSame('2nd Year', $evalRow[3]);
        $this->assertNotSame('4th Year', $evalRow[3]);

        // D. Excel Export verification
        $attendanceExport = new EventAttendanceExport($payload);
        $sheets = $attendanceExport->sheets();
        $this->assertCount(2, $sheets);
        $this->assertSame('Summary', $sheets[0]->title());
        $this->assertSame('Attendance', $sheets[1]->title());

        $evaluationExport = new EventEvaluationExport($payload);
        $evalSheets = $evaluationExport->sheets();
        $this->assertCount(2, $evalSheets);
        $this->assertSame('Summary', $evalSheets[0]->title());
        $this->assertSame('Evaluations', $evalSheets[1]->title());

        // E. PDF Views render successfully with locked historical values
        $attendanceHtml = view('exports.event-attendance', $payload)->render();
        $this->assertStringContainsString('Historical Dept of Computing', $attendanceHtml);
        $this->assertStringContainsString('Historical BS Computer Science', $attendanceHtml);
        $this->assertStringContainsString('2nd Year', $attendanceHtml);
        $this->assertStringContainsString('2025-2026', $attendanceHtml);
        $this->assertStringNotContainsString('Renamed Dept of Advanced AI', $attendanceHtml);

        $evalHtml = view('exports.event-evaluations', $payload)->render();
        $this->assertStringContainsString('Historical Dept of Computing', $evalHtml);
        $this->assertStringContainsString('Historical BS Computer Science', $evalHtml);
        $this->assertStringContainsString('2nd Year', $evalHtml);
        $this->assertStringContainsString('2025-2026', $evalHtml);
    }

    public function test_historical_permits_retain_original_scope_after_departments_and_rosters_change(): void
    {
        $setup = $this->createDomainHierarchy();
        $sy2025 = $setup['sy2025'];
        $org = $setup['org1A'];
        $dept = $setup['dept1A'];
        $prog = $setup['prog1A'];
        $col = $setup['college1'];

        $osa = $this->createAdminUser('Super Admin (OSA)');
        $organizer = $this->createAdminUser('Society Admin', $org, $col, $dept, $sy2025);

        // Create venue and evidence
        $venue = Venue::factory()->create([
            'name' => 'Historical Convention Center',
            'campus_location' => 'East Campus',
            'managing_office' => 'Facilities Office',
        ]);
        Storage::put('proposal-documents/historical.pdf', 'packet content');
        Storage::put('venue-confirmations/historical.pdf', 'venue confirmation');

        $event = Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $sy2025->id,
            'department_id' => $dept->id,
            'title' => 'Historical Conclave 2025',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_UNDER_REVIEW,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => '2025-10-15',
            'end_date' => '2025-10-15',
            'location_type' => 'on-campus',
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
            'venue_confirmation_office' => 'Facilities Office',
            'venue_confirmation_date' => '2025-10-01',
            'venue_confirmation_reference_number' => 'VC-HIST-001',
            'venue_confirmation_document_path' => 'venue-confirmations/historical.pdf',
            'venue_confirmation_document_original_name' => 'venue-conf.pdf',
            'proposal_document_path' => 'proposal-documents/historical.pdf',
            'proposal_document_original_name' => 'conclave-proposal.pdf',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
            'college_name_snapshot' => 'Historical Science College',
            'department_name_snapshot' => 'Historical Math Department',
            'program_name_snapshot' => 'Historical BS Mathematics',
            'organization_name_snapshot' => 'Historical Math Society',
            'proponent_name_snapshot' => 'Original Secretary 2025',
            'governance_snapshots' => [
                'school_year_id' => $sy2025->id,
                'college_name_snapshot' => 'Historical Science College',
                'department_name_snapshot' => 'Historical Math Department',
                'program_name_snapshot' => 'Historical BS Mathematics',
                'organization_name_snapshot' => 'Historical Math Society',
                'proponent_name_snapshot' => 'Original Secretary 2025',
                'metadata' => [
                    'school_year_label' => $sy2025->label,
                ],
            ],
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => '2025-10-15',
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        CanonicalPpaPacket::factory()->for($event)->create([
            'title' => $event->title,
            'proponent_name' => 'Original Secretary 2025',
            'proponent_organization' => 'Historical Math Society',
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'location_type' => 'on-campus',
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
            'rationale' => 'Historical conclave rationale.',
            'general_objective' => 'Conclave general objective.',
            'specific_objectives' => ['Objective 1'],
            'methodology' => 'Conclave methodology.',
            'program_activities' => [[
                'date' => '2025-10-15',
                'time' => '08:00 - 17:00',
                'activity' => 'Conclave session',
                'person_in_charge' => 'Original Secretary 2025',
            ]],
            'expected_output' => 'Approved conclave output.',
            'beneficiaries' => 'Math society students',
            'budget_breakdown' => [['item' => 'Logistics', 'amount' => '2000.00']],
        ]);
        EventEvaluationForm::createForEvent($event, $osa, [['id' => 'q1', 'label' => 'Q1', 'description' => 'D1']], 'Eval');

        // Approve workflow tasks and permit
        $run = $this->createApprovedWorkflowRun($event, $osa);

        $permitService = app(EventPermitService::class);
        $approvedEvent = $permitService->approve($event->fresh(), $osa);
        $version = $approvedEvent->currentPermitVersion()->firstOrFail();

        // Check proposal_snapshot locked contents
        $proposalSnap = $version->proposal_snapshot;
        $this->assertSame('Historical Math Department', $proposalSnap['department_name_snapshot']);
        $this->assertSame('Historical BS Mathematics', $proposalSnap['program_name_snapshot']);
        $this->assertSame('Historical Math Society', $proposalSnap['organization_name_snapshot']);
        $this->assertSame('Original Secretary 2025', $proposalSnap['proponent_name_snapshot']);
        $this->assertSame('2025-2026', $proposalSnap['school_year_label']);

        // Mutate live entities in database:
        $dept->update(['name' => 'Mutated Modern Math Dept']);
        $prog->update(['name' => 'Mutated Pure Mathematics']);
        $org->update(['name' => 'Mutated Modern Math Guild']);

        // Render permit certificate
        $certHtml = view('dashboard.events.permit-certificate', [
            'event' => $event->fresh(),
            'permitVersion' => $version->fresh(),
        ])->render();

        $this->assertStringContainsString('Historical Math Department', $certHtml);
        $this->assertStringContainsString('Historical BS Mathematics', $certHtml);
        $this->assertStringContainsString('Historical Math Society', $certHtml);
        $this->assertStringContainsString('Original Secretary 2025', $certHtml);
        $this->assertStringContainsString('2025-2026', $certHtml);

        $this->assertStringNotContainsString('Mutated Modern Math Dept', $certHtml);
        $this->assertStringNotContainsString('Mutated Pure Mathematics', $certHtml);
        $this->assertStringNotContainsString('Mutated Modern Math Guild', $certHtml);

        // New officer roster takes over in active school year 2026-2027
        $currentOrganizer = $this->createAdminUser('Society Admin', $org, $col, $dept, $setup['sy2026']);

        // Access certificate download endpoint as new active organizer
        $this->actingAs($currentOrganizer)
            ->get(route('dashboard.events.permit-history.certificate', [$event, $version]))
            ->assertOk();

        // Access certificate download endpoint as OSA
        $this->actingAs($osa)
            ->get(route('dashboard.events.permit-history.certificate', [$event, $version]))
            ->assertOk();
    }

    public function test_nlp_query_wrapper_securely_applies_authority_projection_parameters_for_selected_school_year(): void
    {
        $setup = $this->createDomainHierarchy();
        $sy2025 = $setup['sy2025'];
        $sy2026 = $setup['sy2026'];

        $org1A = $setup['org1A']; // College 1, Dept 1A
        $org1B = $setup['org1B']; // College 1, Dept 1B
        $org2A = $setup['org2A']; // College 2, Dept 2A

        // Create events in different school years and organizations
        $eventOrgA_2025 = $this->createHistoricalEvent('Event Org1A 2025', $org1A, $setup['dept1A'], $sy2025);
        $eventOrgA_2026 = $this->createHistoricalEvent('Event Org1A 2026', $org1A, $setup['dept1A'], $sy2026);
        $eventOrgB_2026 = $this->createHistoricalEvent('Event Org1B 2026', $org1B, $setup['dept1B'], $sy2026);
        $eventOrg2A_2026 = $this->createHistoricalEvent('Event Org2A 2026', $org2A, $setup['dept2A'], $sy2026);

        // 1. Society Admin for Org 1A (assigned to SY 2026-2027)
        $societyAdmin = $this->createAdminUser('Society Admin', $org1A, $setup['college1'], $setup['dept1A'], $sy2026);

        $executor = app(NlpQueryExecutor::class);

        // Query with default scope (actor projection for SY 2026-2027)
        $resDefault = $executor->execute($societyAdmin, [
            'target_table' => 'events',
            'filters' => [],
        ]);

        $defaultEventIds = collect($resDefault['results'])->pluck('id')->all();
        $this->assertContains($eventOrgA_2026->id, $defaultEventIds);
        $this->assertNotContains($eventOrgA_2025->id, $defaultEventIds); // excluded: different school year
        $this->assertNotContains($eventOrgB_2026->id, $defaultEventIds); // excluded: different organization
        $this->assertNotContains($eventOrg2A_2026->id, $defaultEventIds); // excluded: different college

        // Query specifying their authorized school year 2026-2027
        $resSy2026 = $executor->execute($societyAdmin, [
            'target_table' => 'events',
            'filters' => [],
        ], $sy2026->id);
        $this->assertContains($eventOrgA_2026->id, collect($resSy2026['results'])->pluck('id')->all());

        // Society admin attempting to query a different School Year outside Authority Projection:
        $blocked = false;
        try {
            $executor->execute($societyAdmin, [
                'target_table' => 'events',
                'filters' => [],
            ], $sy2025->id);
        } catch (HttpException $e) {
            $blocked = true;
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('outside assigned School Year', $e->getMessage());
        }
        $this->assertTrue($blocked);

        // 2. Academic Unit Admin for Dept 1A (assigned to SY 2026-2027)
        $deptAdmin = $this->createAdminUser('Academic Unit Admin', null, $setup['college1'], $setup['dept1A'], $sy2026);

        $deptRes = $executor->execute($deptAdmin, [
            'target_table' => 'events',
            'filters' => [],
        ], $sy2026->id);
        $deptEventIds = collect($deptRes['results'])->pluck('id')->all();
        $this->assertContains($eventOrgA_2026->id, $deptEventIds);
        $this->assertNotContains($eventOrgB_2026->id, $deptEventIds); // Dept 1B excluded
        $this->assertNotContains($eventOrg2A_2026->id, $deptEventIds); // Dept 2A excluded

        // 3. LSG Admin for College 1 (assigned to SY 2026-2027)
        $lsgAdmin = $this->createAdminUser('LSG Admin', null, $setup['college1'], null, $sy2026);

        $lsgRes = $executor->execute($lsgAdmin, [
            'target_table' => 'events',
            'filters' => [],
        ], $sy2026->id);
        $lsgEventIds = collect($lsgRes['results'])->pluck('id')->all();
        $this->assertContains($eventOrgA_2026->id, $lsgEventIds);
        $this->assertContains($eventOrgB_2026->id, $lsgEventIds); // Org 1B is in College 1
        $this->assertNotContains($eventOrg2A_2026->id, $lsgEventIds); // Org 2A is in College 2

        // 4. Super Admin (OSA) - institutional access across organizations for any selected school year
        $osaAdmin = $this->createAdminUser('Super Admin (OSA)');

        // OSA queries SY 2025-2026
        $osaRes2025 = $executor->execute($osaAdmin, [
            'target_table' => 'events',
            'filters' => [],
        ], $sy2025->id);
        $osaEventIds2025 = collect($osaRes2025['results'])->pluck('id')->all();
        $this->assertContains($eventOrgA_2025->id, $osaEventIds2025);
        $this->assertNotContains($eventOrgA_2026->id, $osaEventIds2025);

        // OSA queries SY 2026-2027
        $osaRes2026 = $executor->execute($osaAdmin, [
            'target_table' => 'events',
            'filters' => [],
        ], $sy2026->id);
        $osaEventIds2026 = collect($osaRes2026['results'])->pluck('id')->all();
        $this->assertContains($eventOrgA_2026->id, $osaEventIds2026);
        $this->assertContains($eventOrgB_2026->id, $osaEventIds2026);
        $this->assertContains($eventOrg2A_2026->id, $osaEventIds2026);
        $this->assertNotContains($eventOrgA_2025->id, $osaEventIds2026);
    }

    public function test_nlp_api_and_web_endpoints_apply_school_year_and_authority_projection_security(): void
    {
        $setup = $this->createDomainHierarchy();
        $sy2025 = $setup['sy2025'];
        $sy2026 = $setup['sy2026'];
        $org = $setup['org1A'];

        $event2025 = $this->createHistoricalEvent('API Conclave 2025', $org, $setup['dept1A'], $sy2025);
        $event2026 = $this->createHistoricalEvent('API Conclave 2026', $org, $setup['dept1A'], $sy2026);

        $societyAdmin = $this->createAdminUser('Society Admin', $org, $setup['college1'], $setup['dept1A'], $sy2026);

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService
        {
            public function __construct() {}

            public function parseNaturalLanguageQuery(string $query, User $user): array
            {
                return [
                    'target_table' => 'events',
                    'filters' => [
                        ['field' => 'title', 'operator' => 'LIKE', 'value' => 'API Conclave'],
                    ],
                ];
            }
        });

        // 1. API endpoint with valid authorized school year
        Sanctum::actingAs($societyAdmin);
        $response = $this->postJson('/api/v1/admin/nlp-query', [
            'query' => 'Show API conclaves',
            'school_year_id' => $sy2026->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('school_year_id', $sy2026->id)
            ->assertJsonPath('authority_projection.organization_id', $org->id);

        $results = collect($response->json('results'));
        $this->assertTrue($results->contains('id', $event2026->id));
        $this->assertFalse($results->contains('id', $event2025->id));

        // 2. API endpoint attempting unauthorized school year
        $forbiddenResponse = $this->postJson('/api/v1/admin/nlp-query', [
            'query' => 'Show API conclaves',
            'school_year_id' => $sy2025->id,
        ]);
        $forbiddenResponse->assertForbidden();

        // 3. Web controller endpoint
        $this->actingAs($societyAdmin)
            ->post(route('dashboard.ai.query'), [
                'query' => 'Show API conclaves',
                'school_year_id' => $sy2026->id,
            ])
            ->assertOk()
            ->assertSee('API Conclave 2026')
            ->assertDontSee('API Conclave 2025');
    }

    /**
     * @return array{
     *     university: University,
     *     college1: College,
     *     college2: College,
     *     dept1A: Department,
     *     dept1B: Department,
     *     dept2A: Department,
     *     prog1A: Program,
     *     prog1B: Program,
     *     prog2A: Program,
     *     org1A: Organization,
     *     org1B: Organization,
     *     org2A: Organization,
     *     sy2025: SchoolYear,
     *     sy2026: SchoolYear
     * }
     */
    private function createDomainHierarchy(): array
    {
        $seq = ++$this->seq;

        $university = University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao']
        );

        $sy2025 = SchoolYear::firstOrCreate(['label' => '2025-2026'], [
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'status' => SchoolYear::STATUS_CLOSED,
            'is_current' => false,
        ]);

        $sy2026 = SchoolYear::firstOrCreate(['label' => '2026-2027'], [
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'status' => SchoolYear::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        $college1 = College::create([
            'university_id' => $university->id,
            'name' => "College of Computing {$seq}",
            'code' => "CC{$seq}",
        ]);

        $college2 = College::create([
            'university_id' => $university->id,
            'name' => "College of Arts {$seq}",
            'code' => "CA{$seq}",
        ]);

        $dept1A = Department::create([
            'college_id' => $college1->id,
            'name' => "Department of CS {$seq}",
            'code' => "DCS{$seq}",
        ]);

        $dept1B = Department::create([
            'college_id' => $college1->id,
            'name' => "Department of IT {$seq}",
            'code' => "DIT{$seq}",
        ]);

        $dept2A = Department::create([
            'college_id' => $college2->id,
            'name' => "Department of Literature {$seq}",
            'code' => "DLIT{$seq}",
        ]);

        $prog1A = Program::create([
            'college_id' => $college1->id,
            'department_id' => $dept1A->id,
            'name' => "BS Computer Science {$seq}",
            'code' => "BSCS{$seq}",
        ]);

        $prog1B = Program::create([
            'college_id' => $college1->id,
            'department_id' => $dept1B->id,
            'name' => "BS Information Tech {$seq}",
            'code' => "BSIT{$seq}",
        ]);

        $prog2A = Program::create([
            'college_id' => $college2->id,
            'department_id' => $dept2A->id,
            'name' => "BA Literature {$seq}",
            'code' => "BALIT{$seq}",
        ]);

        $org1A = Organization::create([
            'college_id' => $college1->id,
            'department_id' => $dept1A->id,
            'name' => "Computer Science Society {$seq}",
            'acronym' => "CSS{$seq}",
            'type' => 'society',
            'status' => 'active',
        ]);
        $org1A->programs()->attach($prog1A->id);

        $org1B = Organization::create([
            'college_id' => $college1->id,
            'department_id' => $dept1B->id,
            'name' => "IT Society {$seq}",
            'acronym' => "ITS{$seq}",
            'type' => 'society',
            'status' => 'active',
        ]);
        $org1B->programs()->attach($prog1B->id);

        $org2A = Organization::create([
            'college_id' => $college2->id,
            'department_id' => $dept2A->id,
            'name' => "Literature Society {$seq}",
            'acronym' => "LITS{$seq}",
            'type' => 'society',
            'status' => 'active',
        ]);
        $org2A->programs()->attach($prog2A->id);

        return compact(
            'university',
            'college1',
            'college2',
            'dept1A',
            'dept1B',
            'dept2A',
            'prog1A',
            'prog1B',
            'prog2A',
            'org1A',
            'org1B',
            'org2A',
            'sy2025',
            'sy2026'
        );
    }

    private function createStudentUser(
        string $name,
        Organization $org,
        Program $prog,
        Department $dept,
        College $col,
        string $yearLevel
    ): User {
        $seq = ++$this->seq;
        $user = User::create([
            'name' => $name,
            'email' => "student.hist.{$seq}@usm.edu.ph",
            'password' => null,
            'organization_id' => $org->id,
            'college_id' => $col->id,
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'program_code' => $prog->code,
            'student_id_number' => "25-{$seq}",
            'qr_code_value' => "QR-HIST-{$seq}",
            'year_level' => $yearLevel,
            'user_type' => 'student',
        ]);
        $user->assignRole('Student');

        return $user;
    }

    private function createStudentMembership(
        User $student,
        Organization $org,
        Program $prog,
        Department $dept,
        College $col,
        SchoolYear $sy,
        string $yearLevel
    ): OrganizationMembership {
        return OrganizationMembership::create([
            'user_id' => $student->id,
            'organization_id' => $org->id,
            'college_id' => $col->id,
            'department_id' => $dept->id,
            'program_id' => $prog->id,
            'school_year_id' => $sy->id,
            'academic_year' => $sy->label,
            'year_level' => $yearLevel,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'source' => 'historical-test',
        ]);
    }

    private function createAdminUser(
        string $role,
        ?Organization $org = null,
        ?College $col = null,
        ?Department $dept = null,
        ?SchoolYear $sy = null
    ): User {
        $seq = ++$this->seq;
        $user = User::create([
            'name' => "Admin {$role} {$seq}",
            'email' => "admin.hist.{$seq}@usm.edu.ph",
            'password' => bcrypt('password123'),
            'user_type' => 'faculty_or_staff_pending',
        ]);

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $role,
            organization: $org,
            college: $col ?: $org?->college,
            academicYear: $sy?->label ?? '2026-2027',
            termStart: '2026-08-01',
            termEnd: '2027-07-31',
            positionTitle: "{$role} Officer",
            approvedBy: $user,
            isPrimaryAdmin: false,
            department: $dept ?: $org?->department,
        );

        return $user->fresh();
    }

    private function createHistoricalEvent(
        string $title,
        Organization $org,
        Department $dept,
        SchoolYear $sy
    ): Event {
        return Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $sy->id,
            'department_id' => $dept->id,
            'title' => $title,
            'proposal_category' => 'activity',
            'status' => Event::STATUS_COMPLETED,
            'lifecycle_phase' => 'completed',
            'event_domain' => 'student_affairs',
            'start_date' => $sy->starts_on->copy()->addMonth(),
            'end_date' => $sy->starts_on->copy()->addMonth(),
            'location_type' => 'on-campus',
        ]);
    }

    private function createApprovedWorkflowRun(Event $event, User $osa): ProposalWorkflowRun
    {
        $template = ProposalProcessTemplate::create([
            'name' => 'Permit Template '.uniqid(),
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);
        $version = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);
        $run = ProposalWorkflowRun::create([
            'event_id' => $event->id,
            'template_version_id' => $version->id,
            'status' => ProposalWorkflowRun::STATUS_APPROVED,
            'started_by' => $osa->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
        $step = ProposalProcessStep::create([
            'template_version_id' => $version->id,
            'step_key' => 'osa_approval',
            'title' => 'OSA Final Permit Approval',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'permit',
            'display_order' => 1,
            'is_required' => true,
            'is_gate' => true,
            'condition_config' => ['assigned_role' => 'osa', 'assigned_scope' => 'university'],
        ]);
        $task = ProposalWorkflowTask::create([
            'workflow_run_id' => $run->id,
            'process_step_id' => $step->id,
            'status' => ProposalWorkflowTask::STATUS_APPROVED,
            'display_order' => 1,
            'step_key' => 'osa_approval',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'title' => 'OSA Final Permit Approval',
            'assigned_user_id' => $osa->id,
            'assigned_role' => 'osa',
            'assigned_scope' => 'university',
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            'decided_by' => $osa->id,
            'decided_at' => now(),
        ]);
        ApprovalTrail::create([
            'proposal_id' => $event->id,
            'workflow_run_id' => $run->id,
            'workflow_task_id' => $task->id,
            'actor_id' => $osa->id,
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            'previous_status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'resulting_status' => ProposalWorkflowTask::STATUS_APPROVED,
            'reference_id' => 'AT-HIST-001',
            'assigned_role' => 'osa',
            'assigned_scope' => 'university',
            'decided_at' => now(),
        ]);

        return $run;
    }
}
