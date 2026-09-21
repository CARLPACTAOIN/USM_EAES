<?php

namespace App\Services;

use App\Models\Event;
use DomainException;
use Illuminate\Support\Facades\Storage;

class EventProposalSubmissionGuard
{
    /** @return list<string> */
    public function missingRequirements(Event $event): array
    {
        if (! $event->requiresVenueConfirmation()) {
            return [];
        }

        $missing = [];

        if (! $event->proposal_document_path || ! Storage::exists($event->proposal_document_path)) {
            $missing[] = 'official PPA softcopy';
        }

        if (! $event->hardcopy_submitted) {
            $missing[] = 'OSA hardcopy confirmation';
        }

        if ($event->location_type === 'on-campus') {
            if (! $event->venue_id) {
                if (blank($event->location_details)) {
                    $missing[] = 'campus venue name';
                }
            } elseif (! $event->venue()->active()->exists()) {
                $missing[] = 'active on-campus venue';
            }
        } elseif ($event->location_type === 'off-campus') {
            if ($event->venue_id || blank($event->location_details)) {
                $missing[] = 'off-campus venue name and address';
            }
        } else {
            $missing[] = 'venue classification';
        }

        if (blank($event->venue_confirmation_office)) {
            $missing[] = 'confirming office';
        }

        if (! $event->venue_confirmation_date) {
            $missing[] = 'confirmation date';
        } elseif ($event->venue_confirmation_date->isFuture()) {
            $missing[] = 'valid confirmation date';
        }

        if (! $event->venue_confirmation_document_path || ! Storage::exists($event->venue_confirmation_document_path)) {
            $missing[] = 'venue confirmation evidence';
        }

        return array_values(array_unique($missing));
    }

    public function assertSubmittable(Event $event): void
    {
        $missing = $this->missingRequirements($event);

        if ($missing !== []) {
            throw new DomainException('Complete the following before submission: '.implode(', ', $missing).'.');
        }
    }
}
