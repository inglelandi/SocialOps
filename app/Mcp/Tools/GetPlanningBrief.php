<?php

namespace App\Mcp\Tools;

use App\Http\Requests\PostRequest;
use App\Models\Client;
use App\Models\Post;
use App\Planning\ClaudeQueue;
use App\Planning\ClientReference;
use App\Planning\PillarQuota;
use App\Planning\PlanRequest;
use App\Planning\Prompt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Step 1 of planning a batch for a client: the brief you plan from. It holds the brand profile, the pillar quota for the batch, the publishing window with its key dates, holidays and posting slots, the recent posts not to repeat, the client\'s own Drive files (the team\'s posts slideshow with the posts prepared to go up, to plan around and never plan again, the posts it published before Social Ops, not to repeat, its reference files, as facts, and its past designs as Claude described them from the images), the formats and the caption rules. Research the current trends yourself (the last30days skill or web search), plan the posts, write the captions, then hand everything to save-plan. Nothing is published.')]
class GetPlanningBrief extends Tool
{
    use ScopedToToken;

    public function handle(Request $request): Response
    {
        $client = $this->client($request->get('client'));
        $profile = $client->toProfileArray();
        $from = CarbonImmutable::parse($request->get('from') ?: 'tomorrow');
        $days = max(1, (int) ($request->get('days') ?: 30));

        try {
            $plan = new PlanRequest(
                profile: $profile,
                count: (int) ($request->get('posts') ?: 8),
                platforms: $request->get('platforms') ?: PlanRequest::plannable($profile),
                from: $from,
                to: $from->addDays($days - 1),
                instruction: trim((string) $request->get('instruction')),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['posts' => $e->getMessage()]);
        }

        $quota = $plan->instruction === '' && $profile['pillars'] ? PillarQuota::split($profile['pillars'], $plan->count) : [];
        $languages = array_merge($profile['languages'], $profile['extra_languages'] ?? []);
        $reference = ClientReference::for($client);
        $prompt = Prompt::render('content-plan', $plan->promptVars() + [
            'quota' => PillarQuota::line($quota, $plan->instruction),
            'trend_brief' => "Research it yourself before planning (the last30days skill, or web search): what people in {$profile['location']} and the guests of a {$profile['type']} talk about between {$plan->from->toDateString()} and {$plan->to->toDateString()}, the formats and sounds that work now on ".implode(', ', $plan->platforms).', and the moments of the season. Name each signal you use.',
            'recent' => $client->recentTopics(),
            'upcoming_posts' => $reference['upcoming_posts'],
            'products' => $reference['products'],
            'previous_posts' => $reference['previous_posts'],
            'reference' => $reference['reference'],
            'designs' => $reference['designs'],
        ]);

        $brief = [
            'client' => $client->slug,
            'window' => ['start' => $plan->from->toDateString(), 'end' => $plan->to->toDateString()],
            'count' => $plan->count,
            'platforms' => $plan->platforms,
            'quota' => $quota,
            'pillars' => array_keys($profile['pillars']),
            'formats' => Post::FORMATS,
            'languages' => $languages,
            'prompt' => $prompt,
            'posts_slideshow' => $reference['slideshow'],
            'shop' => $reference['products'] !== '' ? $reference['products'].' Search it with find-products.' : null,
            'reference_folder' => $reference['folder'],
            'reference_files' => $reference['files'],
            'caption_rules' => 'For each post write captions per platform and language ('.implode(', ', $languages).') in the voice above, the hook in the first line, placeholders like {{phone}} as written in the profile fields. Limits: '
                .implode(', ', array_map(fn ($p, $n) => "{$p} {$n}", array_keys(PostRequest::CAPTION_MAX), PostRequest::CAPTION_MAX)).' characters. '.self::hashtagRule($profile['hashtags'] ?? []).' Add alt text per language and the design text (what goes on the graphic or in the video). Greek captions are reviewed by a person; do not publish anything.',
            'then' => 'Write the captions of every post (caption_rules above), the posts are not done without them. Then call save-plan with the client, the trend brief you wrote (Markdown) and the posts: date, platforms, format, pillar, title, angle, trend_signal, why_now, asset_brief, caption_brief, fixed_date (true when the post is about that specific day and must not move), products (the ids find-products gave for the shop products the post links, when the client has a shop), captions {'.implode(', ', array_map(fn ($l) => "{$l}: {platform: caption}", $languages)).', hashtags: [], alt_text: {lang: text}, design: {lang: text}, notes_for_reviewer}, language first, then platform. A post that arrives without captions is listed under captions_missing: write those with get-caption-brief and update-post.',
        ];
        if ($note = self::referenceNote($client, $reference)) {
            $brief['note'] = $note;
        }

        return Response::json($brief);
    }

