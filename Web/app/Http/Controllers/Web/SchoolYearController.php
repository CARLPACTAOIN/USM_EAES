<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CloseSchoolYearTransitionRequest;
use App\Http\Requests\OpenSchoolYearTransitionRequest;
use App\Http\Requests\StoreSchoolYearRequest;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\SchoolYearLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolYearController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOsa($request);

        $schoolYears = SchoolYear::with($this->relationships())
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (SchoolYear $schoolYear): array => $this->serializeSchoolYear($schoolYear));

        return response()->json([
            'school_years' => $schoolYears,
        ]);
    }

    public function store(StoreSchoolYearRequest $request, SchoolYearLifecycleService $service): JsonResponse
    {
        $schoolYear = $service->create($request->validated(), $request->user());

        return response()->json([
            'school_year' => $this->serializeSchoolYear($schoolYear),
        ], 201);
    }

    public function activate(
        Request $request,
        SchoolYear $schoolYear,
        SchoolYearLifecycleService $service
    ): JsonResponse {
        $this->authorizeOsa($request);

        $schoolYear = $service->activate($schoolYear, $request->user());

        return response()->json([
            'school_year' => $this->serializeSchoolYear($schoolYear),
        ]);
    }

    public function openTransition(
        OpenSchoolYearTransitionRequest $request,
        SchoolYear $schoolYear,
        SchoolYearLifecycleService $service
    ): JsonResponse {
        $schoolYear = $service->openTransition($schoolYear, $request->validated(), $request->user());

        return response()->json([
            'school_year' => $this->serializeSchoolYear($schoolYear),
        ]);
    }

    public function closeTransition(
        CloseSchoolYearTransitionRequest $request,
        SchoolYear $schoolYear,
        SchoolYearLifecycleService $service
    ): JsonResponse {
        $schoolYear = $service->closeTransition($schoolYear, $request->user(), $request->closeReason());

        return response()->json([
            'school_year' => $this->serializeSchoolYear($schoolYear),
        ]);
    }

    private function authorizeOsa(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Super Admin (OSA)'), 403);
    }

    private function serializeSchoolYear(SchoolYear $schoolYear): array
    {
        $schoolYear->loadMissing($this->relationships());

        return [
            'id' => $schoolYear->id,
            'label' => $schoolYear->label,
            'starts_on' => $schoolYear->starts_on?->toDateString(),
            'ends_on' => $schoolYear->ends_on?->toDateString(),
            'status' => $schoolYear->status,
            'activated_at' => $schoolYear->activated_at?->toJSON(),
            'activated_by' => $this->serializeActor($schoolYear->activatedBy),
            'closed_at' => $schoolYear->closed_at?->toJSON(),
            'closed_by' => $this->serializeActor($schoolYear->closedBy),
            'transition_started_at' => $schoolYear->transition_started_at?->toJSON(),
            'transition_ends_at' => $schoolYear->transition_ends_at?->toDateString(),
            'transition_opened_by' => $this->serializeActor($schoolYear->transitionOpenedBy),
            'transition_closed_at' => $schoolYear->transition_closed_at?->toJSON(),
            'transition_closed_by' => $this->serializeActor($schoolYear->transitionClosedBy),
        ];
    }

    private function serializeActor(?User $actor): ?array
    {
        if (! $actor) {
            return null;
        }

        return [
            'id' => $actor->id,
            'name' => $actor->name,
            'email' => $actor->email,
        ];
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
