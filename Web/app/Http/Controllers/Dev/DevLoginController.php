<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class DevLoginController extends Controller
{
    private const PSITS_ADMIN_EMAILS = [
        'society.demo@usm.edu.ph',
        'psits.admin@usm.edu.ph',
    ];

    public function loginAsPsitsAdmin(): RedirectResponse
    {
        $this->ensureDevLoginEnabled();

        [$college, $program, $psits] = $this->bootstrapPsitsContext();
        $user = $this->resolvePsitsAdminUser($psits, $college, $program);

        Auth::login($user);

        return redirect()
            ->route('dashboard')
            ->with('success', 'Logged in as PSITS Society Admin ('.$user->email.')');
    }

    private function ensureDevLoginEnabled(): void
    {
        abort_unless(
            config('app.env') === 'local' && config('services.eaes.dev_login_enabled'),
            404
        );
    }

    /**
     * @return array{0: College, 1: Program, 2: Organization}
     */
    private function bootstrapPsitsContext(): array
    {
        $university = University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao']
        );

        $college = College::firstOrCreate(
            ['code' => 'CEIT'],
            [
                'name' => 'College of Engineering and Information Technology',
                'university_id' => $university->id,
            ]
        );

        $program = Program::firstOrCreate(
            ['college_id' => $college->id, 'code' => 'BSIS'],
            ['name' => 'BS Information Systems']
        );

        $psits = Organization::firstOrCreate(
            [
                'acronym' => 'PSITS',
                'type' => 'society',
                'college_id' => $college->id,
            ],
            [
                'name' => 'Philippine Society of Information Technology Students',
                'status' => 'active',
            ]
        );

        $programIds = Program::query()
            ->where('college_id', $college->id)
            ->whereIn('code', ['BSIS', 'BSCS'])
            ->pluck('id');

        if ($programIds->isEmpty()) {
            $programIds = collect([$program->id]);
        }

        $psits->programs()->syncWithoutDetaching($programIds);

        return [$college, $program, $psits];
    }

    private function resolvePsitsAdminUser(
        Organization $psits,
        College $college,
        Program $program
    ): User {
        $existingUsers = collect(self::PSITS_ADMIN_EMAILS)
            ->map(fn (string $email) => User::where('email', $email)->first())
            ->filter();

        $assignedUser = $existingUsers->first(
            fn (User $user) => $this->hasActivePsitsSocietyAdminAssignment($user, $psits)
        );

        if ($assignedUser) {
            return $assignedUser;
        }

        $existingUser = $existingUsers->first();

        if ($existingUser) {
            $this->ensurePsitsSocietyAdminAssignment($existingUser, $psits, $college);

            return $existingUser->fresh();
        }

        $user = User::updateOrCreate(
            ['email' => 'psits.admin@usm.edu.ph'],
            [
                'name' => 'Admin of PSITS',
                'google_sub' => 'dev-psits-admin',
                'password' => null,
                'organization_id' => $psits->id,
                'college_id' => $college->id,
                'program_id' => $program->id,
                'program_code' => $program->code,
            ]
        );

        $this->ensurePsitsSocietyAdminAssignment($user, $psits, $college);

        return $user->fresh();
    }

    private function hasActivePsitsSocietyAdminAssignment(User $user, Organization $psits): bool
    {
        return $user->adminAssignments()
            ->where('role_name', 'Society Admin')
            ->where('organization_id', $psits->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->exists();
    }

    private function ensurePsitsSocietyAdminAssignment(
        User $user,
        Organization $psits,
        College $college
    ): void {
        if ($this->hasActivePsitsSocietyAdminAssignment($user, $psits)) {
            app(AdminAssignmentService::class)->syncUserProjection($user);

            return;
        }

        $academicYear = now()->year.'-'.(now()->year + 1);

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: 'Society Admin',
            organization: $psits,
            college: $college,
            academicYear: $academicYear,
            termStart: now()->toDateString(),
            termEnd: now()->addYear()->toDateString(),
            positionTitle: 'Dev Login',
            approvedBy: $user,
            isPrimaryAdmin: true
        );
    }
}
