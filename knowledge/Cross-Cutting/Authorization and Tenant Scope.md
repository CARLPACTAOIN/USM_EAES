---
type: cross-cutting
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Authorization and Tenant Scope

#cross-cutting

## Purpose

Keeps all admin, scanner, analytics, export, and pending-link actions inside active role and tenant boundaries.

## Related Domains

- [[Authentication and Access Control]]
- [[Organizations and Membership]]
- [[Event Management]]
- [[Attendance]]
- [[Analytics and Exports]]
- [[AI Insights]]

## Key Concepts

- [[Admin Assignment]]
- [[User]]
- [[Organization]]
- [[College]]
- [[Scanner Session]]

## Important Implementation

### Core
- `Web/app/Services/AdminScopeService.php` - Graphify nodes: 3
- `Web/app/Support/AdminScope.php` - Graphify nodes: 13
- `Web/app/Http/Middleware/TenantScopeMiddleware.php` - Graphify nodes: 3

### Boundaries
- `Web/app/Support/EventTenantScope.php` - Graphify nodes: 4
- `Web/app/Support/ScannerAccess.php` - Graphify nodes: 12
- `Web/app/Support/NlpQueryExecutor.php` - Graphify nodes: 5

### Tests
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43
- `Web/tests/Feature/OrganizationAnalyticsTest.php` - Graphify nodes: 16
- `Web/tests/Feature/WebPortalTest.php` - Graphify nodes: 122

## Important Rules

- Admin authority resolves from active admin assignments.
- Student profile membership does not authorize admin access.
- Scanner access is checked at activation, hydration, validation, and sync.

## Change Impact

- [[Event Management]]
- [[Attendance]]
- [[Scanner Mobile App]]
- [[Analytics and Exports]]
- [[AI Insights]]

<!-- END GENERATED:eaes-knowledge -->
