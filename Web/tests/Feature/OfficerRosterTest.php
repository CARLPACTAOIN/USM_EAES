<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfficerRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_outgoing_president_can_save_partial_draft_roster(): void
    {
        [$org, $schoolYear, $president, , $incomingPresident] = $this->setupOrganizationWithOfficers();

        $response = $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $incomingPresident->id,
                        'position_key' => 'president',
                        'position_title' => 'President',
                    ],
                ],
                'attestation_text' => 'Draft in progress.',
            ])
            ->assertOk()
            ->assertJsonPath('roster.status', OrganizationOfficerRoster::STATUS_DRAFT)
            ->assertJsonPath('roster.attestation_text', 'Draft in progress.')
            ->assertJsonCount(1, 'roster.officers');

        $roster = OrganizationOfficerRoster::where('organization_id', $org->id)
            ->where('school_year_id', $schoolYear->id)
            ->firstOrFail();

        $this->assertSame(OrganizationOfficerRoster::STATUS_DRAFT, $roster->status);
        $this->assertSame(1, $roster->officers()->count());
        $this->assertSame($incomingPresident->id, $roster->officers()->first()->user_id);
    }

    public function test_outgoing_secretary_can_submit_valid_roster_with_evidence_file(): void
    {
        [
            $org,
            $schoolYear,
            ,
            $secretary,
            $incomingPres,
            $incomingSec,
            $adviser
        ] = $this->setupOrganizationWithOfficers();

        $file = UploadedFile::fake()->create('election_minutes.pdf', 200, 'application/pdf');

        $this->actingAs($secretary)
            ->post(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $incomingPres->id,
                        'position_key' => 'president',
                        'position_title' => 'President',
                    ],
                    [
                        'user_id' => $incomingSec->id,
                        'position_key' => 'secretary',
                        'position_title' => 'Secretary',
                    ],
                    [
                        'user_id' => $adviser->id,
                        'position_key' => 'adviser',
                        'position_title' => 'Faculty Adviser',
                    ],
                ],
                'evidence_file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('roster.status', OrganizationOfficerRoster::STATUS_SUBMITTED)
            ->assertJsonPath('roster.submitted_by', $secretary->id)
            ->assertJsonCount(3, 'roster.officers');

        $roster = OrganizationOfficerRoster::where('organization_id', $org->id)
            ->where('school_year_id', $schoolYear->id)
            ->firstOrFail();

        $this->assertSame(OrganizationOfficerRoster::STATUS_SUBMITTED, $roster->status);
        $this->assertSame($secretary->id, $roster->submitted_by);
        $this->assertNotNull($roster->submitted_at);
        $this->assertNotNull($roster->evidence_file_path);
        Storage::disk('local')->assertExists($roster->evidence_file_path);
    }

    public function test_outgoing_president_can_submit_valid_roster_with_attestation(): void
    {
        [
            $org,
            $schoolYear,
            $president,
            ,
            $incomingPres,
            $incomingSec,
            $adviser
        ] = $this->setupOrganizationWithOfficers();

        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $incomingPres->id,
                        'position_key' => 'president',
                    ],
                    [
                        'user_id' => $incomingSec->id,
                        'position_key' => 'secretary',
                    ],
                    [
                        'user_id' => $adviser->id,
                        'position_key' => 'adviser',
                    ],
                ],
                'attestation_text' => 'Official certified election results for the incoming academic term.',
            ])
            ->assertOk()
            ->assertJsonPath('roster.status', OrganizationOfficerRoster::STATUS_SUBMITTED)
            ->assertJsonPath('roster.submitted_by', $president->id);
    }

    public function test_student_officers_must_be_active_registered_members_of_organization(): void
    {
        [
            $org,
            $schoolYear,
            $president,
            ,
            ,
            $incomingSec,
            $adviser
        ] = $this->setupOrganizationWithOfficers();

        $nonMemberStudent = User::factory()->create([
            'email' => 's.nonmember@usm.edu.ph',
            'user_type' => 'student',
        ]);
        $nonMemberStudent->assignRole('Student');

        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $nonMemberStudent->id,
                        'position_key' => 'president',
                    ],
                    [
                        'user_id' => $incomingSec->id,
                        'position_key' => 'secretary',
                    ],
                    [
                        'user_id' => $adviser->id,
                        'position_key' => 'adviser',
                    ],
                ],
                'attestation_text' => 'Attestation.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('officers.0.user_id');
    }

    public function test_adviser_must_be_eligible_institutional_account_not_student(): void
    {
        [
            $org,
            $schoolYear,
            $president,
            ,
            $incomingPres,
            $incomingSec,
        ] = $this->setupOrganizationWithOfficers();

        $studentAsAdviser = User::factory()->create([
            'email' => 's.fakeadviser@usm.edu.ph',
            'user_type' => 'student',
        ]);
        $studentAsAdviser->assignRole('Student');

        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $incomingPres->id,
                        'position_key' => 'president',
                    ],
                    [
                        'user_id' => $incomingSec->id,
                        'position_key' => 'secretary',
                    ],
                    [
                        'user_id' => $studentAsAdviser->id,
                        'position_key' => 'adviser',
                    ],
                ],
                'attestation_text' => 'Attestation.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('officers.2.user_id');
    }

    public function test_submission_requires_president_secretary_and_adviser(): void
    {
        [
            $org,
            $schoolYear,
            $president,
            ,
            $incomingPres,
            ,
            $adviser
        ] = $this->setupOrganizationWithOfficers();

        // Missing Secretary
        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $incomingPres->id,
                        'position_key' => 'president',
                    ],
                    [
                        'user_id' => $adviser->id,
                        'position_key' => 'adviser',
                    ],
                ],
                'attestation_text' => 'Attestation.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_submission_requires_supporting_evidence_file_or_attestation(): void
    {
        [
            $org,
            $schoolYear,
            $president,
            ,
            $incomingPres,
            $incomingSec,
            $adviser
        ] = $this->setupOrganizationWithOfficers();

        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $incomingPres->id,
                        'position_key' => 'president',
                    ],
                    [
                        'user_id' => $incomingSec->id,
                        'position_key' => 'secretary',
                    ],
                    [
                        'user_id' => $adviser->id,
                        'position_key' => 'adviser',
                    ],
                ],
                // No attestation and no file
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('evidence');
    }

    public function test_unauthorized_user_cannot_prepare_roster(): void
    {
        [$org, $schoolYear] = $this->setupOrganizationWithOfficers();

        $unauthorizedStudent = User::factory()->create([
            'email' => 's.random@usm.edu.ph',
            'user_type' => 'student',
        ]);
        $unauthorizedStudent->assignRole('Student');

        $this->actingAs($unauthorizedStudent)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'attestation_text' => 'Unauthorized draft.',
            ])
            ->assertForbidden();
    }

    public function test_adviser_can_initiate_only_when_outgoing_officers_are_unavailable(): void
    {
        [
            $org,
            $schoolYear,
            ,
            ,
            $incomingPres,
            $incomingSec,
            $adviser
        ] = $this->setupOrganizationWithOfficers();

        // 1. While outgoing officers exist, Adviser cannot initiate
        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'attestation_text' => 'Adviser attempting early initiation.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preparer');

        // 2. Remove outgoing officers / assignments so officers are unavailable
        AdminAssignment::where('organization_id', $org->id)->delete();
        OrganizationOfficer::query()
            ->whereHas('roster', fn ($q) => $q->where('organization_id', $org->id))
            ->delete();

        // 3. Now Adviser can initiate and submit
        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    [
                        'user_id' => $incomingPres->id,
                        'position_key' => 'president',
                    ],
                    [
                        'user_id' => $incomingSec->id,
                        'position_key' => 'secretary',
                    ],
                    [
                        'user_id' => $adviser->id,
                        'position_key' => 'adviser',
                    ],
                ],
                'attestation_text' => 'Adviser initiation due to unavailable outgoing officers.',
            ])
            ->assertOk()
            ->assertJsonPath('roster.status', OrganizationOfficerRoster::STATUS_SUBMITTED)
            ->assertJsonPath('roster.submitted_by', $adviser->id);
    }

    public function test_roster_preparation_respects_school_year_lifecycle(): void
    {
        [$org, $activeYear, $president, , $incomingPres] = $this->setupOrganizationWithOfficers();

        // Closed School Year
        $closedYear = SchoolYear::factory()->create([
            'label' => '2022-2023',
            'status' => SchoolYear::STATUS_CLOSED,
        ]);

        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $closedYear->id,
            ]), [
                'officers' => [
                    ['user_id' => $incomingPres->id, 'position_key' => 'president'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_year');

        // Draft School Year without transition open
        $incomingYear = SchoolYear::factory()->create([
            'label' => '2025-2026',
            'status' => SchoolYear::STATUS_DRAFT,
        ]);

        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $incomingYear->id,
            ]), [
                'officers' => [
                    ['user_id' => $incomingPres->id, 'position_key' => 'president'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_year');

        // Open transition on active year
        $activeYear->update([
            'transition_started_at' => now(),
            'transition_ends_at' => now()->addDays(30),
        ]);

        // Now incoming draft year can be drafted during transition
        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $incomingYear->id,
            ]), [
                'officers' => [
                    ['user_id' => $incomingPres->id, 'position_key' => 'president'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('roster.status', OrganizationOfficerRoster::STATUS_DRAFT);
    }

    public function test_submitted_roster_cannot_be_re_edited_as_draft(): void
    {
        [
            $org,
            $schoolYear,
            $president,
            ,
            $incomingPres,
            $incomingSec,
            $adviser
        ] = $this->setupOrganizationWithOfficers();

        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.submit', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'officers' => [
                    ['user_id' => $incomingPres->id, 'position_key' => 'president'],
                    ['user_id' => $incomingSec->id, 'position_key' => 'secretary'],
                    ['user_id' => $adviser->id, 'position_key' => 'adviser'],
                ],
                'attestation_text' => 'Submitted officially.',
            ])
            ->assertOk();

        // Attempting to draft over the submitted roster
        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'attestation_text' => 'Trying to edit after submission.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roster');
    }

    public function test_eligible_members_endpoint_returns_only_active_registered_members(): void
    {
        [$org, , $president, , $incomingPres, $incomingSec] = $this->setupOrganizationWithOfficers();

        $response = $this->actingAs($president)
            ->getJson(route('dashboard.officer-rosters.eligible-members', ['organization' => $org->id]))
            ->assertOk();

        $memberIds = collect($response->json('members'))->pluck('id')->all();

        $this->assertContains($incomingPres->id, $memberIds);
        $this->assertContains($incomingSec->id, $memberIds);
    }

    public function test_eligible_advisers_endpoint_returns_institutional_faculty_accounts(): void
    {
        [$org, , $president, , , , $adviser] = $this->setupOrganizationWithOfficers();

        $response = $this->actingAs($president)
            ->getJson(route('dashboard.officer-rosters.eligible-advisers', ['organization' => $org->id]))
            ->assertOk();

        $adviserIds = collect($response->json('advisers'))->pluck('id')->all();

        $this->assertContains($adviser->id, $adviserIds);
    }

    public function test_show_endpoint_returns_roster_and_preparer_state(): void
    {
        [$org, $schoolYear, $president] = $this->setupOrganizationWithOfficers();

        $this->actingAs($president)
            ->getJson(route('dashboard.officer-rosters.show', ['organization' => $org->id]))
            ->assertOk()
            ->assertJsonPath('organization.id', $org->id)
            ->assertJsonPath('school_year.id', $schoolYear->id)
            ->assertJsonPath('can_prepare', true);
    }

    private function setupOrganizationWithOfficers(): array
    {
        $university = University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao']
        );

        $college = College::firstOrCreate(
            ['code' => 'CEIT'],
            [
                'name' => 'College of Engineering and Information Technology',
                'university_id' => $university->id,
            ]
        );

        $department = Department::firstOrCreate(
            ['code' => 'ICT'],
            [
                'name' => 'Department of Information and Communication Technology',
                'college_id' => $college->id,
                'status' => 'active',
            ]
        );

        $program = Program::firstOrCreate(
            ['code' => 'BSIS'],
            [
                'name' => 'BS Information Systems',
                'college_id' => $college->id,
                'department_id' => $department->id,
            ]
        );

        $org = Organization::firstOrCreate(
            ['acronym' => 'PSITS'],
            [
                'name' => 'Philippine Society of Information Technology Students',
                'type' => 'society',
                'college_id' => $college->id,
                'department_id' => $department->id,
                'status' => 'active',
            ]
        );

        $org->programs()->syncWithoutDetaching([$program->id]);

        $schoolYear = SchoolYear::firstOrCreate(
            ['label' => '2024-2025'],
            [
                'starts_on' => '2024-07-01',
                'ends_on' => '2025-06-30',
                'status' => SchoolYear::STATUS_ACTIVE,
                'activated_at' => now(),
            ]
        );

        // Outgoing President (student with Society Admin role and primary admin assignment)
        $president = User::factory()->create([
            'email' => 's.outgoing.president@usm.edu.ph',
            'user_type' => 'student',
            'organization_id' => $org->id,
        ]);
        $president->assignRole('Society Admin');

        AdminAssignment::create([
            'user_id' => $president->id,
            'role_name' => 'Society Admin',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'position_title' => 'President',
            'status' => AdminAssignment::STATUS_ACTIVE,
            'academic_year' => '2024-2025',
            'is_primary_admin' => true,
            'term_start' => now()->subMonths(6),
        ]);

        // Outgoing Secretary (student with Society Admin role)
        $secretary = User::factory()->create([
            'email' => 's.outgoing.secretary@usm.edu.ph',
            'user_type' => 'student',
            'organization_id' => $org->id,
        ]);
        $secretary->assignRole('Society Admin');

        AdminAssignment::create([
            'user_id' => $secretary->id,
            'role_name' => 'Society Admin',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'position_title' => 'Secretary',
            'status' => AdminAssignment::STATUS_ACTIVE,
            'academic_year' => '2024-2025',
            'is_primary_admin' => false,
            'term_start' => now()->subMonths(6),
        ]);

        // Incoming President (registered active member)
        $incomingPres = User::factory()->create([
            'email' => 's.incoming.pres@usm.edu.ph',
            'user_type' => 'student',
            'organization_id' => $org->id,
        ]);
        $incomingPres->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $incomingPres->id,
            'organization_id' => $org->id,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'school_year_id' => $schoolYear->id,
        ]);

        // Incoming Secretary (registered active member)
        $incomingSec = User::factory()->create([
            'email' => 's.incoming.sec@usm.edu.ph',
            'user_type' => 'student',
            'organization_id' => $org->id,
        ]);
        $incomingSec->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $incomingSec->id,
            'organization_id' => $org->id,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'school_year_id' => $schoolYear->id,
        ]);

        // Faculty Adviser
        $adviser = User::factory()->create([
            'email' => 'faculty.adviser@usm.edu.ph',
            'user_type' => 'faculty_or_staff',
        ]);
        $adviser->assignRole('Faculty');

        return [$org, $schoolYear, $president, $secretary, $incomingPres, $incomingSec, $adviser];
    }
}
