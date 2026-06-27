<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Event;
use App\Support\ScannerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
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
        if (!$user->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'ARO Admin', 'Scanner'])) {
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'A valid event ID is required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!ScannerAccess::canOpenScannerSession($user, $user->currentAccessToken())) {
            return response()->json([
                'success' => false,
                'message' => 'This token is not authorized for scanner sessions.',
            ], 403);
        }

        $eventId = $request->input('event_id');
        $event = Event::find($eventId);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found or invalid.'
            ], 404);
        }

        if (!in_array($event->status, ['approved', 'completed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Scanner session is locked until the event proposal is approved.',
                'event_status' => $event->status,
            ], 423);
        }

        // Verify the user has access to this event organization boundary
        if (!ScannerAccess::canAccessEvent($user, $event)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized event boundary check.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'status' => $event->status,
                'start_date' => $event->start_date,
                'end_date' => $event->end_date,
            ],
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
}
