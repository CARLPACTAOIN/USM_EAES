<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPermitVersion;
use App\Models\Venue;
use App\Services\AdminScopeService;
use App\Support\EventTenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create-proposals') || $request->user()->can('approve-proposals'), 403);

        return response()->json([
            'success' => true,
            'venues' => Venue::active()->with('college')->orderBy('name')->get(),
        ]);
    }

    public function evidence(Request $request, Event $event): StreamedResponse
    {
        $this->authorizeEvent($request, $event);
        abort_unless($event->venue_confirmation_document_path && Storage::exists($event->venue_confirmation_document_path), 404);

        return Storage::download($event->venue_confirmation_document_path, $event->venue_confirmation_document_original_name ?: 'venue-confirmation-evidence');
    }

    public function history(Request $request, Event $event): JsonResponse
    {
        $this->authorizeEvent($request, $event);

        return response()->json([
            'success' => true,
            'permit_version' => $event->permit_version,
            'versions' => $event->permitVersions()->with('approver')->get()->map(fn (EventPermitVersion $version): array => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'venue' => [
                    'id' => $version->venue_id,
                    'name' => $version->venue_name_snapshot,
                    'campus_location' => $version->venue_location_snapshot,
                ],
                'location_type' => $version->location_type,
                'location_details' => $version->location_details,
                'confirmation_office' => $version->confirmation_office,
                'confirmation_date' => $version->confirmation_date?->toDateString(),
                'confirmation_reference_number' => $version->confirmation_reference_number,
                'confirmation_document_original_name' => $version->confirmation_document_original_name,
                'ppa_document_original_name' => $version->ppa_document_original_name,
                'amendment_reason' => $version->amendment_reason,
                'approved_by' => $version->approver?->only(['id', 'name']),
                'approved_at' => $version->approved_at?->toISOString(),
                'superseded_at' => $version->superseded_at?->toISOString(),
            ]),
        ]);
    }

    public function versionEvidence(Request $request, Event $event, EventPermitVersion $permitVersion): StreamedResponse
    {
        abort_unless($permitVersion->event_id === $event->id, 404);
        $this->authorizeEvent($request, $event);
        abort_unless(Storage::exists($permitVersion->confirmation_document_path), 404);

        return Storage::download($permitVersion->confirmation_document_path, $permitVersion->confirmation_document_original_name);
    }

    private function authorizeEvent(Request $request, Event $event): void
    {
        $scope = app(AdminScopeService::class)->forUser($request->user());
        abort_unless($scope, 403);
        if ($scope->isSuperAdmin()) {
            abort_if($event->status === Event::STATUS_DRAFT, 403);

            return;
        }

        EventTenantScope::authorize($event, $request->user());
    }
}
