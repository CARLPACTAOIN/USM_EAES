<?php

namespace App\Livewire\Dashboard;

use App\Jobs\ResolveAttendanceJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\ParticipationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class PendingQrLinks extends Component
{
    use WithPagination;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(as: 'source', except: '')]
    public string $source = '';

    public array $resolveStudentIds = [];

    public array $resolveNotes = [];

    public array $flagNotes = [];

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(): void
    {
        $this->authorizeDashboardAccess();
        $this->search = trim((string) request('search', $this->search));
        $this->source = (string) request('source', $this->source);
    }

    public function updated(string $property): void
    {
        $this->notice = null;
        $this->errorNotice = null;

        if (in_array($property, ['search', 'source'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->source = '';
        $this->resetPage();
    }

    public function resolveLink(string $linkId): void
    {
        $this->resetFeedback();

        $pendingLink = PendingStudentLink::with(['participationScope', 'organization'])
            ->findOrFail($linkId);

        $this->authorizePendingLinkAccess($pendingLink, auth()->user());

        if ($pendingLink->status !== 'pending') {
            $this->errorNotice = 'Only pending QR links can be resolved.';

            return;
        }

        $payload = [
            'student_id' => $this->resolveStudentIds[$linkId] ?? null,
            'notes' => $this->resolveNotes[$linkId] ?? null,
        ];

        $validator = Validator::make($payload, [
            'student_id' => ['required', 'uuid', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->get('student_id') as $message) {
                $this->addError("resolveStudentIds.{$linkId}", $message);
            }

            foreach ($validator->errors()->get('notes') as $message) {
                $this->addError("resolveNotes.{$linkId}", $message);
            }

            return;
        }

        $student = User::findOrFail($payload['student_id']);
        if (! $this->canResolveStudentForPendingLink($pendingLink, $student, auth()->user())) {
            abort(403, 'Student is outside the pending link organization boundary.');
        }

        DB::transaction(function () use ($pendingLink, $student, $payload): void {
            RawScan::where('event_id', $pendingLink->event_id)
                ->when($pendingLink->event_participation_scope_id, fn ($query, $scopeId) => $query->where('event_participation_scope_id', $scopeId))
                ->where('qr_code_value', $pendingLink->qr_code_value)
                ->whereNull('student_id')
                ->update(['student_id' => $student->id]);

            $pendingLink->update([
                'status' => 'resolved',
                'resolved_student_id' => $student->id,
                'resolved_by' => auth()->id(),
                'notes' => $payload['notes'],
                'resolved_at' => now(),
            ]);

            AuditLog::create([
                'target_id' => $pendingLink->id,
                'admin_id' => auth()->id(),
                'action' => 'resolve-pending-student-link',
                'details' => [
                    'event_id' => $pendingLink->event_id,
                    'qr_code_value' => $pendingLink->qr_code_value,
                    'student_id' => $student->id,
                ],
            ]);
        });

        ResolveAttendanceJob::dispatch($pendingLink->event_id, [$student->id], $pendingLink->event_participation_scope_id);

        unset($this->resolveStudentIds[$linkId], $this->resolveNotes[$linkId]);
        $this->notice = 'Pending QR link resolved successfully.';
        $this->dispatch('pending-qr-link-resolved', id: $linkId);
    }

    public function flagLink(string $linkId): void
    {
        $this->resetFeedback();

        $pendingLink = PendingStudentLink::with(['participationScope', 'organization'])
            ->findOrFail($linkId);

        $this->authorizePendingLinkAccess($pendingLink, auth()->user());

        if ($pendingLink->status !== 'pending') {
            $this->errorNotice = 'Only pending QR links can be flagged.';

            return;
        }

        $payload = [
            'notes' => $this->flagNotes[$linkId] ?? null,
        ];

        $validator = Validator::make($payload, [
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->get('notes') as $message) {
                $this->addError("flagNotes.{$linkId}", $message);
            }

            return;
        }

        $pendingLink->update([
            'status' => 'flagged',
            'flagged_by' => auth()->id(),
            'notes' => $payload['notes'],
            'flagged_at' => now(),
        ]);

        AuditLog::create([
            'target_id' => $pendingLink->id,
            'admin_id' => auth()->id(),
            'action' => 'flag-pending-student-link',
            'details' => [
                'event_id' => $pendingLink->event_id,
                'qr_code_value' => $pendingLink->qr_code_value,
            ],
        ]);

        unset($this->flagNotes[$linkId]);
        $this->notice = 'Pending QR link flagged.';
        $this->dispatch('pending-qr-link-flagged', id: $linkId);
    }

    public function render()
    {
        $this->authorizeDashboardAccess();

        return view('livewire.dashboard.pending-qr-links', [
            'pendingLinks' => $this->query()->paginate(15),
        ]);
    }

    private function query()
    {
        $search = trim($this->search);

        $query = PendingStudentLink::with(['event', 'participationScope.organization', 'organization', 'rawScan'])
            ->where('status', 'pending')
            ->when($search !== '', function ($query) use ($search) {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(function ($inner) use ($needle) {
                    $inner->whereRaw('LOWER(qr_code_value) LIKE ?', [$needle])
                        ->orWhereHas('event', fn ($eventQuery) => $eventQuery->whereRaw('LOWER(title) LIKE ?', [$needle]))
                        ->orWhereHas('organization', function ($organizationQuery) use ($needle) {
                            $organizationQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(acronym) LIKE ?', [$needle]);
                        });
                });
            })
            ->when($this->source === 'manual', fn ($query) => $query->whereHas('rawScan', fn ($rawQuery) => $rawQuery->where('manual_entry', true)))
            ->when($this->source === 'scan', function ($query) {
                $query->where(function ($inner) {
                    $inner->whereDoesntHave('rawScan')
                        ->orWhereHas('rawScan', fn ($rawQuery) => $rawQuery->where('manual_entry', false));
                });
            })
            ->latest();

        $this->applyPendingLinkTenantScope($query, auth()->user());

        return $query;
    }

    private function applyPendingLinkTenantScope($query, User $user): void
    {
        $scope = app(AdminScopeService::class)->forUser($user);

        if (! $scope) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        if ($scope->isUsgAdmin()) {
            $query->whereHas('organization', fn ($orgQuery) => $orgQuery->where('type', 'usg')->orWhere('id', $scope->organizationId));

            return;
        }

        if ($scope->isLsgAdmin()) {
            $query->whereHas('organization', fn ($orgQuery) => $orgQuery->where('college_id', $scope->collegeId));

            return;
        }

        if (! $scope->organizationId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('organization_id', $scope->organizationId);
    }

    private function authorizePendingLinkAccess(PendingStudentLink $pendingLink, User $user): void
    {
        $scope = app(AdminScopeService::class)->forUser($user);

        if (! $scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        if ($pendingLink->participationScope && app(ParticipationScopeService::class)->canViewScope($user, $pendingLink->participationScope)) {
            return;
        }

        $targetOrg = $pendingLink->organization ?: Organization::findOrFail($pendingLink->organization_id);
        if (! $scope->canAccessOrganization($targetOrg)) {
            abort(403, $scope->isLsgAdmin()
                ? 'Access denied. LSG Admin restricted to college boundary.'
                : 'Access denied. Restricted to your organization boundary.');
        }
    }

    private function canResolveStudentForPendingLink(PendingStudentLink $pendingLink, User $student, User $admin): bool
    {
        return app(ParticipationScopeService::class)->canResolveStudentForPendingLink($pendingLink, $student, $admin);
    }

    private function authorizeDashboardAccess(): void
    {
        abort_unless(auth()->user()?->hasAnyRole([
            'Super Admin (OSA)',
            'USG Admin',
            'LSG Admin',
            'Society Admin',
            'Academic Unit Admin',
            'Office Event Admin',
            'HR Admin',
        ]), 403);
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->errorNotice = null;
        $this->resetErrorBag();
    }
}
