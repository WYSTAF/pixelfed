<?php

namespace App\Util\ActivityPub\Inbox;

use App\Jobs\LikePipeline\LikePipeline;
use App\Models\Like;
use App\Util\ActivityPub\Helpers;

trait HandlesLikes
{
    public function handleLikeActivity(): void
    {
        $actor = $this->payload['actor'];

        if (! Helpers::validateUrl($actor)) {
            return;
        }

        $profile = $this->validateAndFetchActor($actor);
        $obj = $this->payload['object'];

        if (! Helpers::validateUrl($obj)) {
            return;
        }

        $status = Helpers::statusFirstOrFetch($obj);

        if (! $status || ! $profile) {
            return;
        }

        if ($this->isDomainBlocked($status->profile_id, $profile->domain)) {
            return;
        }

        if ($this->isUserBlocked($status->profile_id, $profile->id)) {
            return;
        }

        $like = Like::firstOrCreate([
            'profile_id' => $profile->id,
            'status_id' => $status->id,
        ]);

        if ($like->wasRecentlyCreated == true) {
            /*
             * Atomic increment, not a read-modify-write on the model. Two
             * likes federated for the same post at the same time both read the
             * same likes_count here, and the loser's write dropped one --
             * undercounting the post for good, since nothing recounts it.
             * This is the same call the local like path uses
             * (ApiV1Controller::statusFavourite).
             */
            $status->increment('likes_count');

            LikePipeline::dispatch($like);
        }
    }
}
