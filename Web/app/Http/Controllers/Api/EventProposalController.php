<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class EventProposalController extends Controller
{
    /**
     * Display a listing of events scoped by user organization/college tenancy.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Event::with(['organization', 'eventDays']);

        // Tenancy constraints based on Spatie roles
        if ($user->hasRole('Super Admin (OSA)')) {
            // OSA reviews submitted proposals and history; drafts stay organizer-private.
            $query->where('status', '!=', 'draft');
        } elseif ($user->hasRole('USG Admin')) {
            // USG Admin sees USG events or their specific organization events
            $query->whereHas('organization', function ($q) use ($user) {
                $q->where('type', 'usg')->orWhere('id', $user->organization_id);
            });
        } elseif ($user->hasRole('LSG Admin')) {
            // LSG Admin sees events within their college boundary
            $userOrg = $user->organization;
            if ($userOrg) {
                $query->whereHas('organization', function ($q) use ($userOrg) {
                    $q->where('college_id', $userOrg->college_id);
                });
            } else {
                return response()->json(['success' => false, 'message' => 'User organization not found.'], 400);
            }
        } elseif ($user->hasRole('ARO Admin')) {
            // ARO Admin sees only ARO-owned university ceremony events in their organization
            $query->where('organization_id', $user->organization_id);
        } else {
            // Society Admins, Scanners, and students see only their specific organization events
            $query->where('organization_id', $user->organization_id);
        }

        $events = $query->latest()->get();

        return response()->json([
            'success' => true,
            'events' => $events
        ]);
    }

    /**
     * Store a newly created event proposal in storage (default status 'draft').
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location_type' => 'required|in:on-campus,off-campus',
            'location_details' => 'nullable|string',
            'target_demographics' => 'nullable|array',
            'budget_allocations' => 'nullable|array',
            'event_days' => 'required|array|min:1',
            'event_days.*.day_number' => 'required|integer|min:1',
            'event_days.*.date' => 'required|date',
            'event_days.*.start_time' => 'required|date_format:H:i',
            'event_days.*.end_time' => 'required|date_format:H:i|after:event_days.*.start_time',
            'parent_event_id' => 'nullable|uuid|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create the Event
            $event = Event::create([
                'organization_id' => $user->organization_id,
                'parent_event_id' => $request->parent_event_id,
                'title' => $request->title,
                'status' => 'draft', // Default is draft
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'location_type' => $request->location_type,
                'location_details' => $request->location_details,
                'target_demographics' => $request->target_demographics,
                'budget_allocations' => $request->budget_allocations,
            ]);

            // Create Event Days
            foreach ($request->event_days as $dayData) {
                EventDay::create([
                    'event_id' => $event->id,
                    'day_number' => $dayData['day_number'],
                    'date' => $dayData['date'],
                    'start_time' => $dayData['start_time'],
                    'end_time' => $dayData['end_time'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Event PPA proposal created as draft.',
                'event' => $event->load('eventDays')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create event proposal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit PPA proposal for review.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit($id)
    {
        $event = Event::findOrFail($id);

        if ($event->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft proposals can be submitted.'
            ], 422);
        }

        $event->status = 'submitted';
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal successfully submitted for review.',
            'event' => $event
        ]);
    }

    /**
     * transition event to under_review status (OSA action).
     */
    public function startReview($id)
    {
        $event = Event::findOrFail($id);

        if ($event->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Only submitted proposals can be reviewed.'
            ], 422);
        }

        $event->status = 'under_review';
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Proposal is now under review.',
            'event' => $event
        ]);
    }

    /**
     * Approve PPA proposal (OSA action).
     */
    public function approve($id)
    {
        $event = Event::findOrFail($id);

        if ($event->status !== 'under_review' && $event->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Proposals must be submitted or under review to be approved.'
            ], 422);
        }

        $event->status = 'approved';
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal successfully approved. Scanning is now unlocked.',
            'event' => $event
        ]);
    }

    /**
     * Reject PPA proposal (OSA action).
     */
    public function reject($id)
    {
        $event = Event::findOrFail($id);

        if ($event->status !== 'under_review' && $event->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Proposals must be submitted or under review to be rejected.'
            ], 422);
        }

        $event->status = 'rejected';
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal was rejected.',
            'event' => $event
        ]);
    }

    /**
     * Link a sub-organization PPA to a parent event (Multi-Org Event Linking).
     */
    public function linkSubEvent(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'parent_event_id' => 'required|uuid|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $parentEvent = Event::findOrFail($request->parent_event_id);

        // Verify the parent event belongs to a campus-wide organizer
        $parentOrg = $parentEvent->organization;
        if (!in_array($parentOrg->type, ['usg', 'lsg', 'aro'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Sub-events can only be linked to USG, LSG, or ARO parent events.'
            ], 422);
        }

        $event->parent_event_id = $request->parent_event_id;
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Event successfully linked under the parent permit.',
            'event' => $event->load('parentEvent')
        ]);
    }
}
