---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Event Management

#domain

## Purpose

Owns proposal drafting, PPA review, event schedules, participation scopes, event domains, and lifecycle state.

## Related Domains

- [[Organizations and Membership]]
- [[Attendance]]
- [[Scanner Mobile App]]
- [[Evaluation]]
- [[Analytics and Exports]]
- [[Authorization and Tenant Scope]]

## Key Concepts

- [[Event]]
- [[Event Day]]
- [[Participation Scope]]
- [[PPA]]
- [[Evaluation Form]]

## Main Workflows

- [[PPA Approval]]
- [[Event Lifecycle]]
- [[Participant Scope Approval]]
- [[Attendance Rules]]

## Cross-Cutting Concerns

- [[Authorization and Tenant Scope]]
- [[Audit Logging]]
- [[Evaluation Gate]]

## Important Implementation

### Models
- `Web/app/Models/Event.php` - Graphify nodes: 16
- `Web/app/Models/EventDay.php` - Graphify nodes: 5
- `Web/app/Models/EventParticipationScope.php` - Graphify nodes: 21
- `Web/app/Models/EvaluationFormVersion.php` - Graphify nodes: 4

### Services and Support
- `Web/app/Services/EventProposalDraftService.php` - Graphify nodes: 7
- `Web/app/Services/EventLifecycleService.php` - Graphify nodes: 15
- `Web/app/Services/ParticipationScopeService.php` - Graphify nodes: 17
- `Web/app/Services/DashboardEventWorkspaceService.php` - Graphify nodes: 18
- `Web/app/Support/EventTenantScope.php` - Graphify nodes: 4
- `Web/app/Support/EventEvaluationForm.php` - Graphify nodes: 6

### Controllers and Livewire
- `Web/app/Http/Controllers/Web/DashboardController.php` - Graphify nodes: 48
- `Web/app/Http/Controllers/Api/EventProposalController.php` - Graphify nodes: 13
- `Web/app/Livewire/Dashboard/EventProposalCreate.php` - Graphify nodes: 33
- `Web/app/Livewire/Dashboard/EventProposalWorkflow.php` - Graphify nodes: 19
- `Web/app/Livewire/Dashboard/EventParticipantScopes.php` - Graphify nodes: 31
- `Web/app/Livewire/Dashboard/EventAttendanceRules.php` - Graphify nodes: 20
- `Web/app/Livewire/Dashboard/EventsWorkspace.php` - Graphify nodes: 14

### Tests
- `Web/tests/Feature/WebPortalTest.php` - Graphify nodes: 122
- `Web/tests/Feature/ParticipantScopeConcurrencyTest.php` - Graphify nodes: 16
- `Web/tests/Feature/UsmEaesTest.php` - Graphify nodes: 43

## Important Rules

- PRD Epic 2 makes proposal approval separate from runtime lifecycle.
- Participant PPAs must be approved or marked not required before participant scanners, hydration, sync, and official analytics counting are allowed.
- Organizer attendance scope is automatically included for the organizing organization.
- HR-domain events bypass OSA PPA approval and use faculty/staff roster paths.

## Change Impact

Changes in this domain may affect:

- [[Attendance]]
- [[Scanner Mobile App]]
- [[Evaluation]]
- [[Analytics and Exports]]
- [[Authorization and Tenant Scope]]

<!-- END GENERATED:eaes-knowledge -->
