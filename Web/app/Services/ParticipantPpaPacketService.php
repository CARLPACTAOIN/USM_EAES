<?php

namespace App\Services;

use App\Models\CanonicalPpaAttachment;
use App\Models\CanonicalPpaPacket;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ParticipantPpaPacketService
{
    public function __construct(
        private readonly EventProposalSubmissionGuard $submissionGuard,
        private readonly EventNotificationService $notifications,
        private readonly ProposalWorkflowService $workflows,
        private readonly LegacyProposalCompatibilityProjection $legacyProjection,
    ) {}

    public function getOrCreatePacket(EventParticipationScope $scope, ?User $actor = null): CanonicalPpaPacket
    {
        $this->assertParticipantScope($scope);

        $scope->loadMissing([
            'event.organization',
            'event.venue',
            'event.canonicalPpaPacket',
            'organization',
            'canonicalPpaPacket',
        ]);

        $packet = $scope->relationLoaded('canonicalPpaPacket') && $scope->canonicalPpaPacket
            ? $scope->canonicalPpaPacket
            : CanonicalPpaPacket::where('event_participation_scope_id', $scope->id)->first();

        if (! $packet) {
            $packet = $this->initializePacket($scope, $actor);
        } else {
            $this->syncParentContext($packet, $scope->event);
            $packet->save();
        }

        $scope->setRelation('canonicalPpaPacket', $packet);

        return $packet;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(
        EventParticipationScope $scope,
        User $actor,
        array $data = [],
        mixed $supportingDocument = null,
    ): CanonicalPpaPacket {
        $packet = $this->getOrCreatePacket($scope, $actor);
        $event = $scope->event;

        $packet->activity_role = CanonicalPpaPacket::ROLE_PARTICIPANT;
        $packet->prepared_by_user_id = $packet->prepared_by_user_id ?: $actor->id;

        if (array_key_exists('proponent_name', $data)) {
            $packet->proponent_name = $this->blankToNull($data['proponent_name']);
        }
        if (array_key_exists('proponent_position', $data)) {
            $packet->proponent_position = $this->blankToNull($data['proponent_position']);
        }
        if (array_key_exists('proponent_organization', $data)) {
            $packet->proponent_organization = $this->blankToNull($data['proponent_organization']) ?: $scope->displayName();
        }
        if (array_key_exists('proponent_email', $data)) {
            $packet->proponent_email = $this->blankToNull($data['proponent_email']);
        }
        if (array_key_exists('proponent_contact_number', $data)) {
            $packet->proponent_contact_number = $this->blankToNull($data['proponent_contact_number']);
        }
        if (array_key_exists('delegate_count', $data)) {
            $packet->delegate_count = filled($data['delegate_count']) ? (int) $data['delegate_count'] : null;
        }
        if (array_key_exists('beneficiaries', $data)) {
            $packet->beneficiaries = $this->blankToNull($data['beneficiaries']);
        }
        if (array_key_exists('source_of_fund', $data)) {
            $packet->source_of_fund = $this->blankToNull($data['source_of_fund']);
        }
        if (array_key_exists('budget_cost', $data)) {
            $packet->budget_cost = filled($data['budget_cost']) ? (float) $data['budget_cost'] : null;
        }
        if (array_key_exists('budget_breakdown', $data)) {
            $packet->budget_breakdown = $this->normalizeBudgetBreakdown($data['budget_breakdown']);
        }
        if (array_key_exists('rationale', $data)) {
            $packet->rationale = $this->blankToNull($data['rationale']);
        }
        if (array_key_exists('specific_objectives', $data)) {
            $packet->specific_objectives = $this->normalizeList($data['specific_objectives']);
        }
        if (array_key_exists('expected_output', $data)) {
            $packet->expected_output = $this->blankToNull($data['expected_output']);
        }

        // Handle supporting document upload
        if ($supportingDocument instanceof UploadedFile) {
            $storedPath = $supportingDocument->store('participant-ppa-documents');
            $originalName = $supportingDocument->getClientOriginalName();

            $packet->supporting_document_path = $storedPath;
            $packet->supporting_document_original_name = $originalName;

            $scope->update([
                'ppa_softcopy_path' => $storedPath,
                'ppa_softcopy_original_name' => $originalName,
            ]);
        }

        // Re-assert parent context to strictly prevent duplicate venue booking or dates deviation
        $this->syncParentContext($packet, $event);

        $packet->save();
        $this->syncSupportingAttachment($packet, $actor);

        if (in_array($scope->ppa_status, [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REVISION_REQUESTED], true)) {
            $scopeUpdates = [];
            if ($packet->supporting_document_path && ! $scope->ppa_softcopy_path) {
                $scopeUpdates['ppa_softcopy_path'] = $packet->supporting_document_path;
                $scopeUpdates['ppa_softcopy_original_name'] = $packet->supporting_document_original_name;
            }
            if (! empty($scopeUpdates)) {
                $scope->update($scopeUpdates);
            }
        }

        return $packet->fresh(['event', 'participationScope', 'venue']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submitPacket(
        EventParticipationScope $scope,
        User $actor,
        array $data = [],
        mixed $supportingDocument = null,
    ): CanonicalPpaPacket {
        $this->assertParticipantScope($scope);

        if (! $scope->ppa_required) {
            throw new DomainException('This participant scope does not require a PPA.');
        }

        if (! in_array($scope->ppa_status, [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REVISION_REQUESTED], true)) {
            throw new DomainException('Only draft or revision-requested participant PPAs can be submitted.');
        }

        // Save any submitted form payload or files into draft
        $packet = $this->saveDraft($scope, $actor, $data, $supportingDocument);

        // Strictly assert all participant-specific required fields
        $this->submissionGuard->assertParticipantSubmittable($packet);

        DB::transaction(function () use ($scope, $actor, $packet): void {
            // Single-entry participant submission
            $scope->update([
                'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'review_remarks' => null,
                'ppa_softcopy_path' => $packet->supporting_document_path,
                'ppa_softcopy_original_name' => $packet->supporting_document_original_name,
            ]);

            $this->workflows->bindParticipantSubmission(
                $scope->fresh(['event.organization', 'organization']),
                $actor
            );

            $this->legacyProjection->syncParticipantScope($scope->fresh());
        });

        $this->notifications->notifyParticipantScopeSubmitted(
            $scope->fresh(['event.organization', 'organization', 'delegatedOrganization'])
        );

        return $packet->fresh(['event', 'participationScope', 'venue']);
    }

    private function initializePacket(EventParticipationScope $scope, ?User $actor = null): CanonicalPpaPacket
    {
        $event = $scope->event;
        $parentPacket = $event->canonicalPpaPacket;

        $packet = new CanonicalPpaPacket([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT,
            'title' => $parentPacket?->title ?: $event->title,
            'proposal_category' => $parentPacket?->proposal_category ?: ($event->proposal_category ?: 'activity'),
            'proponent_organization' => $scope->displayName(),
            'proponent_name' => $actor?->name,
            'proponent_email' => $actor?->email,
            'prepared_by_user_id' => $actor?->id,
            'supporting_document_path' => $scope->ppa_softcopy_path,
            'supporting_document_original_name' => $scope->ppa_softcopy_original_name,
        ]);

        $this->syncParentContext($packet, $event);
        $packet->save();

        return $packet;
    }

    private function syncParentContext(CanonicalPpaPacket $packet, Event $event): void
    {
        $parentPacket = $event->canonicalPpaPacket;

        $packet->event_id = $event->id;
        $packet->activity_role = CanonicalPpaPacket::ROLE_PARTICIPANT;
        $packet->title = $parentPacket?->title ?: $event->title;
        $packet->proposal_category = $parentPacket?->proposal_category ?: ($event->proposal_category ?: 'activity');
        $packet->start_date = $parentPacket?->start_date ?: $event->start_date;
        $packet->end_date = $parentPacket?->end_date ?: $event->end_date;
        $packet->location_type = $parentPacket?->location_type ?: $event->location_type;
        $packet->venue_id = $parentPacket?->venue_id ?: $event->venue_id;
        $packet->location_details = $parentPacket?->location_details ?: $event->location_details;
        $packet->venue_confirmation_office = $parentPacket?->venue_confirmation_office ?: $event->venue_confirmation_office;
        $packet->venue_confirmation_date = $parentPacket?->venue_confirmation_date ?: $event->venue_confirmation_date;
        $packet->venue_confirmation_reference_number = $parentPacket?->venue_confirmation_reference_number ?: $event->venue_confirmation_reference_number;
        // Venue evidence belongs to the organizer's private packet. Participant packets inherit
        // the approved venue context without copying the organizer's attachment metadata.
        $packet->venue_confirmation_document_path = null;
        $packet->venue_confirmation_document_original_name = null;
        $packet->schedule_summary = $parentPacket?->schedule_summary;
        $packet->venue_summary = $parentPacket?->venue_summary;
    }

    private function syncSupportingAttachment(CanonicalPpaPacket $packet, User $actor): void
    {
        if (blank($packet->supporting_document_path)) {
            return;
        }

        $path = $packet->supporting_document_path;

        $packet->attachments()->updateOrCreate(
            ['attachment_type' => CanonicalPpaAttachment::TYPE_SUPPORTING],
            [
                'path' => $path,
                'original_name' => $packet->supporting_document_original_name ?: basename($path),
                'mime_type' => Storage::exists($path) ? Storage::mimeType($path) : null,
                'size_bytes' => Storage::exists($path) ? Storage::size($path) : null,
                'uploaded_by_user_id' => $actor->id,
            ]
        );
    }

    /**
     * @return list<array{item: string, amount: float}>
     */
    private function normalizeBudgetBreakdown(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $item = trim((string) ($entry['item'] ?? ''));
            $amount = $entry['amount'] ?? null;

            if ($item === '' && ! is_numeric($amount)) {
                continue;
            }

            $normalized[] = [
                'item' => $item,
                'amount' => is_numeric($amount) ? (float) $amount : 0.0,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $items): array
    {
        if (is_string($items)) {
            $items = preg_split('/\r\n|\r|\n/', $items) ?: [];
        }

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertParticipantScope(EventParticipationScope $scope): void
    {
        if ($scope->participation_role !== EventParticipationScope::ROLE_PARTICIPANT) {
            throw new DomainException('Participant packets can only be created for invited participant scopes.');
        }
    }
}
