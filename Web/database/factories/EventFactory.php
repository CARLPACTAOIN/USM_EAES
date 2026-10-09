<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id'),
            'title' => fake()->sentence(4),
            'proposal_category' => 'activity',
            'status' => Event::STATUS_DRAFT,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth(),
            'location_type' => 'on-campus',
            'location_details' => null,
        ];
    }

    public function confirmedAt(Venue $venue): static
    {
        return $this->state(fn (): array => [
            'venue_id' => $venue->id,
            'location_type' => 'on-campus',
            'location_details' => $venue->displayName(),
            'venue_confirmation_office' => $venue->managing_office,
            'venue_confirmation_date' => today(),
            'venue_confirmation_reference_number' => fake()->bothify('VC-####'),
            'venue_confirmation_document_path' => 'venue-confirmations/example.pdf',
            'venue_confirmation_document_original_name' => 'confirmation.pdf',
            'proposal_document_path' => 'proposal-documents/example.pdf',
            'proposal_document_original_name' => 'proposal.pdf',
        ]);
    }

    public function withGovernanceSnapshots(?SchoolYear $schoolYear = null): static
    {
        return $this->state(function (array $attributes) use ($schoolYear): array {
            $orgId = $attributes['organization_id'] ?? null;
            $org = $orgId ? Organization::find($orgId) : null;
            $schoolYear ??= SchoolYear::where('status', SchoolYear::STATUS_ACTIVE)->first();

            return [
                'school_year_id' => $schoolYear?->id,
                'college_name_snapshot' => $org?->resolvedCollege()?->name ?? 'College of Science and Mathematics',
                'department_name_snapshot' => $org?->department?->name ?? 'Department of Computer Science',
                'program_name_snapshot' => $org?->programs()->first()?->name ?? 'Bachelor of Science in Computer Science',
                'organization_name_snapshot' => $org?->name ?? 'Computer Science Society',
                'proponent_name_snapshot' => fake()->name(),
            ];
        });
    }
}
