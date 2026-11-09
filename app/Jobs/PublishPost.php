<?php

namespace App\Jobs;

use App\Alerts\Slack;
use App\Mail\PostNeedsHuman;
use App\Models\Account;
use App\Models\Post;
use App\Posts\PostState;
use App\Publishing\ManualNeeded;
use App\Publishing\MetaPublisher;
use App\Publishing\Publishers;
use App\Publishing\PublishFailed;
use App\Webhooks\Webhooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Publishes one Scheduled post to every platform it names; only an Approved post can ever be Scheduled. */
class PublishPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Post $post) {}

    public function backoff(): array
    {
        return [60, 600];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->post->id))->dontRelease()];
    }

    public function handle(): void
    {
        $post = $this->post->fresh(); // fresh() also finds a post in the recycle bin
        if (! $post || $post->trashed() || $post->state !== PostState::Scheduled) {
            return; // a human moved or deleted it, or a twin job already did the work
        }

        $done = $post->external_ids ?? [];
        $manual = [];
        foreach ($post->platforms as $platform) {
            if (isset($done[$platform])) {
                continue; // published on an earlier attempt
            }
            $account = $post->client->accounts()->where('platform', $platform)->first()
                ?? throw new PublishFailed("No {$platform} account is connected for {$post->client->name}");
            try {
                $done[$platform] = Publishers::for($platform)->publish($post, $account);
                $post->forceFill(['external_ids' => $done])->save();
                $this->firstComment($post, $account, $done[$platform]);
            } catch (ManualNeeded $e) {
                $manual[] = $e->getMessage();
            }
        }

        if ($manual) {
            $post->forceFill(['publish_error' => implode(' ', $manual)])->save();
            $post->transitionTo(PostState::ManualNeeded);
            Mail::to(self::whoToTell($post))->send($mail = new PostNeedsHuman($post, 'manual'));
            Slack::mailed($mail, route('posts.edit', $post));
            Webhooks::post($post, 'post.manual_needed');

            return;
        }
        $post->forceFill(['publish_error' => null])->save();
        $post->transitionTo(PostState::Published); // drafts the next occurrence of a repeating post too
        Webhooks::post($post, 'post.published');
    }

    /** The first comment (hashtags or a link) goes under Instagram and Facebook posts; a failure is told to Slack, the post stays Published. */
    private function firstComment(Post $post, Account $account, string $mediaId): void
    {
        $text = $post->firstCommentFor($account->platform);
        if ($text === '' || ! in_array($account->platform, ['instagram', 'facebook'])) {
            return;
        }
        try {
            app(MetaPublisher::class)->comment($account, $mediaId, $text);
        } catch (PublishFailed $e) {
            Slack::send(__(':client: the first comment under :name on :platform failed: :error', ['client' => $post->client->name, 'name' => $post->name, 'platform' => $account->platform, 'error' => $e->getMessage()])."\n".route('posts.edit', $post));
        }
    }

    /** All retries used up: the post is marked Failed with the last error and a human is told. */
    public function failed(Throwable $e): void
    {
        $post = $this->post->fresh();
        if (! $post || $post->trashed() || $post->state !== PostState::Scheduled) {
            return;
        }
        $post->forceFill(['publish_error' => $e->getMessage()])->save();
        $post->transitionTo(PostState::Failed);
        Mail::to(self::whoToTell($post))->send($mail = new PostNeedsHuman($post, 'failed'));
        Slack::mailed($mail, route('posts.edit', $post));
        Webhooks::post($post, 'post.failed');
    }

    public static function whoToTell(Post $post): string
    {
        return $post->creator?->email ?? config('mail.from.address');
    }
}
