<?php

use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\Inbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Federated like counts must move in one statement
|--------------------------------------------------------------------------
|
| HandlesLikes and HandlesUndos used to adjust likes_count with a
| read-modify-write on the model:
|
|   $status->likes_count = $status->likes_count + 1;
|   $status->likes_count -= 1;
|
| The count cannot actually be raced in a single-threaded test -- handling two
| activities in sequence never interleaves their reads and writes, so broken
| and fixed code produce identical results and an outcome assertion would pass
| either way. These tests pin the shape of the update instead: the count must
| move in a single statement evaluated by the database, so that a concurrent
| writer's change is added to rather than overwritten.
|
| Asserted on the SQL each handler emits while running for real.
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

/**
 * Record every statement touching the statuses table. Returns the live array
 * by reference so the listener keeps appending to what the caller holds.
 */
function likeShapeCapture(array &$queries): void
{


    DB::listen(function ($q) use (&$queries) {
        if (stripos($q->sql, 'update') === 0 && stripos($q->sql, '"statuses"') !== false) {
            $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
        }
    });
}

function likeShapeRemote(string $domain, string $username): Profile
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

function likeShapeDeliver(Profile $actor, array $payload): void
{
    $sig = 'keyId="'.$actor->key_id.'",algorithm="rsa-sha256",'
        .'headers="(request-target) host date digest",signature="dGVzdA=="';

    $headers = [
        'signature' => [$sig],
        'date' => [now()->toRfc7231String()],
    ];

    (new Inbox($headers, null, $payload))->handle();
}

function likeShapeSeed(Profile $remote, ?Status $status = null): void
{
    Cache::put('helpers:url:public-ips:'.hash('xxh128', 'remote.example'), ['203.0.113.40'], 3600);
    Cache::put('instances:banned:domains', [], 1209600);
    Cache::put(Helpers::fetchCacheKey($remote->remote_url), $remote, 600);

    if ($status) {
        Cache::put(Helpers::fetchCacheKey($status->url()), $status, 600);
    }
}

it('bumps likes_count with a database-side increment, not a PHP-computed value', function () {
    $author = User::factory()->create()->refresh();
    $status = Status::factory()->create([
        'profile_id' => $author->profile_id,
        'likes_count' => 0,
    ]);

    $remote = likeShapeRemote('remote.example', 'liker');
    likeShapeSeed($remote, $status);

    $queries = [];
    likeShapeCapture($queries);

    likeShapeDeliver($remote, [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $remote->remote_url.'/activity/like-1',
        'type' => 'Like',
        'actor' => $remote->remote_url,
        'object' => $status->url(),
    ]);

    expect(Status::find($status->id)->likes_count)->toBe(1);

    /*
     * The update must be `likes_count = likes_count + 1` -- the increment
     * evaluated by the database. A read-modify-write instead sends the literal
     * computed value as a binding, with no reference to the column.
     */
    $increments = array_values(array_filter(
        $queries,
        fn ($q) => str_contains($q['sql'], '"likes_count" = "likes_count" + 1')
    ));

    expect($increments)->not->toBeEmpty();
});

it('drops likes_count with a guarded database-side decrement', function () {
    $author = User::factory()->create()->refresh();
    $status = Status::factory()->create([
        'profile_id' => $author->profile_id,
        'likes_count' => 1,
    ]);

    $remote = likeShapeRemote('remote.example', 'liker');
    \App\Models\Like::create([
        'profile_id' => $remote->id,
        'status_id' => $status->id,
    ]);

    likeShapeSeed($remote, $status);

    $queries = [];
    likeShapeCapture($queries);

    likeShapeDeliver($remote, [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $remote->remote_url.'/activity/undo-1',
        'type' => 'Undo',
        'actor' => $remote->remote_url,
        'object' => [
            'id' => $remote->remote_url.'/activity/like-1',
            'type' => 'Like',
            'actor' => $remote->remote_url,
            'object' => $status->url(),
        ],
    ]);

    expect(Status::find($status->id)->likes_count)->toBe(0);

    /*
     * The zero floor has to be part of the statement -- a `likes_count > ?`
     * predicate with 0 bound -- not a PHP `if ($status->likes_count > 0)`
     * decided from a value read before the write.
     */
    $guarded = array_values(array_filter(
        $queries,
        fn ($q) => str_contains($q['sql'], '"likes_count" = "likes_count" - 1')
            && str_contains($q['sql'], '"likes_count" > ?')
            && in_array(0, $q['bindings'], true)
    ));

    expect($guarded)->not->toBeEmpty();
});