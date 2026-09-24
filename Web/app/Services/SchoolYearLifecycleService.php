<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolYearLifecycleService
{
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

    public function closeTransition(SchoolYear $schoolYear, User $actor): SchoolYear
    {
        return DB::transaction(function () use ($schoolYear, $actor) {
            $schoolYear = SchoolYear::query()->lockForUpdate()->findOrFail($schoolYear->id);

            $this->assertActive($schoolYear);

            if (! $schoolYear->isTransitionOpen()) {
                throw ValidationException::withMessages([
                    'transition' => 'This School Year does not have an open transition period.',
                ]);
            }

            $schoolYear->update([
                'transition_closed_at' => now(),
                'transition_closed_by' => $actor->id,
            ]);

            $this->audit($schoolYear, $actor, 'close-school-year-transition');

            return $schoolYear->fresh($this->relationships());
        });
    }

    private function assertActive(SchoolYear $schoolYear): void
    {
        if ($schoolYear->status !== SchoolYear::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'school_year' => 'Only the active School Year can use transition controls.',
            ]);
        }
    }

    private function audit(SchoolYear $schoolYear, User $actor, string $action): void
    {
        AuditLog::create([
            'target_id' => $schoolYear->id,
            'admin_id' => $actor->id,
            'action' => $action,
            'details' => [
                'label' => $schoolYear->label,
                'status' => $schoolYear->status,
                'starts_on' => $schoolYear->starts_on?->toDateString(),
                'ends_on' => $schoolYear->ends_on?->toDateString(),
                'transition_ends_at' => $schoolYear->transition_ends_at?->toDateString(),
            ],
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
