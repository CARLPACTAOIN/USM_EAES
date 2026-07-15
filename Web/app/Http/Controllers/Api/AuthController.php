<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Event;
use App\Support\ScannerAccess;
use App\Services\ScannerSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    private const MOBILE_ASSIGNMENT_ABILITIES = ['scanner-assignments'];

    public function __construct(private readonly ScannerSessionService $scannerSessions)
    {
    }

    public function mobileGoogleLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_token' => 'required|string',
            'device_name' => 'required|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $googleUser = $this->verifyGoogleIdToken($request->input('id_token'));
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Google authentication failed.',
            ], 401);
        }

        $email = Str::lower((string) ($googleUser['email'] ?? ''));
        $allowedDomain = $this->allowedEmailDomain();

        if (!Str::endsWith($email, '@' . $allowedDomain)) {
            return response()->json([
                'success' => false,
                'message' => "Access restricted to institutional @{$allowedDomain} accounts.",
            ], 403);
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $googleUser['name'] ?? Str::before($email, '@'),
                'google_sub' => $googleUser['sub'] ?? null,
                'password' => null,
                'user_type' => $this->userTypeForEmail($email),
            ]
        );

        $updates = [];
        if (!$user->google_sub && isset($googleUser['sub'])) {
            $updates['google_sub'] = $googleUser['sub'];
        }
        if (($googleUser['name'] ?? null) && $user->name !== $googleUser['name']) {
            $updates['name'] = $googleUser['name'];
        }
        if (!$user->user_type) {
            $updates['user_type'] = $this->userTypeForEmail($email);
        }
        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }

        if (!$user->hasAnyRole(['Student', 'Faculty', 'Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'Academic Unit Admin', 'Office Event Admin', 'HR Admin', 'Scanner'])) {
            $user->assignRole($this->userTypeForEmail($email) === 'student' ? 'Student' : 'Faculty');
        }

        $plainTextToken = $user
            ->createToken($request->input('device_name'), self::MOBILE_ASSIGNMENT_ABILITIES)
            ->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => self::MOBILE_ASSIGNMENT_ABILITIES,
            'user' => $this->mobileUserPayload($user->fresh()),
        ]);
    }

    /**
     * Authenticate a user (specifically scanners/officers) and return a Sanctum API token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.'
            ], 401);
        }

        // Verify the user has administrative/scanning access
        if (!$user->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'Academic Unit Admin', 'Office Event Admin', 'HR Admin', 'Scanner'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. This account does not have scanner privileges.'
            ], 403);
        }

        // Issue Sanctum token
        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organization_id' => $user->organization_id,
                'roles' => $user->getRoleNames(),
            ]
        ]);
    }

    /**
     * Validate a tokenized deep-link session and return the scanner details.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateScannerSession(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|uuid',
            'device_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'A valid event ID is required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $eventId = $request->input('event_id');
        $event = Event::find($eventId);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found or invalid.'
            ], 404);
        }

        $access = $this->scannerSessions->validateRequest($request, $event, allowCompleted: true);
        if (!$access['allowed']) {
            return response()->json([
                'success' => false,
                'message' => $access['message'],
                'event_status' => $event->status,
                'event_phase' => $event->lifecycle_phase,
                'reason' => $access['reason'],
            ], $access['status']);
        }

        return response()->json([
            'success' => true,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'status' => $event->status,
                'lifecycle_phase' => $event->lifecycle_phase,
                'start_date' => $event->start_date,
                'end_date' => $event->end_date,
                'event_days' => $event->eventDays
                    ->sortBy('day_number')
                    ->values()
                    ->map(fn ($day) => [
                        'id' => $day->id,
                        'day_number' => $day->day_number,
                        'date' => $day->date,
                        'start_time' => $day->start_time,
                        'end_time' => $day->end_time,
                    ]),
            ],
            'scanner_session' => $this->scannerSessions->scannerState($event, $access['session']),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'roles' => $user->getRoleNames(),
            ]
        ]);
    }

    /**
     * Log the user out (revoke current token).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Token revoked successfully.'
        ]);
    }

    private function verifyGoogleIdToken(string $idToken): array
    {
        $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (!$response->ok()) {
            throw new \RuntimeException('Google tokeninfo rejected the ID token.');
        }

        $payload = $response->json();
        $clientIds = config('services.google.mobile_client_ids', []);
        if ($clientIds !== [] && !in_array($payload['aud'] ?? null, $clientIds, true)) {
            throw new \RuntimeException('Google ID token audience is not configured for this app.');
        }

        if (($payload['email_verified'] ?? 'false') !== 'true') {
            throw new \RuntimeException('Google email is not verified.');
        }

        return $payload;
    }

    private function mobileUserPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'organization_id' => $user->organization_id,
            'college_id' => $user->college_id,
            'program_id' => $user->program_id,
            'year_level' => $user->year_level,
            'user_type' => $user->user_type,
            'roles' => $user->getRoleNames(),
        ];
    }

    private function userTypeForEmail(string $email): string
    {
        return Str::startsWith(Str::lower($email), 's.')
            ? 'student'
            : 'faculty_or_staff_pending';
    }

    private function allowedEmailDomain(): string
    {
        return Str::lower(ltrim((string) config('services.eaes.allowed_email_domain', 'usm.edu.ph'), '@'));
    }
}
