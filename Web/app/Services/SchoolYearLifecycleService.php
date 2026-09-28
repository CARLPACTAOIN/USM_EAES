<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolYearLifecycleService
{
    public function __construct(
        private readonly AdminAssignmentService $assignments,
        private readonly OfficerAuthorityProjectionService $authorityProjections
    ) {}

    public function create(array $data, User $actor): SchoolYear
    {
        return DB::transaction(function () use ($data, $actor) {
            $schoolYear = SchoolYear::create([
                'label' => $data['label'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'status' => SchoolYear::STATUS_DRAFT,
            ]);

            $this->audit($schoolYear, $actor, 'create-school-year');

            return $schoolYear->fresh($this->relationships());
        });
    }

    public function activate(SchoolYear $schoolYear, User $actor): SchoolYear
    {
        return DB::transaction(function () use ($schoolYear, $actor) {
            SchoolYear::query()->lockForUpdate()->get();

            $schoolYear = SchoolYear::query()->lockForUpdate()->findOrFail($schoolYear->id);

            if ($schoolYear->status === SchoolYear::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'school_year' => 'Closed School Years cannot be activated.',
                ]);
            }

            $now = now();

            $previousActiveYears = SchoolYear::query()
                ->where('status', SchoolYear::STATUS_ACTIVE)
                ->where('id', '!=', $schoolYear->id)
                ->get();

            foreach ($previousActiveYears as $previousActiveYear) {
                $previousActiveYear->forceFill([
                    'status' => SchoolYear::STATUS_CLOSED,
                    'closed_by' => $actor->id,
                    'closed_at' => $now,
                    'transition_closed_by' => $previousActiveYear->isTransitionOpen()
                        ? $actor->id
                        : $previousActiveYear->transition_closed_by,
                    'transition_closed_at' => $previousActiveYear->isTransitionOpen()
                        ? $now
                        : $previousActiveYear->transition_closed_at,
                ])->save();
            }

            $schoolYear->update([
                'status' => SchoolYear::STATUS_ACTIVE,
                'activated_by' => $actor->id,
                'activated_at' => $now,
                'closed_by' => null,
                'closed_at' => null,
            ]);

            $this->audit($schoolYear, $actor, 'activate-school-year');

            return $schoolYear->fresh($this->relationships());
        }, attempts: 5);
    }

    public function openTransition(SchoolYear $schoolYear, array $data, User $actor): SchoolYear
    {
        return DB::transaction(function () use ($schoolYear, $data, $actor) {
            $schoolYear = SchoolYear::query()->lockForUpdate()->findOrFail($schoolYear->id);

            $this->assertActive($schoolYear);

            if ($schoolYear->isTransitionOpen()) {
                throw ValidationException::withMessages([
                    'transition' => 'This School Year already has an open transition period.',
                ]);
            }

            $schoolYear->update([
                'transition_started_at' => now(),
                'transition_ends_at' => $data['transition_ends_at'] ?? null,
                'transition_opened_by' => $actor->id,
                'transition_closed_at' => null,
                'transition_closed_by' => null,
            ]);

            $this->audit($schoolYear, $actor, 'open-school-year-transition');

            return $schoolYear->fresh($this->relationships());
        });
    }

    public function closeTransition(SchoolYear $schoolYear, User $actor, string $reason = 'Transition period closed.'): SchoolYear
    {
        return DB::transaction(function () use ($schoolYear, $actor, $reason) {
            $schoolYear = SchoolYear::query()->lockForUpdate()->findOrFail($schoolYear->id);

            $this->assertActive($schoolYear);

            if (! $schoolYear->isTransitionOpen()) {
                throw ValidationException::withMessages([
                    'transition' => 'This School Year does not have an open transition period.',
                ]);
            }

            $closedAt = now();
            $authorityClose = $this->expireOutgoingTransitionAuthority($schoolYear, $actor, $reason, $closedAt);

            $schoolYear->update([
                'transition_closed_at' => $closedAt,
                'transition_closed_by' => $actor->id,
            ]);

            foreach ($authorityClose['incoming_rosters'] as $incomingRoster) {
                $this->authorityProjections->synchronize($incomingRoster, $actor);
            }

            foreach ($authorityClose['affected_users'] as $user) {
                $this->assignments->syncUserProjection($user->fresh());
            }

            $this->audit($schoolYear, $actor, 'close-school-year-transition', [
                'reason' => $reason,
                'closed_at' => $closedAt->toJSON(),
                'expired_officer_ids' => $authorityClose['expired_officer_ids'],
                'expired_assignment_ids' => $authorityClose['expired_assignment_ids'],
                'replacement_officer_ids' => $authorityClose['replacement_officer_ids'],
                'incoming_roster_ids' => $authorityClose['incoming_roster_ids'],
            ]);

            return $schoolYear->fresh($this->relationships());
        }, attempts: 5);
    }

    private function assertActive(SchoolYear $schoolYear): void
    {
        if ($schoolYear->status !== SchoolYear::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'school_year' => 'Only the active School Year can use transition controls.',
            ]);
        }
    }

    /**
     * @return array{
     *     incoming_rosters: Collection<int, OrganizationOfficerRoster>,
     *     affected_users: Collection<int, User>,
     *     expired_officer_ids: array<int, string>,
     *     expired_assignment_ids: array<int, string>,
     *     replacement_officer_ids: array<int, string>,
     *     incoming_roster_ids: array<int, string>
     * }
     */
    private function expireOutgoingTransitionAuthority(
        SchoolYear $schoolYear,
        User $actor,
        string $reason,
        Carbon $closedAt
    ): array {
        $outgoingRosterIds = OrganizationOfficerRoster::query()
            ->where('school_year_id', $schoolYear->id)
            ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)
            ->lockForUpdate()
            ->pluck('id');

        $outgoingOfficers = OrganizationOfficer::query()
            ->with(['roster', 'user'])
            ->whereIn('officer_roster_id', $outgoingRosterIds)
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->whereIn('position_key', [
                OrganizationOfficer::POSITION_PRESIDENT,
                OrganizationOfficer::POSITION_SECRETARY,
            ])
            ->lockForUpdate()
            ->get();

        $organizationIds = $outgoingOfficers
            ->pluck('roster.organization_id')
            ->filter()
            ->unique()
            ->values();

        $incomingRosters = $this->incomingVerifiedRosters($schoolYear, $organizationIds);
        $incomingOfficersByPosition = $this->incomingOfficersByPosition($incomingRosters);
        $replacementOfficerIds = collect();

        foreach ($outgoingOfficers as $outgoingOfficer) {
            $replacementOfficer = $incomingOfficersByPosition->get($this->officerTransitionKey(
                $outgoingOfficer->roster->organization_id,
                $outgoingOfficer->position_key
            ));

            $metadata = $outgoingOfficer->metadata ?? [];
            $metadata['transition_closed'] = [
                'school_year_id' => $schoolYear->id,
                'closed_by' => $actor->id,
                'closed_at' => $closedAt->toJSON(),
                'reason' => $reason,
                'replacement_officer_id' => $replacementOfficer?->id,
            ];

            $outgoingOfficer->forceFill([
                'status' => $replacementOfficer
                    ? OrganizationOfficer::STATUS_REPLACED
                    : OrganizationOfficer::STATUS_EXPIRED,
                'effective_end' => $closedAt->toDateString(),
                'replacement_officer_id' => $replacementOfficer?->id,
                'metadata' => $metadata,
            ])->save();

            if ($replacementOfficer) {
                $replacementOfficerIds->push($replacementOfficer->id);
            }
        }

        $assignmentsToExpire = $this->outgoingAuthorityAssignments(
            $schoolYear,
            $outgoingOfficers->pluck('id'),
            $organizationIds
        );
        $affectedUsers = $assignmentsToExpire->pluck('user')->filter()->unique('id')->values();

        foreach ($assignmentsToExpire as $assignment) {
            $assignment->forceFill([
                'status' => AdminAssignment::STATUS_EXPIRED,
                'revoked_by' => $actor->id,
                'revoked_at' => $closedAt,
                'revocation_reason' => 'Officer transition closed: '.$reason,
            ])->save();
        }

        return [
            'incoming_rosters' => $incomingRosters,
            'affected_users' => $affectedUsers,
            'expired_officer_ids' => $outgoingOfficers->pluck('id')->values()->all(),
            'expired_assignment_ids' => $assignmentsToExpire->pluck('id')->values()->all(),
            'replacement_officer_ids' => $replacementOfficerIds->unique()->values()->all(),
            'incoming_roster_ids' => $incomingRosters->pluck('id')->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, string>  $organizationIds
     * @return Collection<int, OrganizationOfficerRoster>
     */
    private function incomingVerifiedRosters(SchoolYear $schoolYear, Collection $organizationIds): Collection
    {
        if ($organizationIds->isEmpty()) {
            return collect();
        }

        return OrganizationOfficerRoster::query()
            ->with('schoolYear')
            ->whereIn('organization_id', $organizationIds)
            ->where('school_year_id', '!=', $schoolYear->id)
            ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)
            ->where('verified_at', '>=', $schoolYear->transition_started_at)
            ->whereHas('schoolYear', function (Builder $query) use ($schoolYear): void {
                $query->whereDate('starts_on', '>', $schoolYear->starts_on);
            })
            ->lockForUpdate()
            ->get()
            ->groupBy('organization_id')
            ->map(fn (Collection $rosters): OrganizationOfficerRoster => $rosters
                ->sort(function (OrganizationOfficerRoster $first, OrganizationOfficerRoster $second): int {
                    $startsOnComparison = $first->schoolYear->starts_on <=> $second->schoolYear->starts_on;

                    if ($startsOnComparison !== 0) {
                        return $startsOnComparison;
                    }

                    return $second->verified_at <=> $first->verified_at;
                })
                ->first())
            ->values();
    }

    /**
     * @param  Collection<int, OrganizationOfficerRoster>  $incomingRosters
     * @return Collection<string, OrganizationOfficer>
     */
    private function incomingOfficersByPosition(Collection $incomingRosters): Collection
    {
        if ($incomingRosters->isEmpty()) {
            return collect();
        }

        return OrganizationOfficer::query()
            ->with('roster')
            ->whereIn('officer_roster_id', $incomingRosters->pluck('id'))
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->whereIn('position_key', [
                OrganizationOfficer::POSITION_PRESIDENT,
                OrganizationOfficer::POSITION_SECRETARY,
            ])
            ->lockForUpdate()
            ->get()
            ->sortByDesc(fn (OrganizationOfficer $officer): int => $officer->roster->verified_at?->getTimestamp() ?? 0)
            ->keyBy(fn (OrganizationOfficer $officer): string => $this->officerTransitionKey(
                $officer->roster->organization_id,
                $officer->position_key
            ));
    }

    /**
     * @param  Collection<int, string>  $outgoingOfficerIds
     * @param  Collection<int, string>  $organizationIds
     * @return Collection<int, AdminAssignment>
     */
    private function outgoingAuthorityAssignments(
        SchoolYear $schoolYear,
        Collection $outgoingOfficerIds,
        Collection $organizationIds
    ): Collection {
        $verifiedOfficerAssignments = AdminAssignment::query()
            ->with('user')
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->whereIn('source_id', $outgoingOfficerIds)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        $legacyAssignments = AdminAssignment::query()
            ->with('user')
            ->whereNull('source_type')
            ->whereIn('organization_id', $organizationIds)
            ->where('academic_year', $schoolYear->label)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function (Builder $query): void {
                $query->where('is_primary_admin', true)
                    ->orWhere('position_title', 'like', '%President%')
                    ->orWhere('position_title', 'like', '%Secretary%');
            })
            ->lockForUpdate()
            ->get();

        return $verifiedOfficerAssignments
            ->merge($legacyAssignments)
            ->unique('id')
            ->values();
    }

    private function officerTransitionKey(string $organizationId, string $positionKey): string
    {
        return $organizationId.'|'.$positionKey;
    }

    private function audit(SchoolYear $schoolYear, User $actor, string $action, array $details = []): void
    {
        AuditLog::create([
            'target_id' => $schoolYear->id,
            'admin_id' => $actor->id,
            'action' => $action,
            'details' => array_merge([
                'label' => $schoolYear->label,
                'status' => $schoolYear->status,
                'starts_on' => $schoolYear->starts_on?->toDateString(),
                'ends_on' => $schoolYear->ends_on?->toDateString(),
                'transition_ends_at' => $schoolYear->transition_ends_at?->toDateString(),
            ], $details),
        ]);
    }

    private function relationships(): array
    {
        return [
            'activatedBy',
            'closedBy',
            'transitionOpenedBy',
            'transitionClosedBy',
        ];
    }
}
