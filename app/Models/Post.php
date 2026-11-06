<?php

namespace App\Models;

use App\Alerts\Slack;
use App\Http\Requests\PostRequest;
use App\Posts\InvalidTransition;
use App\Posts\PostNamer;
use App\Posts\PostState;
use App\Publishing\PublishFailed;
use Carbon\CarbonInterface;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/** A deleted post is soft-deleted: it waits in its client's recycle bin (`moveToBin()`, `restoreFromBin()`) and every ordinary query skips it. */
#[Fillable(['name', 'state', 'platforms', 'format', 'pillar', 'brief', 'settings', 'scheduled_at', 'created_by'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use SoftDeletes;

    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'linkedin'];

    /** LinkedIn posts use feed (image or text), carousel (images or a document) and reel (video). */
    public const FORMATS = ['feed', 'carousel', 'reel', 'story', 'tiktok_video', 'tiktok_photos'];

    /** Changing any of these on a locked post sends it back to review. */
    public const CONTENT_FIELDS = ['platforms', 'format', 'pillar', 'brief', 'settings'];

    /** Instagram's limit on collab invites per post. */
    public const MAX_COLLABORATORS = 3;

    protected function casts(): array
    {
        return [
            'state' => PostState::class,
            'platforms' => 'array',
            'settings' => 'array',
            'external_ids' => 'array',
            'metrics' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // the Claude queue (App\Planning\ClaudeQueue) keeps when the "Ask Claude" tick went on; when it goes off, its bookkeeping goes too
        static::saving(function (Post $post) {
            $settings = $post->settings ?? [];
            $asked = ($settings['ask_claude'] ?? false) === true;
            if ($asked && ! ($post->exists && ($post->getOriginal('settings')['ask_claude'] ?? false) === true)) {
                $post->settings = ['ask_claude_at' => now()->toIso8601String()] + Arr::except($settings, 'ask_claude_alerted_at');
            } elseif (! $asked && Arr::hasAny($settings, ['ask_claude_at', 'ask_claude_alerted_at'])) {
                $post->settings = Arr::except($settings, ['ask_claude_at', 'ask_claude_alerted_at']);
            }
        });
        static::updating(function (Post $post) {
            if ($post->state->isLocked() && $post->state !== PostState::Published && $post->isDirty(self::CONTENT_FIELDS)) {
                $post->state = PostState::InReview;
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Who moved the post to the recycle bin. */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /** Into the client's recycle bin: the post leaves every list and keeps its captions, media, notes and approvals for a restore. */
    public function moveToBin(?int $by = null): void
    {
        $this->forceFill(['deleted_by' => $by])->save();
        $this->delete();
    }

    /**
     * Back from the recycle bin as it was, with two exceptions that keep the state true: with posting on, a Scheduled post whose time
     * has passed comes back Approved, so a restore never publishes on its own; and a post that was waiting for the client comes back
     * a Draft when every approval it waited in has closed meanwhile, so it is sent again instead of waiting for nobody.
     */
    public function restoreFromBin(): void
    {
        if (config('features.publishing') && $this->state === PostState::Scheduled && $this->scheduled_at?->isPast()) {
            $this->state = PostState::Approved;
        }
        $asked = $this->reviews()->wherePivotNull('decision')->get();
        if ($this->state === PostState::InReview && $asked->isNotEmpty() && $asked->every(fn (Review $review) => $review->completed_at !== null)) {
            $this->state = PostState::Draft;
        }
        $this->deleted_by = null;
        $this->restore();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(PostNote::class)->with('user')->latest()->orderByDesc('id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class)->orderBy('id');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class)->withPivot('position')->orderByPivot('position');
    }

    /** The platform's signature line from the profile (bookings phone, site), empty when there is none. */
    public function signatureFor(string $platform): string
    {
        return trim($this->client->substitute((string) ($this->client->profile['signatures'][$platform] ?? '')));
    }

    /** The caption languages of the profile in order: the main ones, then the extra ones. */
    public function languages(): array
    {
        return array_merge($this->client->profile['languages'], $this->client->profile['extra_languages'] ?? []);
    }

    /**
     * The caption as it goes out, and as the team pastes it when posting by hand (the Post today page, the ZIP): the languages
     * given, else every language of the profile in order, the platform's signature, then the hashtags once; placeholders
     * filled, links tagged when the profile has utm. Unchecked: captionFor() refuses an unknown placeholder or a caption over the limit.
     */
    public function pasteText(string $platform, ?array $languages = null): string
    {
        $variants = $this->variants->where('platform', $platform)->keyBy('lang');
        $hashtags = $variants->flatMap(fn (PostVariant $v) => $v->hashtags ?? [])->map(fn ($t) => '#'.ltrim($t, '#'))->unique()->join(' ');

        return $this->client->tagLinks($this->client->substitute(collect($languages ?? $this->languages())->map(fn ($lang) => trim((string) $variants->get($lang)?->caption))->push($this->signatureFor($platform))->push($hashtags)->filter()->join("\n\n")), $platform, $this->name);
    }

    /** The first comment under an Instagram or Facebook post, placeholders filled and links tagged for that platform; empty when there is none. */
    public function firstCommentFor(string $platform): string
    {
        return trim($this->client->tagLinks($this->client->substitute((string) ($this->settings['first_comment'] ?? '')), $platform, $this->name));
    }

    /** The text that goes to the platform (pasteText()), refused when a placeholder is not in the profile or the caption is over the platform's limit. */
    public function captionFor(string $platform): string
    {
        $caption = $this->pasteText($platform);
        if (preg_match('/\{\{\s*[\p{L}\p{N}_]+\s*\}\}/u', $caption, $m)) {
            throw new PublishFailed(ucfirst($platform)." caption has the placeholder {$m[0]}, which is not in the profile's fields");
        }
        $max = PostRequest::CAPTION_MAX[$platform];
        throw_if(mb_strlen($caption) > $max, new PublishFailed(ucfirst($platform).' caption is '.mb_strlen($caption)." characters with both languages, over {$max}"));

        return $caption;
    }

    /** Instagram usernames typed as "@a, @b" or one per line, without the @ and without duplicates. */
    public static function handles(?string $text): array
    {
        return array_values(array_unique(array_map(fn ($h) => ltrim($h, '@'), preg_split('/[\s,]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY))));
    }

    /** The accounts invited as collaborators on the Instagram post (feed, carousel, Reel). */
    public function collaborators(): array
    {
        return self::handles($this->settings['instagram']['collaborators'] ?? null);
    }

    /** The date range and state filters of the JSON API and the MCP server, plus the calendar's platform and pillar. */
    public function scopeFilter(Builder $q, array $filters): Builder
    {
        return $q->when($filters['from'] ?? null, fn ($q, $d) => $q->where('scheduled_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('scheduled_at', '<=', $d))
            ->when($filters['state'] ?? null, fn ($q, $s) => $q->where('state', $s))
            ->when($filters['platform'] ?? null, fn ($q, $p) => $q->whereJsonContains('platforms', $p))
            ->when($filters['pillar'] ?? null, fn ($q, $p) => $q->where('pillar', $p));
    }

    /** The post as the JSON API and the webhooks show it (docs/api.md). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_id' => $this->client_id,
            'state' => $this->state->value,
            'platforms' => $this->platforms,
            'format' => $this->format,
            'pillar' => $this->pillar,
            'brief' => $this->brief,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'external_ids' => $this->external_ids,
            'publish_error' => $this->publish_error,
            'metrics' => $this->metrics,
            'settings' => $this->settings,
            'captions' => $this->variants->groupBy('platform')->map(fn ($vs) => $vs->keyBy('lang')->map(fn (PostVariant $v) => ['caption' => $v->caption, 'alt_text' => $v->alt_text, 'hashtags' => $v->hashtags ?? []])),
            'assets' => $this->assets->map(fn (Asset $a) => ['id' => $a->id, 'name' => $a->name, 'kind' => $a->kind])->values(),
            'url' => route('posts.edit', $this),
        ];
    }

    public function altTextFor(string $platform): ?string
    {
        return $this->variants->where('platform', $platform)->whereNotNull('alt_text')->first()?->alt_text;
    }

    /** Every review batch this post was part of, with the approver's decision and comment on the pivot. */
    public function reviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class)->withPivot(Review::PIVOT)->latest('reviews.created_at');
    }

    /**
     * Move to the next state; approval with a date on the calendar schedules the post at once. Reaching Published, by the
     * drivers or by hand, drafts the next occurrence of a repeating post (once, spawnRepeat()). The way back from Published
     * (PostState::back(), "Not published after all") is only for a post that never went out through a platform API.
     */
    public function transitionTo(PostState $to): void
    {
        if (! $this->state->canTransitionTo($to)) {
            throw new InvalidTransition("Cannot go from {$this->state->label()} to {$to->label()}.");
        }
        $back = $to === $this->state->back();
        if ($back && $this->external_ids) {
            throw new InvalidTransition(__('This post went out through the platform, so it stays published. Withdraw it instead.'));
        }

        // a post sent back whose time has passed waits Approved while posting is on, so the queue does not publish it at once
        if ($to === PostState::Approved && $this->scheduled_at && ! ($back && config('features.publishing') && $this->scheduled_at->isPast())) {
            $to = PostState::Scheduled;
        }

        $this->forceFill([
            'state' => $to,
            'published_at' => match (true) {
                $to === PostState::Published => now(),
                $back => null,
                default => $this->published_at,
            },
        ])->save();

        if ($to === PostState::Published && ($next = $this->spawnRepeat())) {
            Slack::send("{$this->client->name}: next {$this->settings['repeat']} repeat drafted, {$next->name}, send it to review\n".route('posts.edit', $next));
        }
    }

    /**
     * A post set to repeat: its next occurrence as a new Draft a week or a month later, same content, captions and assets.
     * It goes through approval again. Only once per post: the copy's id is kept in `settings.repeated_as`, so marking a post
     * published again after "Not published after all" drafts no second copy (and the way back leaves the copy alone).
     */
    public function spawnRepeat(): ?Post
    {
        $repeat = $this->settings['repeat'] ?? null;
        if (! $repeat || ! $this->scheduled_at || isset($this->settings['repeated_as'])) {
            return null;
        }

        $next = $this->duplicate(null, $repeat === 'weekly' ? $this->scheduled_at->copy()->addWeek() : $this->scheduled_at->copy()->addMonthNoOverflow());
        // quietly: bookkeeping, not a content change that would send a locked post back to review
        $this->forceFill(['settings' => ['repeated_as' => $next->id] + $this->settings])->saveQuietly();

        return $next;
    }

    /** A Draft copy with the captions and settings: for the same client with its media too, for another client without (the media stays with its owner). */
    public function duplicate(?Client $client = null, ?CarbonInterface $date = null): Post
    {
        $client ??= $this->client;
        $copy = $client->posts()->create([
            'name' => PostNamer::name($client, $this->platforms, $this->format, $this->pillar, $date ?? now()),
            'state' => PostState::Draft,
            'platforms' => $this->platforms,
            'format' => $this->format,
            'pillar' => $this->pillar,
            'brief' => $this->brief,
            'settings' => $this->settings === null ? null : Arr::except($this->settings, 'repeated_as'), // the copy repeats on its own
            'scheduled_at' => $date,
            'created_by' => auth()->id() ?? $this->created_by,
        ]);
        $copy->variants()->createMany($this->variants->map(fn (PostVariant $v) => $v->only(['platform', 'lang', 'caption', 'alt_text', 'hashtags']))->all());
        if ($client->is($this->client)) {
            $copy->assets()->sync($this->assets->mapWithKeys(fn (Asset $a) => [$a->id => ['position' => $a->pivot->position]])->all());
        }

        return $copy;
    }

    /**
     * The form's (or update-post's) settings over the stored ones. Keys the sender does not show stay (repeat, collaborators,
     * TikTok while posting is off), and when nothing really changed the stored value comes back as it was, so saving an
     * approved post untouched keeps it approved (empty, null and false count as "not set").
     */
    public static function mergeSettings(?array $stored, array $incoming): ?array
    {
        $merged = array_replace_recursive($stored ?? [], $incoming);

        return self::setValues($merged) == self::setValues($stored ?? []) ? $stored : $merged;
    }

    /** The settings without the empty values, for comparing what a post really has set. */
    private static function setValues(array $settings): array
    {
        foreach ($settings as $key => $value) {
            $value = is_array($value) ? self::setValues($value) : $value;
            if ($value === null || $value === '' || $value === false || $value === []) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /** Content went back to review after a change to a locked post (captions or media; a settings change goes through the updating hook). */
    public function reopenForReview(): void
    {
        if ($this->state->isLocked() && $this->state !== PostState::Published) {
            $this->forceFill(['state' => PostState::InReview])->save();
        }
    }
}
