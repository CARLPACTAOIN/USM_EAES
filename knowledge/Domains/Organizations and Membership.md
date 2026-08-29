---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Organizations and Membership

#domain

## Purpose

Models USM colleges, programs, organizations, membership records, employee rosters, and admin assignment scopes.

## Related Domains

- [[Authentication and Access Control]]
- [[Event Management]]
- [[Attendance]]
- [[Analytics and Exports]]
- [[HR Attendance]]

## Key Concepts

- [[Organization]]
- [[College]]
- [[Program]]
- [[Organization Membership]]
- [[Admin Assignment]]
- [[Employee Roster]]

## Main Workflows

- [[Admin Assignment Governance]]
- [[Participant Scope Approval]]
- [[Organization Analytics]]

## Cross-Cutting Concerns

- [[Authorization and Tenant Scope]]
- [[Audit Logging]]

## Important Implementation

### Models
- `Web/app/Models/Organization.php` - Graphify nodes: 10
- `Web/app/Models/College.php` - Graphify nodes: 5
- `Web/app/Models/Program.php` - Graphify nodes: 5
- `Web/app/Models/OrganizationMembership.php` - Graphify nodes: 7
- `Web/app/Models/AdminAssignment.php` - Graphify nodes: 7
- `Web/app/Models/EmployeeProfile.php` - Graphify nodes: 5
- `Web/app/Models/EmployeeRosterEntry.php` - Graphify nodes: 5

### Services and Support
- `Web/app/Services/OrganizationManagementService.php` - Graphify nodes: 6
- `Web/app/Services/AdminAssignmentService.php` - Graphify nodes: 11
- `Web/app/Services/AdminScopeService.php` - Graphify nodes: 3
- `Web/app/Support/AdminScope.php` - Graphify nodes: 13
- `Web/app/Support/YearLevels.php` - Graphify nodes: 5

### Controllers and Livewire
- `Web/app/Http/Controllers/Web/AdminAccessController.php` - Graphify nodes: 18
- `Web/app/Http/Controllers/Web/OrganizationProfileController.php` - Graphify nodes: 7
- `Web/app/Livewire/Dashboard/AdminAccessWorkspace.php` - Graphify nodes: 19

### Tests
- `Web/tests/Feature/AdminOnboardingTest.php` - Graphify nodes: 32
- `Web/tests/Feature/OrganizationAnalyticsTest.php` - Graphify nodes: 16
- `Web/tests/Feature/WebPortalTest.php` - Graphify nodes: 122

## Important Rules

- PRD Epic 1.3 makes admin_assignments the source of truth for administrative authority and tenant scope.
- Student profile membership fields are not admin tenant scope.
- Colleges and programs are official master data maintained by OSA/Super Admin.
- Organization memberships feed rosters, eligibility, and analytics denominators.

## Change Impact

Changes in this domain may affect:

- [[Authorization and Tenant Scope]]
- [[Event Management]]
- [[Scanner Mobile App]]
- [[Analytics and Exports]]

<!-- END GENERATED:eaes-knowledge -->
