<?php

namespace App\Util\ActivityPub\Inbox;

use App\Models\Status;
use App\Services\NotificationService;
use App\Services\ReblogService;
use App\Services\StatusService;
use App\Util\ActivityPub\Helpers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HandlesAnnouncements
{
    public function handleAnnounceActivity(): void
    {
        $actor = $this->validateAndFetchActor($this->payload['actor']);
        $activity = $this->payload['object'];

        if (! $actor || $actor->domain == null) {
            return;
        }

        try {
            $parent = Helpers::statusFetch($activity);
        } catch (\Exception $e) {
            $context = json_decode($e->getMessage(), true);

            Log::debug('Announce: skipped fetching status', [
                'actor' => $actor->id,
                'actor_domain' => $actor->domain,
                'reason' => is_array($context) ? $context : $e->getMessage(),
                'activity' => $activity,
            ]);

            return;
        }

        if (! $parent) {
            return;
        }

        if ($this->isDomainBlocked($parent->profile_id, $actor->domain)) {
            return;
        }

        if ($this->isUserBlocked($parent->profile_id, $actor->id)) {
            return;
        }

        $status = Status::firstOrCreate([
            // Empty strings, not null: statuses.caption is NOT NULL on every
            // driver except PostgreSQL (2018_08_12 changed it without
            // ->nullable(), and the 2025_02_10 repair only runs when the driver
            // *is* postgres). A remote share supplies no caption, so without
            // this the whole Announce fails on sqlite and MySQL. Matches the
            // local share path in ApiV1Controller::statusShare.
            'caption' => '',
            'rendered' => '',
            'profile_id' => $actor->id,
            'reblog_of_id' => $parent->id,
            'type' => 'share',
            'local' => false,
        ]);

        NotificationService::firstOrCreateNotification($parent->profile_id, $actor->id, 'share', $parent->id, Status::class);

        /*
         * Only count a share we actually created. This used to increment
         * unconditionally, so a redelivered Announce -- which firstOrCreate
         * turns into a no-op -- still bumped reblogs_count, inflating the
         * parent's count for every duplicate that ever arrived.
         *
         * The increment is a database-side statement for the same reason as
         * the local path in SharePipeline: a read-modify-write on the model
         * loses one of two concurrent Announces. The Cache::add guard is
         * SharePipeline's own double-count protection, applied the same way
         * here so both paths agree on what counts as new.
         */
        if ($status->wasRecentlyCreated && Cache::add('pf:services:share:counted:'.$status->id, 1, now()->addDays(30))) {
            Status::whereId($parent->id)->increment('reblogs_count');
            StatusService::del($parent->id);
        }

        ReblogService::addPostReblog($parent->profile_id, $status->id);
    }
}
