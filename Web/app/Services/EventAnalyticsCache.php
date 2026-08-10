<?php

namespace App\Services;

use App\Models\Event;
use Closure;
use Illuminate\Support\Facades\Cache;

class EventAnalyticsCache
{
    private const SummaryTtlSeconds = 15;

    public function remember(Event $event, Closure $callback): array
    {
        return Cache::remember(
            $this->summaryKey($event->id),
            now()->addSeconds(self::SummaryTtlSeconds),
            $callback,
        );
    }

    public function invalidateEvent(string $eventId): void
    {
        $this->incrementVersion($this->eventVersionKey($eventId));
    }

    public function invalidateAudienceData(): void
    {
        $this->incrementVersion($this->audienceVersionKey());
    }

    private function summaryKey(string $eventId): string
    {
        return sprintf(
            'eaes:event-analytics:v1:event:%s:%d:audience:%d',
            $eventId,
            $this->version($this->eventVersionKey($eventId)),
            $this->version($this->audienceVersionKey()),
        );
    }

    private function eventVersionKey(string $eventId): string
    {
        return 'eaes:event-analytics:v1:event:'.$eventId.':version';
    }

    private function audienceVersionKey(): string
    {
        return 'eaes:event-analytics:v1:audience:version';
    }

    private function version(string $key): int
    {
        return (int) Cache::get($key, 1);
    }

    private function incrementVersion(string $key): void
    {
        Cache::add($key, 1);
        Cache::increment($key);
    }
}
