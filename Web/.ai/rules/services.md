---
paths:
  - 'app/Services/EventAnalytics*'
  - app/Services/OrganizationAnalyticsService.php
---

# Services

## Versioned event analytics cache
Cache only EventAnalyticsService::summary() for 15 seconds. Keep authorization outside the cache boundary, advance the event version after event-scoped source writes, and advance the audience version when profile or membership data changes. Do not cache participant drilldown rows or scanner decisions.

## Keep Gawad analytics organizer-owned
Gawad metrics include only completed, non-HR events owned by the requested organization. Use the active analytics-enabled organizer scope for each attendance denominator with the legacy audience fallback when absent; do not credit participant-organization scope rollups. Pool numeric scores using each event's evaluation question map.
