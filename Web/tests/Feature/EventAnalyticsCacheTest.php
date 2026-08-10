<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\OrganizationMembership;
use App\Observers\EventAnalyticsAudienceInvalidationObserver;
use App\Observers\EventAnalyticsCacheInvalidationObserver;
use App\Services\EventAnalyticsCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EventAnalyticsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_summary_is_cached_until_the_event_version_changes(): void
    {
        $event = new Event(['id' => 'event-analytics-cache-test']);
        $event->setAttribute('id', 'event-analytics-cache-test');
        $cache = app(EventAnalyticsCache::class);
        $calls = 0;

        $first = $cache->remember($event, function () use (&$calls): array {
            $calls++;

            return ['calls' => $calls];
        });
        $second = $cache->remember($event, function () use (&$calls): array {
            $calls++;

            return ['calls' => $calls];
        });

        $this->assertSame(['calls' => 1], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);

        app(EventAnalyticsCacheInvalidationObserver::class)->saved($event);

        $third = $cache->remember($event, function () use (&$calls): array {
            $calls++;

            return ['calls' => $calls];
        });

        $this->assertSame(['calls' => 2], $third);
        $this->assertSame(2, $calls);
    }

    public function test_audience_invalidation_recomputes_every_event_summary(): void
    {
        $event = new Event(['id' => 'event-analytics-cache-audience-test']);
        $event->setAttribute('id', 'event-analytics-cache-audience-test');
        $cache = app(EventAnalyticsCache::class);
        $calls = 0;

        $cache->remember($event, function () use (&$calls): array {
            $calls++;

            return ['calls' => $calls];
        });

        app(EventAnalyticsAudienceInvalidationObserver::class)->saved(new OrganizationMembership);

        $summary = $cache->remember($event, function () use (&$calls): array {
            $calls++;

            return ['calls' => $calls];
        });

        $this->assertSame(['calls' => 2], $summary);
        $this->assertSame(2, $calls);
    }
}
