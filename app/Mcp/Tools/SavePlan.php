<?php

namespace App\Mcp\Tools;

use App\Models\Post;
use App\Planning\ClaudeQueue;
use App\Planning\PlanRequest;
use App\Planning\PlanWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Step 2 of planning: file the plan you made from get-planning-brief. Every post becomes a Draft on the calendar (named by the convention, dated on the client\'s next free posting slot from its date, a caption per platform and language when given; captions within the post form\'s limits, else nothing is filed and the error names the post to shorten), the trend brief and a review sheet land in the client\'s reports, and the sheet is what the Word export of the θεματολογία is made from. A human reviews, sends for approval and posts; nothing is published here.')]
class SavePlan extends Tool
{
    use ScopedToToken;

    public function handle(Request $request, PlanWriter $writer): Response
    {
        $client = $this->client($request->get('client'));
        $profile = $client->toProfileArray();
        $languages = array_merge($profile['languages'], $profile['extra_languages'] ?? []);

        $input = Validator::make($request->all(), [
            'job' => ['nullable', 'integer'],
            'trend_brief' => ['nullable', 'string', 'max:100000'],
            'posts' => ['required', 'array', 'min:1', 'max:'.PlanRequest::MAX_POSTS],
            'posts.*.date' => ['required', 'date_format:Y-m-d'],
            'posts.*.platforms' => ['required', 'array', 'min:1'],
            'posts.*.platforms.*' => [Rule::in(Post::PLATFORMS)],
            'posts.*.format' => ['required', Rule::in(Post::FORMATS)],
            'posts.*.pillar' => ['nullable', Rule::in(array_keys($profile['pillars']))], // a profile may have no pillars (the brief then says so)
            'posts.*.title' => ['required', 'string', 'max:200'],
            'posts.*.angle' => ['required', 'string', 'max:5000'],
            'posts.*.trend_signal' => ['nullable', 'string', 'max:200'],
            'posts.*.why_now' => ['nullable', 'string', 'max:2000'],
            'posts.*.asset_brief' => ['nullable', 'string', 'max:5000'],
            'posts.*.caption_brief' => ['nullable', 'string', 'max:5000'],
            'posts.*.captions' => ['nullable', 'array'],
            'posts.*.captions.hashtags' => ['nullable', 'array'],
            'posts.*.captions.hashtags.*' => ['string'],
            'posts.*.captions.alt_text' => ['nullable', 'array'],
            'posts.*.captions.alt_text.*' => ['nullable', 'string'],
            'posts.*.captions.design' => ['nullable', 'array'],
            'posts.*.captions.design.*' => ['nullable', 'string'],
            'posts.*.captions.notes_for_reviewer' => ['nullable', 'string'],
            ...collect($languages)->mapWithKeys(fn ($lang) => ["posts.*.captions.{$lang}" => ['nullable', 'array'], "posts.*.captions.{$lang}.*" => ['nullable', 'string']])->all(),
        ])->validate();
        // the post form's limits: a Draft with a longer caption could not be saved again, in the app or with update-post
        if ($tooLong = PlanWriter::overLimits($input['posts'])) {
            throw ValidationException::withMessages($tooLong);
        }

        $posts = array_map(fn (array $post) => $post + ['pillar' => null, 'trend_signal' => null, 'why_now' => '', 'asset_brief' => '', 'caption_brief' => ''], $input['posts']);
        $dates = array_column($posts, 'date');
        $result = [
            'client' => $client->slug,
            'created_at' => now()->toIso8601String(),
            'window' => ['start' => min($dates), 'end' => max($dates)],
            'platforms' => array_values(array_unique(array_merge(...array_column($posts, 'platforms')))),
            'trend_brief' => $input['trend_brief'] ?? '',
            'warnings' => [],
            'posts' => $posts,
        ];
        $sheet = $writer->write($client, $result, fileBrief: ($input['trend_brief'] ?? '') !== '');
        if ($job = $client->reports()->where('kind', 'claude_job')->find($input['job'] ?? 0)) {
            ClaudeQueue::clear($job); // with identical requests that slipped in next to it
        }

        return Response::json([
            'drafts' => count($result['posts']),
            'review_sheet' => route('reports.show', $sheet),
            'word' => route('reports.word', $sheet),
            'posts' => array_map(fn ($p) => ['date' => $p['date'], 'time' => $p['time'], 'name' => $p['name'], 'url' => $p['url']] + (isset($p['planned_date']) ? ['planned_date' => $p['planned_date']] : []), $result['posts']),
            'next' => 'The team reviews the Drafts in the app, sends them for approval and posts them by hand.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or slug')->required(),
            'job' => $schema->integer()->description('The plan job from list-claude-jobs this plan answers; it leaves the queue'),
            'trend_brief' => $schema->string()->description('The trend research the plan rests on, Markdown; filed in the client\'s reports'),
            'posts' => $schema->array()->items($schema->object()->description('date (YYYY-MM-DD), platforms [], format, pillar, title, angle, trend_signal (or null), why_now, asset_brief, caption_brief, captions {el: {instagram: "...", facebook: "..."}, en: {...}, hashtags: ["#chania"], alt_text: {el: "...", en: "..."}, design: {el: "...", en: "..."}, notes_for_reviewer: "..."}'))->min(1)->required(),
        ];
    }
}
