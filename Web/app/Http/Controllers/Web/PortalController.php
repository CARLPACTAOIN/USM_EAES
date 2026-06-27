<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Support\EvaluationQuestions;
use App\Support\EvaluationWindow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PortalController extends Controller
{
    /**
     * Student portal home page.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Profile completion check
        $profileComplete = $user->student_id_number
            && $user->qr_code_value
            && $user->organization_id;

        // Events needing evaluation (attended but not yet evaluated)
        $attendedEventIds = AttendanceRecord::where('student_id', $user->id)
            ->pluck('event_id')
            ->unique();

        $evaluatedEventIds = Evaluation::where('student_id', $user->id)
            ->pluck('event_id');

        $pendingEvalEvents = Event::whereIn('id', $attendedEventIds)
            ->whereNotIn('id', $evaluatedEventIds)
            ->with('eventDays')
            ->latest()
            ->get()
            ->filter(fn ($event) => EvaluationWindow::isOpen($event));

        // Recent attendance
        $recentAttendance = AttendanceRecord::where('student_id', $user->id)
            ->with('event')
            ->latest()
            ->take(5)
            ->get();

        return view('portal.index', compact(
            'user', 'profileComplete', 'pendingEvalEvents', 'recentAttendance'
        ));
    }

    /**
     * Show profile form.
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        $colleges = College::orderBy('name')->get();

        // Build college→organizations map for dependent dropdown
        $orgsByCollege = [];
        $allOrgs = Organization::orderBy('name')->get();
        foreach ($allOrgs as $org) {
            $key = $org->college_id ?? 'university_wide';
            $orgsByCollege[$key][] = ['id' => $org->id, 'name' => $org->name, 'acronym' => $org->acronym];
        }

        // Build college→programs map for dependent dropdown
        $programsByCollege = [];
        $allPrograms = Program::orderBy('name')->get();
        foreach ($allPrograms as $prog) {
            $programsByCollege[$prog->college_id][] = ['id' => $prog->id, 'name' => $prog->name, 'code' => $prog->code];
        }

        return view('portal.profile', compact('user', 'colleges', 'orgsByCollege', 'programsByCollege'));
    }

    /**
     * Update student profile (PRD Feature 1.2).
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'college_id' => 'required|uuid|exists:colleges,id',
            'program_id' => 'required|uuid|exists:programs,id',
            'organization_id' => 'required|uuid|exists:organizations,id',
            'student_id_number' => [
                'required', 'string', 'max:20',
                'unique:users,student_id_number,' . $user->id,
            ],
            'qr_code_value' => [
                'required', 'string', 'max:255',
                'unique:users,qr_code_value,' . $user->id,
            ],
        ], [
            'student_id_number.unique' => 'This Student ID number is already registered to another account.',
            'qr_code_value.unique' => 'This QR code value is already registered to another account.',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $program = Program::findOrFail($request->program_id);
        if ($program->college_id !== $request->college_id) {
            return redirect()->back()
                ->withErrors(['program_id' => 'Select a program that belongs to the chosen college.'])
                ->withInput();
        }

        $organization = Organization::findOrFail($request->organization_id);
        if ($organization->college_id !== $request->college_id) {
            return redirect()->back()
                ->withErrors(['organization_id' => 'Select a society/organization that belongs to the chosen college.'])
                ->withInput();
        }

        // Ensure the society covers the selected program
        if ($organization->type === 'society' && !$organization->programs()->where('program_id', $program->id)->exists()) {
            return redirect()->back()
                ->withErrors(['organization_id' => 'The selected society does not cover your chosen academic program.'])
                ->withInput();
        }

        $user->update([
            'college_id' => $request->college_id,
            'program_id' => $request->program_id,
            'organization_id' => $request->organization_id,
            'program_code' => $program->code,
            'student_id_number' => $request->student_id_number,
            'qr_code_value' => $request->qr_code_value,
        ]);

        return redirect()->route('portal.profile')
            ->with('success', 'Profile updated successfully.');
    }

    /**
     * Show evaluation form for a specific event.
     */
    public function evaluation(Request $request, $eventId)
    {
        $user = $request->user();
        $event = Event::with('eventDays')->findOrFail($eventId);

        // Check if already submitted
        $existing = Evaluation::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->first();

        // Check evaluation window
        $windowCloseAt = EvaluationWindow::closesAt($event);
        $windowOpen = $windowCloseAt !== null && Carbon::now()->lte($windowCloseAt);

        $categories = EvaluationQuestions::all();

        return view('portal.evaluation', compact(
            'event', 'existing', 'windowOpen', 'windowCloseAt', 'categories'
        ));
    }

    /**
     * Submit evaluation (delegates to existing evaluation gate logic).
     */
    public function submitEvaluation(Request $request)
    {
        $user = $request->user();

        $rules = [
            'event_id' => 'required|uuid|exists:events,id',
            'section_scores' => 'required|array',
            'open_comment' => 'nullable|string|max:2000',
        ];

        foreach (EvaluationQuestions::keys() as $key) {
            $rules["section_scores.{$key}"] = 'required|integer|min:1|max:5';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $eventId = $request->input('event_id');
        $event = Event::with('eventDays')->findOrFail($eventId);

        if (!AttendanceRecord::where('event_id', $eventId)->where('student_id', $user->id)->exists()) {
            abort(403, 'Only attendees can submit an evaluation.');
        }

        // Check evaluation window
        if (EvaluationWindow::isClosed($event)) {
            return redirect()->back()->with('error', 'Evaluation window has closed for this event.');
        }

        // Check duplicate submission
        if (Evaluation::where('event_id', $eventId)->where('student_id', $user->id)->exists()) {
            return redirect()->back()->with('error', 'You have already submitted an evaluation for this event.');
        }

        // Create evaluation
        Evaluation::create([
            'event_id' => $eventId,
            'student_id' => $user->id,
            'section_scores' => $request->input('section_scores'),
            'open_comment' => $request->input('open_comment'),
            'sentiment' => 'unprocessed',
            'submitted_at' => Carbon::now(),
        ]);

        // Evaluation Gate: validate attendance
        AttendanceRecord::where('event_id', $eventId)
            ->where('student_id', $user->id)
            ->update(['valid' => true]);

        return redirect()->route('portal')
            ->with('success', 'Evaluation submitted successfully. Your attendance has been validated.');
    }
}
