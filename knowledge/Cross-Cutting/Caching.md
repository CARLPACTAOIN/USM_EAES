---
type: cross-cutting
status: generated
source: graphify
---

<!-- BEGIN GENERATED:eaes-knowledge -->
# Caching

#cross-cutting

## Purpose

Keeps analytics responsive while invalidating stale event and audience metrics after attendance, evaluation, or scope changes.

## Related Domains

- [[Analytics and Exports]]
- [[Attendance]]
- [[Evaluation]]
- [[Event Management]]

## Key Concepts

- [[Event]]
- [[Attendance Record]]
- [[Evaluation Submission]]
- [[Participation Scope]]

## Important Implementation

### Cache
- `Web/app/Services/EventAnalyticsCache.php` - Graphify nodes: 10

### Observers
- `Web/app/Observers/EventAnalyticsCacheInvalidationObserver.php` - Graphify nodes: 6
- `Web/app/Observers/EventAnalyticsAudienceInvalidationObserver.php` - Graphify nodes: 5

### Tests
- `Web/tests/Feature/EventAnalyticsCacheTest.php` - Graphify nodes: 5
- `Web/tests/Feature/EventAnalyticsPageTest.php` - Graphify nodes: 17

## Important Rules

- Cache may accelerate analytics, but PostgreSQL remains authoritative.
- Audience changes and attendance/evaluation updates must invalidate related analytics.

## Change Impact

- [[Analytics and Exports]]
- [[Attendance]]
- [[Evaluation]]

<!-- END GENERATED:eaes-knowledge -->
