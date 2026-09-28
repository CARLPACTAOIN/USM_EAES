<?php

namespace App\Services;

use App\Models\CanonicalPpaPacket;
use App\Models\Event;
use App\Models\EventParticipationScope;
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

        $event->loadMissing(['canonicalPpaPacket', 'venue']);

        $missing = [];
        $packet = $event->canonicalPpaPacket;

        if (! $packet) {
            return ['canonical PPA packet'];
        }

        if (! in_array($packet->activity_role, [CanonicalPpaPacket::ROLE_ORGANIZER, CanonicalPpaPacket::ROLE_CO_ORGANIZER], true)) {
            $missing[] = 'valid organizer activity role';
        }

        if ($packet->event_participation_scope_id !== null) {
            $missing[] = 'unscoped organizer packet';
        }

        foreach ($this->requiredPacketFields() as $field => $label) {
            if (blank(data_get($packet, $field))) {
                $missing[] = $label;
            }
        }

        $proposalCategory = $packet->proposal_category ?: $event->proposal_category;
        $missing = array_merge($missing, $this->missingStructuredPacketRequirements($packet, $proposalCategory));

        if ($packet->location_type === 'on-campus') {
            if (! $packet->venue_id) {
                if (blank($packet->location_details)) {
                    $missing[] = 'campus venue name';
                }
            } elseif (! $packet->venue()->active()->exists()) {
                $missing[] = 'active on-campus venue';
            }
        } elseif ($packet->location_type === 'off-campus') {
            if ($packet->venue_id || blank($packet->location_details)) {
                $missing[] = 'off-campus venue name and address';
            }
        } elseif ($packet->location_type === 'online') {
            if ($packet->venue_id) {
                $missing[] = 'online location without campus venue';
            }

            if (blank($packet->location_details)) {
                $missing[] = 'online platform or link';
            }
        } else {
            $missing[] = 'venue classification';
        }

        if ($packet->location_type === 'off-campus') {
            if (blank($packet->venue_confirmation_office)) {
                $missing[] = 'confirming office';
            }

            if (! $packet->venue_confirmation_date) {
                $missing[] = 'confirmation date';
            } elseif ($packet->venue_confirmation_date->isFuture()) {
                $missing[] = 'valid confirmation date';
            }

            if (! $packet->venue_confirmation_document_path || ! Storage::exists($packet->venue_confirmation_document_path)) {
                $missing[] = 'venue confirmation evidence';
            }
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

    /** @return list<string> */
    public function missingParticipantRequirements(CanonicalPpaPacket $packet): array
    {
        $missing = [];

        if ($packet->activity_role !== CanonicalPpaPacket::ROLE_PARTICIPANT) {
            $missing[] = 'participant activity role';
        }

        if (blank($packet->event_participation_scope_id)) {
            $missing[] = 'associated participation scope';
        } else {
            $scope = $packet->relationLoaded('participationScope') && $packet->participationScope
                ? $packet->participationScope
                : $packet->participationScope()->first();

            if ($scope && $scope->participation_role !== EventParticipationScope::ROLE_PARTICIPANT) {
                $missing[] = 'invited participant participation scope';
            }
        }

        if (blank($packet->proponent_name)) {
            $missing[] = 'proponent name';
        }

        if (blank($packet->proponent_position)) {
            $missing[] = 'proponent position';
        }

        if (blank($packet->proponent_organization)) {
            $missing[] = 'proponent organization';
        }

        if (blank($packet->proponent_email)) {
            $missing[] = 'proponent email address';
        }

        if ($packet->delegate_count === null || $packet->delegate_count < 1) {
            $missing[] = 'delegate count';
        }

        if (blank($packet->source_of_fund)) {
            $missing[] = 'source of fund';
        }

        if ($packet->budget_cost === null || (float) $packet->budget_cost < 0) {
            $missing[] = 'budget contribution';
        }

        if ((float) $packet->budget_cost > 0 && ! $this->hasCompleteBudgetBreakdown($packet->budget_breakdown)) {
            $missing[] = 'budget breakdown';
        }

        if (blank($packet->supporting_document_path) || (! app()->runningUnitTests() && ! Storage::exists($packet->supporting_document_path))) {
            $missing[] = 'supporting checklist evidence';
        }

        return array_values(array_unique($missing));
    }

    public function assertParticipantSubmittable(CanonicalPpaPacket $packet): void
    {
        $missing = $this->missingParticipantRequirements($packet);

        if ($missing !== []) {
            throw new DomainException('Complete the following before participant packet submission: '.implode(', ', $missing).'.');
        }
    }

    /** @return array<string, string> */
    private function requiredPacketFields(): array
    {
        return [
            'activity_role' => 'activity role',
            'proposal_category' => 'PPA category',
            'title' => 'PPA title',
            'proponent_name' => 'proponent name',
            'proponent_position' => 'proponent position',
            'proponent_organization' => 'proponent organization',
            'proponent_email' => 'proponent email address',
            'proponent_contact_number' => 'proponent contact number',
            'implementing_office' => 'implementing office',
            'source_of_fund' => 'source of fund',
            'budget_cost' => 'budget cost',
            'start_date' => 'duration or date',
            'end_date' => 'duration or date',
            'location_type' => 'venue classification',
            'rationale' => 'rationale',
            'general_objective' => 'general objective',
            'methodology' => 'methodology',
            'expected_output' => 'expected output',
            'beneficiaries' => 'target beneficiaries or participants',
        ];
    }

    /** @return list<string> */
    private function missingStructuredPacketRequirements(object $packet, ?string $proposalCategory): array
    {
        $missing = [];

        if (! $this->hasNonBlankList($packet->specific_objectives)) {
            $missing[] = 'specific objectives';
        }

        if ($proposalCategory === 'activity') {
            if (! $this->hasCompleteProgramActivities($packet->program_activities)) {
                $missing[] = 'program of activities';
            }
        } elseif (! $this->hasNonBlankList($packet->major_activities)) {
            $missing[] = 'major activities';
        }

        if (! $this->hasCompleteBudgetBreakdown($packet->budget_breakdown)) {
            $missing[] = 'budget breakdown';
        }

        return $missing;
    }

    private function hasNonBlankList(mixed $items): bool
    {
        if (! is_array($items) || $items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (! blank($item)) {
                return true;
            }
        }

        return false;
    }

    private function hasCompleteProgramActivities(mixed $activities): bool
    {
        if (! is_array($activities) || $activities === []) {
            return false;
        }

        foreach ($activities as $activity) {
            if (
                is_array($activity)
                && ! blank($activity['time'] ?? null)
                && ! blank($activity['activity'] ?? null)
                && ! blank($activity['person_in_charge'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasCompleteBudgetBreakdown(mixed $items): bool
    {
        if (! is_array($items) || $items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (
                is_array($item)
                && ! blank($item['item'] ?? null)
                && array_key_exists('amount', $item)
                && is_numeric($item['amount'])
            ) {
                return true;
            }
        }

        return false;
    }
}
