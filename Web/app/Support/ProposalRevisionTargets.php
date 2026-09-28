<?php

namespace App\Support;

use App\Models\CanonicalPpaAttachment;
use App\Models\CanonicalPpaPacket;
use App\Models\ProposalWorkflowTask;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class ProposalRevisionTargets
{
    public const TYPE_PACKET_SECTION = 'packet_section';

    public const TYPE_ATTACHMENT_REQUIREMENT = 'attachment_requirement';

    /**
     * @return array<string, array<int, array{type: string, key: string, label: string, description: string, anchor: string}>>
     */
    public function optionsForTask(ProposalWorkflowTask $task): array
    {
        $packet = $this->packetForTask($task);

        return [
            'Packet sections' => $this->packetSectionOptions(),
            'Attachment requirements' => $this->attachmentRequirementOptions($packet),
        ];
    }

    /**
     * @return array{type: string, key: string, label: string, description: string, anchor: string}|null
     */
    public function normalizeForTask(ProposalWorkflowTask $task, ?string $type, ?string $key): ?array
    {
        if (blank($type) || blank($key)) {
            return null;
        }

        foreach ($this->optionsForTask($task) as $options) {
            foreach ($options as $option) {
                if ($option['type'] === $type && $option['key'] === $key) {
                    return $option;
                }
            }
        }

        return null;
    }

    public function targetAddressedAt(ProposalWorkflowTask $task): ?Carbon
    {
        $target = data_get($task->metadata, 'revision.target');

        if (! is_array($target)) {
            return null;
        }

        $packet = $this->packetForTask($task);

        if (! $packet) {
            return null;
        }

        if (($target['type'] ?? null) === self::TYPE_ATTACHMENT_REQUIREMENT) {
            $attachmentType = $this->attachmentTypeForKey($target['key'] ?? null);

            return $packet->attachments
                ->firstWhere('attachment_type', $attachmentType)
                ?->updated_at;
        }

        if (($target['type'] ?? null) === self::TYPE_PACKET_SECTION) {
            return $packet->updated_at;
        }

        return null;
    }

    /**
     * @param  array{type?: string|null, key?: string|null}  $target
     */
    public function targetFingerprintForTask(ProposalWorkflowTask $task, array $target): ?string
    {
        if (($target['type'] ?? null) !== self::TYPE_PACKET_SECTION) {
            return null;
        }

        $packet = $this->packetForTask($task);

        if (! $packet) {
            return null;
        }

        $fields = $this->packetSectionFields((string) ($target['key'] ?? ''));

        if ($fields === []) {
            return null;
        }

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->normalizeValue(data_get($packet, $field));
        }

        return sha1((string) json_encode($values));
    }

    public function labelForMetadata(mixed $target): ?string
    {
        if (! is_array($target)) {
            return null;
        }

        return filled($target['label'] ?? null) ? (string) $target['label'] : null;
    }

    private function packetForTask(ProposalWorkflowTask $task): ?CanonicalPpaPacket
    {
        $task->loadMissing([
            'workflowRun.event.canonicalPpaPacket.attachments',
            'workflowRun.participationScope.canonicalPpaPacket.attachments',
        ]);

        return $task->workflowRun->participationScope?->canonicalPpaPacket
            ?: $task->workflowRun->event->canonicalPpaPacket;
    }

    /**
     * @return array<int, array{type: string, key: string, label: string, description: string, anchor: string}>
     */
    private function packetSectionOptions(): array
    {
        return [
            $this->option(self::TYPE_PACKET_SECTION, 'proponent', 'Proponent and governance details', 'Names, positions, offices, contact details, and organizer context.'),
            $this->option(self::TYPE_PACKET_SECTION, 'schedule', 'Schedule and duration', 'Activity dates, time blocks, and schedule summary.'),
            $this->option(self::TYPE_PACKET_SECTION, 'venue', 'Venue or platform details', 'On-campus, off-campus, or online location and venue confirmation data.'),
            $this->option(self::TYPE_PACKET_SECTION, 'objectives', 'Rationale and objectives', 'Rationale, general objective, and specific objectives.'),
            $this->option(self::TYPE_PACKET_SECTION, 'activities', 'Methodology and activities', 'Mechanics, major activities, and program of activities.'),
            $this->option(self::TYPE_PACKET_SECTION, 'budget', 'Budget and funding', 'Source of fund, budget cost, and budget breakdown.'),
            $this->option(self::TYPE_PACKET_SECTION, 'beneficiaries', 'Expected output and beneficiaries', 'Expected output, participant groups, and beneficiary details.'),
        ];
    }

    /**
     * @return array<int, array{type: string, key: string, label: string, description: string, anchor: string}>
     */
    private function attachmentRequirementOptions(?CanonicalPpaPacket $packet): array
    {
        $options = [
            $this->option(self::TYPE_ATTACHMENT_REQUIREMENT, CanonicalPpaAttachment::TYPE_PROPOSAL, 'PPA proposal attachment', 'Uploaded proposal packet softcopy or generated packet evidence.'),
        ];

        if (
            ! $packet
            || $packet->location_type === 'off-campus'
            || filled($packet->venue_confirmation_document_path)
            || $packet->attachments->contains('attachment_type', CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION)
        ) {
            $options[] = $this->option(self::TYPE_ATTACHMENT_REQUIREMENT, CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION, 'Venue confirmation evidence', 'Private venue confirmation file, office, date, and reference details.');
        }

        if (
            ! $packet
            || $packet->isParticipant()
            || filled($packet->supporting_document_path)
            || $packet->attachments->contains('attachment_type', CanonicalPpaAttachment::TYPE_SUPPORTING)
        ) {
            $options[] = $this->option(self::TYPE_ATTACHMENT_REQUIREMENT, CanonicalPpaAttachment::TYPE_SUPPORTING, 'Supporting checklist evidence', 'Participant or checklist support document required by the packet.');
        }

        return $options;
    }

    /**
     * @return array{type: string, key: string, label: string, description: string, anchor: string}
     */
    private function option(string $type, string $key, string $label, string $description): array
    {
        return [
            'type' => $type,
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'anchor' => str($type.'-'.$key)->replace('_', '-')->toString(),
        ];
    }

    private function attachmentTypeForKey(?string $key): ?string
    {
        return match ($key) {
            CanonicalPpaAttachment::TYPE_PROPOSAL => CanonicalPpaAttachment::TYPE_PROPOSAL,
            CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION => CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION,
            CanonicalPpaAttachment::TYPE_SUPPORTING => CanonicalPpaAttachment::TYPE_SUPPORTING,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function packetSectionFields(string $key): array
    {
        return match ($key) {
            'proponent' => [
                'activity_role',
                'proposal_category',
                'title',
                'proponent_name',
                'proponent_position',
                'proponent_organization',
                'proponent_email',
                'proponent_contact_number',
                'implementing_office',
                'collaborating_office',
            ],
            'schedule' => [
                'start_date',
                'end_date',
                'schedule_summary',
                'program_activities',
            ],
            'venue' => [
                'location_type',
                'venue_id',
                'location_details',
                'venue_confirmation_office',
                'venue_confirmation_date',
                'venue_confirmation_reference_number',
                'venue_confirmation_document_original_name',
            ],
            'objectives' => [
                'rationale',
                'general_objective',
                'specific_objectives',
            ],
            'activities' => [
                'methodology',
                'major_activities',
                'program_activities',
            ],
            'budget' => [
                'source_of_fund',
                'budget_cost',
                'budget_breakdown',
            ],
            'beneficiaries' => [
                'expected_output',
                'beneficiaries',
                'delegate_count',
            ],
            default => [],
        };
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): mixed => $this->normalizeValue($item))
                ->all();
        }

        return $value;
    }
}
