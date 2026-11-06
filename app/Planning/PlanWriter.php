<?php

namespace App\Planning;

use App\Http\Requests\PostRequest;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Posts\PostNamer;
use App\Posts\PostState;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Files a planned batch (the DraftBatch shape, from the command or from Claude over MCP): one Draft post per plan with a
 * caption variant per platform and language when captions came along, the trend brief and the review sheet in the
 * client's reports. Nothing is published or approved; a human sends each Draft to review.
 */
class PlanWriter
{
    /**
     * All or nothing: a sheet that fails to file takes its Drafts with it, so a retried job does not file them twice.
     *
     * @param  array  $result  gets each post's name, url, time and the day it has on the calendar; the sheet is returned
     */
    public function write(Client $client, array &$result, bool $fileBrief): Report
    {
        return DB::transaction(function () use ($client, &$result, $fileBrief) {
            foreach ($result['posts'] as &$post) {
                $draft = $this->createDraft($client, $post);
                $post['name'] = $draft->name;
                $post['url'] = route('posts.edit', $draft);
                // the Draft's own day, which a taken or missing slot can move: the sheet, the Word file and the calendar say the same
                if ($draft->scheduled_at->toDateString() !== $post['date']) {
                    $post['planned_date'] = $post['date'];
                    $post['date'] = $draft->scheduled_at->toDateString();
                }
                $post['time'] = $draft->scheduled_at->format('H:i');
            }
            unset($post);
            $dates = array_column($result['posts'], 'date');
            $result['window'] = ['start' => min([$result['window']['start'], ...$dates]), 'end' => max([$result['window']['end'], ...$dates])];

            $t = fn (string $key, array $replace = []) => __($key, $replace, $client->uiLocale()); // the sheet is read by the team and the client
            if ($fileBrief && trim($result['trend_brief'] ?? '') !== '') {
                $client->reports()->create(['kind' => 'trend_brief', 'title' => $t('Trend brief :date', ['date' => now()->format('d-m-Y')]), 'body' => $result['trend_brief']]);
            }

            return $client->reports()->create(['kind' => 'plan', 'title' => $t('Content batch :start to :end', self::shown($result['window'])), 'body' => $this->reviewSheet($result, $t), 'meta' => $result]);
        });
    }

    /**
     * What the post form would refuse in a plan (PostRequest's limits), so a Draft never holds text it cannot save again:
     * one message per field, keyed by its path in the plan and naming the post, for the caller to shorten and file again.
     * The texts are strings already (SavePlan's rules).
     *
     * @return array<string, string>
     */
    public static function overLimits(array $posts): array
    {
        $errors = [];
        foreach ($posts as $i => $post) {
            $captions = (array) ($post['captions'] ?? []);
            $which = 'Post '.($i + 1).' ("'.($post['title'] ?? '').'", '.($post['date'] ?? '').')';
            $over = fn (string $what, ?string $text, int $max) => ($length = mb_strlen((string) PostRequest::lines($text))) > $max ? "$which: $what is $length characters, over the limit of $max. Shorten it and call save-plan again." : null;
            foreach (self::languages($captions) as $lang) {
                foreach ((array) ($post['platforms'] ?? []) as $platform) { // only the post's platforms become captions, as on the form
                    $errors["posts.$i.captions.$lang.$platform"] = $over('the '.ucfirst($platform).' caption in '.strtoupper($lang), is_array($captions[$lang]) ? ($captions[$lang][$platform] ?? null) : null, PostRequest::CAPTION_MAX[$platform] ?? 5000);
                }
            }
            foreach ((array) ($captions['alt_text'] ?? []) as $lang => $text) {
                $errors["posts.$i.captions.alt_text.$lang"] = $over('the alt text in '.strtoupper($lang), $text, PostRequest::ALT_TEXT_MAX);
            }
            foreach ((array) ($captions['design'] ?? []) as $lang => $text) {
                $errors["posts.$i.captions.design.$lang"] = $over('the design text in '.strtoupper($lang), $text, PostRequest::DESIGN_MAX);
            }
            foreach ((array) ($captions['hashtags'] ?? []) as $n => $tag) {
                $errors["posts.$i.captions.hashtags.$n"] = $over("hashtag $tag", $tag, PostRequest::HASHTAG_MAX);
            }
        }

        return array_filter($errors);
    }

    /** The window's ISO dates (the report's meta keeps them so) as people read them: DD-MM-YYYY. */
    private static function shown(array $window): array
    {
        return array_map(fn (string $date) => CarbonImmutable::parse($date)->format('d-m-Y'), $window);
    }

