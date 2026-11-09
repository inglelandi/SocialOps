<?php

namespace App\Planning;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Posts\PostState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The work the team queued for the scheduled Claude worker (list-claude-jobs), which runs once an hour: plan requests
 * ("Queue for Claude" on the client page, `reports` rows of kind claude_job, the request in `meta`) and caption requests
 * (posts ticked "Ask Claude to write the captions", `settings.ask_claude`, ticked at `settings.ask_claude_at`), and the
 * designs of the clients' Drive folders still to be described (designs(), DESIGNS_PER_RUN an hour, oldest first).
 * The client page, the Jobs page and the dashboard show it; `claude:check-queue` tells Slack once about a late request.
 */
final class ClaudeQueue
{
    /** A request older than this has missed at least one hourly run: the dashboard and Slack say so. */
    public const LATE_HOURS = 2;

    /** Designs list-claude-jobs hands the worker per call: an archive is described a batch an hour. */
    public const DESIGNS_PER_RUN = 25;

    /** What makes two plan requests the same request. */
    public const PLAN_FIELDS = ['posts', 'from', 'days', 'instruction'];

    /** Bookkeeping kept in a post's settings next to the tick; not content. */
    public const POST_KEYS = ['ask_claude', 'ask_claude_at', 'ask_claude_alerted_at'];

    /** Waiting plan requests, of the login's clients when one is given. */
    public static function plans(?User $user = null): Builder
    {
        $query = Report::query()->where('kind', 'claude_job');

        return $user ? $user->restrict($query) : $query;
    }

    /** Posts waiting for their captions (the recycle bin and published posts left out, as list-claude-jobs does). */
    public static function captions(?User $user = null): Builder
    {
        $query = Post::query()->where('settings->ask_claude', true)->whereNot('state', PostState::Published);

        return $user ? $user->restrict($query) : $query;
    }

    /** Design files waiting for Claude's description (a preview, no description yet), of the login's clients when one is given. */
    public static function designs(?User $user = null): Builder
    {
        $query = ClientDocument::query()->where('kind', 'design')->whereNull('text')->whereNull('error');

        return $user ? $user->restrict($query) : $query;
    }

    /** A plan request in a form that compares: the same numbers and the same words (spaces around them aside) are the same request. */
    public static function key(array $fields): array
    {
        return [
            'posts' => (int) ($fields['posts'] ?? 0),
            'from' => (string) ($fields['from'] ?? ''),
            'days' => (int) ($fields['days'] ?? 0),
            'instruction' => trim((string) ($fields['instruction'] ?? '')),
        ];
    }

    /** The client's waiting plan requests that ask for the same as $fields, oldest first. */
    public static function same(Client $client, array $fields): Collection
    {
        return $client->reports()->where('kind', 'claude_job')->orderBy('id')->get()
            ->filter(fn (Report $job) => self::key($job->meta ?? []) === self::key($fields))->values();
    }

    /** When the request was made. A post ticked before the time was kept counts from its last change. */
    public static function askedAt(Post|Report $request): CarbonInterface
    {
        if ($request instanceof Report) {
            return $request->created_at;
        }
        $at = $request->settings['ask_claude_at'] ?? null;

        return $at ? Carbon::parse($at)->setTimezone(config('app.timezone')) : $request->updated_at;
    }

    public static function isLate(Post|Report $request): bool
    {
        return self::askedAt($request)->lt(now()->subHours(self::LATE_HOURS));
    }

    /** Every waiting request, plans and captions together, oldest first, with their client. */
    public static function all(?User $user = null): Collection
    {
        return self::plans($user)->with('client')->get()->toBase()
            ->concat(self::captions($user)->with('client')->get())
            ->sortBy(fn (Post|Report $request) => self::askedAt($request)->getTimestamp())->values();
    }

    /** The requests Claude has not picked up for over LATE_HOURS. */
    public static function late(?User $user = null): Collection
    {
        return self::all($user)->filter(fn (Post|Report $request) => self::isLate($request))->values();
    }

    /** What the plan request asks for, in a line, with the date as people read it. */
    public static function describe(Report $job): string
    {
        $fields = self::key($job->meta ?? []);
        $from = rescue(fn () => Carbon::createFromFormat('Y-m-d', $fields['from'])->format('d-m-Y'), $fields['from'], false);

        return __(':posts posts from :from for :days days', ['posts' => $fields['posts'], 'from' => $from, 'days' => $fields['days']]);
    }

    /** The request fields of a plan job, without the bookkeeping, for the worker. */
    public static function request(Report $job): array
    {
        return Arr::only($job->meta ?? [], self::PLAN_FIELDS);
    }

    /** Whether Slack has heard about this late request already. */
    public static function alerted(Post|Report $request): bool
    {
        return $request instanceof Report ? isset($request->meta['alerted_at']) : isset($request->settings['ask_claude_alerted_at']);
    }

    /** Remember that Slack heard about it, so the hourly check says it once. */
    public static function markAlerted(Post|Report $request): void
    {
        $at = now()->toIso8601String();
        if ($request instanceof Report) {
            $request->update(['meta' => ['alerted_at' => $at] + ($request->meta ?? [])]);

            return;
        }
        // bookkeeping, not an edit: no new "last changed" and no trip back to review for an approved post
        $request->timestamps = false;
        $request->forceFill(['settings' => ['ask_claude_alerted_at' => $at] + ($request->settings ?? [])])->saveQuietly();
        $request->timestamps = true;
    }

    /** "Stop asking": the post leaves the queue. Not a change to what the client approves, so an approved post stays approved. */
    public static function stopAsking(Post $post): void
    {
        $post->forceFill(['settings' => ['ask_claude' => false] + Arr::except($post->settings ?? [], self::POST_KEYS)])->saveQuietly();
    }

    /** save-plan answered the job: it leaves the queue with any identical request of the client that slipped in next to it. */
    public static function clear(Report $job): int
    {
        return Report::query()->whereKey(self::same($job->client, $job->meta ?? [])->pluck('id')->push($job->id))->delete();
    }
}
