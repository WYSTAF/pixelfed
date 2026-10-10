<?php

use App\Jobs\DeletePipeline\DeleteRemoteProfilePipeline;
use App\Models\Follower;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Deleting a remote profile must correct its followers' counters
|--------------------------------------------------------------------------
|
| The delete pipeline removes the Follower rows in bulk:
|
|   Follower::whereProfileId($pid)->orWhere('following_id', $pid)->delete();
|
| which fires no observer and dispatches no UnfollowPipeline, and it only
| clears AccountService for the profile being deleted ($pid). Every local
| account that followed the deleted remote keeps a following_count one
| higher than reality, and every local account it followed keeps a
| followers_count one higher than reality -- permanently, since nothing
| else revisits those rows. On the profile page that reads the stored column
| (AccountTransformer) the numbers simply never come back down.
|
| This is the same class of defect as the remote-follow and Undo Follow cases:
| a count that disagrees with the follows actually recorded.
|
| Asserted on the stored columns. Do NOT assert via Profile::followingCount():
| it recomputes from the followers table and writes the column back, healing
| the bug.
|
*/

beforeEach(function () {
    Redis::spy();
});

function deleteCountLocal(): Profile
{
    $user = User::factory()->create();

    return $user->refresh()->profile;
}

it('drops the deleted profile from a local followers count', function () {
    $remote = Profile::factory()->remote()->create(['private_key' => null]);
    $local = deleteCountLocal();

    Follower::create([
        'profile_id' => $local->id,
        'following_id' => $remote->id,
    ]);

    $local->update(['following_count' => 1]);

    (new DeleteRemoteProfilePipeline($remote))->handle();

    expect(Follower::whereProfileId($local->id)->exists())->toBeFalse()
        ->and(Profile::find($local->id)->following_count)->toBe(0);
});

it('drops the deleted profile from a local followers own count', function () {
    $remote = Profile::factory()->remote()->create(['private_key' => null]);
    $local = deleteCountLocal();

    Follower::create([
        'profile_id' => $remote->id,
        'following_id' => $local->id,
    ]);

    $local->update(['followers_count' => 1]);

    (new DeleteRemoteProfilePipeline($remote))->handle();

    expect(Follower::whereFollowingId($local->id)->exists())->toBeFalse()
        ->and(Profile::find($local->id)->followers_count)->toBe(0);
});

it('leaves other accounts counts alone', function () {
    $remote = Profile::factory()->remote()->create(['private_key' => null]);
    $follower = deleteCountLocal();
    $followee = deleteCountLocal();
    $bystander = deleteCountLocal();

    // bystander follows the remote too, plus an unrelated follow it keeps.
    Follower::create(['profile_id' => $bystander->id, 'following_id' => $remote->id]);
    Follower::create(['profile_id' => $bystander->id, 'following_id' => $follower->id]);

    $bystander->update(['following_count' => 2]);

    (new DeleteRemoteProfilePipeline($remote))->handle();

    // One of the two follows is gone, and only that one.
    expect(Profile::find($bystander->id)->following_count)->toBe(1)
        ->and(Follower::whereProfileId($bystander->id)->whereFollowingId($follower->id)->exists())->toBeTrue();
});
