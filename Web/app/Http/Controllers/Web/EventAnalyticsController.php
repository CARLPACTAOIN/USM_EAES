<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EventAnalyticsService;
use App\Support\EventTenantScope;
use Illuminate\Http\Request;

class EventAnalyticsController extends Controller
{
    public function __construct(private readonly EventAnalyticsService $analytics)
    {
    }

    public function show(Request $request, Event $event)
    {
        EventTenantScope::authorize($event, $request->user());

        $analytics = $this->analytics->summary($event);
        $participantRows = $this->analytics
            ->participantRows(
                $event,
                $request->only(['scope_id', 'day_id', 'status', 'validation', 'year_level', 'q']),
                (int) $request->integer('per_page', 25)
            )
            ->appends($request->query());

        return view('dashboard.analytics.event', [
            'event' => $event,
            'analytics' => $analytics,
            'participantRows' => $participantRows,
            'filters' => [
                'scope_id' => $request->query('scope_id'),
                'day_id' => $request->query('day_id'),
                'status' => $request->query('status', 'all'),
                'validation' => $request->query('validation', 'all'),
                'year_level' => $request->query('year_level', ''),
                'q' => $request->query('q', ''),
                'per_page' => (int) $request->query('per_page', 25),
            ],
        ]);
    }

    public function data(Request $request, Event $event)
    {
        EventTenantScope::authorize($event, $request->user());

        return response()->json([
            'success' => true,
            ...$this->analytics->summary($event),
        ]);
    }
}
