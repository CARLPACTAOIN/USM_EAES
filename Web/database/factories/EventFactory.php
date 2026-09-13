<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
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
            'hardcopy_submitted' => false,
            'head_organization_signed' => false,
            'adviser_signed' => false,
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
            'hardcopy_submitted' => true,
        ]);
    }
}
