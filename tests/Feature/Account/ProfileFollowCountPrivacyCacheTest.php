<?php

use App\Models\Follower;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A hidden follow count must not be cached
|--------------------------------------------------------------------------
|
| followingCount()/followerCount() used to evaluate the privacy gate inside
| the Cache::remember() closure, so the 0 it returned for a hidden count was
| written to the cache and kept serving for the full month TTL, long after
| the setting was turned back on. The gate now runs before the cache.
|
*/

it('does not cache a hidden following count', function () {
    $user = User::factory()->create();
    $user->refresh();
    $user->settings->update(['show_profile_following_count' => false]);

    $other = Profile::factory()->create();

    $profile = Profile::find($user->profile_id);
    Follower::create([
        'profile_id' => $profile->id,
        'following_id' => $other->id,
    ]);

    // Hidden: reports 0.
    expect($profile->followingCount())->toBe(0);

    // The 0 must not have been written to the month-long cache, so turning
    // the setting back on must immediately show the real count.
    $user->settings->update(['show_profile_following_count' => true]);

    expect(Profile::find($profile->id)->followingCount())->toBe(1);
});

it('does not cache a hidden follower count', function () {
    $user = User::factory()->create();
    $user->refresh();
    $user->settings->update(['show_profile_follower_count' => false]);

    $other = Profile::factory()->create();

    $profile = Profile::find($user->profile_id);
    Follower::create([
        'profile_id' => $other->id,
        'following_id' => $profile->id,
    ]);

    expect($profile->followerCount())->toBe(0);

    $user->settings->update(['show_profile_follower_count' => true]);

    expect(Profile::find($profile->id)->followerCount())->toBe(1);
});

it('shows the real count for a local profile with no settings row', function () {
    $user = User::factory()->create();
    $user->refresh();

    $profile = Profile::find($user->profile_id);
    $other = Profile::factory()->create();

    Follower::create([
        'profile_id' => $profile->id,
        'following_id' => $other->id,
    ]);

    // A missing user_settings row must not fatal or hide the count.
    $user->settings()->delete();

    expect(Profile::find($profile->id)->followingCount())->toBe(1);
});
