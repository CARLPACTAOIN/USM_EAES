---
paths:
  - 'app/Services/EventAnalytics*'
---

# Services

## Versioned event analytics cache
Cache only EventAnalyticsService::summary() for 15 seconds. Keep authorization outside the cache boundary, advance the event version after event-scoped source writes, and advance the audience version when profile or membership data changes. Do not cache participant drilldown rows or scanner decisions.
