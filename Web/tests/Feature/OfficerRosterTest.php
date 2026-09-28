<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\OfficerChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Services\OfficerRosterService;
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

    public function test_osa_can_view_roster_directories_and_change_requests_across_organizations(): void
    {
        [$org, , , , , , $adviser] = $this->setupOrganizationWithOfficers();

        $osa = User::factory()->create([
            'email' => 'osa.roster.governance@usm.edu.ph',
            'user_type' => 'faculty_or_staff',
        ]);
        $osa->assignRole('Super Admin (OSA)');

        $this->actingAs($osa)
            ->getJson(route('dashboard.officer-rosters.show', ['organization' => $org->id]))
            ->assertOk()
            ->assertJsonPath('organization.id', $org->id);

        $this->actingAs($osa)
            ->getJson(route('dashboard.officer-rosters.eligible-members', ['organization' => $org->id]))
            ->assertOk();

        $this->actingAs($osa)
            ->getJson(route('dashboard.officer-rosters.eligible-advisers', ['organization' => $org->id]))
            ->assertOk()
            ->assertJsonFragment(['id' => $adviser->id]);

        $this->actingAs($osa)
            ->getJson(route('dashboard.officer-change-requests.index', ['organization' => $org->id]))
            ->assertOk()
            ->assertJsonPath('requests', []);
    }

    public function test_broad_dashboard_role_without_exact_organization_authority_cannot_view_roster_governance(): void
    {
        [$org] = $this->setupOrganizationWithOfficers();
        $otherOrganization = $this->createPeerOrganization('WRONG');

        $wrongOrganizationAdmin = User::factory()->create([
            'email' => 's.wrong.organization.admin@usm.edu.ph',
            'user_type' => 'student',
        ]);
        $wrongOrganizationAdmin->assignRole('Society Admin');

        AdminAssignment::create([
            'user_id' => $wrongOrganizationAdmin->id,
            'role_name' => 'Society Admin',
            'organization_id' => $otherOrganization->id,
            'college_id' => $otherOrganization->college_id,
            'department_id' => $otherOrganization->department_id,
            'position_title' => 'President',
            'status' => AdminAssignment::STATUS_ACTIVE,
            'academic_year' => '2024-2025',
            'is_primary_admin' => true,
            'term_start' => now()->subMonth(),
        ]);

        $this->actingAs($wrongOrganizationAdmin)
            ->getJson(route('dashboard.officer-rosters.show', ['organization' => $org->id]))
            ->assertForbidden();

        $this->actingAs($wrongOrganizationAdmin)
            ->getJson(route('dashboard.officer-rosters.eligible-members', ['organization' => $org->id]))
            ->assertForbidden();

        $this->actingAs($wrongOrganizationAdmin)
            ->getJson(route('dashboard.officer-rosters.eligible-advisers', ['organization' => $org->id]))
            ->assertForbidden();

        $this->actingAs($wrongOrganizationAdmin)
            ->getJson(route('dashboard.officer-change-requests.index', ['organization' => $org->id]))
            ->assertForbidden();
    }

    public function test_mismatched_nested_roster_routes_return_not_found_before_mutating(): void
    {
        [$org, , $roster, $adviser] = $this->setupSubmittedRoster();
        $otherOrganization = $this->createPeerOrganization('MISMATCH');

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $otherOrganization->id,
                'roster' => $roster->id,
            ]))
            ->assertNotFound();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.request-changes', [
                'organization' => $otherOrganization->id,
                'roster' => $roster->id,
            ]), [
                'remarks' => 'This route should be rejected before changing the real roster.',
            ])
            ->assertNotFound();

        $this->assertSame(OrganizationOfficerRoster::STATUS_SUBMITTED, $roster->fresh()->status);
        $this->assertSame($org->id, $roster->organization_id);
    }

    public function test_mismatched_nested_change_request_routes_return_not_found_before_mutating(): void
    {
        [
            $org,
            ,
            $roster,
            $adviser,
            $incomingPres,
            ,
            ,
            $presOfficer,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertOk();

        $changeRequest = OfficerChangeRequest::create([
            'organization_id' => $org->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $presOfficer->id,
            'request_type' => OfficerChangeRequest::TYPE_RESIGNATION,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Pending resignation.',
            'requested_by' => $incomingPres->id,
            'requested_at' => now(),
        ]);

        $otherOrganization = $this->createPeerOrganization('CHANGE');

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.approve', [
                'organization' => $otherOrganization->id,
                'changeRequest' => $changeRequest->id,
            ]))
            ->assertNotFound();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.reject', [
                'organization' => $otherOrganization->id,
                'changeRequest' => $changeRequest->id,
            ]), [
                'remarks' => 'Rejecting through a mismatched parent should fail.',
            ])
            ->assertNotFound();

        $this->assertSame(OfficerChangeRequest::STATUS_PENDING, $changeRequest->fresh()->status);
        $this->assertSame($org->id, $changeRequest->organization_id);
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

    private function createPeerOrganization(string $acronym): Organization
    {
        $baseOrganization = Organization::where('acronym', 'PSITS')->firstOrFail();

        return Organization::create([
            'name' => "{$acronym} Society",
            'acronym' => $acronym,
            'type' => 'society',
            'college_id' => $baseOrganization->college_id,
            'department_id' => $baseOrganization->department_id,
            'status' => 'active',
        ]);
    }

    protected function setupSubmittedRoster(): array
    {
        [$org, $schoolYear, $president, $secretary, $incomingPres, $incomingSec, $adviser] = $this->setupOrganizationWithOfficers();

        $roster = OrganizationOfficerRoster::create([
            'organization_id' => $org->id,
            'school_year_id' => $schoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_SUBMITTED,
            'attestation_text' => 'Official roster for SY 2024-2025 submitted by outgoing president.',
            'submitted_by' => $president->id,
            'submitted_at' => now(),
            'created_by' => $president->id,
        ]);

        $presOfficer = $roster->officers()->create([
            'user_id' => $incomingPres->id,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => now()->toDateString(),
        ]);

        $secOfficer = $roster->officers()->create([
            'user_id' => $incomingSec->id,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => now()->toDateString(),
        ]);

        $advOfficer = $roster->officers()->create([
            'user_id' => $adviser->id,
            'position_key' => OrganizationOfficer::POSITION_ADVISER,
            'position_title' => 'Faculty Adviser',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => now()->toDateString(),
        ]);

        return [$org, $schoolYear, $roster, $adviser, $incomingPres, $incomingSec, $president, $presOfficer, $secOfficer, $advOfficer];
    }

    public function test_adviser_can_verify_submitted_officer_roster_with_remarks(): void
    {
        [
            $org,
            $schoolYear,
            $roster,
            $adviser,
            $incomingPres,
            ,
            ,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]), [
                'remarks' => 'Verified after reviewing election minutes and credentials.',
            ])
            ->assertOk()
            ->assertJsonPath('roster.status', OrganizationOfficerRoster::STATUS_VERIFIED)
            ->assertJsonPath('roster.verified_by', $adviser->id)
            ->assertJsonPath('roster.verification_remarks', 'Verified after reviewing election minutes and credentials.');

        $roster->refresh();
        $this->assertSame(OrganizationOfficerRoster::STATUS_VERIFIED, $roster->status);
        $this->assertSame($adviser->id, $roster->verified_by);
        $this->assertNotNull($roster->verified_at);

        // Verification activates official governance: incoming president gets primary admin assignment projection
        $assignment = AdminAssignment::where('organization_id', $org->id)
            ->where('user_id', $incomingPres->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->is_primary_admin);
        $this->assertSame('Society Admin', $assignment->role_name);
        $this->assertTrue($incomingPres->fresh()->hasRole('Society Admin'));

        // Incoming president is now recognized as active verified officer
        $this->assertTrue(app(OfficerRosterService::class)->isOutgoingPresidentOrSecretary($incomingPres, $org));

        // AuditLog recorded
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $roster->id,
            'admin_id' => $adviser->id,
            'action' => 'verify-officer-roster',
        ]);
    }

    public function test_adviser_can_request_changes_on_submitted_roster_with_remarks(): void
    {
        [
            $org,
            $schoolYear,
            $roster,
            $adviser,
            $incomingPres,
            ,
            $president,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.request-changes', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]), [
                'remarks' => 'Please upload the signed certification letter from the student affairs coordinator.',
            ])
            ->assertOk()
            ->assertJsonPath('roster.status', OrganizationOfficerRoster::STATUS_CHANGES_REQUESTED)
            ->assertJsonPath('roster.rejection_remarks', 'Please upload the signed certification letter from the student affairs coordinator.');

        $roster->refresh();
        $this->assertSame(OrganizationOfficerRoster::STATUS_CHANGES_REQUESTED, $roster->status);
        $this->assertTrue($roster->isEditable());

        // Preparer can now update the draft and resubmit
        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.draft', [
                'organization' => $org->id,
                'schoolYear' => $schoolYear->id,
            ]), [
                'attestation_text' => 'Updated attestation with coordinator certification.',
            ])
            ->assertOk()
            ->assertJsonPath('roster.attestation_text', 'Updated attestation with coordinator certification.');

        // AuditLog recorded
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $roster->id,
            'admin_id' => $adviser->id,
            'action' => 'request-changes-officer-roster',
        ]);
    }

    public function test_non_adviser_cannot_verify_or_request_changes_on_roster(): void
    {
        [
            $org,
            ,
            $roster,
            ,
            $incomingPres,
            ,
            $president,
        ] = $this->setupSubmittedRoster();

        // Outgoing president (student) cannot verify
        $this->actingAs($president)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]), [
                'remarks' => 'Self verification attempt.',
            ])
            ->assertForbidden();

        // Incoming president (student) cannot request changes
        $this->actingAs($incomingPres)
            ->postJson(route('dashboard.officer-rosters.request-changes', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]), [
                'remarks' => 'Unauthorized request changes.',
            ])
            ->assertForbidden();

        // Other faculty not affiliated cannot verify
        $otherFaculty = User::factory()->create([
            'email' => 'other.faculty@usm.edu.ph',
            'user_type' => 'faculty_or_staff',
        ]);
        $otherFaculty->assignRole('Faculty');

        $this->actingAs($otherFaculty)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertForbidden();

        $roster->refresh();
        $this->assertSame(OrganizationOfficerRoster::STATUS_SUBMITTED, $roster->status);
    }

    public function test_unverified_roster_activation_is_rejected(): void
    {
        [$org, $schoolYear, $president, , $incomingPres] = $this->setupOrganizationWithOfficers();

        // 1. Create a draft roster
        $draftRoster = OrganizationOfficerRoster::create([
            'organization_id' => $org->id,
            'school_year_id' => $schoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_DRAFT,
            'created_by' => $president->id,
        ]);

        $draftRoster->officers()->create([
            'user_id' => $incomingPres->id,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'status' => OrganizationOfficer::STATUS_ACTIVE,
        ]);

        $adviser = User::factory()->create([
            'email' => 'adviser.unverified@usm.edu.ph',
            'user_type' => 'faculty_or_staff',
        ]);
        $adviser->assignRole('Faculty');

        $draftRoster->officers()->create([
            'user_id' => $adviser->id,
            'position_key' => OrganizationOfficer::POSITION_ADVISER,
            'status' => OrganizationOfficer::STATUS_ACTIVE,
        ]);

        // Attempting to verify a draft roster directly is rejected
        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $draftRoster->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roster']);

        // 2. Incoming president on unverified roster cannot act as verified officer
        $this->assertFalse(app(OfficerRosterService::class)->isOutgoingPresidentOrSecretary($incomingPres, $org));

        // 3. Attempting to create an officer change request without a verified roster is rejected
        $this->actingAs($president)
            ->postJson(route('dashboard.officer-change-requests.store', [
                'organization' => $org->id,
            ]), [
                'old_officer_id' => $draftRoster->officers()->first()->id,
                'request_type' => 'replacement',
                'new_user_id' => $incomingPres->id,
                'reason' => 'Change request on unverified roster.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roster']);
    }

    public function test_verified_officer_can_submit_officer_change_request_for_replacement(): void
    {
        [
            $org,
            $schoolYear,
            $roster,
            $adviser,
            $incomingPres,
            ,
            ,
            $presOfficer,
        ] = $this->setupSubmittedRoster();

        // Adviser verifies roster first
        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertOk();

        // Create replacement student member
        $replacementStudent = User::factory()->create([
            'email' => 's.replacement.pres@usm.edu.ph',
            'user_type' => 'student',
            'organization_id' => $org->id,
        ]);
        $replacementStudent->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $replacementStudent->id,
            'organization_id' => $org->id,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'school_year_id' => $schoolYear->id,
        ]);

        $file = UploadedFile::fake()->create('endorsement.pdf', 100, 'application/pdf');

        $this->actingAs($incomingPres)
            ->post(route('dashboard.officer-change-requests.store', [
                'organization' => $org->id,
            ]), [
                'old_officer_id' => $presOfficer->id,
                'request_type' => 'replacement',
                'new_user_id' => $replacementStudent->id,
                'reason' => 'Officer transferring to another campus, special election completed.',
                'evidence_file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('request.status', OfficerChangeRequest::STATUS_PENDING)
            ->assertJsonPath('request.request_type', 'replacement')
            ->assertJsonPath('request.new_user_id', $replacementStudent->id);

        $changeRequest = OfficerChangeRequest::where('organization_id', $org->id)->firstOrFail();
        $this->assertSame(OfficerChangeRequest::STATUS_PENDING, $changeRequest->status);
        $this->assertSame($presOfficer->id, $changeRequest->old_officer_id);
        $this->assertSame($replacementStudent->id, $changeRequest->new_user_id);
        $this->assertNotNull($changeRequest->evidence_file_path);
        Storage::disk('local')->assertExists($changeRequest->evidence_file_path);

        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $changeRequest->id,
            'admin_id' => $incomingPres->id,
            'action' => 'create-officer-change-request',
        ]);
    }

    public function test_officer_change_request_rejects_non_member_student_replacement(): void
    {
        [
            $org,
            ,
            $roster,
            $adviser,
            $incomingPres,
            ,
            ,
            $presOfficer,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertOk();

        // Student from another org (not a member of $org)
        $nonMember = User::factory()->create([
            'email' => 's.outsider@usm.edu.ph',
            'user_type' => 'student',
        ]);
        $nonMember->assignRole('Student');

        $this->actingAs($incomingPres)
            ->postJson(route('dashboard.officer-change-requests.store', [
                'organization' => $org->id,
            ]), [
                'old_officer_id' => $presOfficer->id,
                'request_type' => 'replacement',
                'new_user_id' => $nonMember->id,
                'reason' => 'Attempting to appoint external non-member.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_user_id']);
    }

    public function test_adviser_can_approve_replacement_change_request_preserving_history(): void
    {
        [
            $org,
            $schoolYear,
            $roster,
            $adviser,
            $incomingPres,
            ,
            ,
            $presOfficer,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertOk();

        $replacementStudent = User::factory()->create([
            'email' => 's.approved.replacement@usm.edu.ph',
            'user_type' => 'student',
            'organization_id' => $org->id,
        ]);
        $replacementStudent->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $replacementStudent->id,
            'organization_id' => $org->id,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'school_year_id' => $schoolYear->id,
        ]);

        $changeRequest = OfficerChangeRequest::create([
            'organization_id' => $org->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $presOfficer->id,
            'new_user_id' => $replacementStudent->id,
            'request_type' => OfficerChangeRequest::TYPE_REPLACEMENT,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Officer resignation and succession election.',
            'requested_by' => $incomingPres->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.approve', [
                'organization' => $org->id,
                'changeRequest' => $changeRequest->id,
            ]), [
                'remarks' => 'Replacement approved by adviser.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', OfficerChangeRequest::STATUS_APPROVED)
            ->assertJsonPath('request.decision_remarks', 'Replacement approved by adviser.');

        $changeRequest->refresh();
        $this->assertSame(OfficerChangeRequest::STATUS_APPROVED, $changeRequest->status);
        $this->assertSame($adviser->id, $changeRequest->decided_by);

        // Historical preservation check:
        // 1. Old officer record MUST STILL EXIST in the database (not deleted)
        $presOfficer->refresh();
        $this->assertSame(OrganizationOfficer::STATUS_REPLACED, $presOfficer->status);
        $this->assertNotNull($presOfficer->effective_end);
        $this->assertNotNull($presOfficer->replacement_officer_id);

        // 2. New officer record created
        $newOfficer = OrganizationOfficer::find($changeRequest->created_officer_id);
        $this->assertNotNull($newOfficer);
        $this->assertSame($replacementStudent->id, $newOfficer->user_id);
        $this->assertSame(OrganizationOfficer::STATUS_ACTIVE, $newOfficer->status);
        $this->assertSame($presOfficer->replacement_officer_id, $newOfficer->id);

        // 3. New president receives primary admin assignment projection
        $assignment = AdminAssignment::where('organization_id', $org->id)
            ->where('user_id', $replacementStudent->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->is_primary_admin);

        // 4. AuditLog recorded
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $changeRequest->id,
            'admin_id' => $adviser->id,
            'action' => 'approve-officer-change-request',
        ]);
    }

    public function test_adviser_can_approve_resignation_and_revocation_preserving_history(): void
    {
        [
            $org,
            ,
            $roster,
            $adviser,
            ,
            $incomingSec,
            ,
            ,
            $secOfficer,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertOk();

        // 1. Resignation without immediate replacement -> prior officer marked expired
        $resignationRequest = OfficerChangeRequest::create([
            'organization_id' => $org->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $secOfficer->id,
            'new_user_id' => null,
            'request_type' => OfficerChangeRequest::TYPE_RESIGNATION,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Voluntary resignation due to personal circumstances.',
            'requested_by' => $incomingSec->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.approve', [
                'organization' => $org->id,
                'changeRequest' => $resignationRequest->id,
            ]), [
                'remarks' => 'Resignation accepted.',
            ])
            ->assertOk();

        $secOfficer->refresh();
        $this->assertSame(OrganizationOfficer::STATUS_EXPIRED, $secOfficer->status);
        $this->assertNotNull($secOfficer->effective_end);

        // 2. Revocation -> prior officer marked revoked
        $revocationRequest = OfficerChangeRequest::create([
            'organization_id' => $org->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $secOfficer->id,
            'new_user_id' => null,
            'request_type' => OfficerChangeRequest::TYPE_REVOCATION,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Revocation of privileges.',
            'requested_by' => $adviser->id,
            'requested_at' => now(),
        ]);

        // Reactivate temporarily to test revocation
        $secOfficer->update(['status' => OrganizationOfficer::STATUS_ACTIVE]);

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.approve', [
                'organization' => $org->id,
                'changeRequest' => $revocationRequest->id,
            ]), [
                'remarks' => 'Revocation confirmed.',
            ])
            ->assertOk();

        $secOfficer->refresh();
        $this->assertSame(OrganizationOfficer::STATUS_REVOKED, $secOfficer->status);
        $this->assertNotNull($secOfficer->effective_end);
    }

    public function test_adviser_can_reject_change_request_with_remarks(): void
    {
        [
            $org,
            ,
            $roster,
            $adviser,
            $incomingPres,
            ,
            ,
            $presOfficer,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertOk();

        $changeRequest = OfficerChangeRequest::create([
            'organization_id' => $org->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $presOfficer->id,
            'request_type' => OfficerChangeRequest::TYPE_REVOCATION,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Disputed revocation request.',
            'requested_by' => $incomingPres->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.reject', [
                'organization' => $org->id,
                'changeRequest' => $changeRequest->id,
            ]), [
                'remarks' => 'Disapproved: dispute unresolved and lacks due process.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', OfficerChangeRequest::STATUS_REJECTED)
            ->assertJsonPath('request.decision_remarks', 'Disapproved: dispute unresolved and lacks due process.');

        $changeRequest->refresh();
        $this->assertSame(OfficerChangeRequest::STATUS_REJECTED, $changeRequest->status);

        // Old officer status remains unchanged (active)
        $presOfficer->refresh();
        $this->assertSame(OrganizationOfficer::STATUS_ACTIVE, $presOfficer->status);

        // AuditLog recorded
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $changeRequest->id,
            'admin_id' => $adviser->id,
            'action' => 'reject-officer-change-request',
        ]);
    }

    public function test_unauthorized_user_cannot_approve_or_reject_change_request(): void
    {
        [
            $org,
            ,
            $roster,
            $adviser,
            $incomingPres,
            ,
            ,
            $presOfficer,
        ] = $this->setupSubmittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $org->id,
                'roster' => $roster->id,
            ]))
            ->assertOk();

        $changeRequest = OfficerChangeRequest::create([
            'organization_id' => $org->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $presOfficer->id,
            'request_type' => OfficerChangeRequest::TYPE_RESIGNATION,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Pending resignation.',
            'requested_by' => $incomingPres->id,
            'requested_at' => now(),
        ]);

        // Student president cannot self-approve
        $this->actingAs($incomingPres)
            ->postJson(route('dashboard.officer-change-requests.approve', [
                'organization' => $org->id,
                'changeRequest' => $changeRequest->id,
            ]))
            ->assertForbidden();

        // Student president cannot reject
        $this->actingAs($incomingPres)
            ->postJson(route('dashboard.officer-change-requests.reject', [
                'organization' => $org->id,
                'changeRequest' => $changeRequest->id,
            ]), [
                'remarks' => 'Rejecting.',
            ])
            ->assertForbidden();
    }
}
