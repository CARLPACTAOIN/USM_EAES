<?php

namespace App\Services;

use App\Models\CanonicalPpaAttachment;
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

        $evaluation = $this->evaluate($event);

        return array_values(array_unique(array_column($evaluation['issues'], 'label')));
    }

    /**
     * @return array{
     *     score: int,
     *     total_checks: int,
     *     passed_checks: int,
     *     missing_count: int,
     *     is_submittable: bool,
     *     issues: list<array{section: string, section_index: int, section_title: string, field: string, target_id: string, label: string, message: string}>,
     *     grouped_issues: array<string, list<array{section: string, section_index: int, section_title: string, field: string, target_id: string, label: string, message: string}>>
     * }
     */
    public function readiness(Event $event): array
    {
        if (! $event->requiresVenueConfirmation()) {
            return $this->formatEvaluation(0, 0, []);
        }

        return $this->evaluate($event);
    }

    /**
     * @return array{
     *     score: int,
     *     total_checks: int,
     *     passed_checks: int,
     *     missing_count: int,
     *     is_submittable: bool,
     *     issues: list<array{section: string, section_index: int, section_title: string, field: string, target_id: string, label: string, message: string}>,
     *     grouped_issues: array<string, list<array{section: string, section_index: int, section_title: string, field: string, target_id: string, label: string, message: string}>>
     * }
     */
    public function evaluate(Event $event): array
    {
        $event->loadMissing(['canonicalPpaPacket.attachments', 'venue']);

        $issues = [];
        $totalChecks = 0;
        $passedChecks = 0;
        $packet = $event->canonicalPpaPacket;

        $totalChecks++;
        if (! $packet) {
            $issues[] = [
                'section' => 'basics',
                'section_index' => 1,
                'section_title' => 'Basics',
                'field' => 'canonical_packet',
                'target_id' => 'draft-title',
                'label' => 'canonical PPA packet',
                'message' => 'Canonical PPA packet is required.',
            ];

            return $this->formatEvaluation($totalChecks, $passedChecks, $issues);
        }
        $passedChecks++;

        $totalChecks++;
        if (! in_array($packet->activity_role, [CanonicalPpaPacket::ROLE_ORGANIZER, CanonicalPpaPacket::ROLE_CO_ORGANIZER], true)) {
            $issues[] = [
                'section' => 'basics',
                'section_index' => 1,
                'section_title' => 'Basics',
                'field' => 'activity_role',
                'target_id' => 'draft-title',
                'label' => 'valid organizer activity role',
                'message' => 'Activity role must be organizer or co-organizer.',
            ];
        } else {
            $passedChecks++;
        }

        $totalChecks++;
        if ($packet->event_participation_scope_id !== null) {
            $issues[] = [
                'section' => 'basics',
                'section_index' => 1,
                'section_title' => 'Basics',
                'field' => 'event_participation_scope_id',
                'target_id' => 'draft-title',
                'label' => 'unscoped organizer packet',
                'message' => 'Organizer packet must not be bound to a participant scope.',
            ];
        } else {
            $passedChecks++;
        }

        $basicsFields = [
            'proposal_category' => ['target_id' => 'draft-category', 'label' => 'PPA category', 'message' => 'Select a proposal category.'],
            'title' => ['target_id' => 'draft-title', 'label' => 'PPA title', 'message' => 'Event title is required.'],
            'proponent_name' => ['target_id' => 'draft-title', 'label' => 'proponent name', 'message' => 'Proponent name is required.'],
            'proponent_position' => ['target_id' => 'draft-proponent-position', 'label' => 'proponent position', 'message' => 'Proponent position is required.'],
            'proponent_organization' => ['target_id' => 'draft-title', 'label' => 'proponent organization', 'message' => 'Proponent organization is required.'],
            'proponent_email' => ['target_id' => 'draft-title', 'label' => 'proponent email address', 'message' => 'Proponent email is required.'],
            'proponent_contact_number' => ['target_id' => 'draft-proponent-contact', 'label' => 'proponent contact number', 'message' => 'Proponent contact number is required.'],
            'implementing_office' => ['target_id' => 'draft-implementing-office', 'label' => 'implementing office', 'message' => 'Implementing office is required.'],
        ];

        foreach ($basicsFields as $field => $meta) {
            $totalChecks++;
            if (blank(data_get($packet, $field))) {
                $issues[] = [
                    'section' => 'basics',
                    'section_index' => 1,
                    'section_title' => 'Basics',
                    'field' => $field,
                    'target_id' => $meta['target_id'],
                    'label' => $meta['label'],
                    'message' => $meta['message'],
                ];
            } else {
                $passedChecks++;
            }
        }

        $totalChecks++;
        if (blank($packet->start_date) || blank($packet->end_date)) {
            $issues[] = [
                'section' => 'schedule_and_venue',
                'section_index' => 2,
                'section_title' => 'Schedule and venue',
                'field' => 'dates',
                'target_id' => blank($packet->start_date) ? 'draft-start-date' : 'draft-end-date',
                'label' => 'duration or date',
                'message' => 'Start date and end date are required.',
            ];
        } else {
            $passedChecks++;
        }

        $totalChecks++;
        if (blank($packet->location_type) || ! in_array($packet->location_type, ['on-campus', 'off-campus', 'online'], true)) {
            $issues[] = [
                'section' => 'schedule_and_venue',
                'section_index' => 2,
                'section_title' => 'Schedule and venue',
                'field' => 'location_type',
                'target_id' => 'draft-location-type',
                'label' => 'venue classification',
                'message' => 'Select a venue classification (On-campus, Off-campus, or Online).',
            ];
        } else {
            $passedChecks++;
        }

        if ($packet->location_type === 'on-campus') {
            $totalChecks++;
            if (! $packet->venue_id) {
                if (blank($packet->location_details)) {
                    $issues[] = [
                        'section' => 'schedule_and_venue',
                        'section_index' => 2,
                        'section_title' => 'Schedule and venue',
                        'field' => 'venue_id',
                        'target_id' => 'draft-venue-id',
                        'label' => 'campus venue name',
                        'message' => 'Select an on-campus venue or record a campus location name.',
                    ];
                } else {
                    $passedChecks++;
                }
            } elseif (! $packet->venue()->active()->exists()) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'venue_id',
                    'target_id' => 'draft-venue-id',
                    'label' => 'active on-campus venue',
                    'message' => 'Selected on-campus venue is not currently active.',
                ];
            } else {
                $passedChecks++;
            }
        } elseif ($packet->location_type === 'off-campus') {
            $totalChecks++;
            if ($packet->venue_id || blank($packet->location_details)) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'location_details',
                    'target_id' => 'draft-location-details',
                    'label' => 'off-campus venue name and address',
                    'message' => 'Provide the off-campus venue name and complete address.',
                ];
            } else {
                $passedChecks++;
            }

            $totalChecks++;
            if (blank($packet->venue_confirmation_office)) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'venue_confirmation_office',
                    'target_id' => 'draft-confirmation-office',
                    'label' => 'confirming office',
                    'message' => 'Record the external confirming office.',
                ];
            } else {
                $passedChecks++;
            }

            $totalChecks++;
            if (! $packet->venue_confirmation_date) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'venue_confirmation_date',
                    'target_id' => 'draft-confirmation-date',
                    'label' => 'confirmation date',
                    'message' => 'Record the venue confirmation date.',
                ];
            } elseif ($packet->venue_confirmation_date->isFuture()) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'venue_confirmation_date',
                    'target_id' => 'draft-confirmation-date',
                    'label' => 'valid confirmation date',
                    'message' => 'Confirmation date cannot be in the future.',
                ];
            } else {
                $passedChecks++;
            }

            $totalChecks++;
            if (! $this->hasStoredAttachment($packet, CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION, $packet->venue_confirmation_document_path)) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'venue_confirmation_document',
                    'target_id' => 'draft-venue-evidence',
                    'label' => 'venue confirmation evidence',
                    'message' => 'Upload the private venue confirmation evidence file.',
                ];
            } else {
                $passedChecks++;
            }
        } elseif ($packet->location_type === 'online') {
            $totalChecks++;
            if ($packet->venue_id) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'venue_id',
                    'target_id' => 'draft-location-details',
                    'label' => 'online location without campus venue',
                    'message' => 'Online proposals must not reference a campus venue.',
                ];
            } elseif (blank($packet->location_details)) {
                $issues[] = [
                    'section' => 'schedule_and_venue',
                    'section_index' => 2,
                    'section_title' => 'Schedule and venue',
                    'field' => 'location_details',
                    'target_id' => 'draft-location-details',
                    'label' => 'online platform or link',
                    'message' => 'Provide online platform details or link.',
                ];
            } else {
                $passedChecks++;
            }
        }

        $programFields = [
            'rationale' => ['target_id' => 'draft-rationale', 'label' => 'rationale', 'message' => 'Rationale / Significance is required.'],
            'general_objective' => ['target_id' => 'draft-general-objective', 'label' => 'general objective', 'message' => 'General objective is required.'],
            'methodology' => ['target_id' => 'draft-methodology', 'label' => 'methodology', 'message' => 'Methodology / Mechanics is required.'],
            'expected_output' => ['target_id' => 'draft-expected-output', 'label' => 'expected output', 'message' => 'Expected output is required.'],
            'beneficiaries' => ['target_id' => 'draft-beneficiaries', 'label' => 'target beneficiaries or participants', 'message' => 'Target beneficiaries description is required.'],
        ];

        foreach ($programFields as $field => $meta) {
            $totalChecks++;
            if (blank(data_get($packet, $field))) {
                $issues[] = [
                    'section' => 'program_and_beneficiaries',
                    'section_index' => 3,
                    'section_title' => 'Program and beneficiaries',
                    'field' => $field,
                    'target_id' => $meta['target_id'],
                    'label' => $meta['label'],
                    'message' => $meta['message'],
                ];
            } else {
                $passedChecks++;
            }
        }

        $totalChecks++;
        if (! $this->hasNonBlankList($packet->specific_objectives)) {
            $issues[] = [
                'section' => 'program_and_beneficiaries',
                'section_index' => 3,
                'section_title' => 'Program and beneficiaries',
                'field' => 'specific_objectives',
                'target_id' => 'draft-specific-objectives',
                'label' => 'specific objectives',
                'message' => 'At least one specific objective is required.',
            ];
        } else {
            $passedChecks++;
        }

        $proposalCategory = $packet->proposal_category ?: $event->proposal_category;
        $totalChecks++;
        if ($proposalCategory === 'activity') {
            if (! $this->hasCompleteProgramActivities($packet->program_activities)) {
                $issues[] = [
                    'section' => 'program_and_beneficiaries',
                    'section_index' => 3,
                    'section_title' => 'Program and beneficiaries',
                    'field' => 'program_activities',
                    'target_id' => 'draft-activities',
                    'label' => 'program of activities',
                    'message' => 'Complete program of activities with time, activity, and person-in-charge is required.',
                ];
            } else {
                $passedChecks++;
            }
        } else {
            if (! $this->hasNonBlankList($packet->major_activities)) {
                $issues[] = [
                    'section' => 'program_and_beneficiaries',
                    'section_index' => 3,
                    'section_title' => 'Program and beneficiaries',
                    'field' => 'major_activities',
                    'target_id' => 'draft-activities',
                    'label' => 'major activities',
                    'message' => 'At least one major activity is required.',
                ];
            } else {
                $passedChecks++;
            }
        }

        $totalChecks++;
        if (blank($packet->source_of_fund)) {
            $issues[] = [
                'section' => 'budget',
                'section_index' => 4,
                'section_title' => 'Budget',
                'field' => 'source_of_fund',
                'target_id' => 'draft-source-of-fund',
                'label' => 'source of fund',
                'message' => 'Source of fund is required.',
            ];
        } else {
            $passedChecks++;
        }

        $totalChecks++;
        if ($packet->budget_cost === null || (float) $packet->budget_cost < 0) {
            $issues[] = [
                'section' => 'budget',
                'section_index' => 4,
                'section_title' => 'Budget',
                'field' => 'budget_cost',
                'target_id' => 'draft-budget-cost',
                'label' => 'budget cost',
                'message' => 'Total budget cost is required.',
            ];
        } else {
            $passedChecks++;
        }

        $totalChecks++;
        if (! $this->hasCompleteBudgetBreakdown($packet->budget_breakdown)) {
            $issues[] = [
                'section' => 'budget',
                'section_index' => 4,
                'section_title' => 'Budget',
                'field' => 'budget_breakdown',
                'target_id' => 'draft-budget-breakdown',
                'label' => 'budget breakdown',
                'message' => 'Itemized budget breakdown with items and amounts is required.',
            ];
        } else {
            $passedChecks++;
        }

        return $this->formatEvaluation($totalChecks, $passedChecks, $issues);
    }

    /**
     * @param  list<array{section: string, section_index: int, section_title: string, field: string, target_id: string, label: string, message: string}>  $issues
     * @return array{
     *     score: int,
     *     total_checks: int,
     *     passed_checks: int,
     *     missing_count: int,
     *     is_submittable: bool,
     *     issues: list<array{section: string, section_index: int, section_title: string, field: string, target_id: string, label: string, message: string}>,
     *     grouped_issues: array<string, list<array{section: string, section_index: int, section_title: string, field: string, target_id: string, label: string, message: string}>>
     * }
     */
    private function formatEvaluation(int $totalChecks, int $passedChecks, array $issues): array
    {
        $groupedIssues = [
            'basics' => [],
            'schedule_and_venue' => [],
            'program_and_beneficiaries' => [],
            'budget' => [],
            'evaluation_and_supporting_files' => [],
            'review_and_submit' => [],
        ];

        foreach ($issues as $issue) {
            $sec = $issue['section'] ?? 'basics';
            if (isset($groupedIssues[$sec])) {
                $groupedIssues[$sec][] = $issue;
            }
        }

        $score = $totalChecks > 0 ? (int) round(($passedChecks / $totalChecks) * 100) : 100;

        return [
            'score' => $score,
            'total_checks' => $totalChecks,
            'passed_checks' => $passedChecks,
            'missing_count' => count($issues),
            'is_submittable' => count($issues) === 0,
            'submittable' => count($issues) === 0,
            'issues' => $issues,
            'grouped_issues' => $groupedIssues,
        ];
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
        $packet->loadMissing('attachments');
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

        if (! $this->hasStoredAttachment($packet, CanonicalPpaAttachment::TYPE_SUPPORTING, $packet->supporting_document_path, allowMissingStorageInTests: true)) {
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
                && (! blank($activity['person_in_charge'] ?? null) || ! blank($activity['persons_involved'] ?? null))
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

    private function hasStoredAttachment(
        CanonicalPpaPacket $packet,
        string $type,
        ?string $legacyPath,
        bool $allowMissingStorageInTests = false,
    ): bool {
        $attachment = $packet->attachments->firstWhere('attachment_type', $type);
        if (blank($legacyPath)) {
            return false;
        }

        $attachmentPath = $attachment?->getRawOriginal('path');
        if ($attachmentPath !== null && $attachmentPath !== $legacyPath) {
            return false;
        }

        $path = $attachmentPath ?: $legacyPath;

        return filled($path) && (($allowMissingStorageInTests && app()->runningUnitTests()) || Storage::exists($path));
    }
}
