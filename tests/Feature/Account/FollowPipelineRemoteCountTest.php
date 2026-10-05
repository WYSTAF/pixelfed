<?php

use App\Jobs\FollowPipeline\FollowPipeline;
use App\Models\Follower;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FollowPipeline must maintain counts for remote follows too
|--------------------------------------------------------------------------
|
| handle() used to return early when the target was a remote profile
| ($target->domain) before refreshing either denormalized counter, so
| following a remote account left following_count at 0 and the profile
| page rendered a stale 0 (pixelfed#7601). The counters are now refreshed
| before that early return; only the local-only bookkeeping after it is
| skipped.
|
*/

it('updates the following count when a local profile follows a remote account', function () {
    $actor = User::factory()->create();
    $actor->refresh();
    $actor->settings->update(['show_profile_following_count' => true]);

    $remote = Profile::factory()->remote()->create([
        'private_key' => null,
    ]);

    $follower = Follower::create([
        'profile_id' => $actor->profile_id,
        'following_id' => $remote->id,
    ]);

    (new FollowPipeline($follower))->handle();

    $fresh = Profile::find($actor->profile_id);
    expect($fresh->following_count)->toBe(1)
        ->and($fresh->followingCount())->toBe(1);
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

    $fresh = Profile::find($target->profile_id);
    expect($fresh->followers_count)->toBe(1)
        ->and($fresh->followerCount())->toBe(1);
});

it('still maintains counts for a local follow', function () {
    $actor = User::factory()->create();
    $actor->refresh();
    $actor->settings->update(['show_profile_following_count' => true]);

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