    /**
     * The slideshow or the folder is linked but gave the brief nothing, or part of it is still waiting: say so, so Claude
     * does not take "none" for "the client has no files", nor the designs described so far for all of them.
     */
    private static function referenceNote(Client $client, array $reference): ?string
    {
        $notes = [];
        if ($slideshow = $reference['slideshow']) {
            if ($slideshow['error']) {
                $notes[] = "The team's posts slideshow \"{$slideshow['name']}\" could not be read: {$slideshow['error']}. Its section above holds what was read before, if anything; say so in notes_for_reviewer.";
            } elseif ($reference['upcoming_posts'] === '') {
                $notes[] = "The team's posts slideshow \"{$slideshow['name']}\" is linked but nothing has been read from it yet (the first read is still running, or the file is empty).";
            }
        }
        if ($reference['folder'] === null) {
            return $notes ? implode(' ', $notes) : null;
        }
        $error = $client->accounts()->where('platform', 'drive_docs')->first()?->meta['error'] ?? null;
        if ($error) {
            $notes[] = "The client's Drive folder \"{$reference['folder']['name']}\" could not be read: {$error}. The file sections above hold what was read before, if anything.";
        } elseif ($reference['previous_posts'] === '' && $reference['reference'] === '' && $reference['designs'] === '' && $reference['designs_waiting'] === 0) {
            $notes[] = "The client's Drive folder \"{$reference['folder']['name']}\" is linked but nothing has been read from it yet: no readable file or design, the first read is still running, or every file is marked Not used. Plan from the profile.";
        }
        if ($reference['designs_waiting'] > 0) {
            $notes[] = "{$reference['designs_waiting']} designs in the client's Drive folder are still waiting to be described (the designs of list-claude-jobs, ".ClaudeQueue::DESIGNS_PER_RUN." an hour); the past designs section holds the {$reference['designs_described']} described so far.";
        }

        return $notes ? implode(' ', $notes) : null;
    }

    /** The profile's hashtag rule as a sentence: none when it allows 0 and names none to always use, no empty lists read out. */
    private static function hashtagRule(array $hashtags): string
    {
        $always = $hashtags['always'] ?? [];
        $pool = $hashtags['pool'] ?? [];
        $max = max((int) ($hashtags['max_per_post'] ?? 5), count($always));
        if ($max === 0) {
            return 'Hashtags: none (the profile allows 0 per post).';
        }

        return "Hashtags: up to {$max}".($always ? ', always '.implode(' ', $always) : '').($pool ? ', '.($always ? 'the rest' : 'picked').' from '.implode(' ', $pool) : '').'.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or slug')->required(),
            'posts' => $schema->integer()->description('How many posts to plan, 1 to '.PlanRequest::MAX_POSTS.' (default 8)'),
            'from' => $schema->string()->description('First publishing date, YYYY-MM-DD (default tomorrow)'),
            'days' => $schema->integer()->description('Length of the publishing window in days (default 30)'),
            'platforms' => $schema->array()->items($schema->string()->enum(Post::PLATFORMS))->description('Subset of the client\'s accounts (default: all of them; every platform while the profile names no account)'),
            'instruction' => $schema->string()->description('What the posts should be about, in the team\'s words; it decides the topic instead of the pillar quota'),
        ];
    }
}
