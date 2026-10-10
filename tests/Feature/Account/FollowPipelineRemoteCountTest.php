<?php

use App\Jobs\FollowPipeline\FollowPipeline;
use App\Models\Follower;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Redis::spy();

    /*
     * Queue::fake() is what makes this a faithful reproduction rather than a
     * tautology.
     *
     * Follower::create() fires FollowerObserver::created(), which dispatches
     * FeedFollowPipeline. That job calls FollowerService::follows(), which
     * calls cacheSyncCheck(), which dispatches FollowServiceWarmCache — and
     * that job recomputes both count columns straight from the followers
     * table. Under QUEUE_CONNECTION=sync (the test default) those all run
     * inline and repair the counters regardless of what FollowPipeline does,
     * so an assertion on the column passes even against the unfixed code.
     *
     * Production runs these on real async queues, so the repair only happens
     * later, and only if something calls the Redis-backed follower/following
     * list helpers. The profile page itself reads the column via
     * AccountTransformer and never triggers it, which is how a stale 0
     * survives (pixelfed#7601). Faking the queue reproduces that ordering.
     */
    Queue::fake();
});

/*
|--------------------------------------------------------------------------
| FollowPipeline must maintain counts for remote follows too
|--------------------------------------------------------------------------
|
| handle() used to return early when the target was a remote profile
| ($target->domain) before refreshing either denormalized counter, so
| following a remote account left following_count at 0 and the profile page
| rendered a stale 0 (pixelfed#7601). The counters are now refreshed before
| that early return; only the local-only bookkeeping after it is skipped.
|
| These assert on the stored column, which is what the profile page renders.
| Do NOT assert via Profile::followingCount(): it recomputes from the
| followers table and writes the column back, so it heals the very bug
| under test and passes even against the unfixed code.
|
*/

it('updates the following count when a local profile follows a remote account', function () {
    $actor = User::factory()->create();
    $actor->refresh();

    $remote = Profile::factory()->remote()->create([
        'private_key' => null,
    ]);

    $follower = Follower::create([
        'profile_id' => $actor->profile_id,
        'following_id' => $remote->id,
    ]);

    (new FollowPipeline($follower))->handle();

    expect(Profile::find($actor->profile_id)->following_count)->toBe(1);
});

it('updates the follower count when a remote account follows a local profile', function () {
    $target = User::factory()->create();
    $target->refresh();

    $remote = Profile::factory()->remote()->create([
        'private_key' => null,
    ]);

    $follower = Follower::create([
        'profile_id' => $remote->id,
        'following_id' => $target->profile_id,
    ]);

    (new FollowPipeline($follower))->handle();

    expect(Profile::find($target->profile_id)->followers_count)->toBe(1);
});

it('still maintains counts for a local follow', function () {
    $actor = User::factory()->create();
    $actor->refresh();

    $target = User::factory()->create();
    $target->refresh();

    $follower = Follower::create([
        'profile_id' => $actor->profile_id,
        'following_id' => $target->profile_id,
    ]);

    (new FollowPipeline($follower))->handle();

    expect(Profile::find($actor->profile_id)->following_count)->toBe(1)
        ->and(Profile::find($target->profile_id)->followers_count)->toBe(1);
});
