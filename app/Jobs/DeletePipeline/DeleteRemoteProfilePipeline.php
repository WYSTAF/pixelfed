<?php

namespace App\Jobs\DeletePipeline;

use App\Jobs\StatusPipeline\RemoteStatusDelete;
use App\Models\Avatar;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\Like;
use App\Models\Media;
use App\Models\MediaTag;
use App\Models\Mention;
use App\Models\Notification;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\Profile;
use App\Models\QuoteAuthorization;
use App\Models\Report;
use App\Models\Status;
use App\Models\StatusEdit;
use App\Models\Story;
use App\Models\StoryView;
use App\Models\UserFilter;
use App\Services\AccountService;
use App\Services\DirectMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeleteRemoteProfilePipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $profile;

    public $timeout = 900;

    public $tries = 3;

    public $maxExceptions = 1;

    public $deleteWhenMissingModels = true;

    public function __construct(Profile $profile)
    {
        $this->profile = $profile;
    }

    public function handle()
    {
        $profile = $this->profile;

        // Verify profile exists
        if (! $profile) {
            Log::info('DeleteRemoteProfilePipeline: Profile no longer exists, skipping job');

            return null;
        }

        $pid = $profile->id;

        if ($profile->domain == null || $profile->private_key) {
            return null;
        }

        $profile->status = 'delete';
        $profile->save();

        AccountService::del($pid);

        // Delete statuses. chunkById, not chunk: RemoteStatusDelete
        // soft-deletes the rows it is handed, so OFFSET paging would skip rows
        // as the live set shrinks. Keyset paging on the monotonic id is stable.
        Status::whereProfileId($pid)
            ->chunkById(50, function ($statuses) {
                foreach ($statuses as $status) {
                    RemoteStatusDelete::dispatch($status)->onQueue('delete');
                }
            });

        // Safety net: purge any edit-history rows for this profile in case a
        // per-status delete job was skipped or failed (mirrors DeleteAccountPipeline).
        StatusEdit::whereProfileId($pid)->delete();

        // Delete Poll Votes
        PollVote::whereProfileId($pid)->delete();

        // Delete Polls
        Poll::whereProfileId($pid)->delete();

        // Delete Avatar
        $profile->avatar->forceDelete();

        // Delete media tags
        MediaTag::whereProfileId($pid)->delete();

        // Delete DMs
        DirectMessage::whereFromId($pid)->orWhere('to_id', $pid)->delete();
        Conversation::whereFromId($pid)->orWhere('to_id', $pid)->delete();
        app(DirectMessageService::class)->purgeProfile($pid);

        // Delete FollowRequests
        FollowRequest::whereFollowingId($pid)
            ->orWhere('follower_id', $pid)
            ->delete();

        /*
         * Collect the counterparties before the rows go. A bulk delete fires no
         * observer, so nothing would revisit these accounts and every local
         * profile that followed -- or was followed by -- this remote account
         * kept a phantom count forever, since the profile page reads the stored
         * column (AccountTransformer) and never recomputes it.
         */
        $followerIds = Follower::whereProfileId($pid)->pluck('following_id')->all();
        $followeeIds = Follower::whereFollowingId($pid)->pluck('profile_id')->all();

        // Delete relationships
        Follower::whereProfileId($pid)
            ->orWhere('following_id', $pid)
            ->delete();

        $this->resyncFollowCounts($pid, $followerIds, $followeeIds);

        // Delete likes
        Like::whereProfileId($pid)->forceDelete();

        // Delete Story Views + Stories
        StoryView::whereProfileId($pid)->delete();
        Story::whereProfileId($pid)->cursor()->each(function ($story) {
            $path = storage_path('app/'.$story->path);
            if (is_file($path)) {
                unlink($path);
            }
            $story->forceDelete();
        });

        // Delete mutes/blocks. Per-row delete (not a bulk ->delete()) so the
        // UserFilterObserver fires and UserFilterService::unmute()/unblock()
        // clears each muting/blocking user's Redis cache; a bulk delete would
        // leave stale entries (and inflated mute/block counts) for up to the
        // 90-day cache TTL. chunkById is safe while deleting.
        UserFilter::whereFilterableType(Profile::class)
            ->whereFilterableId($pid)
            ->chunkById(100, function ($filters) {
                foreach ($filters as $filter) {
                    $filter->delete();
                }
            });

        // Delete mentions
        Mention::whereProfileId($pid)->forceDelete();

        // Delete quote approval stamps issued to this actor
        QuoteAuthorization::whereActorId($pid)->delete();

        // Delete notifications. chunkById, not chunk: the loop force-deletes
        // rows, so OFFSET paging would skip half of them. The profile/actor
        // match is grouped so chunkById's appended id constraint ANDs against
        // the whole predicate instead of only the actor_id branch.
        Notification::where(function ($query) use ($pid) {
            $query->where('profile_id', $pid)
                ->orWhere('actor_id', $pid);
        })
            ->chunkById(50, function ($notifications) {
                foreach ($notifications as $n) {
                    $n->forceDelete();
                }
            });

        // Delete reports
        Report::whereProfileId($pid)->orWhere('reported_profile_id', $pid)->forceDelete();

        // Delete profile
        Profile::findOrFail($profile->id)->delete();

        return 1;
    }

    /**
     * Recompute the follower/following counters of every profile that had a
     * relationship with the deleted one, and drop their cached copies.
     *
     * Done inline rather than by dispatching UnfollowPipeline per row: the
     * list can be large, and each of those jobs would issue its own count
     * query. Recomputing here also covers the profiles that are about to be
     * deleted further down, which is why it runs before Profile::delete().
     *
     * @param  array<int>  $followerIds  profiles that followed the deleted one
     * @param  array<int>  $followeeIds  profiles the deleted one followed
     */
    private function resyncFollowCounts(int $pid, array $followerIds, array $followeeIds): void
    {
        // $pid itself is deleted later in handle(); skip it here.
        $ids = collect(array_merge($followerIds, $followeeIds))
            ->filter(fn ($id) => (int) $id !== $pid)
            ->unique()
            ->values();

        foreach ($ids as $id) {
            $profile = Profile::find($id);

            if (! $profile) {
                continue;
            }

            $profile->followers_count = Follower::whereFollowingId($id)->count();
            $profile->following_count = Follower::whereProfileId($id)->count();
            $profile->save();

            Cache::forget('profile:follower_count:'.$id);
            Cache::forget('profile:following_count:'.$id);
            AccountService::del($id);
        }
    }
}
