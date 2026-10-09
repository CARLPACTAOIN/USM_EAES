<?php

namespace App\Services;

use App\Models\CanonicalPpaPacket;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($data, $event): Event {
            $eventUpdates = $this->eventUpdates($data);
            if ($eventUpdates !== []) {
                $event->update($eventUpdates);
            }

            $packet = $event->canonicalPpaPacket()->firstOrFail();
            $packetUpdates = $this->packetUpdates($data, $eventUpdates);
            if ($packetUpdates !== []) {
                $packet->update($packetUpdates);
            }

            return $event->fresh(['canonicalPpaPacket.preparedBy', 'organization', 'schoolYear']);
        });
    }

    public function view(Event $event, User $actor): Event
    {
        $this->authorize($event, $actor);

        return $event->load(['canonicalPpaPacket.preparedBy', 'organization', 'schoolYear']);
    }

    private function authorize(Event $event, User $actor): void
    {
        $organization = $this->authorizedOrganization($actor);

        if ($event->organization_id !== $organization->id) {
            throw new AuthorizationException('This proposal draft is outside your organizer scope.');
        }
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

        if (Arr::has($packet, 'activity_role')) {
            throw new DomainException('The organizer packet role cannot be changed from the proposal draft path.');
        }

        $updates = [];

        foreach (self::PACKET_FIELDS as $field) {
            if (Arr::has($packet, $field)) {
                $updates[$field] = $this->normalize($field, $packet[$field]);
            }
        }

        foreach (['title', 'proposal_category', 'start_date', 'end_date', 'location_type', 'venue_id', 'location_details', 'venue_confirmation_office', 'venue_confirmation_date', 'venue_confirmation_reference_number'] as $field) {
            if (array_key_exists($field, $eventUpdates)) {
                $updates[$field] = $eventUpdates[$field];
            }
        }

        return $updates;
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if (in_array($field, ['target_demographics', 'budget_allocations', 'specific_objectives', 'major_activities', 'program_activities', 'budget_breakdown', 'schedule_summary', 'venue_summary'], true)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
