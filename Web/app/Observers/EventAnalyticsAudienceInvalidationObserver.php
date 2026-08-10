<?php

namespace App\Observers;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\EventAnalyticsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class EventAnalyticsAudienceInvalidationObserver implements ShouldHandleEventsAfterCommit
{
    /** @var list<string> */
    private const UserAnalyticsAttributes = [
        'name',
        'organization_id',
        'college_id',
        'program_id',
        'program_code',
        'year_level',
    ];

    public function __construct(private readonly EventAnalyticsCache $cache) {}

    public function saved(Model $model): void
    {
        if ($model instanceof OrganizationMembership
            || ($model instanceof User && ($model->wasRecentlyCreated || $model->wasChanged(self::UserAnalyticsAttributes)))) {
            $this->cache->invalidateAudienceData();
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof User || $model instanceof OrganizationMembership) {
            $this->cache->invalidateAudienceData();
        }
    }
}
