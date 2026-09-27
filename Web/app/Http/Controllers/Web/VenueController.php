<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPermitVersion;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Support\EventTenantScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VenueController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('manage-venues'), 403);

        return view('dashboard.venues.index');
    }

    public function availability(Request $request): View
    {
        abort_unless($request->user()->can('create-proposals'), 403);

        return view('dashboard.venues.availability');
    }

    public function downloadEvidence(Request $request, Event $event): StreamedResponse
    {
        $this->authorizeEvent($request->user(), $event);
        abort_unless($event->venue_confirmation_document_path && Storage::exists($event->venue_confirmation_document_path), 404);

        return Storage::download($event->venue_confirmation_document_path, $event->venue_confirmation_document_original_name ?: 'venue-confirmation-evidence');
    }

    public function permitHistory(Request $request, Event $event): View
    {
        $this->authorizeEvent($request->user(), $event);
        $event->load(['organization', 'venue', 'permitVersions.approver', 'permitVersions.copyOwners']);

        return view('dashboard.events.permit-history', compact('event'));
    }

    public function downloadCurrentPermitCertificate(Request $request, Event $event): Response
    {
        $this->authorizeEvent($request->user(), $event);

        $permitVersion = $event->currentPermitVersion()
            ->with(['approver', 'copyOwners'])
            ->first();

        abort_unless($permitVersion, 404);

        return $this->permitCertificateResponse($event, $permitVersion);
    }

    public function downloadVersionCertificate(Request $request, Event $event, EventPermitVersion $permitVersion): Response
    {
        $this->authorizeVersion($request->user(), $event, $permitVersion);

        return $this->permitCertificateResponse($event, $permitVersion->loadMissing(['approver', 'copyOwners']));
    }

    public function downloadVersionEvidence(Request $request, Event $event, EventPermitVersion $permitVersion): StreamedResponse
    {
        $this->authorizeVersion($request->user(), $event, $permitVersion);
        abort_unless(Storage::exists($permitVersion->confirmation_document_path), 404);

        return Storage::download($permitVersion->confirmation_document_path, $permitVersion->confirmation_document_original_name);
    }

    public function downloadVersionPpa(Request $request, Event $event, EventPermitVersion $permitVersion): StreamedResponse
    {
        $this->authorizeVersion($request->user(), $event, $permitVersion);
        abort_unless(Storage::exists($permitVersion->ppa_document_path), 404);

        return Storage::download($permitVersion->ppa_document_path, $permitVersion->ppa_document_original_name);
    }

    private function authorizeVersion(User $user, Event $event, EventPermitVersion $permitVersion): void
    {
        abort_unless($permitVersion->event_id === $event->id, 404);
        $this->authorizeEvent($user, $event);
    }

    private function permitCertificateResponse(Event $event, EventPermitVersion $permitVersion): Response
    {
        $event->loadMissing(['organization', 'venue']);

        return Pdf::loadView('dashboard.events.permit-certificate', [
            'event' => $event,
            'permitVersion' => $permitVersion,
        ])->download($this->permitCertificateFilename($event, $permitVersion));
    }

    private function permitCertificateFilename(Event $event, EventPermitVersion $permitVersion): string
    {
        $title = preg_replace('/[^A-Za-z0-9-]+/', '-', (string) str($event->title)->slug());
        $title = trim((string) $title, '-') ?: 'event';

        return $title.'-permit-v'.$permitVersion->version_number.'.pdf';
    }

    private function authorizeEvent(User $user, Event $event): void
    {
        $scope = app(AdminScopeService::class)->forUser($user);
        abort_unless($scope, 403);

        if ($scope->isSuperAdmin()) {
            abort_if($event->status === Event::STATUS_DRAFT, 403, 'Draft proposals are visible only to their organizers.');

            return;
        }

        EventTenantScope::authorize($event, $user);
    }
}
