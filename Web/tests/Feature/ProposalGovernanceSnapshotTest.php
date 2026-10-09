<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Services\EventProposalDraftService;
use App\Services\ProposalGovernanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProposalGovernanceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private University $university;

    private SchoolYear $activeSchoolYear;

    private College $college;

    private Department $department;

    private Program $program;

    private Organization $organization;

    private User $president;

    private User $secretary;

    private User $deptChair;

    private User $collegeDean;

    private OrganizationOfficerRoster $roster;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);

        // 1. Create University & Active School Year
        $this->university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $this->activeSchoolYear = SchoolYear::create([
            'label' => '2026-2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-03-31',
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        // 2. Create Hierarchy: College -> Department -> Program
        $this->college = College::create([
            'university_id' => $this->university->id,
            'name' => 'College of Science and Mathematics',
            'code' => 'CSM',
        ]);

        $this->department = Department::create([
            'college_id' => $this->college->id,
            'name' => 'Department of Computer Science',
            'code' => 'DCS',
            'status' => 'active',
        ]);

        $this->program = Program::create([
            'college_id' => $this->college->id,
            'department_id' => $this->department->id,
            'name' => 'Bachelor of Science in Computer Science',
            'code' => 'BSCS',
        ]);

        // 3. Create Proposing Organization
        $this->organization = Organization::create([
            'college_id' => $this->college->id,
            'department_id' => $this->department->id,
            'name' => 'Computer Science Society',
            'acronym' => 'CSS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $this->organization->programs()->attach($this->program->id);

        // 4. Create Officers
        $this->president = User::factory()->create([
            'name' => 'Alice President',
            'email' => 'alice.president@usm.edu.ph',
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'college_id' => $this->college->id,
            'program_id' => $this->program->id,
        ]);
        $this->president->assignRole('Society Admin');

        $this->secretary = User::factory()->create([
            'name' => 'Bob Secretary',
            'email' => 'bob.secretary@usm.edu.ph',
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'college_id' => $this->college->id,
            'program_id' => $this->program->id,
        ]);
        $this->secretary->assignRole('Society Admin');

        // Verified Officer Roster for Active School Year
        $this->roster = OrganizationOfficerRoster::create([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->activeSchoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
            'submitted_by' => $this->president->id,
            'submitted_at' => now()->subDays(5),
            'verified_by' => $this->president->id,
            'verified_at' => now()->subDays(3),
            'verification_remarks' => 'Verified official roster.',
        ]);

        OrganizationOfficer::create([
            'officer_roster_id' => $this->roster->id,
            'user_id' => $this->president->id,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => '2026-06-01',
        ]);

        OrganizationOfficer::create([
            'officer_roster_id' => $this->roster->id,
            'user_id' => $this->secretary->id,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => '2026-06-01',
        ]);

        // 5. Create Department Chairperson with AdminAssignment
        $this->deptChair = User::factory()->create([
            'name' => 'Dr. Charlie Chairperson',
            'email' => 'charlie.chair@usm.edu.ph',
            'department_id' => $this->department->id,
            'college_id' => $this->college->id,
            'user_type' => 'faculty',
        ]);
        AdminAssignment::create([
            'user_id' => $this->deptChair->id,
            'role_name' => 'Department Chairperson',
            'position_title' => 'Chairperson, Department of Computer Science',
            'department_id' => $this->department->id,
            'college_id' => $this->college->id,
            'school_year_id' => $this->activeSchoolYear->id,
            'academic_year' => '2026-2027',
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
        ]);

        // 6. Create College Dean with AdminAssignment
        $this->collegeDean = User::factory()->create([
            'name' => 'Dr. Diana Dean',
            'email' => 'diana.dean@usm.edu.ph',
            'college_id' => $this->college->id,
            'user_type' => 'faculty',
        ]);
        AdminAssignment::create([
            'user_id' => $this->collegeDean->id,
            'role_name' => 'College Dean',
            'position_title' => 'Dean, College of Science and Mathematics',
            'college_id' => $this->college->id,
            'school_year_id' => $this->activeSchoolYear->id,
            'academic_year' => '2026-2027',
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
        ]);
    }

    private function sampleProposalData(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'title' => 'CS Tech Summit 2026',
            'proposal_category' => 'activity',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'location_type' => 'on-campus',
            'location_details' => 'CS AVR Hall',
            'event_days' => [
                [
                    'date' => now()->addDays(10)->toDateString(),
                    'start_time' => '08:00',
                    'end_time' => '17:00',
                ],
            ],
            'evaluation_questions' => [
                [
                    'label' => 'Overall event satisfaction',
                    'type' => 'rating',
                ],
            ],
            'target_demographics' => ['CS Students'],
            'budget_allocations' => ['budget_cost' => 5000],
            'resolution_number' => 'RES-2026-001',
            'parent_event_id' => null,
        ];
    }

    public function test_proposals_store_school_year_id_fk_and_capture_hierarchy_snapshots(): void
    {
        $proposalService = app(EventProposalDraftService::class);
        $event = $proposalService->create($this->secretary, $this->sampleProposalData(), null, null);

        // 1. Foreign Key
        $this->assertNotNull($event->school_year_id);
        $this->assertSame($this->activeSchoolYear->id, $event->school_year_id);
        $this->assertTrue($event->schoolYear->is($this->activeSchoolYear));

        // 2. Human-readable snapshots on columns and accessors
        $this->assertSame('College of Science and Mathematics', $event->college_name_snapshot);
        $this->assertSame('College of Science and Mathematics', $event->college_snapshot);

        $this->assertSame('Department of Computer Science', $event->department_name_snapshot);
        $this->assertSame('Department of Computer Science', $event->department_snapshot);

        $this->assertSame('Bachelor of Science in Computer Science', $event->program_name_snapshot);
        $this->assertSame('Bachelor of Science in Computer Science', $event->program_snapshot);

        $this->assertSame('Computer Science Society', $event->organization_name_snapshot);
        $this->assertSame('Computer Science Society', $event->organization_snapshot);

        $this->assertSame('Bob Secretary', $event->proponent_name_snapshot);
        $this->assertSame('Bob Secretary', $event->proponent_snapshot);

        // 3. User relationships
        $this->assertSame($this->secretary->id, $event->proponent_user_id);
        $this->assertTrue($event->proponent->is($this->secretary));

        $this->assertSame($this->president->id, $event->org_head_user_id);
        $this->assertTrue($event->organizationHead->is($this->president));

        // 4. Governance metadata snapshot
        $this->assertIsArray($event->governance_snapshots);
        $this->assertSame('2026-2027', $event->governance_snapshots['metadata']['school_year_label']);
        $this->assertSame('CSM', $event->governance_snapshots['metadata']['college_code']);
        $this->assertSame('DCS', $event->governance_snapshots['metadata']['department_code']);
        $this->assertSame('BSCS', $event->governance_snapshots['metadata']['program_code']);
        $this->assertSame('CSS', $event->governance_snapshots['metadata']['organization_acronym']);
    }

    public function test_proponent_and_org_head_resolve_from_verified_roster_when_secretary_creates(): void
    {
        $governance = app(ProposalGovernanceService::class);

        // Proponent resolves to Secretary when Secretary creates
        $proponent = $governance->resolveProponent($this->organization, $this->activeSchoolYear, $this->secretary);
        $this->assertNotNull($proponent);
        $this->assertSame($this->secretary->id, $proponent->id);

        // Org Head resolves to President
        $orgHead = $governance->resolveOrganizationHead($this->organization, $this->activeSchoolYear);
        $this->assertNotNull($orgHead);
        $this->assertSame($this->president->id, $orgHead->id);
    }

    public function test_proponent_and_org_head_resolve_from_verified_roster_when_president_creates(): void
    {
        $governance = app(ProposalGovernanceService::class);

        // When President is the actor, Proponent is President
        $proponent = $governance->resolveProponent($this->organization, $this->activeSchoolYear, $this->president);
        $this->assertNotNull($proponent);
        $this->assertSame($this->president->id, $proponent->id);

        // Org Head is still President
        $orgHead = $governance->resolveOrganizationHead($this->organization, $this->activeSchoolYear);
        $this->assertNotNull($orgHead);
        $this->assertSame($this->president->id, $orgHead->id);

        // Event creation reflects President as proponent
        $event = app(EventProposalDraftService::class)->create($this->president, $this->sampleProposalData(), null, null);
        $this->assertSame('Alice President', $event->proponent_name_snapshot);
        $this->assertSame($this->president->id, $event->proponent_user_id);
        $this->assertSame($this->president->id, $event->org_head_user_id);
    }

    public function test_proponent_defaults_to_secretary_when_third_party_creates(): void
    {
        $governance = app(ProposalGovernanceService::class);
        $externalUser = User::factory()->create(['name' => 'OSA Staff']);

        // Third party actor -> Secretary is default Proponent
        $proponent = $governance->resolveProponent($this->organization, $this->activeSchoolYear, $externalUser);
        $this->assertNotNull($proponent);
        $this->assertSame($this->secretary->id, $proponent->id);
    }

    public function test_department_chairperson_and_college_dean_are_dynamically_resolved(): void
    {
        $governance = app(ProposalGovernanceService::class);

        $chairperson = $governance->resolveDepartmentChairperson($this->organization);
        $this->assertNotNull($chairperson);
        $this->assertSame($this->deptChair->id, $chairperson->id);

        $dean = $governance->resolveCollegeDean($this->organization);
        $this->assertNotNull($dean);
        $this->assertSame($this->collegeDean->id, $dean->id);

        // On event creation, chair and dean ids are populated
        $event = app(EventProposalDraftService::class)->create($this->secretary, $this->sampleProposalData(), null, null);
        $this->assertSame($this->deptChair->id, $event->dept_chair_user_id);
        $this->assertTrue($event->departmentChairperson->is($this->deptChair));

        $this->assertSame($this->collegeDean->id, $event->college_dean_user_id);
        $this->assertTrue($event->collegeDean->is($this->collegeDean));
    }

    public function test_snapshots_are_immutable_when_master_data_changes_later(): void
    {
        $proposalService = app(EventProposalDraftService::class);
        $event = $proposalService->create($this->secretary, $this->sampleProposalData(), null, null);

        $originalEventId = $event->id;
        $originalSchoolYearId = $event->school_year_id;
        $originalCollegeSnapshot = $event->college_name_snapshot;
        $originalDeptSnapshot = $event->department_name_snapshot;
        $originalProgSnapshot = $event->program_name_snapshot;
        $originalOrgSnapshot = $event->organization_name_snapshot;
        $originalProponentSnapshot = $event->proponent_name_snapshot;
        $originalProponentUserId = $event->proponent_user_id;
        $originalOrgHeadUserId = $event->org_head_user_id;

        // Verify initial state
        $this->assertSame('College of Science and Mathematics', $originalCollegeSnapshot);
        $this->assertSame('Department of Computer Science', $originalDeptSnapshot);
        $this->assertSame('Bachelor of Science in Computer Science', $originalProgSnapshot);
        $this->assertSame('Computer Science Society', $originalOrgSnapshot);
        $this->assertSame('Bob Secretary', $originalProponentSnapshot);

        // Mutate Master Data Later:
        $this->college->update(['name' => 'College of Computing and Artificial Intelligence']);
        $this->department->update(['name' => 'Department of Advanced Intelligence']);
        $this->program->update(['name' => 'BS in AI and Robotics']);
        $this->organization->update(['name' => 'Robotics and AI Guild']);
        $this->secretary->update(['name' => 'Robert Secretary Renamed']);
        $this->president->update(['name' => 'Alicia President Renamed']);

        // Also appoint a new president and secretary in a new roster
        $newPres = User::factory()->create(['name' => 'New President']);
        $newSec = User::factory()->create(['name' => 'New Secretary']);

        $newRoster = OrganizationOfficerRoster::create([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->activeSchoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
            'submitted_by' => $newPres->id,
            'verified_by' => $newPres->id,
            'verified_at' => now(),
        ]);
        OrganizationOfficer::create([
            'officer_roster_id' => $newRoster->id,
            'user_id' => $newPres->id,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'status' => OrganizationOfficer::STATUS_ACTIVE,
        ]);
        OrganizationOfficer::create([
            'officer_roster_id' => $newRoster->id,
            'user_id' => $newSec->id,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'status' => OrganizationOfficer::STATUS_ACTIVE,
        ]);

        // Update the event proposal with draft changes
        $updatedData = $this->sampleProposalData();
        $updatedData['title'] = 'CS Tech Summit 2026 - Revised Title';

        $updatedEvent = $proposalService->update($event, $this->secretary, $updatedData, null, null);

        // Refresh from database
        $freshEvent = Event::find($originalEventId);

        // Assert Title updated
        $this->assertSame('CS Tech Summit 2026 - Revised Title', $freshEvent->title);

        // Assert all snapshots remained IMMUTABLE
        $this->assertSame($originalSchoolYearId, $freshEvent->school_year_id);
        $this->assertSame($originalCollegeSnapshot, $freshEvent->college_name_snapshot);
        $this->assertSame($originalDeptSnapshot, $freshEvent->department_name_snapshot);
        $this->assertSame($originalProgSnapshot, $freshEvent->program_name_snapshot);
        $this->assertSame($originalOrgSnapshot, $freshEvent->organization_name_snapshot);
        $this->assertSame($originalProponentSnapshot, $freshEvent->proponent_name_snapshot);
        $this->assertSame($originalProponentUserId, $freshEvent->proponent_user_id);
        $this->assertSame($originalOrgHeadUserId, $freshEvent->org_head_user_id);
    }

    public function test_dynamic_resolution_falls_back_to_user_roles_when_no_admin_assignment(): void
    {
        $governance = app(ProposalGovernanceService::class);

        // Department with no AdminAssignment, but faculty user with Department Chairperson role
        $dept2 = Department::create([
            'college_id' => $this->college->id,
            'name' => 'Department of Mathematics',
            'code' => 'DMATH',
            'status' => 'active',
        ]);
        $org2 = Organization::create([
            'college_id' => $this->college->id,
            'department_id' => $dept2->id,
            'name' => 'Math Society',
            'acronym' => 'MS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $mathChair = User::factory()->create([
            'name' => 'Prof. Math Chair',
            'department_id' => $dept2->id,
            'user_type' => 'faculty',
        ]);
        $mathChair->assignRole('Academic Unit Admin'); // Academic Unit Admin is seeded with dept chair permissions

        // Create explicit Dept Chair role
        Role::firstOrCreate(['name' => 'Department Chairperson', 'guard_name' => 'web']);
        $mathChair->assignRole('Department Chairperson');

        $resolved = $governance->resolveDepartmentChairperson($org2);
        $this->assertNotNull($resolved);
        $this->assertSame($mathChair->id, $resolved->id);
    }

    public function test_chairperson_resolves_null_cleanly_for_organization_without_department(): void
    {
        $governance = app(ProposalGovernanceService::class);

        // USG or college-wide org without direct department
        $collegeOrg = Organization::create([
            'college_id' => $this->college->id,
            'department_id' => null,
            'name' => 'CSM Student Council',
            'acronym' => 'CSMSC',
            'type' => 'lsg',
            'status' => 'active',
        ]);

        $chairperson = $governance->resolveDepartmentChairperson($collegeOrg);
        $this->assertNull($chairperson);

        // Dean still resolves from College
        $dean = $governance->resolveCollegeDean($collegeOrg);
        $this->assertNotNull($dean);
        $this->assertSame($this->collegeDean->id, $dean->id);
    }

    public function test_proponent_resolves_correctly_when_officer_is_replaced(): void
    {
        $governance = app(ProposalGovernanceService::class);

        // Mark current secretary as replaced
        $oldSecOfficer = OrganizationOfficer::where('officer_roster_id', $this->roster->id)
            ->where('position_key', OrganizationOfficer::POSITION_SECRETARY)
            ->first();
        $oldSecOfficer->update(['status' => OrganizationOfficer::STATUS_REPLACED]);

        // Add new active secretary to the verified roster
        $replacementSecretary = User::factory()->create(['name' => 'Carol Replacement Sec']);
        OrganizationOfficer::create([
            'officer_roster_id' => $this->roster->id,
            'user_id' => $replacementSecretary->id,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => now()->toDateString(),
        ]);

        $proponent = $governance->resolveProponent($this->organization, $this->activeSchoolYear, null);
        $this->assertNotNull($proponent);
        $this->assertSame($replacementSecretary->id, $proponent->id);
    }

    public function test_api_proposal_creation_captures_governance_snapshots_and_school_year(): void
    {
        // Give secretary active admin assignment to pass tenant scope middleware
        AdminAssignment::create([
            'user_id' => $this->secretary->id,
            'role_name' => 'Society Admin',
            'position_title' => 'Secretary',
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->activeSchoolYear->id,
            'academic_year' => '2026-2027',
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
        ]);

        $payload = [
            'organization_id' => $this->organization->id,
            'title' => 'API Created Proposal',
            'proposal_category' => 'activity',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'location_type' => 'on-campus',
            'location_details' => 'CS Hall',
            'event_days' => [
                [
                    'date' => now()->addDays(5)->toDateString(),
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                ],
            ],
            'evaluation_questions' => [
                [
                    'label' => 'Session relevance',
                    'type' => 'rating',
                ],
            ],
        ];

        Sanctum::actingAs($this->secretary);
        $response = $this->postJson('/api/v1/events', $payload);

        $response->assertStatus(201);
        $eventId = $response->json('event.id');
        $event = Event::findOrFail($eventId);

        $this->assertSame($this->activeSchoolYear->id, $event->school_year_id);
        $this->assertSame('College of Science and Mathematics', $event->college_name_snapshot);
        $this->assertSame('Department of Computer Science', $event->department_name_snapshot);
        $this->assertSame('Bachelor of Science in Computer Science', $event->program_name_snapshot);
        $this->assertSame('Computer Science Society', $event->organization_name_snapshot);
        $this->assertSame('Bob Secretary', $event->proponent_name_snapshot);
        $this->assertSame($this->secretary->id, $event->proponent_user_id);
        $this->assertSame($this->president->id, $event->org_head_user_id);
    }
}
