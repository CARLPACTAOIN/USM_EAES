<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Notifications\EventWorkflowNotification;
use App\Services\ProposalWorkflowService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalWorkflowAssignmentResolutionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $schoolYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $this->schoolYear = SchoolYear::create([
            'label' => '2026-2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-03-31',
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
    }

    public function test_society_workflow_tasks_resolve_real_approvers_and_snapshot_scope_context(): void
    {
        $context = $this->createGovernedOrganization('society', 'Computer Science Society', withDepartment: true);
        $this->createTemplateForOrganizationType('society', [
            ['proponent_review', 'Proponent Review', 'proponent', 'organization'],
            ['organization_head_endorsement', 'Organization Head Endorsement', 'organization_head', 'organization'],
            ['adviser_endorsement', 'Adviser Endorsement', 'adviser', 'organization'],
            ['dept_chair_endorsement', 'Department Chair Endorsement', 'dept_chair', 'department'],
            ['college_dean_endorsement', 'College Dean Endorsement', 'college_dean', 'college'],
            ['security_clearance', 'Security Clearance', 'chief_security', 'university'],
            ['osa_review', 'OSA Review', 'osa', 'university'],
        ]);

        $event = $this->createEvent($context);
        $run = app(ProposalWorkflowService::class)->bindFirstSubmission($event, $context['secretary']);

        $tasks = $run->tasks()->get()->keyBy('step_key');

        $this->assertSame($context['secretary']->id, $tasks['proponent_review']->assigned_user_id);
        $this->assertSame($context['president']->id, $tasks['organization_head_endorsement']->assigned_user_id);
        $this->assertSame($context['adviser']->id, $tasks['adviser_endorsement']->assigned_user_id);
        $this->assertSame($context['chair']->id, $tasks['dept_chair_endorsement']->assigned_user_id);
        $this->assertSame($context['dean']->id, $tasks['college_dean_endorsement']->assigned_user_id);
        $this->assertSame($context['security']->id, $tasks['security_clearance']->assigned_user_id);
        $this->assertSame($context['osa']->id, $tasks['osa_review']->assigned_user_id);

        $chairSnapshot = $tasks['dept_chair_endorsement']->metadata['assignment'];
        $this->assertSame('dept_chair', $chairSnapshot['role_key']);
        $this->assertSame($context['department']->id, $chairSnapshot['scope']['department_id']);
        $this->assertSame('Department of Computer Science', $chairSnapshot['scope']['department_name']);
        $this->assertSame($context['chair']->id, $chairSnapshot['assigned_user_snapshot']['id']);
        $this->assertSame('Dr. Chairperson', $chairSnapshot['assigned_user_snapshot']['name']);

        $context['chair']->update(['name' => 'Renamed Chair']);
        $context['department']->update(['name' => 'Renamed Department']);

        $freshTask = $tasks['dept_chair_endorsement']->fresh();
        $this->assertSame($context['chair']->id, $freshTask->assigned_user_id);
        $this->assertSame('Dr. Chairperson', $freshTask->metadata['assignment']['assigned_user_snapshot']['name']);
        $this->assertSame('Department of Computer Science', $freshTask->metadata['assignment']['scope']['department_name']);
    }

    public function test_participant_workflow_tasks_resolve_from_participant_governance_context(): void
    {
        $organizer = $this->createGovernedOrganization('society', 'Organizer Society', withDepartment: true);
        $participant = $this->createGovernedOrganization('society', 'Participant Society', withDepartment: true);
        $participantSchoolYear = SchoolYear::create([
            'label' => '2027-2028',
            'starts_on' => '2027-06-01',
            'ends_on' => '2028-03-31',
            'status' => SchoolYear::STATUS_DRAFT,
        ]);
        $this->createVerifiedRoster($participant, $participantSchoolYear);
        $this->adminAssignment(
            $participant['chair'],
            'Department Chairperson',
            'Chairperson, Participant Department of Computer Science',
            college: $participant['college'],
            department: $participant['department'],
            schoolYear: $participantSchoolYear,
        );
        $this->adminAssignment(
            $participant['dean'],
            'College Dean',
            'Dean, Participant College of Science and Mathematics',
            college: $participant['college'],
            schoolYear: $participantSchoolYear,
        );
        $this->createTemplateForOrganizationType('society', [
            ['participant_proponent', 'Participant Proponent', 'proponent', 'organization'],
            ['participant_head', 'Participant Organization Head', 'organization_head', 'organization'],
            ['participant_adviser', 'Participant Adviser', 'adviser', 'organization'],
            ['participant_chair', 'Participant Department Chair', 'dept_chair', 'department'],
            ['participant_dean', 'Participant College Dean', 'college_dean', 'college'],
        ], EventParticipationScope::ROLE_PARTICIPANT);

        $event = $this->createEvent($organizer);
        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'school_year_id' => $participantSchoolYear->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participant['organization']->id,
            'college_id' => $participant['college']->id,
            'department_id' => $participant['department']->id,
            'participant_name_snapshot' => $participant['organization']->name,
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        $run = app(ProposalWorkflowService::class)->bindParticipantSubmission($scope, $participant['secretary']);
        $tasks = $run->tasks()->get()->keyBy('step_key');

        $this->assertSame($participant['secretary']->id, $tasks['participant_proponent']->assigned_user_id);
        $this->assertSame($participant['president']->id, $tasks['participant_head']->assigned_user_id);
        $this->assertSame($participant['adviser']->id, $tasks['participant_adviser']->assigned_user_id);
        $this->assertSame($participant['chair']->id, $tasks['participant_chair']->assigned_user_id);
        $this->assertSame($participant['dean']->id, $tasks['participant_dean']->assigned_user_id);

        $participantScope = $tasks['participant_chair']->metadata['assignment']['scope'];
        $this->assertSame($participant['organization']->id, $participantScope['organization_id']);
        $this->assertSame($participant['department']->id, $participantScope['department_id']);
        $this->assertSame($participant['college']->id, $participantScope['college_id']);
        $this->assertSame($participantSchoolYear->id, $participantScope['school_year_id']);
        $this->assertNotSame($event->school_year_id, $participantScope['school_year_id']);
        $this->assertNotSame($organizer['organization']->id, $participantScope['organization_id']);
        $this->assertNotSame($organizer['department']->id, $participantScope['department_id']);
    }

    public function test_lsg_usg_and_non_academic_events_resolve_their_configured_institutional_actors(): void
    {
        $cases = [
            'lsg' => [
                'name' => 'College Student Council',
                'with_department' => false,
                'steps' => [
                    ['lsg_proponent', 'LSG Proponent', 'proponent', 'organization', 'secretary'],
                    ['lsg_head', 'LSG Head', 'organization_head', 'organization', 'president'],
                    ['lsg_dean', 'College Dean', 'college_dean', 'college', 'dean'],
                    ['lsg_osa', 'OSA Review', 'osa', 'university', 'osa'],
                ],
            ],
            'usg' => [
                'name' => 'University Student Government',
                'with_department' => false,
                'steps' => [
                    ['usg_proponent', 'USG Proponent', 'proponent', 'organization', 'secretary'],
                    ['usg_head', 'USG Head', 'organization_head', 'organization', 'president'],
                    ['usg_osa', 'OSA Review', 'osa', 'university', 'osa'],
                ],
            ],
            'non_academic_org' => [
                'name' => 'Culture and Arts Guild',
                'with_department' => false,
                'steps' => [
                    ['office_proponent', 'Office Proponent', 'proponent', 'organization', 'secretary'],
                    ['office_security', 'Security Clearance', 'chief_security', 'university', 'security'],
                    ['office_osa', 'OSA Review', 'osa', 'university', 'osa'],
                ],
            ],
        ];

        foreach ($cases as $organizationType => $case) {
            $context = $this->createGovernedOrganization($organizationType, $case['name'], $case['with_department']);
            $this->createTemplateForOrganizationType($organizationType, array_map(
                fn (array $step): array => array_slice($step, 0, 4),
                $case['steps'],
            ));

            $event = $this->createEvent($context);
            $run = app(ProposalWorkflowService::class)->bindFirstSubmission($event, $context['secretary']);
            $tasks = $run->tasks()->get()->keyBy('step_key');

            foreach ($case['steps'] as [$stepKey, , , , $expectedContextKey]) {
                if ($expectedContextKey === 'osa') {
                    $this->assertTrue($tasks[$stepKey]->assignedUser->hasRole('Super Admin (OSA)'), "{$organizationType} {$stepKey} did not resolve to OSA.");

                    continue;
                }

                if ($expectedContextKey === 'security') {
                    $this->assertTrue($tasks[$stepKey]->assignedUser->hasRole('Chief Security'), "{$organizationType} {$stepKey} did not resolve to Chief Security.");

                    continue;
                }

                $this->assertSame($context[$expectedContextKey]->id, $tasks[$stepKey]->assigned_user_id, "{$organizationType} {$stepKey} resolved incorrectly.");
            }
        }
    }

    public function test_chief_security_and_osa_resolve_to_distinct_accounts_with_distinct_permissions(): void
    {
        $context = $this->createGovernedOrganization('society', 'Security Distinction Society', withDepartment: false);
        $this->createTemplateForOrganizationType('society', [
            ['security_clearance', 'Security Clearance', 'chief_security', 'university'],
            ['osa_review', 'OSA Review', 'osa', 'university'],
        ]);

        $event = $this->createEvent($context);
        $run = app(ProposalWorkflowService::class)->bindFirstSubmission($event, $context['secretary']);
        $tasks = $run->tasks()->get()->keyBy('step_key');

        $this->assertNotSame($tasks['security_clearance']->assigned_user_id, $tasks['osa_review']->assigned_user_id);
        $this->assertSame('chief_security', $tasks['security_clearance']->assigned_role);
        $this->assertSame('osa', $tasks['osa_review']->assigned_role);
        $this->assertTrue($context['security']->can('approve-security-clearance'));
        $this->assertFalse($context['security']->can('approve-proposals'));
        $this->assertTrue($context['osa']->can('approve-proposals'));
        $this->assertFalse($context['osa']->can('approve-security-clearance'));
    }

    public function test_missing_required_approver_halts_workflow_creation_and_alerts_osa(): void
    {
        $context = $this->createGovernedOrganization('society', 'Unrouted Society', withDepartment: false, includeSecurity: false);
        $this->createTemplateForOrganizationType('society', [
            ['security_clearance', 'Security Clearance', 'chief_security', 'university'],
        ]);

        $event = $this->createEvent($context);

        try {
            app(ProposalWorkflowService::class)->bindFirstSubmission($event, $context['secretary']);
            $this->fail('Expected workflow assignment resolution to fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Chief Security', $exception->getMessage());
        }

        $this->assertSame(0, ProposalWorkflowRun::where('event_id', $event->id)->count());
        $this->assertSame(0, ProposalWorkflowTask::count());

        $notification = $context['osa']->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('proposal_workflow_assignment_failed', $notification->data['kind']);
        $this->assertSame($event->id, $notification->data['event_id']);
        $this->assertSame('chief_security', $notification->data['role_key']);
        $this->assertTrue($notification->data['needs_action']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createGovernedOrganization(string $type, string $name, bool $withDepartment, bool $includeSecurity = true): array
    {
        $college = College::create([
            'university_id' => University::query()->value('id'),
            'name' => 'College of Science and Mathematics',
            'code' => 'CSM'.mb_strtoupper(str()->random(5)),
        ]);

        $department = $withDepartment
            ? Department::create([
                'college_id' => $college->id,
                'name' => 'Department of Computer Science',
                'code' => 'DCS',
                'status' => 'active',
            ])
            : null;

        $organization = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department?->id,
            'name' => $name,
            'acronym' => str($name)->headline()->explode(' ')->map(fn (string $word): string => mb_substr($word, 0, 1))->join(''),
            'type' => $type,
            'status' => 'active',
        ]);

        $president = User::factory()->create(['name' => "{$name} President", 'college_id' => $college->id, 'department_id' => $department?->id]);
        $secretary = User::factory()->create(['name' => "{$name} Secretary", 'college_id' => $college->id, 'department_id' => $department?->id]);
        $adviser = User::factory()->create(['name' => "{$name} Adviser", 'college_id' => $college->id, 'department_id' => $department?->id, 'user_type' => 'faculty']);
        $chair = User::factory()->create(['name' => 'Dr. Chairperson', 'college_id' => $college->id, 'department_id' => $department?->id, 'user_type' => 'faculty']);
        $dean = User::factory()->create(['name' => 'Dr. Dean', 'college_id' => $college->id, 'user_type' => 'faculty']);
        $osa = User::factory()->create(['name' => 'OSA Approver', 'email' => uniqid('osa.').'@usm.edu.ph']);
        $security = $includeSecurity ? User::factory()->create(['name' => 'Chief Security', 'email' => uniqid('security.').'@usm.edu.ph']) : null;

        $president->assignRole('Society Admin');
        $secretary->assignRole('Society Admin');
        $adviser->assignRole('Faculty');
        $osa->assignRole('Super Admin (OSA)');
        $security?->assignRole('Chief Security');

        $roster = OrganizationOfficerRoster::create([
            'organization_id' => $organization->id,
            'school_year_id' => $this->schoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
            'submitted_by' => $president->id,
            'submitted_at' => now()->subDays(4),
            'verified_by' => $adviser->id,
            'verified_at' => now()->subDays(3),
        ]);

        foreach ([
            [$president, OrganizationOfficer::POSITION_PRESIDENT, 'President'],
            [$secretary, OrganizationOfficer::POSITION_SECRETARY, 'Secretary'],
            [$adviser, OrganizationOfficer::POSITION_ADVISER, 'Faculty Adviser'],
        ] as [$user, $positionKey, $positionTitle]) {
            OrganizationOfficer::create([
                'officer_roster_id' => $roster->id,
                'user_id' => $user->id,
                'position_key' => $positionKey,
                'position_title' => $positionTitle,
                'status' => OrganizationOfficer::STATUS_ACTIVE,
                'effective_start' => $this->schoolYear->starts_on,
            ]);
        }

        if ($department) {
            $this->adminAssignment($chair, 'Department Chairperson', 'Chairperson, Department of Computer Science', college: $college, department: $department);
        }

        $this->adminAssignment($dean, 'College Dean', 'Dean, College of Science and Mathematics', college: $college);
        $this->adminAssignment($osa, 'Super Admin (OSA)', 'OSA Workflow Approver');

        if ($security) {
            $this->adminAssignment($security, 'Chief Security', 'Chief Security Officer');
        }

        return compact('college', 'department', 'organization', 'president', 'secretary', 'adviser', 'chair', 'dean', 'osa', 'security');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createVerifiedRoster(array $context, SchoolYear $schoolYear): OrganizationOfficerRoster
    {
        $roster = OrganizationOfficerRoster::create([
            'organization_id' => $context['organization']->id,
            'school_year_id' => $schoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
            'submitted_by' => $context['president']->id,
            'submitted_at' => now()->subDays(2),
            'verified_by' => $context['adviser']->id,
            'verified_at' => now()->subDay(),
        ]);

        foreach ([
            [$context['president'], OrganizationOfficer::POSITION_PRESIDENT, 'President'],
            [$context['secretary'], OrganizationOfficer::POSITION_SECRETARY, 'Secretary'],
            [$context['adviser'], OrganizationOfficer::POSITION_ADVISER, 'Faculty Adviser'],
        ] as [$user, $positionKey, $positionTitle]) {
            OrganizationOfficer::create([
                'officer_roster_id' => $roster->id,
                'user_id' => $user->id,
                'position_key' => $positionKey,
                'position_title' => $positionTitle,
                'status' => OrganizationOfficer::STATUS_ACTIVE,
                'effective_start' => $schoolYear->starts_on,
            ]);
        }

        return $roster;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $steps
     */
    private function createTemplateForOrganizationType(
        string $organizationType,
        array $steps,
        ?string $organizerRole = null,
    ): ProposalProcessTemplateVersion {
        $template = ProposalProcessTemplate::create([
            'name' => 'Workflow for '.$organizationType.' '.uniqid(),
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);

        $version = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);

        foreach ($steps as $index => [$stepKey, $title, $role, $scope]) {
            ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => $stepKey,
                'title' => $title,
                'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                'phase' => 'approval',
                'display_order' => $index + 1,
                'is_required' => true,
                'condition_config' => [
                    'assigned_role' => $role,
                    'assigned_scope' => $scope,
                ],
            ]);
        }

        ProposalTemplateApplicabilityRule::create([
            'template_version_id' => $version->id,
            'priority' => 100,
            'organization_type' => $organizationType,
            'organizer_role' => $organizerRole,
            'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
        ]);

        $version->update([
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return $version->fresh();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createEvent(array $context): Event
    {
        return Event::create([
            'organization_id' => $context['organization']->id,
            'school_year_id' => $this->schoolYear->id,
            'department_id' => $context['department']?->id,
            'title' => $context['organization']->name.' Proposal',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_SUBMITTED,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth(),
            'location_type' => 'on-campus',
            'college_name_snapshot' => $context['college']->name,
            'department_name_snapshot' => $context['department']?->name,
            'organization_name_snapshot' => $context['organization']->name,
            'proponent_name_snapshot' => $context['secretary']->name,
            'proponent_user_id' => $context['secretary']->id,
            'org_head_user_id' => $context['president']->id,
            'dept_chair_user_id' => $context['department'] ? $context['chair']->id : null,
            'college_dean_user_id' => $context['dean']->id,
            'governance_snapshots' => [
                'metadata' => [
                    'school_year_label' => $this->schoolYear->label,
                    'college_code' => $context['college']->code,
                    'department_code' => $context['department']?->code,
                    'organization_acronym' => $context['organization']->acronym,
                    'proponent_email' => $context['secretary']->email,
                    'org_head_email' => $context['president']->email,
                    'dept_chair_email' => $context['department'] ? $context['chair']->email : null,
                    'college_dean_email' => $context['dean']->email,
                ],
            ],
        ]);
    }

    private function adminAssignment(
        User $user,
        string $roleName,
        string $positionTitle,
        ?College $college = null,
        ?Department $department = null,
        ?SchoolYear $schoolYear = null,
    ): AdminAssignment {
        $schoolYear ??= $this->schoolYear;
        $isPrimary = ! AdminAssignment::query()
            ->where('role_name', $roleName)
            ->where('academic_year', $schoolYear->label)
            ->where('organization_id', null)
            ->where('college_id', $college?->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('is_primary_admin', true)
            ->exists();

        return AdminAssignment::create([
            'user_id' => $user->id,
            'role_name' => $roleName,
            'position_title' => $positionTitle,
            'college_id' => $college?->id,
            'department_id' => $department?->id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'term_start' => today()->subMonth()->toDateString(),
            'term_end' => today()->addYear()->toDateString(),
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => $isPrimary,
            'approved_at' => now(),
        ]);
    }
}
