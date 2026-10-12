<?php

use App\Jobs\FollowPipeline\UnfollowPipeline;
use App\Jobs\HomeFeedPipeline\FeedUnfollowPipeline;
use App\Models\Follower;
use App\Models\Profile;
use App\Models\User;
use App\Services\FollowerService;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\Inbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Incoming Undo Follow must correct the denormalized counters
|--------------------------------------------------------------------------
|
| handleUndoFollow() deletes the Follower row and clears the Redis/caches,
| but it never dispatches UnfollowPipeline -- the only place the
| following_count/followers_count columns are corrected on the unfollow path
| -- and never rewrites them itself. Every other unfollow path dispatches it:
|
|   ApiV1Controller::accountUnfollowById
|   FollowersSyncService::removeLocalFollower
|
| So when a remote account unfollows us, the counters keep reading one higher
| than reality, which is the mirror image of the remote-follow case fixed in
| FollowPipeline (pixelfed#7601).
|
| Asserted on the stored columns, which is what the profile page renders.
| Do NOT assert via Profile::followingCount(): it recomputes from the
| followers table and writes the column back, healing the bug.
|
*/

function undoCountRemote(string $username, string $domain): Profile
{
    $actor = "https://{$domain}/@{$username}";

    return Profile::create([
        'username' => "@{$username}@{$domain}",
        'domain' => $domain,
        'remote_url' => $actor,
        'key_id' => "{$actor}#main-key",
        'inbox_url' => "{$actor}/inbox",
        'sharedInbox' => "https://{$domain}/inbox",
        'last_fetched_at' => now(),
    ]);
}

function undoCountSeed(): void
{
    foreach (['remote.example', config('pixelfed.domain.app')] as $host) {
        Cache::put('helpers:url:public-ips:'.hash('xxh128', $host), ['203.0.113.40'], 3600);
    }

    Cache::put('instances:banned:domains', [], 1209600);
}

beforeEach(function () {
    Redis::spy();

    /*
     * UnfollowPipeline fans out into FeedUnfollowPipeline, which reads the
     * home timeline out of Redis and iterates it. Under Redis::spy() that
     * returns nothing usable, so the queue is faked entirely and the
     * UnfollowPipeline job is run by hand, which is the code that actually
     * corrects the counters.
     */
    Queue::fake();
});

it('corrects both counters when a remote account sends Undo Follow', function () {
    $local = User::factory()->create();
    $local->refresh();

    $remote = undoCountRemote('someone', 'remote.example');

    undoCountSeed();
    Cache::put(Helpers::fetchCacheKey($remote->remote_url), $remote, 600);

    Follower::create([
        'profile_id' => $remote->id,
        'following_id' => $local->profile_id,
    ]);

    // Counters as they stand once the follow was accepted.
    $local->profile->update(['followers_count' => 1]);
    $remote->update(['following_count' => 1]);

    // UnfollowPipeline only adjusts the columns when the direction has already
    // been synced; otherwise it re-dispatches the warm-cache job to recompute
    // them. Seeding the keys selects the in-place branch so the test does not
    // depend on that job running.
    Cache::put(FollowerService::FOLLOWING_SYNC_KEY.$remote->id, 1, 604800);
    Cache::put(FollowerService::FOLLOWERS_SYNC_KEY.$local->profile_id, 1, 604800);

    $followActivity = $local->profile->permalink('#accepts/follows/'.Follower::first()->id);

    $payload = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $remote->remote_url.'/activity/undo-1',
        'type' => 'Undo',
        'actor' => $remote->remote_url,
        'object' => [
            'id' => $followActivity,
            'type' => 'Follow',
            'actor' => $remote->remote_url,
            'object' => $local->profile->permalink(),
        ],
    ];

    $headers = [
        'signature' => ['keyId="'.$remote->key_id.'",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="dGVzdA=="'],
        'date' => [now()->toRfc7231String()],
    ];

    (new Inbox($headers, null, $payload))->handle();

    // The follow row is gone...
    expect(Follower::whereProfileId($remote->id)->whereFollowingId($local->profile_id)->exists())->toBeFalse();

    // ...and the handler must have queued the job that corrects the counters.
    // Running it here stands in for the worker, so the assertion below is on
    // the counters the job produces.
    Queue::assertPushed(UnfollowPipeline::class);

    (new UnfollowPipeline($remote->id, $local->profile_id))->handle();

    expect(Profile::find($local->profile_id)->followers_count)->toBe(0)
        ->and(Profile::find($remote->id)->following_count)->toBe(0);
});
