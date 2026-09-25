<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\EventProposalCreate;
use App\Models\AdminAssignment;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\EventPermitService;
use App\Services\EventProposalDraftService;
use App\Services\VenueAvailabilityService;
use App\Support\EvaluationQuestions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VenueAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_confirmed_usage_includes_one_hour_buffer_and_drafts_do_not_block(): void
    {
        [, $organization] = $this->hierarchy();
        $venue = Venue::factory()->create(['name' => 'Main Gym']);
        Venue::factory()->create(['name' => 'Library Hall']);
        $date = today()->addMonth()->toDateString();

        $this->event($organization, [
            'title' => 'Approved Assembly',
            'status' => Event::STATUS_APPROVED,
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
        ], $date, '10:00', '12:00');

        $this->event($organization, [
            'title' => 'Draft Assembly',
            'status' => Event::STATUS_DRAFT,
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
        ], $date, '09:30', '10:30');

        $availability = app(VenueAvailabilityService::class)->availabilityFor($date, '09:15', '09:45');

        $mainGym = collect($availability['venues'])->firstWhere('name', 'Main Gym');
        $libraryHall = collect($availability['venues'])->firstWhere('name', 'Library Hall');

        $this->assertSame('used', $mainGym['status']);
        $this->assertSame('Approved Assembly', $mainGym['events'][0]['title']);
        $this->assertSame('09:00', $mainGym['events'][0]['buffer_start_time']);
        $this->assertSame('available', $libraryHall['status']);
        $this->assertFalse(collect($mainGym['events'])->contains('title', 'Draft Assembly'));
    }

    public function test_manual_submitted_venues_are_tentative_and_normalized_by_casing_and_whitespace(): void
    {
        [, $organization] = $this->hierarchy();
        $date = today()->addMonth()->toDateString();
        $this->event($organization, [
            'title' => 'Pending Manual Venue',
            'status' => Event::STATUS_SUBMITTED,
            'venue_id' => null,
            'location_details' => '  MAIN   gym  ',
        ], $date, '14:00', '16:00');

        $availability = app(VenueAvailabilityService::class)->availabilityFor($date, '14:30', '15:00', 'main gym');
        $row = collect($availability['venues'])->first();

        $this->assertSame('manual', $row['source']);
        $this->assertSame('tentative', $row['status']);
        $this->assertSame('Pending Manual Venue', $row['events'][0]['title']);
    }

    public function test_organizing_admins_can_open_availability_page_but_students_cannot(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $student = User::factory()->create();
        $student->assignRole('Student');

        $this->actingAs($student)->get(route('dashboard.venue-availability'))->assertForbidden();
        $this->actingAs($organizer)->get(route('dashboard.venue-availability'))
            ->assertOk()
            ->assertSee('System-recorded usage only; confirm reservation with the managing office.');
    }

    public function test_proposal_modal_warns_about_confirmed_catalog_venue_usage(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $venue = Venue::factory()->create(['name' => 'Main Gym']);
        $date = today()->addMonth()->toDateString();

        $this->event($organization, [
            'title' => 'Approved Assembly',
            'status' => Event::STATUS_APPROVED,
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
        ], $date, '10:00', '12:00');

        Livewire::actingAs($organizer)
            ->test(EventProposalCreate::class)
            ->set('startDate', $date)
            ->set('endDate', $date)
            ->set('eventDays', [['date' => $date, 'start_time' => '09:30', 'end_time' => '10:30']])
            ->set('venueId', $venue->id)
            ->assertSee('Confirmed system-recorded usage overlaps this venue/time.')
            ->assertSee('Open full availability view');
    }

    public function test_manual_on_campus_venue_can_be_submitted_without_creating_catalog_record(): void
    {
        [, $organization] = $this->hierarchy();
        $organizer = $this->admin('Society Admin', $organization);
        $data = $this->proposalData($organization);
        $data['venue_id'] = null;
        $data['location_details'] = 'Main Gym';
        $data['hardcopy_submitted'] = true;
        $data['head_organization_signed'] = true;
        $data['adviser_signed'] = true;

        $event = app(EventProposalDraftService::class)->create(
            $organizer,
            $data,
            UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('confirmation.pdf', 100, 'application/pdf')
        );

        $submitted = app(EventPermitService::class)->submit($event->fresh(), $organizer);

        $this->assertSame(Event::STATUS_SUBMITTED, $submitted->status);
        $this->assertNull($submitted->venue_id);
        $this->assertSame('Main Gym', $submitted->location_details);
        $this->assertDatabaseCount('venues', 0);
    }

    /** @return array{College, Organization} */
    private function hierarchy(string $collegeName = 'CEIT', string $acronym = 'PSITS', string $type = 'society'): array
    {
        $university = University::firstOrCreate(['domain' => 'usm.edu.ph'], ['name' => 'USM']);
        $college = College::create(['university_id' => $university->id, 'name' => $collegeName, 'code' => $acronym.'-C']);
        $organization = Organization::create(['college_id' => $college->id, 'name' => $acronym, 'acronym' => $acronym, 'type' => $type, 'status' => 'active']);

        return [$college, $organization];
    }

    private function admin(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create();
        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $role,
            organization: $role === 'Super Admin (OSA)' ? null : $organization,
            college: $role === 'Super Admin (OSA)' ? null : $organization?->college,
            academicYear: '2026-2027',
            termStart: today()->subMonth()->toDateString(),
            termEnd: today()->addYear()->toDateString(),
            positionTitle: 'Test Admin',
            approvedBy: $user,
            isPrimaryAdmin: ! AdminAssignment::where('role_name', $role)->where('status', AdminAssignment::STATUS_ACTIVE)->exists()
        );

        return $user->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function event(Organization $organization, array $overrides, string $date, string $startTime, string $endTime): Event
    {
        $event = Event::create(array_merge([
            'organization_id' => $organization->id,
            'title' => 'Venue Event',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_APPROVED,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => $date,
            'end_date' => $date,
            'location_type' => 'on-campus',
            'venue_confirmation_office' => 'Campus Facilities Office',
            'venue_confirmation_date' => today(),
            'hardcopy_submitted' => true,
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ], $overrides));

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return $event;
    }

    /** @return array<string, mixed> */
    private function proposalData(Organization $organization): array
    {
        $date = today()->addMonth()->toDateString();

        return [
            'organization_id' => $organization->id,
            'parent_event_id' => null,
            'title' => 'Manual Campus Venue Event',
            'proposal_category' => 'activity',
            'canonical_packet' => [
                'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
                'proponent_name' => 'Venue Secretary',
                'proponent_position' => 'Secretary',
                'proponent_organization' => $organization->name,
                'proponent_email' => 'venue.secretary@usm.edu.ph',
                'proponent_contact_number' => '09170000000',
                'rationale' => 'Manual campus venue proposals use the generated packet.',
                'general_objective' => 'Submit a manual campus venue packet.',
                'specific_objectives' => ['Prepare venue details', 'Submit generated packet'],
                'methodology' => 'Coordinate with the campus venue office.',
                'expected_output' => 'A submitted manual venue packet.',
            ],
            'start_date' => $date,
            'end_date' => $date,
            'location_type' => 'on-campus',
            'location_details' => null,
            'venue_id' => null,
            'venue_confirmation_office' => 'Campus Facilities Office',
            'venue_confirmation_date' => today()->toDateString(),
            'venue_confirmation_reference_number' => 'VC-100',
            'target_demographics' => [
                'implementing_office' => $organization->name,
                'target_participants' => 'Manual venue participants',
            ],
            'budget_allocations' => [
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 1500,
            ],
            'resolution_number' => null,
            'hardcopy_submitted' => false,
            'head_organization_signed' => false,
            'adviser_signed' => false,
            'event_days' => [['date' => $date, 'start_time' => '08:00', 'end_time' => '12:00']],
            'evaluation_questions' => EvaluationQuestions::defaultSchema(),
        ];
    }
}
