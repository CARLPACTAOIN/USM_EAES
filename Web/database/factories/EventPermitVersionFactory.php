<?php

namespace Database\Factories;

use App\Models\EventPermitVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventPermitVersion>
 */
class EventPermitVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_number' => 1,
            'venue_name_snapshot' => fake()->company().' Hall',
            'venue_location_snapshot' => fake()->streetName(),
            'location_type' => 'on-campus',
            'location_details' => fake()->address(),
            'confirmation_office' => fake()->company(),
            'confirmation_date' => today(),
            'confirmation_reference_number' => fake()->bothify('VC-####'),
            'confirmation_document_path' => 'venue-confirmations/example.pdf',
            'confirmation_document_original_name' => 'confirmation.pdf',
            'ppa_document_path' => 'proposal-documents/example.pdf',
            'ppa_document_original_name' => 'proposal.pdf',
            'amendment_reason' => null,
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'superseded_at' => null,
        ];
    }
}
