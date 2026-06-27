<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Evaluation;
use App\Models\AttendanceRecord;
use App\Support\EvaluationWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class EvaluationController extends Controller
{
    /**
     * Submit an event evaluation questionnaire (Student action).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'event_id' => 'required|uuid|exists:events,id',
            'section_scores' => 'required|array', // Likert scale results
            'open_comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $eventId = $request->input('event_id');
        $event = Event::findOrFail($eventId);

        // 1. Check if the evaluation window has closed (24 hours after final event day concludes)
        $event->load('eventDays');
        $windowClosedAt = EvaluationWindow::closesAt($event);

        if (!$windowClosedAt) {
            return response()->json([
                'success' => false,
                'message' => 'Event days not defined.'
            ], 422);
        }

        if (Carbon::now()->gt($windowClosedAt)) {
            return response()->json([
                'success' => false,
                'message' => 'Evaluation window closed. Submissions are no longer accepted for this event.'
            ], 403);
        }

        // 2. Check if student already submitted an evaluation
        $existing = Evaluation::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You have already submitted an evaluation for this event.'
            ], 422);
        }

        // 3. Create Evaluation record (sentiment left as "unprocessed")
        $evaluation = Evaluation::create([
            'event_id' => $eventId,
            'student_id' => $user->id,
            'section_scores' => $request->input('section_scores'),
            'open_comment' => $request->input('open_comment'),
            'sentiment' => 'unprocessed',
            'submitted_at' => Carbon::now(),
        ]);

        // 4. Evaluation Gate Logic: Unlock and validate all daily attendance records for this student/event
        AttendanceRecord::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->update(['valid' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Evaluation submitted successfully. Your attendance has been validated.',
            'evaluation' => $evaluation
        ], 201);
    }
}
