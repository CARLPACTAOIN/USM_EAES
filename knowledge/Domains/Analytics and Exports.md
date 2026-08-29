---
type: domain
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Analytics and Exports

#domain

## Purpose

Builds per-event analytics, organization performance metrics, Gawad Parangal indicators, and scoped PDF/Excel exports.

## Related Domains

- [[Attendance]]
- [[Evaluation]]
- [[Organizations and Membership]]
- [[Event Management]]
- [[AI Insights]]

## Key Concepts

- [[Attendance Record]]
- [[Evaluation Submission]]
- [[Organization]]
- [[Event]]

## Main Workflows

- [[Organization Analytics]]
- [[Analytics Export]]
- [[AI Sentiment and NLP]]

## Cross-Cutting Concerns

- [[Caching]]
- [[Authorization and Tenant Scope]]
- [[Audit Logging]]

## Important Implementation

### Services and Support
- `Web/app/Services/EventAnalyticsService.php` - Graphify nodes: 28
- `Web/app/Services/OrganizationAnalyticsService.php` - Graphify nodes: 13
- `Web/app/Services/EventAnalyticsCache.php` - Graphify nodes: 10
- `Web/app/Support/EventExportData.php` - Graphify nodes: 13

### Controllers and Livewire
- `Web/app/Http/Controllers/Api/AnalyticsController.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Web/EventAnalyticsController.php` - Graphify nodes: 5
- `Web/app/Http/Controllers/Web/AnalyticsOverviewController.php` - Graphify nodes: 6
- `Web/app/Http/Controllers/Web/EventExportController.php` - Graphify nodes: 8
- `Web/app/Livewire/Dashboard/AnalyticsOverviewFilters.php` - Graphify nodes: 12
- `Web/app/Livewire/Dashboard/EventAnalyticsParticipantFilters.php` - Graphify nodes: 15

### Exports and Observers
- `Web/app/Exports/EventAttendanceExport.php` - Graphify nodes: 4
- `Web/app/Exports/EventEvaluationExport.php` - Graphify nodes: 4
- `Web/app/Observers/EventAnalyticsCacheInvalidationObserver.php` - Graphify nodes: 6
- `Web/app/Observers/EventAnalyticsAudienceInvalidationObserver.php` - Graphify nodes: 5

### Tests
- `Web/tests/Feature/EventAnalyticsPageTest.php` - Graphify nodes: 17
- `Web/tests/Feature/OrganizationAnalyticsTest.php` - Graphify nodes: 16
- `Web/tests/Feature/EventAnalyticsCacheTest.php` - Graphify nodes: 5

## Important Rules

- PRD Epic 6 requires attendance percentages, evaluation averages, demographic breakdowns, and exportable summaries.
- Analytics must honor admin assignment tenant scope.
- Exports include event metadata, attendance validity, evaluation ratings, comments, sentiment fields, timestamps, and generating user.
- Organization analytics separate system-measurable indicators from deferred manual non-system metrics.

## Change Impact

Changes in this domain may affect:

- [[Attendance]]
- [[Evaluation]]
- [[Organizations and Membership]]
- [[AI Insights]]

<!-- END GENERATED:eaes-knowledge -->
