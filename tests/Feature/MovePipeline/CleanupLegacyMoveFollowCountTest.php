<?php

use App\Jobs\MovePipeline\CleanupLegacyAccountMovePipeline;
use App\Models\Follower;
use App\Models\Profile;
use App\Models\User;
use App\Services\FollowerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A legacy account move must not leave followers with a phantom count
|--------------------------------------------------------------------------
|
| The move cleanup dropped the moved account's followers in bulk:
|
|   Follower::whereFollowingId($actorAccount['id'])->delete();
|
| A bulk delete fires no observer and dispatches no UnfollowPipeline, and the
| job only reset the *old* profile's followers_count. Every local profile that
| followed the moved account kept a following_count one higher than reality,
| and nothing revisits those rows: the profile page reads the stored column
| (AccountTransformer), so the phantom count would persist indefinitely.
|
| Same class of defect as the remote-follow and Undo Follow cases.
|
| Asserted on the stored column. Do NOT assert via Profile::followingCount():
| it recomputes from the followers table and writes the column back, healing
| the bug.
|
*/

beforeEach(function () {
    Redis::spy();

    // The job refuses to run outside production.
    config(['app.env' => 'production']);
    config(['federation.activitypub.enabled' => true]);

    /*
     * Seed what Helpers::validateUrl() checks so no DNS lookup happens.
     * getOrFetchRemoteProfile() then matches the profiles by remote_url and
     * returns them without any fetch, so profileFetch() never hits the
     * network. Call factories first: the lazy refresh can flush this cache.
     */
    Cache::put('helpers:url:public-ips:'.hash('xxh128', 'old.example'), ['203.0.113.40'], 3600);
    Cache::put('helpers:url:public-ips:'.hash('xxh128', 'new.example'), ['203.0.113.40'], 3600);
});

it('drops the moved account from its followers following counts', function () {
    $old = Profile::factory()->create([
        'user_id' => null,
        'domain' => 'old.example',
        'remote_url' => 'https://old.example/users/old',
    ]);

    $new = Profile::factory()->create([
        'user_id' => null,
        'domain' => 'new.example',
        'remote_url' => 'https://new.example/users/new',
    ]);

    $followerA = User::factory()->create()->refresh()->profile;
    $followerB = User::factory()->create()->refresh()->profile;

    // A third profile with an unrelated follow, to catch over-correction.
    $survivor = User::factory()->create()->refresh()->profile;
    $other = User::factory()->create()->refresh()->profile;

    Follower::create(['profile_id' => $followerA->id, 'following_id' => $old->id]);
    Follower::create(['profile_id' => $followerB->id, 'following_id' => $old->id]);
    Follower::create(['profile_id' => $survivor->id, 'following_id' => $other->id]);

    $followerA->update(['following_count' => 1]);
    $followerB->update(['following_count' => 1]);
    $survivor->update(['following_count' => 1]);

    // Re-seed: creating the profiles above may have flushed the URL cache.
    Cache::put('helpers:url:public-ips:'.hash('xxh128', 'old.example'), ['203.0.113.40'], 3600);
    Cache::put('helpers:url:public-ips:'.hash('xxh128', 'new.example'), ['203.0.113.40'], 3600);

    (new CleanupLegacyAccountMovePipeline($new->remote_url, $old->remote_url))->handle();

    // The follows of the moved account are gone...
    expect(Follower::whereFollowingId($old->id)->count())->toBe(0);

    // ...so its followers must no longer count it, and must keep their other follows.
    expect(Profile::find($followerA->id)->following_count)->toBe(0)
        ->and(Profile::find($followerB->id)->following_count)->toBe(0)
        ->and(Profile::find($survivor->id)->following_count)->toBe(1);

    /*
     * The warm Redis follow sets are spied rather than stored here, so assert
     * the evictions the cleanup owes them instead of their contents: each
     * follower's following set must have the moved account removed from it.
     */
    Redis::shouldHaveReceived('zrem')
        ->withArgs(fn ($key, $member) => $key === FollowerService::FOLLOWING_KEY.$followerA->id
            && (string) $member === (string) $old->id)
        ->atLeast()->once();

    Redis::shouldHaveReceived('zrem')
        ->withArgs(fn ($key, $member) => $key === FollowerService::FOLLOWING_KEY.$followerB->id
            && (string) $member === (string) $old->id)
        ->atLeast()->once();
});