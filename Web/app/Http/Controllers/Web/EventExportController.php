<?php

namespace App\Http\Controllers\Web;

use App\Exports\EventAttendanceExport;
use App\Exports\EventEvaluationExport;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Support\EventExportData;
use App\Support\EventTenantScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class EventExportController extends Controller
{
    public function __construct(private readonly AdminScopeService $adminScopes)
    {
    }

    public function attendancePdf(Request $request, Event $event)
    {
        $this->authorizeEventAccess($event, $request->user());

        $payload = EventExportData::build($event, $request->user());
        $pdf = Pdf::loadView('exports.event-attendance', $payload)
            ->setPaper('a4', 'landscape');

        return $pdf->download(EventExportData::filename($event, 'attendance', 'pdf'));
    }

    public function attendanceExcel(Request $request, Event $event)
    {
        $this->authorizeEventAccess($event, $request->user());

        $payload = EventExportData::build($event, $request->user());

        return Excel::download(
            new EventAttendanceExport($payload),
            EventExportData::filename($event, 'attendance', 'xlsx')
        );
    }

    public function evaluationsPdf(Request $request, Event $event)
    {
        $this->authorizeEventAccess($event, $request->user());

        $payload = EventExportData::build($event, $request->user());
        $pdf = Pdf::loadView('exports.event-evaluations', $payload)
            ->setPaper('a4', 'landscape');

        return $pdf->download(EventExportData::filename($event, 'evaluations', 'pdf'));
    }

    public function evaluationsExcel(Request $request, Event $event)
    {
        $this->authorizeEventAccess($event, $request->user());

        $payload = EventExportData::build($event, $request->user());

        return Excel::download(
            new EventEvaluationExport($payload),
            EventExportData::filename($event, 'evaluations', 'xlsx')
        );
    }

    private function authorizeEventAccess(Event $event, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if (!$scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) {
            if ($event->status === 'draft') {
                abort(403, 'Draft proposals are visible only to their organizers until submitted.');
            }

            return;
        }

        EventTenantScope::authorize($event, $user);
    }
}
