<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\AdminScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

class OrganizationProfileController extends Controller
{
    public function __construct(private readonly AdminScopeService $adminScopes) {}

    public function show(Request $request)
    {
        $organization = $this->scopedOrganization($request);

        return view('dashboard.organization-profile.show', [
            'organization' => $organization,
        ]);
    }

    public function updateLogo(Request $request)
    {
        $organization = $this->scopedOrganization($request);

        $validated = $request->validate([
            'logo' => [
                'required',
                File::image()
                    ->types(['png', 'jpg', 'jpeg', 'webp'])
                    ->max(2 * 1024),
            ],
        ]);

        $previousLogoPath = $organization->logo_path;
        $newLogoPath = $validated['logo']->store('organizations/logos', 'public');

        $organization->forceFill([
            'logo_path' => $newLogoPath,
        ])->save();

        if ($previousLogoPath && $previousLogoPath !== $newLogoPath) {
            Storage::disk('public')->delete($previousLogoPath);
        }

        AuditLog::create([
            'target_id' => $organization->id,
            'admin_id' => $request->user()->id,
            'action' => 'update-organization-logo',
            'details' => [
                'organization_id' => $organization->id,
                'organization_acronym' => $organization->acronym,
                'previous_logo_path' => $previousLogoPath,
                'new_logo_path' => $newLogoPath,
                'previous_logo_present' => filled($previousLogoPath),
                'new_logo_present' => true,
            ],
        ]);

        return redirect()->route('dashboard.organization-profile')
            ->with('success', 'Organization logo updated.');
    }

    public function destroyLogo(Request $request)
    {
        $organization = $this->scopedOrganization($request);
        $previousLogoPath = $organization->logo_path;

        if ($previousLogoPath) {
            $organization->forceFill([
                'logo_path' => null,
            ])->save();

            Storage::disk('public')->delete($previousLogoPath);

            AuditLog::create([
                'target_id' => $organization->id,
                'admin_id' => $request->user()->id,
                'action' => 'delete-organization-logo',
                'details' => [
                    'organization_id' => $organization->id,
                    'organization_acronym' => $organization->acronym,
                    'previous_logo_path' => $previousLogoPath,
                    'new_logo_path' => null,
                    'previous_logo_present' => true,
                    'new_logo_present' => false,
                ],
            ]);
        }

        return redirect()->route('dashboard.organization-profile')
            ->with('success', $previousLogoPath ? 'Organization logo removed.' : 'No organization logo to remove.');
    }

    private function scopedOrganization(Request $request): Organization
    {
        $scope = $this->adminScopes->forUser($request->user());

        abort_unless($scope && ! $scope->isSuperAdmin() && $scope->organization, 403);

        return $scope->organization->loadMissing(['college', 'programs']);
    }
}
