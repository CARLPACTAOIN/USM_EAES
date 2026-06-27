<?php

namespace App\Http\Controllers\Web;

use App\Exports\EventAttendanceExport;
use App\Exports\EventEvaluationExport;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\EventExportData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class EventExportController extends Controller
{
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
        if ($user->hasRole('Super Admin (OSA)')) {
            if ($event->status === 'draft') {
                abort(403, 'Draft proposals are visible only to their organizers until submitted.');
            }

            return;
        }

        $event->loadMissing('organization');
        $user->loadMissing('organization');

        if ($user->hasRole('LSG Admin')) {
            if (!$user->organization || !$event->organization || $user->organization->college_id !== $event->organization->college_id) {
                abort(403, 'Access denied. LSG Admin restricted to college boundary.');
            }

            return;
        }

        if ($event->organization_id !== $user->organization_id) {
            abort(403, 'Access denied. Restricted to your organization boundary.');
        }
    }
}
