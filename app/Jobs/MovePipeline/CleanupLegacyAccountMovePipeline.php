<?php

namespace App\Jobs\MovePipeline;

use App\Models\Follower;
use App\Models\Profile;
use App\Models\UserFilter;
use App\Services\AccountService;
use App\Services\FollowerService;
use App\Util\ActivityPub\Helpers;
use DateTime;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

class CleanupLegacyAccountMovePipeline implements ShouldQueue
{
    use Queueable;

    public $target;

    public $activity;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 6;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct($target, $activity)
    {
        $this->target = $target;
        $this->activity = $activity;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('process-move-cleanup-legacy-followers:'.$this->target),
            (new ThrottlesExceptions(2, 5 * 60))->backoff(5),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTime
    {
        return now()->addMinutes(5);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('app.env') !== 'production' || (bool) config_cache('federation.activitypub.enabled') === false) {
            throw new Exception('Activitypub not enabled');
        }

        $target = $this->target;
        $actor = $this->activity;

        $targetAccount = Helpers::profileFetch($target);
        $actorAccount = Helpers::profileFetch($actor);

        if (! $targetAccount || ! $actorAccount) {
            throw new Exception('Invalid move accounts');
        }

        UserFilter::where('filterable_type', Profile::class)
            ->where('filterable_id', $actorAccount['id'])
            ->update(['filterable_id' => $targetAccount['id']]);

        /*
         * Collect the counterparties before the rows go. This bulk delete fires
         * no observer and dispatches no UnfollowPipeline, so every local
         * profile that followed the moved account kept a following_count one
         * higher than reality. Nothing revisits those rows afterwards, and the
         * profile page reads the stored column (AccountTransformer), so the
         * phantom count would persist indefinitely.
         */
        $followerIds = Follower::whereFollowingId($actorAccount['id'])->pluck('profile_id')->all();

        Follower::whereFollowingId($actorAccount['id'])->delete();

        foreach ($followerIds as $followerId) {
            $followerId = (int) $followerId;

            // A self-follow row would make the guard below skip this profile
            // entirely and leave the Redis set holding the moved account.
            if ($followerId === (int) $actorAccount['id']) {
                FollowerService::remove($followerId, $followerId, true);

                continue;
            }

            $profile = Profile::find($followerId);

            if (! $profile) {
                continue;
            }

            $profile->following_count = Follower::whereProfileId($profile->id)->count();
            $profile->save();

            /*
             * Drop the moved account from this profile's warm Redis sets too.
             * ForgetService delCache() below only clears the deleted account's
             * own keys; without this the follower lists keep serving it until
             * the TTL, and a self-follow would never be caught at all.
             */
            FollowerService::remove($followerId, $actorAccount['id'], true);

            Cache::forget('profile:following_count:'.$profile->id);
            Cache::forget(FollowerService::FOLLOWING_SYNC_KEY.$followerId);
            AccountService::del($profile->id);
        }

        $oldProfile = Profile::find($actorAccount['id']);

        if ($oldProfile) {
            $oldProfile->moved_to_profile_id = $targetAccount['id'];
            $oldProfile->followers_count = 0;
            $oldProfile->save();
            AccountService::del($oldProfile->id);
            AccountService::del($targetAccount['id']);
        }
    }
}
