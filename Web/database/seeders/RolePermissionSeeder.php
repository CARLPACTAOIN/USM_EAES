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
            'approve-proposals',
            'view-all-analytics',
            'force-validate-any',
            'manage-global-settings',

            // Organizational administrative
            'create-proposals',
            'assign-scanners-own',
            'set-late-threshold',
            'view-own-analytics',
            'view-college-analytics',
            'view-constituent-data',
            'force-validate-own',

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
            'approve-proposals',
            'view-all-analytics',
            'force-validate-any',
            'manage-global-settings',
        ]);
        $superAdminApi = Role::firstOrCreate(['name' => 'Super Admin (OSA)', 'guard_name' => 'api']);
        $superAdminApi->syncPermissions([
            'manage-organizations',
            'approve-proposals',
            'view-all-analytics',
            'force-validate-any',
            'manage-global-settings',
        ]);

        // USG Admin
        $usgAdmin = Role::firstOrCreate(['name' => 'USG Admin', 'guard_name' => 'web']);
        $usgAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'view-own-analytics',
        ]);
        $usgAdminApi = Role::firstOrCreate(['name' => 'USG Admin', 'guard_name' => 'api']);
        $usgAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'view-own-analytics',
        ]);

        // LSG Admin
        $lsgAdmin = Role::firstOrCreate(['name' => 'LSG Admin', 'guard_name' => 'web']);
        $lsgAdmin->syncPermissions([
            'create-proposals',
            'view-college-analytics',
            'view-constituent-data',
        ]);
        $lsgAdminApi = Role::firstOrCreate(['name' => 'LSG Admin', 'guard_name' => 'api']);
        $lsgAdminApi->syncPermissions([
            'create-proposals',
            'view-college-analytics',
            'view-constituent-data',
        ]);

        // Society Admin
        $societyAdmin = Role::firstOrCreate(['name' => 'Society Admin', 'guard_name' => 'web']);
        $societyAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-late-threshold',
            'view-own-analytics',
            'force-validate-own',
        ]);
        $societyAdminApi = Role::firstOrCreate(['name' => 'Society Admin', 'guard_name' => 'api']);
        $societyAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'set-late-threshold',
            'view-own-analytics',
            'force-validate-own',
        ]);

        // ARO Admin
        $aroAdmin = Role::firstOrCreate(['name' => 'ARO Admin', 'guard_name' => 'web']);
        $aroAdmin->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'view-own-analytics',
        ]);
        $aroAdminApi = Role::firstOrCreate(['name' => 'ARO Admin', 'guard_name' => 'api']);
        $aroAdminApi->syncPermissions([
            'create-proposals',
            'assign-scanners-own',
            'view-own-analytics',
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
