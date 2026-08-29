---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# HR Attendance

#domain

## Purpose

Covers HR-domain events, faculty/staff onboarding separation, employee roster verification, and HR-specific scanner hydration.

## Related Domains

- [[Organizations and Membership]]
- [[Event Management]]
- [[Scanner Mobile App]]
- [[Attendance]]
- [[Analytics and Exports]]

## Key Concepts

- [[Employee Roster]]
- [[Event]]
- [[Scanner Session]]
- [[Attendance Record]]

## Main Workflows

- [[Google Authentication]]
- [[Scanner Assignment and Activation]]
- [[Offline Attendance Sync]]

## Cross-Cutting Concerns

- [[Authorization and Tenant Scope]]
- [[Offline Sync]]
- [[Device Binding]]

## Important Implementation

### Models
- `Web/app/Models/EmployeeProfile.php` - Graphify nodes: 5
- `Web/app/Models/EmployeeRosterEntry.php` - Graphify nodes: 5
- `Web/app/Models/Event.php` - Graphify nodes: 16
- `Web/app/Models/ScannerSession.php` - Graphify nodes: 8

### Services
- `Web/app/Services/ParticipationScopeService.php` - Graphify nodes: 17
- `Web/app/Services/ScannerSessionService.php` - Graphify nodes: 17
- `Web/app/Services/OrganizationAnalyticsService.php` - Graphify nodes: 13

### Controllers
- `Web/app/Http/Controllers/Api/AttendanceSyncController.php` - Graphify nodes: 7
- `Web/app/Http/Controllers/Api/ScannerAssignmentController.php` - Graphify nodes: 14
- `Web/app/Http/Controllers/Api/AuthController.php` - Graphify nodes: 11

### Tests
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43
- `Web/tests/Feature/MobileGoogleAuthTest.php` - Graphify nodes: 6
- `Web/tests/Feature/OrganizationAnalyticsTest.php` - Graphify nodes: 16

## Important Rules

- PRD Epic 2.4 separates HR events through events.event_domain.
- HR events use faculty/staff attendance rosters and do not require OSA PPA approval.
- Faculty/staff trust comes from HR roster verification or imported employee records.
- HR analytics and exports must stay scoped to HR-domain authority.

## Change Impact

Changes in this domain may affect:

- [[Organizations and Membership]]
- [[Authentication and Access Control]]
- [[Scanner Mobile App]]
- [[Analytics and Exports]]

<!-- END GENERATED:eaes-knowledge -->
