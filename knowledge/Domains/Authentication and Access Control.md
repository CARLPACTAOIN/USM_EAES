---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Authentication and Access Control

#domain

## Purpose

Handles institutional login, Sanctum API tokens, Spatie role projection, tenant-scoped admin authority, and scanner token boundaries.

## Related Domains

- [[Organizations and Membership]]
- [[Scanner Mobile App]]
- [[Event Management]]
- [[Attendance]]
- [[AI Insights]]

## Key Concepts

- [[User]]
- [[Admin Assignment]]
- [[Scanner Assignment]]
- [[Scanner Session]]
- [[Organization]]

## Main Workflows

- [[Google Authentication]]
- [[Admin Assignment Governance]]
- [[Scanner Assignment and Activation]]

## Cross-Cutting Concerns

- [[Authorization and Tenant Scope]]
- [[Device Binding]]
- [[Audit Logging]]

## Important Implementation

### Models and Middleware
- `Web/app/Models/User.php` - Graphify nodes: 15
- `Web/app/Models/AdminAssignment.php` - Graphify nodes: 7
- `Web/app/Http/Middleware/TenantScopeMiddleware.php` - Graphify nodes: 3
- `Web/app/Http/Middleware/SecurityHeaders.php` - Graphify nodes: 3

### Controllers and Services
- `Web/app/Http/Controllers/Auth/GoogleAuthController.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Api/AuthController.php` - Graphify nodes: 11
- `Web/app/Services/AdminScopeService.php` - Graphify nodes: 3
- `Web/app/Services/AdminAssignmentService.php` - Graphify nodes: 11
- `Web/app/Services/ScannerSessionService.php` - Graphify nodes: 17

### Flutter Auth
- `Application/lib/features/auth/data/auth_api_client.dart` - Graphify nodes: 20
- `Application/lib/features/auth/data/google_auth_gateway.dart` - Graphify nodes: 22
- `Application/lib/features/auth/data/device_identity_provider.dart` - Graphify nodes: 9
- `Application/lib/features/auth/presentation/auth_controller.dart` - Graphify nodes: 30
- `Application/lib/features/auth/presentation/auth_gate.dart` - Graphify nodes: 13

### Tests
- `Web/tests/Feature/GoogleAuthControllerTest.php` - Graphify nodes: 4
- `Web/tests/Feature/MobileGoogleAuthTest.php` - Graphify nodes: 6
- `Application/test/auth_api_client_test.dart` - Graphify nodes: 2
- `Application/test/auth_google_sign_in_test.dart` - Graphify nodes: 62
- `Application/test/device_identity_provider_test.dart` - Graphify nodes: 2

## Important Rules

- Institutional Google login must enforce the usm.edu.ph domain.
- Sanctum tokens are used for API/mobile paths, including scanner sessions.
- Spatie roles are permission projection; admin_assignments carry active tenant authority.
- Scanner hydrate and sync require an activated device-bound scanner session token.

## Change Impact

Changes in this domain may affect:

- [[Event Management]]
- [[Scanner Mobile App]]
- [[Attendance]]
- [[Analytics and Exports]]
- [[AI Insights]]

<!-- END GENERATED:eaes-knowledge -->