    /** The two-letter keys of a captions map, in the client's language order. */
    public static function languages(array $captions): array
    {
        return array_values(array_filter(array_keys($captions), fn ($k) => is_string($k) && strlen($k) === 2));
    }

    private function createDraft(Client $client, array $post): Post
    {
        // the planner gives a date only: the client's next free posting slot from that day, else 10:00 as a placeholder the reviewer adjusts
        $day = CarbonImmutable::parse($post['date']);
        $date = $client->nextFreeSlot($day->startOfDay()) ?? $day->setTime(10, 0);
        $t = fn (string $key) => __($key, [], $client->uiLocale());
        $trend = ($post['trend_signal'] ?? null) ? $t('Trend:')." {$post['trend_signal']}. " : '';
        $captions = $post['captions'] ?? [];

        $draft = $client->posts()->create([
            'name' => PostNamer::name($client, $post['platforms'], $post['format'], $post['pillar'], $date),
            'state' => PostState::Draft,
            'platforms' => $post['platforms'],
            'format' => $post['format'],
            'pillar' => $post['pillar'],
            'brief' => "{$post['title']}\n{$post['angle']}\n{$trend}{$t('Why now:')} {$post['why_now']}\n{$t('Asset:')} {$post['asset_brief']}",
            'settings' => isset($captions['design']) ? ['design' => $captions['design']] : null,
            'scheduled_at' => $date,
        ]);

        $draft->variants()->createMany(collect($post['platforms'])->crossJoin(self::languages($captions))
            ->filter(fn ($pair) => trim((string) ($captions[$pair[1]][$pair[0]] ?? '')) !== '')
            ->map(fn ($pair) => [
                'platform' => $pair[0],
                'lang' => $pair[1],
                'caption' => $captions[$pair[1]][$pair[0]],
                'alt_text' => $captions['alt_text'][$pair[1]] ?? null,
                'hashtags' => $captions['hashtags'] ?? [],
            ])->values()->all());

        return $draft;
    }

    private function reviewSheet(array $result, Closure $t): string
    {
        $md = '# '.$t('Content batch: :client, :start to :end', ['client' => $result['client']] + self::shown($result['window']))."\n\n";
        $md .= $t('Drafted :count posts for :platforms. Every caption needs a human review before it goes to the client.', ['count' => count($result['posts']), 'platforms' => implode(', ', $result['platforms'])])."\n\n";
        foreach ($result['warnings'] as $warning) {
            $md .= '> '.$t('Warning:')." {$warning}\n";
        }

        foreach ($result['posts'] as $i => $post) {
            $captions = $post['captions'] ?? [];
            $md .= "\n## ".($i + 1).". {$post['title']} (".trim(CarbonImmutable::parse($post['date'])->format('d-m-Y').' '.($post['time'] ?? '')).")\n\n";
            if (isset($post['planned_date'])) {
                $md .= '- '.$t('Planned for :date; the calendar has it on the next free posting slot.', ['date' => CarbonImmutable::parse($post['planned_date'])->format('d-m-Y')])."\n";
            }
            $md .= '- '.$t('Name:')." `{$post['name']}`\n- ".$t('Platforms:').' '.implode(', ', $post['platforms']).' | '.$t('Format:')." {$post['format']} | ".$t('Pillar:').' '.($post['pillar'] ?? '')."\n";
            $md .= '- '.$t('Trend:').' '.($post['trend_signal'] ?? $t('evergreen')).' | '.$t('Why now:')." {$post['why_now']}\n";
            $md .= '- '.$t('Angle:')." {$post['angle']}\n- ".$t('Asset:')." {$post['asset_brief']}\n";
            if (($post['caption_brief'] ?? '') !== '') {
                $md .= '- '.$t('Caption brief:')." {$post['caption_brief']}\n";
            }
            foreach (self::languages($captions) as $lang) {
                foreach ($captions[$lang] as $platform => $caption) {
                    $md .= "\n**".strtoupper($lang)." / {$platform}**\n\n{$caption}\n";
                }
            }
            if ($captions) {
                $md .= "\n- ".$t('Hashtags:').' '.implode(' ', $captions['hashtags'] ?? [])."\n";
                foreach ($captions['alt_text'] ?? [] as $lang => $alt) {
                    $md .= '- '.$t('Alt text :lang:', ['lang' => strtoupper($lang)])." {$alt}\n";
                }
                if (isset($captions['notes_for_reviewer'])) {
                    $md .= '- '.$t('Notes for reviewer:')." {$captions['notes_for_reviewer']}\n";
                }
            }
        }

        return $md."\n---\n\n## ".$t('Trend brief used')."\n\n".(trim($result['trend_brief'] ?? '') !== '' ? $result['trend_brief'] : $t('(none filed)'))."\n";
    }
}
