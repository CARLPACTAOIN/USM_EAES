<?php

namespace Database\Factories;

use App\Models\CanonicalPpaAttachment;
use App\Models\CanonicalPpaPacket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanonicalPpaAttachment>
 */
class CanonicalPpaAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'canonical_ppa_packet_id' => CanonicalPpaPacket::factory(),
            'attachment_type' => CanonicalPpaAttachment::TYPE_SUPPORTING,
            'path' => 'canonical-ppa/sample-evidence.pdf',
            'original_name' => 'sample-evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ];
    }
}
