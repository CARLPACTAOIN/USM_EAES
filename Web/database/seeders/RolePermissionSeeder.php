<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create permissions
        $permissions = [
            // Super Admin / OSA specific
            'manage-organizations',
            'manage-venues',
            'approve-proposals',
            'view-all-analytics',
            'force-validate-any',
            'manage-global-settings',

            // Organizational administrative
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'set-late-threshold',
            'view-own-analytics',
            'view-college-analytics',
            'view-constituent-data',
            'force-validate-own',
            'invite-participant-scopes',
            'create-hr-events',
            'manage-faculty-roster',
            'assign-hr-scanners',
            'view-hr-analytics',
            'export-hr-attendance',

            // Scanners
            'scan-qr-codes',
            'manual-entry-id',

            // Common / Members
            'register-profile',
            'view-own-history',
            'submit-evaluations',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'api']);
        }

        // 2. Create roles and assign seeded permissions

        // Super Admin (OSA)
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin (OSA)', 'guard_name' => 'web']);
        $superAdmin->syncPermissions([
            'manage-organizations',
            'manage-venues',
            'approve-proposals',
            'view-all-analytics',
            'force-validate-any',
            'manage-global-settings',
            'invite-participant-scopes',
            'create-hr-events',
            'manage-faculty-roster',
            'assign-hr-scanners',
            'view-hr-analytics',
            'export-hr-attendance',
        ]);
        $superAdminApi = Role::firstOrCreate(['name' => 'Super Admin (OSA)', 'guard_name' => 'api']);
        $superAdminApi->syncPermissions([
            'manage-organizations',
            'manage-venues',
            'approve-proposals',
            'view-all-analytics',
            'force-validate-any',
            'manage-global-settings',
            'invite-participant-scopes',
            'create-hr-events',
            'manage-faculty-roster',
            'assign-hr-scanners',
            'view-hr-analytics',
            'export-hr-attendance',
        ]);

        // USG Admin
        $usgAdmin = Role::firstOrCreate(['name' => 'USG Admin', 'guard_name' => 'web']);
        $usgAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'view-own-analytics',
        ]);
        $usgAdminApi = Role::firstOrCreate(['name' => 'USG Admin', 'guard_name' => 'api']);
        $usgAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'view-own-analytics',
        ]);

        // LSG Admin
        $lsgAdmin = Role::firstOrCreate(['name' => 'LSG Admin', 'guard_name' => 'web']);
        $lsgAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'view-college-analytics',
            'view-constituent-data',
        ]);
        $lsgAdminApi = Role::firstOrCreate(['name' => 'LSG Admin', 'guard_name' => 'api']);
        $lsgAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'view-college-analytics',
            'view-constituent-data',
        ]);

        // Society Admin
        $societyAdmin = Role::firstOrCreate(['name' => 'Society Admin', 'guard_name' => 'web']);
        $societyAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'set-late-threshold',
            'view-own-analytics',
            'force-validate-own',
        ]);
        $societyAdminApi = Role::firstOrCreate(['name' => 'Society Admin', 'guard_name' => 'api']);
        $societyAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'set-late-threshold',
            'view-own-analytics',
            'force-validate-own',
        ]);

        // Academic Unit Admin
        $academicUnitAdmin = Role::firstOrCreate(['name' => 'Academic Unit Admin', 'guard_name' => 'web']);
        $academicUnitAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'invite-participant-scopes',
            'view-college-analytics',
            'view-constituent-data',
            'force-validate-own',
        ]);
        $academicUnitAdminApi = Role::firstOrCreate(['name' => 'Academic Unit Admin', 'guard_name' => 'api']);
        $academicUnitAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'invite-participant-scopes',
            'view-college-analytics',
            'view-constituent-data',
            'force-validate-own',
        ]);

        // Office Event Admin
        $officeEventAdmin = Role::firstOrCreate(['name' => 'Office Event Admin', 'guard_name' => 'web']);
        $officeEventAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'invite-participant-scopes',
            'view-own-analytics',
            'force-validate-own',
        ]);
        $officeEventAdminApi = Role::firstOrCreate(['name' => 'Office Event Admin', 'guard_name' => 'api']);
        $officeEventAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'invite-participant-scopes',
            'view-own-analytics',
            'force-validate-own',
        ]);

        // HR Admin
        $hrAdmin = Role::firstOrCreate(['name' => 'HR Admin', 'guard_name' => 'web']);
        $hrAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'create-hr-events',
            'manage-faculty-roster',
            'assign-hr-scanners',
            'view-hr-analytics',
            'export-hr-attendance',
            'view-own-analytics',
            'force-validate-own',
        ]);
        $hrAdminApi = Role::firstOrCreate(['name' => 'HR Admin', 'guard_name' => 'api']);
        $hrAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-attendance-policy',
            'create-hr-events',
            'manage-faculty-roster',
            'assign-hr-scanners',
            'view-hr-analytics',
            'export-hr-attendance',
            'view-own-analytics',
            'force-validate-own',
        ]);

        // Scanner
        $scanner = Role::firstOrCreate(['name' => 'Scanner', 'guard_name' => 'web']);
        $scanner->syncPermissions([
            'scan-qr-codes',
            'manual-entry-id',
        ]);
        $scannerApi = Role::firstOrCreate(['name' => 'Scanner', 'guard_name' => 'api']);
        $scannerApi->syncPermissions([
            'scan-qr-codes',
            'manual-entry-id',
        ]);

        // Faculty
        $faculty = Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        $faculty->syncPermissions([
            'view-own-history',
            'submit-evaluations',
        ]);
        $facultyApi = Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'api']);
        $facultyApi->syncPermissions([
            'view-own-history',
            'submit-evaluations',
        ]);

        // Student
        $student = Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web']);
        $student->syncPermissions([
            'register-profile',
            'view-own-history',
            'submit-evaluations',
        ]);
        $studentApi = Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'api']);
        $studentApi->syncPermissions([
            'register-profile',
            'view-own-history',
            'submit-evaluations',
        ]);
    }
}
