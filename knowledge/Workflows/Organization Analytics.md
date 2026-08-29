---
type: workflow
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Organization Analytics

#workflow

## Summary

Organization and program-level performance metrics aggregate attendance and evaluation data under tenant-scoped filters.

## Domains

- [[Analytics and Exports]]
- [[Organizations and Membership]]
- [[Attendance]]
- [[Evaluation]]

## Concepts

- [[Organization]]
- [[Program]]
- [[Attendance Record]]
- [[Evaluation Submission]]

## Flow

1. Admin opens analytics
2. Scope and filters resolved
3. Organization metrics calculated
4. Program leaderboard calculated
5. Event drilldowns and exports available

## Implementation

- `Web/app/Services/OrganizationAnalyticsService.php` - Graphify nodes: 13
- `Web/app/Http/Controllers/Web/AnalyticsOverviewController.php` - Graphify nodes: 6
- `Web/app/Livewire/Dashboard/AnalyticsOverviewFilters.php` - Graphify nodes: 12

## Important Rules

- OSA can see all organization analytics; LSG/Society scopes are constrained by active assignment.
- Manual non-system Gawad fields remain deferred.

<!-- END GENERATED:eaes-knowledge -->
