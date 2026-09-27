<?php

namespace Database\Factories;

use App\Models\CanonicalPpaPacket;
use App\Models\Event;
use App\Models\EventParticipationScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanonicalPpaPacket>
 */
class CanonicalPpaPacketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'proposal_category' => 'activity',
            'title' => fake()->sentence(4),
            'proponent_name' => fake()->name(),
            'proponent_position' => 'Secretary',
            'proponent_organization' => fake()->company(),
            'proponent_email' => fake()->safeEmail(),
            'proponent_contact_number' => fake()->phoneNumber(),
            'implementing_office' => fake()->company(),
            'source_of_fund' => 'Organization fund',
            'budget_cost' => fake()->randomFloat(2, 0, 50000),
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth(),
            'location_type' => 'on-campus',
            'location_details' => fake()->company().' Hall',
            'specific_objectives' => [fake()->sentence()],
            'major_activities' => [fake()->sentence()],
            'program_activities' => [],
            'budget_breakdown' => [],
        ];
    }

    public function participant(?EventParticipationScope $scope = null): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT,
            'event_participation_scope_id' => $scope?->id,
            'delegate_count' => fake()->numberBetween(5, 50),
            'supporting_document_path' => 'participant-ppa-documents/sample-evidence.pdf',
            'supporting_document_original_name' => 'sample-evidence.pdf',
        ]);
    }
}
