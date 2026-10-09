<?php

namespace App\Services;

use App\Models\CanonicalPpaAttachment;
use App\Models\CanonicalPpaPacket;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Models\Venue;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrganizerProposalDraftService
{
    private const RECOGNIZED_ORGANIZER_TYPES = [
        'society',
        'usg',
        'lsg',
        'academic_unit',
        'administrative_office',
        'non_academic_org',
        'hr_unit',
    ];

    /** @var list<string> */
    private const EVENT_FIELDS = [
        'title',
        'proposal_category',
        'start_date',
        'end_date',
        'location_type',
        'location_details',
        'venue_id',
        'venue_confirmation_office',
        'venue_confirmation_date',
        'venue_confirmation_reference_number',
        'target_demographics',
        'budget_allocations',
        'resolution_number',
    ];

    /** @var list<string> */
    private const PACKET_FIELDS = [
        'proposal_category',
        'title',
        'proponent_name',
        'proponent_position',
        'proponent_organization',
        'proponent_email',
        'proponent_contact_number',
        'implementing_office',
        'collaborating_office',
        'source_of_fund',
        'budget_cost',
        'start_date',
        'end_date',
        'location_type',
        'venue_id',
        'location_details',
        'venue_confirmation_office',
        'venue_confirmation_date',
        'venue_confirmation_reference_number',
        'rationale',
        'general_objective',
        'specific_objectives',
        'methodology',
        'major_activities',
        'program_activities',
        'expected_output',
        'beneficiaries',
        'delegate_count',
        'budget_breakdown',
        'schedule_summary',
        'venue_summary',
    ];

    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly ProposalGovernanceService $governance,
    ) {}

    public function start(User $actor): Event
    {
        $organization = $this->authorizedOrganization($actor);
        $context = $this->governance->resolveGovernanceContext($organization, $actor);

        if (! $context['school_year_id']) {
            throw new DomainException('An active School Year is required before starting a proposal draft.');
        }

        return DB::transaction(function () use ($actor, $context, $organization): Event {
            $snapshots = $context['snapshots'];
            $event = Event::create([
                'organization_id' => $organization->id,
                'school_year_id' => $context['school_year_id'],
                'department_id' => $context['department']?->id,
                'event_domain' => $organization->type === 'hr_unit' ? 'hr' : 'student_affairs',
                'status' => Event::STATUS_DRAFT,
                'college_name_snapshot' => $snapshots['college_name_snapshot'],
                'department_name_snapshot' => $snapshots['department_name_snapshot'],
                'program_name_snapshot' => $snapshots['program_name_snapshot'],
                'organization_name_snapshot' => $snapshots['organization_name_snapshot'],
                'proponent_name_snapshot' => $snapshots['proponent_name_snapshot'],
                'proponent_user_id' => $snapshots['proponent_user_id'],
                'org_head_user_id' => $snapshots['org_head_user_id'],
                'dept_chair_user_id' => $snapshots['dept_chair_user_id'],
                'college_dean_user_id' => $snapshots['college_dean_user_id'],
                'governance_snapshots' => $snapshots,
            ]);

            $event->canonicalPpaPacket()->create([
                'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
                'proponent_name' => $context['proponent']?->name ?? $actor->name,
                'proponent_organization' => $organization->name,
                'proponent_email' => $context['proponent']?->email ?? $actor->email,
                'prepared_by_user_id' => $actor->id,
            ]);

            return $event->fresh(['canonicalPpaPacket.preparedBy', 'organization', 'schoolYear']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Event $event, User $actor, array $data): Event
    {
        $this->authorize($event, $actor);

        if ($event->status !== Event::STATUS_DRAFT) {
            throw new DomainException('Only a draft proposal can be autosaved.');
        }

        return DB::transaction(function () use ($actor, $data, $event): Event {
            $eventUpdates = $this->eventUpdates($data);

            if (array_key_exists('location_type', $eventUpdates)) {
                $locType = $eventUpdates['location_type'];
                if ($locType === 'off-campus' || $locType === 'online') {
                    $eventUpdates['venue_id'] = null;
                }
                if ($locType === 'on-campus' || $locType === 'online') {
                    $eventUpdates['venue_confirmation_office'] = null;
                    $eventUpdates['venue_confirmation_date'] = null;
                    $eventUpdates['venue_confirmation_reference_number'] = null;
                }
            }

            if (array_key_exists('venue_id', $eventUpdates) && $eventUpdates['venue_id']) {
                $venue = Venue::active()->find($eventUpdates['venue_id']);
                if ($venue && empty($eventUpdates['location_details'])) {
                    $eventUpdates['location_details'] = $venue->displayName();
                }
            }

            if (Arr::has($data, 'evaluation_questions')) {
                $demographics = $event->target_demographics ?? [];
                $demographics['evaluation_questions'] = $data['evaluation_questions'];
                $eventUpdates['target_demographics'] = $demographics;
            }
            if (Arr::has($data, 'event_days')) {
                $demographics = $eventUpdates['target_demographics'] ?? ($event->target_demographics ?? []);
                $demographics['event_days'] = $data['event_days'];
                $eventUpdates['target_demographics'] = $demographics;
            }

            // Handle file uploads for event:
            if (isset($data['proposal_document']) && $data['proposal_document'] instanceof UploadedFile) {
                $file = $data['proposal_document'];
                $path = $file->store('events/proposals');
                $eventUpdates['proposal_document_path'] = $path;
                $eventUpdates['proposal_document_original_name'] = $file->getClientOriginalName();
            } elseif ($this->booleanFlag($data, 'clear_proposal_document')) {
                if ($event->proposal_document_path) {
                    Storage::delete($event->proposal_document_path);
                }
                $eventUpdates['proposal_document_path'] = null;
                $eventUpdates['proposal_document_original_name'] = null;
            }

            if (isset($data['venue_confirmation_document']) && $data['venue_confirmation_document'] instanceof UploadedFile) {
                $file = $data['venue_confirmation_document'];
                $path = $file->store('events/venue-confirmations');
                $eventUpdates['venue_confirmation_document_path'] = $path;
                $eventUpdates['venue_confirmation_document_original_name'] = $file->getClientOriginalName();
            } elseif ($this->booleanFlag($data, 'clear_venue_confirmation_document')) {
                if ($event->venue_confirmation_document_path) {
                    Storage::delete($event->venue_confirmation_document_path);
                }
                $eventUpdates['venue_confirmation_document_path'] = null;
                $eventUpdates['venue_confirmation_document_original_name'] = null;
            }

            if ($eventUpdates !== []) {
                $event->update($eventUpdates);
            }

            $packet = $event->canonicalPpaPacket()->firstOrFail();
            $packetUpdates = $this->packetUpdates($data, $eventUpdates);

            if (array_key_exists('venue_confirmation_document_path', $eventUpdates)) {
                $packetUpdates['venue_confirmation_document_path'] = $eventUpdates['venue_confirmation_document_path'];
                $packetUpdates['venue_confirmation_document_original_name'] = $eventUpdates['venue_confirmation_document_original_name'];
            }

            if (isset($data['supporting_document']) && $data['supporting_document'] instanceof UploadedFile) {
                $file = $data['supporting_document'];
                $path = $file->store('events/supporting');
                $packetUpdates['supporting_document_path'] = $path;
                $packetUpdates['supporting_document_original_name'] = $file->getClientOriginalName();
            } elseif ($this->booleanFlag($data, 'clear_supporting_document')) {
                if ($packet->supporting_document_path) {
                    Storage::delete($packet->supporting_document_path);
                }
                $packetUpdates['supporting_document_path'] = null;
                $packetUpdates['supporting_document_original_name'] = null;
            }

            if ($packetUpdates !== []) {
                $packet->update($packetUpdates);
            }

            $this->syncCanonicalAttachments($packet, $event, $actor);

            return $event->fresh([
                'canonicalPpaPacket.attachments.uploadedBy',
                'canonicalPpaPacket.preparedBy',
                'canonicalPpaPacket.venue',
                'organization',
                'schoolYear',
                'venue',
            ]);
        });
    }

    public function view(Event $event, User $actor): Event
    {
        $this->authorize($event, $actor);

        return $event->load([
            'canonicalPpaPacket.attachments.uploadedBy',
            'canonicalPpaPacket.preparedBy',
            'canonicalPpaPacket.venue',
            'organization',
            'schoolYear',
            'venue',
        ]);
    }

    private function authorize(Event $event, User $actor): void
    {
        $organization = $this->authorizedOrganization($actor);

        if ($event->organization_id !== $organization->id) {
            throw new AuthorizationException('This proposal draft is outside your organizer scope.');
        }
    }

    /** @param array<string, mixed> $data */
    private function booleanFlag(array $data, string $key): bool
    {
        return filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function authorizedOrganization(User $actor): Organization
    {
        if (! $actor->can('create-proposals') || $actor->hasRole('Super Admin (OSA)')) {
            throw new AuthorizationException('Only an authorized organizer can manage proposal drafts.');
        }

        $organizationId = $this->adminScopes->forUser($actor)?->organizationId;
        $organization = $organizationId ? Organization::find($organizationId) : null;

        if (! $organization || ! in_array($organization->type, self::RECOGNIZED_ORGANIZER_TYPES, true)) {
            throw new AuthorizationException('An active recognized organizer context is required to manage proposal drafts.');
        }

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function eventUpdates(array $data): array
    {
        $updates = [];

        foreach (self::EVENT_FIELDS as $field) {
            if (Arr::has($data, $field)) {
                $updates[$field] = $this->normalize($field, $data[$field]);
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $eventUpdates
     * @return array<string, mixed>
     */
    private function packetUpdates(array $data, array $eventUpdates): array
    {
        $packet = is_array($data['canonical_packet'] ?? null) ? $data['canonical_packet'] : [];

        if (Arr::has($packet, 'activity_role') || Arr::has($data, 'activity_role')) {
            throw new DomainException('The organizer packet role cannot be changed from the proposal draft path.');
        }

        $updates = [];

        foreach (self::PACKET_FIELDS as $field) {
            if (Arr::has($packet, $field)) {
                $updates[$field] = $this->normalize($field, $packet[$field]);
            } elseif (Arr::has($data, $field)) {
                $updates[$field] = $this->normalize($field, $data[$field]);
            }
        }

        foreach (['title', 'proposal_category', 'start_date', 'end_date', 'location_type', 'venue_id', 'location_details', 'venue_confirmation_office', 'venue_confirmation_date', 'venue_confirmation_reference_number'] as $field) {
            if (array_key_exists($field, $eventUpdates)) {
                $updates[$field] = $eventUpdates[$field];
            }
        }

        return $updates;
    }

    private function syncCanonicalAttachments(CanonicalPpaPacket $packet, Event $event, User $actor): void
    {
        $this->syncCanonicalAttachment(
            packet: $packet,
            type: CanonicalPpaAttachment::TYPE_PROPOSAL,
            path: $event->proposal_document_path,
            originalName: $event->proposal_document_original_name,
            actor: $actor,
        );

        $this->syncCanonicalAttachment(
            packet: $packet,
            type: CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION,
            path: $event->location_type === 'off-campus' ? ($event->venue_confirmation_document_path ?? $packet->venue_confirmation_document_path) : null,
            originalName: $event->location_type === 'off-campus' ? ($event->venue_confirmation_document_original_name ?? $packet->venue_confirmation_document_original_name) : null,
            actor: $actor,
        );

        $this->syncCanonicalAttachment(
            packet: $packet,
            type: CanonicalPpaAttachment::TYPE_SUPPORTING,
            path: $packet->supporting_document_path,
            originalName: $packet->supporting_document_original_name,
            actor: $actor,
        );
    }

    private function syncCanonicalAttachment(
        CanonicalPpaPacket $packet,
        string $type,
        ?string $path,
        ?string $originalName,
        User $actor,
    ): void {
        $query = $packet->attachments()->where('attachment_type', $type);

        if (blank($path)) {
            $query->delete();

            return;
        }

        $packet->attachments()->updateOrCreate(
            ['attachment_type' => $type],
            [
                'path' => $path,
                'original_name' => $originalName ?: basename($path),
                'mime_type' => Storage::exists($path) ? Storage::mimeType($path) : null,
                'size_bytes' => Storage::exists($path) ? Storage::size($path) : null,
                'uploaded_by_user_id' => $actor->id,
            ]
        );
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if (in_array($field, ['target_demographics', 'budget_allocations', 'program_activities', 'budget_breakdown', 'schedule_summary', 'venue_summary'], true)) {
            return $value;
        }

        if ($field === 'delegate_count') {
            return is_numeric($value) ? (int) $value : null;
        }

        if ($field === 'budget_cost') {
            return is_numeric($value) ? (float) $value : null;
        }

        if (in_array($field, ['specific_objectives', 'major_activities'], true)) {
            if (is_array($value)) {
                return array_values(array_filter($value, fn ($v) => ! blank($v)));
            }
            if (is_string($value)) {
                $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value)));

                return array_values($lines);
            }

            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
