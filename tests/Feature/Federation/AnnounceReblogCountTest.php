<?php

use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\Inbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A redelivered Announce must not inflate reblogs_count
|--------------------------------------------------------------------------
|
| handleAnnounceActivity created the share row with firstOrCreate() and then
| bumped the parent's counter unconditionally:
|
|   $parent->reblogs_count = $parent->reblogs_count + 1;
|   $parent->save();
|
 * firstOrCreate() makes a redelivery a no-op, but the increment still ran, so
 * every duplicate Announce that ever arrived left the parent's count one
 * higher than the number of share rows it actually has. ActivityPub delivery
 * is at-least-once, so duplicates are normal rather than exceptional.
|
| The increment was also a read-modify-write on the model, so two concurrent
| Announces could both read the same value and one increment would be lost --
| SharePipeline, the local path, already used a database-side increment()
| behind a Cache::add guard.
|
| Asserted on the stored column, which is what the transformers read.
|
*/

beforeEach(function () {
    Redis::spy();
    Queue::fake();

    config([
        'instance.enable_cc' => false,
        'federation.activitypub.enabled' => true,
    ]);
});

function announceRemote(string $domain, string $username): Profile
{
    $actor = "https://{$domain}/users/{$username}";

    return Profile::factory()->remote()->create([
        'domain' => $domain,
        'username' => "@{$username}@{$domain}",
        'remote_url' => $actor,
        'inbox_url' => "{$actor}/inbox",
        'sharedInbox' => "https://{$domain}/inbox",
        'key_id' => "{$actor}#main-key",
        'last_fetched_at' => now(),
    ]);
}

function announceSeed(Profile $remote, Status $parent): void
{
    Cache::put('helpers:url:public-ips:'.hash('xxh128', 'remote.example'), ['203.0.113.40'], 3600);
    Cache::put('instances:banned:domains', [], 1209600);
    Cache::put(Helpers::fetchCacheKey($remote->remote_url), $remote, 600);
    Cache::put(Helpers::fetchCacheKey($parent->url()), $parent, 600);
}

function announceDeliver(Profile $actor, Status $parent, string $activityId): void
{
    $sig = 'keyId="'.$actor->key_id.'",algorithm="rsa-sha256",'
        .'headers="(request-target) host date digest",signature="dGVzdA=="';

    $headers = [
        'signature' => [$sig],
        'date' => [now()->toRfc7231String()],
    ];

    (new Inbox($headers, null, [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $actor->remote_url.'/activity/'.$activityId,
        'type' => 'Announce',
        'actor' => $actor->remote_url,
        'object' => $parent->url(),
        'published' => now()->toAtomString(),
    ]))->handle();
}

it('counts a federated announce once', function () {
    $author = User::factory()->create()->refresh();
    $parent = Status::factory()->create([
        'profile_id' => $author->profile_id,
        'reblogs_count' => 0,
    ]);

    $remote = announceRemote('remote.example', 'sharer');
    announceSeed($remote, $parent);

    announceDeliver($remote, $parent, 'announce-1');

    expect(Status::find($parent->id)->reblogs_count)->toBe(1)
        ->and(Status::whereProfileId($remote->id)->whereReblogOfId($parent->id)->count())->toBe(1);
});

it('ignores a redelivered announce instead of counting it twice', function () {
    $author = User::factory()->create()->refresh();
    $parent = Status::factory()->create([
        'profile_id' => $author->profile_id,
        'reblogs_count' => 0,
    ]);

    $remote = announceRemote('remote.example', 'sharer');
    announceSeed($remote, $parent);

    announceDeliver($remote, $parent, 'announce-1');

    // Same activity delivered again, and again: at-least-once delivery means
    // the server retries anything it did not see acknowledged.
    announceDeliver($remote, $parent, 'announce-1');
    announceDeliver($remote, $parent, 'announce-1');

    expect(Status::find($parent->id)->reblogs_count)->toBe(1)
        ->and(Status::whereProfileId($remote->id)->whereReblogOfId($parent->id)->count())->toBe(1);
});

it('counts two different remote shares separately', function () {
    $author = User::factory()->create()->refresh();
    $parent = Status::factory()->create([
        'profile_id' => $author->profile_id,
        'reblogs_count' => 0,
    ]);

    $sharerA = announceRemote('remote.example', 'sharera');
    $sharerB = announceRemote('other.example', 'sharerb');

    Cache::put('helpers:url:public-ips:'.hash('xxh128', 'other.example'), ['203.0.113.41'], 3600);

    announceSeed($sharerA, $parent);
    Cache::put(Helpers::fetchCacheKey($sharerB->remote_url), $sharerB, 600);

    announceDeliver($sharerA, $parent, 'announce-1');
    announceDeliver($sharerB, $parent, 'announce-1');

    expect(Status::find($parent->id)->reblogs_count)->toBe(2)
        ->and(Status::whereReblogOfId($parent->id)->count())->toBe(2);
});