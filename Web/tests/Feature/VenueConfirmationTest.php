<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\VenueCatalog;
use App\Models\AdminAssignment;
use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\ProposalWorkflowTask;
use App\Models\ScannerSession;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\EventPermitService;
use App\Services\EventProposalDraftService;
use App\Support\EvaluationQuestions;
use App\Support\EventEvaluationForm;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VenueConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_only_osa_can_manage_and_retire_venues(): void
    {
        [$college, $organization] = $this->hierarchy();
        $osa = $this->admin('Super Admin (OSA)');
        $organizer = $this->admin('Society Admin', $organization);

        Livewire::actingAs($osa)->test(VenueCatalog::class)
            ->set('name', 'University Auditorium')->set('campusLocation', 'Administration Complex')
            ->set('collegeId', $college->id)->set('managingOffice', 'Campus Facilities Office')
            ->set('inquiryInstructions', 'Email the office before filing the PPA.')
            ->call('save')->assertSet('notice', 'Venue created.');

        $venue = Venue::firstOrFail();
        Livewire::actingAs($osa)->test(VenueCatalog::class)
            ->call('retire', $venue->id)->assertSet('notice', 'Venue retired. Historical permits remain unchanged.')
            ->call('reactivate', $venue->id)->assertSet('notice', 'Venue reactivated.');

        $this->actingAs($organizer)->get(route('dashboard.venues'))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['target_id' => $venue->id, 'action' => 'venue-created']);
        $this->assertDatabaseHas('audit_logs', ['target_id' => $venue->id, 'action' => 'venue-retired']);
    }

    public function test_incomplete_proposal_can_be_saved_but_not_submitted(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $event = app(EventProposalDraftService::class)->create($organizer, $this->proposalData($organization), null, null);

        $this->assertSame(Event::STATUS_DRAFT, $event->status);
        $this->actingAs($organizer)->post(route('dashboard.events.submit', $event))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'campus venue name'));
        $this->assertSame(Event::STATUS_DRAFT, $event->fresh()->status);
    }

    public function test_valid_confirmation_creates_immutable_permit_version(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $osa = $this->admin('Super Admin (OSA)');
        $venue = Venue::factory()->create(['managing_office' => 'Campus Facilities Office']);
        $event = app(EventProposalDraftService::class)->create(
            $organizer,
            $this->proposalData($organization, $venue),
            UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('confirmation.jpg', 100, 'image/jpeg')
        );
        $event->update(['hardcopy_submitted' => true, 'hardcopy_submitted_at' => now()]);

        app(EventPermitService::class)->submit($event->fresh(), $organizer);
        $this->satisfyPermitGates($event->fresh(), $osa);
        $approved = app(EventPermitService::class)->approve($event->fresh(), $osa);

        $this->assertSame(1, $approved->permit_version);
        $this->assertDatabaseHas('event_permit_versions', ['event_id' => $event->id, 'version_number' => 1, 'venue_name_snapshot' => $venue->name, 'superseded_at' => null]);

        $token = $organizer->createToken('permit-history')->plainTextToken;
        $this->withToken($token)->getJson("/api/v1/events/{$event->id}/permit-history")
            ->assertOk()
            ->assertJsonPath('versions.0.version_number', 1)
            ->assertJsonMissingPath('versions.0.confirmation_document_path')
            ->assertJsonMissingPath('versions.0.ppa_document_path');
    }

    public function test_future_date_and_retired_venue_are_rejected(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $venue = Venue::factory()->retired()->create();
        $payload = $this->proposalData($organization, $venue);
        $payload['venue_confirmation_date'] = today()->addDay()->toDateString();
        $token = $organizer->createToken('venue-test')->plainTextToken;

        $this->withToken($token)->post('/api/v1/events', $payload)->assertUnprocessable()->assertJsonValidationErrors('venue_confirmation_date');
        $payload['venue_confirmation_date'] = today()->toDateString();
        $this->withToken($token)->post('/api/v1/events', $payload)->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Select an active on-campus venue from the catalog.']);
    }

    public function test_valid_off_campus_confirmation_can_be_submitted_without_a_catalog_venue(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $data = $this->proposalData($organization);
        $data['location_type'] = 'off-campus';
        $data['location_details'] = 'Kabacan Convention Center, National Highway, Kabacan';
        $data['venue_confirmation_office'] = 'Kabacan Convention Center Administration';
        $data['venue_confirmation_date'] = today()->toDateString();

        $event = app(EventProposalDraftService::class)->create(
            $organizer,
            $data,
            UploadedFile::fake()->create('proposal.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            UploadedFile::fake()->create('confirmation.pdf', 100, 'application/pdf')
        );
        $event->update(['hardcopy_submitted' => true, 'hardcopy_submitted_at' => now()]);

        $submitted = app(EventPermitService::class)->submit($event->fresh(), $organizer);

        $this->assertSame(Event::STATUS_SUBMITTED, $submitted->status);
        $this->assertNull($submitted->venue_id);
        $this->assertSame($data['location_details'], $submitted->location_details);
    }

    public function test_confirmation_upload_rejects_invalid_type_and_files_over_ten_megabytes(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $venue = Venue::factory()->create();
        $token = $organizer->createToken('venue-file-validation')->plainTextToken;
        $payload = $this->proposalData($organization, $venue);

        $this->withToken($token)->post('/api/v1/events', $payload + [
            'venue_confirmation_document' => UploadedFile::fake()->create('confirmation.exe', 100, 'application/octet-stream'),
        ])->assertUnprocessable()->assertInvalid('venue_confirmation_document');

        $this->withToken($token)->post('/api/v1/events', $payload + [
            'venue_confirmation_document' => UploadedFile::fake()->create('confirmation.pdf', 10241, 'application/pdf'),
        ])->assertUnprocessable()->assertInvalid('venue_confirmation_document');
    }

    public function test_osa_approval_rechecks_that_confirmation_evidence_still_exists(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $osa = $this->admin('Super Admin (OSA)');
        $venue = Venue::factory()->create();
        $event = $this->confirmedEvent($organization, $venue, [
            'venue_id' => null,
            'location_type' => 'off-campus',
            'location_details' => 'Kabacan Convention Center, National Highway, Kabacan',
        ]);
        EventEvaluationForm::createForEvent($event, $organizer, EvaluationQuestions::defaultSchema(), 'Evaluation');
        Storage::delete($event->venue_confirmation_document_path);

        try {
            app(EventPermitService::class)->approve($event, $osa);
            $this->fail('Approval should fail when venue evidence is missing from private storage.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('venue confirmation evidence', $exception->getMessage());
            $this->assertSame(Event::STATUS_SUBMITTED, $event->fresh()->status);
            $this->assertDatabaseCount('event_permit_versions', 0);
        }
    }

    public function test_evidence_download_is_private_and_tenant_scoped(): void
    {
        [, $organization] = $this->hierarchy();
        [, $otherOrganization] = $this->hierarchy('Other College', 'OTHER');
        $owner = $this->admin('Society Admin', $organization);
        $outsider = $this->admin('Society Admin', $otherOrganization);
        $venue = Venue::factory()->create();
        Storage::put('venue-confirmations/evidence.pdf', 'evidence');
        $event = $this->confirmedEvent($organization, $venue, ['venue_confirmation_document_path' => 'venue-confirmations/evidence.pdf']);

        $this->get(route('dashboard.events.venue-evidence', $event))->assertRedirect();
        $this->actingAs($outsider)->get(route('dashboard.events.venue-evidence', $event))->assertForbidden();
        $this->actingAs($owner)->get(route('dashboard.events.venue-evidence', $event))->assertOk();
    }

    public function test_amendment_preserves_versions_and_revokes_scanner_sessions(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $osa = $this->admin('Super Admin (OSA)');
        $oldVenue = Venue::factory()->create();
        $newVenue = Venue::factory()->create();
        $event = $this->confirmedEvent($organization, $oldVenue);
        EventEvaluationForm::createForEvent($event, $organizer, EvaluationQuestions::defaultSchema(), 'Evaluation');
        app(EventPermitService::class)->approve($event, $osa);
        $oldPermit = $event->fresh()->permitVersions()->firstOrFail();
        $session = ScannerSession::create(['event_id' => $event->id, 'scanner_user_id' => $organizer->id, 'token_id' => '123', 'issued_by' => $organizer->id, 'status' => ScannerSession::STATUS_ACTIVE, 'issued_at' => now(), 'expires_at' => now()->addDay()]);

        $token = $organizer->createToken('venue-amendment')->plainTextToken;
        $this->withToken($token)->postJson("/api/v1/events/{$event->id}/venue-amendment", [
            'reason' => 'Original hall became unavailable.',
        ])->assertOk()->assertJsonPath('event.amendment_pending', true);
        $this->assertNotNull($oldPermit->fresh()->superseded_at);
        $this->assertSame(ScannerSession::STATUS_REVOKED, $session->fresh()->status);
        Storage::assertExists($oldPermit->ppa_document_path);
        Storage::assertExists($oldPermit->confirmation_document_path);

        Storage::put('proposal-documents/replacement.pdf', 'new ppa');
        Storage::put('venue-confirmations/replacement.pdf', 'new evidence');
        $event->refresh()->update(['venue_id' => $newVenue->id, 'location_type' => 'on-campus', 'location_details' => $newVenue->displayName(), 'venue_confirmation_office' => $newVenue->managing_office, 'venue_confirmation_date' => today(), 'venue_confirmation_document_path' => 'venue-confirmations/replacement.pdf', 'venue_confirmation_document_original_name' => 'replacement.pdf', 'proposal_document_path' => 'proposal-documents/replacement.pdf', 'proposal_document_original_name' => 'replacement.pdf']);
        app(EventPermitService::class)->submit($event->fresh(), $organizer);
        $this->satisfyPermitGates($event->fresh(), $osa);
        $approved = app(EventPermitService::class)->approve($event->fresh(), $osa);

        $this->assertSame(2, $approved->permit_version);
        $this->assertNull($approved->pending_venue_amendment_reason);
        $this->assertCount(2, $approved->permitVersions);
    }

    public function test_amendment_requires_a_reason_and_cannot_start_on_the_first_event_day(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $osa = $this->admin('Super Admin (OSA)');
        $venue = Venue::factory()->create();
        $event = $this->confirmedEvent($organization, $venue);
        EventEvaluationForm::createForEvent($event, $organizer, EvaluationQuestions::defaultSchema(), 'Evaluation');
        app(EventPermitService::class)->approve($event, $osa);

        try {
            app(EventPermitService::class)->beginVenueAmendment($event->fresh(), $organizer, '   ');
            $this->fail('An amendment reason should be required.');
        } catch (DomainException $exception) {
            $this->assertSame('An amendment reason is required.', $exception->getMessage());
        }

        $event->eventDays()->update(['date' => today()]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Venue amendments must begin before the first event day.');
        app(EventPermitService::class)->beginVenueAmendment($event->fresh(), $organizer, 'Same-day venue change.');
    }

    public function test_rejected_amendment_does_not_reactivate_the_superseded_permit(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $osa = $this->admin('Super Admin (OSA)');
        $oldVenue = Venue::factory()->create();
        $newVenue = Venue::factory()->create();
        $event = $this->confirmedEvent($organization, $oldVenue);
        EventEvaluationForm::createForEvent($event, $organizer, EvaluationQuestions::defaultSchema(), 'Evaluation');
        app(EventPermitService::class)->approve($event, $osa);
        app(EventPermitService::class)->beginVenueAmendment($event->fresh(), $organizer, 'Move to the alternate hall.');

        Storage::put('proposal-documents/rejected-replacement.pdf', 'new ppa');
        Storage::put('venue-confirmations/rejected-replacement.pdf', 'new evidence');
        $event->refresh()->update([
            'venue_id' => $newVenue->id,
            'location_type' => 'on-campus',
            'location_details' => $newVenue->displayName(),
            'venue_confirmation_office' => $newVenue->managing_office,
            'venue_confirmation_date' => today(),
            'venue_confirmation_document_path' => 'venue-confirmations/rejected-replacement.pdf',
            'venue_confirmation_document_original_name' => 'rejected-replacement.pdf',
            'proposal_document_path' => 'proposal-documents/rejected-replacement.pdf',
            'proposal_document_original_name' => 'rejected-replacement.pdf',
        ]);
        app(EventPermitService::class)->submit($event->fresh(), $organizer);
        $rejected = app(EventPermitService::class)->reject($event->fresh(), $osa);

        $this->assertSame(Event::STATUS_REJECTED, $rejected->status);
        $this->assertSame(1, $rejected->permit_version);
        $this->assertFalse($rejected->permitVersions()->whereNull('superseded_at')->exists());
    }

    public function test_hr_events_are_exempt(): void
    {
        [, $organization] = $this->hierarchy('HR College', 'HRORG', 'hr_unit');
        $hr = $this->admin('HR Admin', $organization);
        $event = app(EventProposalDraftService::class)->create($hr, $this->proposalData($organization), null, null);

        $this->assertSame('hr', $event->event_domain);
        $this->assertSame(Event::STATUS_APPROVED, $event->status);
        $this->assertNull($event->venue_id);
    }

    public function test_api_lists_only_active_venues_and_returns_structured_confirmation_data(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $activeVenue = Venue::factory()->create();
        Venue::factory()->retired()->create();
        $token = $organizer->createToken('venue-api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/venues')
            ->assertOk()->assertJsonCount(1, 'venues')->assertJsonPath('venues.0.id', $activeVenue->id);

        $event = $this->confirmedEvent($organization, $activeVenue, ['status' => Event::STATUS_DRAFT]);
        $this->withToken($token)->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('events.0.venue.id', $activeVenue->id)
            ->assertJsonPath('events.0.venue_confirmation.office', $activeVenue->managing_office)
            ->assertJsonPath('events.0.permit_version', 0)
            ->assertJsonPath('events.0.amendment_pending', false)
            ->assertJsonMissingPath('events.0.venue_confirmation_document_path');
    }

    public function test_failed_draft_transaction_removes_new_uploads(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $venue = Venue::factory()->create();
        $data = $this->proposalData($organization, $venue);
        $data['event_days'] = [['date' => null, 'start_time' => '08:00', 'end_time' => '12:00']];

        try {
            app(EventProposalDraftService::class)->create(
                $organizer,
                $data,
                UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('confirmation.pdf', 100, 'application/pdf')
            );
            $this->fail('The invalid event day should fail the transaction.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('events', 0);
            $this->assertSame([], Storage::allFiles('proposal-documents'));
            $this->assertSame([], Storage::allFiles('venue-confirmations'));
        }
    }

    /** @return array{College, Organization} */
    private function hierarchy(string $collegeName = 'CEIT', string $acronym = 'PSITS', string $type = 'society'): array
    {
        $university = University::firstOrCreate(['domain' => 'usm.edu.ph'], ['name' => 'USM']);
        $college = College::create(['university_id' => $university->id, 'name' => $collegeName, 'code' => $acronym.'-C']);
        $department = Department::create([
            'college_id' => $college->id,
            'name' => "{$acronym} Department",
            'code' => $acronym.'-D',
            'status' => 'active',
        ]);
        $organization = Organization::create(['college_id' => $college->id, 'department_id' => $department->id, 'name' => $acronym, 'acronym' => $acronym, 'type' => $type, 'status' => 'active']);

        return [$college, $organization];
    }

    private function admin(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create();
        app(AdminAssignmentService::class)->createAssignment(user: $user, roleName: $role, organization: $role === 'Super Admin (OSA)' ? null : $organization, college: $role === 'Super Admin (OSA)' ? null : $organization?->college, academicYear: '2026-2027', termStart: today()->subMonth()->toDateString(), termEnd: today()->addYear()->toDateString(), positionTitle: 'Test Admin', approvedBy: $user, isPrimaryAdmin: ! AdminAssignment::where('role_name', $role)->where('status', AdminAssignment::STATUS_ACTIVE)->exists());

        if ($organization && $role !== 'Super Admin (OSA)' && $organization->type !== 'hr_unit') {
            $this->ensureWorkflowApprovers($organization, $user);
        }

        return $user->fresh();
    }

    private function ensureWorkflowApprovers(Organization $organization, User $president): void
    {
        $schoolYear = SchoolYear::firstOrCreate(
            ['label' => '2026-2027'],
            [
                'starts_on' => today()->subMonths(3),
                'ends_on' => today()->addMonths(9),
                'status' => SchoolYear::STATUS_ACTIVE,
                'activated_at' => now(),
            ],
        );

        $organization->loadMissing(['college', 'department']);
        $department = $organization->department;

        $secretary = User::factory()->create([
            'college_id' => $organization->college_id,
            'department_id' => $department?->id,
            'email' => 'secretary.'.uniqid().'@usm.edu.ph',
        ]);
        $adviser = User::factory()->create([
            'college_id' => $organization->college_id,
            'department_id' => $department?->id,
            'user_type' => 'faculty',
            'email' => 'adviser.'.uniqid().'@usm.edu.ph',
        ]);

        if (! OrganizationOfficerRoster::where('organization_id', $organization->id)->where('school_year_id', $schoolYear->id)->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)->exists()) {
            $roster = OrganizationOfficerRoster::create([
                'organization_id' => $organization->id,
                'school_year_id' => $schoolYear->id,
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
                    'effective_start' => $schoolYear->starts_on,
                ]);
            }
        }

        if ($department) {
            $chair = User::factory()->create([
                'college_id' => $organization->college_id,
                'department_id' => $department->id,
                'user_type' => 'faculty',
                'email' => 'chair.'.uniqid().'@usm.edu.ph',
            ]);
            $this->institutionalAssignment($chair, 'Department Chairperson', 'Chairperson', $schoolYear, $organization->college, $department);
        }

        $dean = User::factory()->create([
            'college_id' => $organization->college_id,
            'user_type' => 'faculty',
            'email' => 'dean.'.uniqid().'@usm.edu.ph',
        ]);
        $this->institutionalAssignment($dean, 'College Dean', 'Dean', $schoolYear, $organization->college);

        $security = User::factory()->create(['email' => 'security.'.uniqid().'@usm.edu.ph']);
        $security->assignRole('Chief Security');
        $this->institutionalAssignment($security, 'Chief Security', 'Chief Security Officer', $schoolYear);

        if (! User::role('Super Admin (OSA)')->exists()) {
            $osa = User::factory()->create(['email' => 'osa.'.uniqid().'@usm.edu.ph']);
            $osa->assignRole('Super Admin (OSA)');
            $this->institutionalAssignment($osa, 'Super Admin (OSA)', 'OSA Workflow Approver', $schoolYear);
        }
    }

    private function institutionalAssignment(
        User $user,
        string $roleName,
        string $positionTitle,
        SchoolYear $schoolYear,
        ?College $college = null,
        ?Department $department = null,
    ): void {
        if (AdminAssignment::where('role_name', $roleName)
            ->where('academic_year', $schoolYear->label)
            ->where('college_id', $college?->id)
            ->where('department_id', $department?->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->exists()) {
            return;
        }

        $isPrimary = ! AdminAssignment::where('role_name', $roleName)
            ->where('academic_year', $schoolYear->label)
            ->where('college_id', $college?->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('is_primary_admin', true)
            ->exists();

        AdminAssignment::create([
            'user_id' => $user->id,
            'role_name' => $roleName,
            'position_title' => $positionTitle,
            'college_id' => $college?->id,
            'department_id' => $department?->id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => $isPrimary,
            'approved_at' => now(),
        ]);
    }

    private function satisfyPermitGates(Event $event, User $actor): void
    {
        $event->loadMissing('workflowRun.tasks.processStep');

        foreach ($event->workflowRun?->tasks ?? [] as $task) {
            if (! $task->processStep?->is_gate || ! $task->processStep?->is_required) {
                continue;
            }

            $decidedAt = now();
            $task->update([
                'status' => ProposalWorkflowTask::STATUS_APPROVED,
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
                'decided_by' => $actor->id,
                'decided_at' => $decidedAt,
                'completed_at' => $decidedAt,
            ]);

            ApprovalTrail::create([
                'proposal_id' => $event->id,
                'workflow_run_id' => $event->workflowRun->id,
                'workflow_task_id' => $task->id,
                'actor_id' => $actor->id,
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
                'remarks' => 'Permit gate satisfied for venue confirmation regression.',
                'reference_id' => 'AT-'.$task->id,
                'previous_status' => ProposalWorkflowTask::STATUS_AVAILABLE,
                'resulting_status' => ProposalWorkflowTask::STATUS_APPROVED,
                'assigned_role' => $task->assigned_role,
                'assigned_scope' => $task->assigned_scope,
                'decided_at' => $decidedAt,
                'metadata' => [
                    'actor' => [
                        'id' => $actor->id,
                        'name' => $actor->name,
                        'email' => $actor->email,
                    ],
                    'assignment' => $task->metadata['assignment'] ?? [],
                ],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function proposalData(Organization $organization, ?Venue $venue = null): array
    {
        return [
            'organization_id' => $organization->id,
            'parent_event_id' => null,
            'title' => 'Venue Confirmation Event',
            'proposal_category' => 'activity',
            'canonical_packet' => [
                'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
                'proponent_name' => 'Venue Secretary',
                'proponent_position' => 'Secretary',
                'proponent_organization' => $organization->name,
                'proponent_email' => 'venue.secretary@usm.edu.ph',
                'proponent_contact_number' => '09170000000',
                'rationale' => 'Venue confirmation coverage requires a complete packet.',
                'general_objective' => 'Validate venue confirmation before submission.',
                'specific_objectives' => ['Prepare the venue request', 'Submit the packet'],
                'methodology' => 'Coordinate with the venue office and submit the packet.',
                'expected_output' => 'A complete event packet with venue details.',
            ],
            'start_date' => today()->addMonth()->toDateString(),
            'end_date' => today()->addMonth()->toDateString(),
            'location_type' => 'on-campus',
            'location_details' => null,
            'venue_id' => $venue?->id,
            'venue_confirmation_office' => $venue?->managing_office,
            'venue_confirmation_date' => $venue ? today()->toDateString() : null,
            'venue_confirmation_reference_number' => $venue ? 'VC-100' : null,
            'target_demographics' => [
                'implementing_office' => $organization->name,
                'target_participants' => 'Venue confirmation participants',
            ],
            'budget_allocations' => [
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 1500,
            ],
            'resolution_number' => null,
            'hardcopy_submitted' => false,
            'head_organization_signed' => false,
            'adviser_signed' => false,
            'event_days' => [[
                'date' => today()->addMonth()->toDateString(),
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
            'evaluation_questions' => EvaluationQuestions::defaultSchema(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function confirmedEvent(Organization $organization, Venue $venue, array $overrides = []): Event
    {
        Storage::put('proposal-documents/proposal.pdf', 'ppa');
        Storage::put('venue-confirmations/confirmation.pdf', 'evidence');
        $event = Event::create(array_merge(['organization_id' => $organization->id, 'title' => 'Approved Venue Event', 'proposal_category' => 'activity', 'status' => Event::STATUS_SUBMITTED, 'lifecycle_phase' => 'scheduled', 'event_domain' => 'student_affairs', 'start_date' => today()->addMonth(), 'end_date' => today()->addMonth(), 'location_type' => 'on-campus', 'venue_id' => $venue->id, 'location_details' => $venue->displayName(), 'venue_confirmation_office' => $venue->managing_office, 'venue_confirmation_date' => today(), 'venue_confirmation_reference_number' => 'VC-100', 'venue_confirmation_document_path' => 'venue-confirmations/confirmation.pdf', 'venue_confirmation_document_original_name' => 'confirmation.pdf', 'proposal_document_path' => 'proposal-documents/proposal.pdf', 'proposal_document_original_name' => 'proposal.pdf', 'hardcopy_submitted' => true, 'hardcopy_submitted_at' => now()], $overrides));
        EventDay::create(['event_id' => $event->id, 'day_number' => 1, 'date' => today()->addMonth(), 'start_time' => '08:00', 'end_time' => '12:00']);
        CanonicalPpaPacket::factory()->for($event)->create([
            'proposal_category' => 'activity',
            'title' => $event->title,
            'proponent_name' => 'Venue Secretary',
            'proponent_position' => 'Secretary',
            'proponent_organization' => $organization->name,
            'proponent_email' => 'venue.secretary@usm.edu.ph',
            'proponent_contact_number' => '09170000000',
            'implementing_office' => $organization->name,
            'source_of_fund' => 'Organization fund',
            'budget_cost' => 1500,
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'location_type' => $event->location_type,
            'venue_id' => $event->venue_id,
            'location_details' => $event->location_details,
            'venue_confirmation_office' => $event->venue_confirmation_office,
            'venue_confirmation_date' => $event->venue_confirmation_date,
            'venue_confirmation_reference_number' => $event->venue_confirmation_reference_number,
            'venue_confirmation_document_path' => $event->venue_confirmation_document_path,
            'venue_confirmation_document_original_name' => $event->venue_confirmation_document_original_name,
            'rationale' => 'Venue approval requires a complete packet.',
            'general_objective' => 'Approve the venue-confirmed packet.',
            'specific_objectives' => ['Prepare the venue request', 'Submit the packet'],
            'methodology' => 'Coordinate with the venue office and submit the packet.',
            'program_activities' => [[
                'date' => today()->addMonth()->toDateString(),
                'time' => '08:00 - 12:00',
                'activity' => $event->title,
                'person_in_charge' => 'Venue Secretary',
            ]],
            'expected_output' => 'A complete approved packet.',
            'beneficiaries' => 'Venue confirmation participants',
            'budget_breakdown' => [['item' => 'Overall total', 'amount' => '1500.00']],
        ]);

        return $event;
    }
}
