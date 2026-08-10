<?php

namespace App\Observers;

use App\Models\Event;
use App\Services\EventAnalyticsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class EventAnalyticsCacheInvalidationObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly EventAnalyticsCache $cache) {}

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $eventId = $model instanceof Event ? $model->getKey() : $model->getAttribute('event_id');

        if (filled($eventId)) {
            $this->cache->invalidateEvent((string) $eventId);
        }
    }
}
