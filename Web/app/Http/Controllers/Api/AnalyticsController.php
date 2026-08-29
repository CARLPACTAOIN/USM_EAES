<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\AdminScopeService;
use App\Services\EventAnalyticsService;
use App\Services\OrganizationAnalyticsService;
use App\Support\EventTenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly EventAnalyticsService $eventAnalytics,
        private readonly OrganizationAnalyticsService $organizationAnalytics,
    ) {}

    /**
     * Get real-time analytics for a specific event.
     *
     * @param  string  $eventId
     * @return JsonResponse
     */
    public function eventAnalytics(Request $request, $eventId)
    {
        $event = Event::with(['organization', 'participationScopes.organization', 'participationScopes.college', 'participationScopes.program'])->findOrFail($eventId);

        // Tenancy Check
        EventTenantScope::authorize($event, $request->user());

        return response()->json([
            'success' => true,
            ...$this->eventAnalytics->summary($event),
        ]);
    }

    /**
     * Calculate Gawad Parangal indicators for an organization.
     */
    public function gawadParangalMetrics(Request $request, string $orgId): JsonResponse
    {
        $org = Organization::findOrFail($orgId);

        // Tenancy Check
        $user = $request->user();
        $scope = $this->adminScopes->forUser($user);
        if (! $scope || ! $scope->canAccessOrganization($org)) {
            abort(403, 'Unauthorized.');
        }

        $metrics = $this->organizationAnalytics->organizationMetrics(
            $org,
            $this->queryFilter($request, 'program_id'),
            $this->queryFilter($request, 'school_year'),
            $this->queryFilter($request, 'semester'),
            $this->queryFilter($request, 'event_type'),
            $this->queryFilter($request, 'year_level'),
        );

        return response()->json([
            'success' => true,
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'acronym' => $org->acronym,
            ],
            'metrics' => [
                'total_members' => $metrics['total_members'],
                'active_members' => $metrics['active_members'],
                'membership_active_ratio' => $metrics['active_member_ratio'],
                'average_event_attendance_rate' => $metrics['avg_attendance_rate'],
                'average_evaluation_score' => $metrics['avg_evaluation_score'],
            ],
        ]);
    }

    private function queryFilter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
