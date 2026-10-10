<?php

use App\Jobs\ProfilePipeline\ProfileMigrationMoveFollowersPipeline;
use App\Models\Follower;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A profile migration must count the followers it actually moved
|--------------------------------------------------------------------------
|
| The job copied the old profile's followers_count onto the new one:
|
|   $ne->followers_count = $og->followers_count;
|
| Those are independent denormalized columns and the old one can drift --
| every remote follow, Undo Follow and deleted-remote profile in this
| codebase has been a way to leave it high. Copying the column propagates
| that drift instead of fixing it, and the new profile then reports a
| follower count it never had.
|
| The rows are reassigned by the loop below, so the truth is what the
| followers table holds for the old profile.
|
| Asserted on the stored columns. Do NOT assert via Profile::followerCount():
| it recomputes from the followers table and writes the column back, healing
| the bug.
|
*/

beforeEach(function () {
    Redis::spy();
});

/**
 * The job opens with $this->batch()->cancelled(), which is a fatal error when
 * it runs outside a Bus::batch -- Batchable::batch() returns null if no batch
 * id is set. In production it is always dispatched inside Bus::batch([...])
 * by ProfileMigrationController, so the guard is safe there; withFakeBatch()
 * supplies the batch it expects. The same unguarded call appears in four
 * sibling jobs.
 */
function migrationMoveJob(int $oldPid, int $newPid): ProfileMigrationMoveFollowersPipeline
{
    $job = new ProfileMigrationMoveFollowersPipeline($oldPid, $newPid);
    $job->withFakeBatch();

    return $job;
}

function migrationCountProfile(): Profile
{
    return User::factory()->create()->refresh()->profile;
}

it('counts the followers actually moved rather than copying a stale column', function () {
    $old = migrationCountProfile();
    $new = migrationCountProfile();

    $a = migrationCountProfile();
    $b = migrationCountProfile();
    $c = migrationCountProfile();

    Follower::create(['profile_id' => $a->id, 'following_id' => $old->id]);
    Follower::create(['profile_id' => $b->id, 'following_id' => $old->id]);
    Follower::create(['profile_id' => $c->id, 'following_id' => $old->id]);

    // The old profile's column has drifted high, as it would after any of the
    // remote-follow or Undo Follow paths that skip the counter update.
    $old->update(['followers_count' => 99]);

    migrationMoveJob($old->id, $new->id)->handle();

    // Three follower rows moved, so the new profile has three followers --
    // not the 99 the old column claimed.
    expect(Profile::find($new->id)->followers_count)->toBe(3)
        ->and(Follower::whereFollowingId($new->id)->count())->toBe(3);

    // The old profile kept none of them.
    expect(Profile::find($old->id)->followers_count)->toBe(0)
        ->and(Follower::whereFollowingId($old->id)->count())->toBe(0);
});

it('agrees with the live count when the old column was already correct', function () {
    $old = migrationCountProfile();
    $new = migrationCountProfile();
    $a = migrationCountProfile();

    Follower::create(['profile_id' => $a->id, 'following_id' => $old->id]);

    $old->update(['followers_count' => 1]);

    migrationMoveJob($old->id, $new->id)->handle();

    expect(Profile::find($new->id)->followers_count)->toBe(1);
});